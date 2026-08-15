<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Models\BusinessDocument;
use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\InvoiceService as BtcPayInvoiceService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BusinessDocumentBtcPayService
{
    public function __construct(
        protected BtcPayInvoiceService $btcPayInvoiceService,
        protected EphemeralBtcpayCheckoutService $ephemeralCheckoutService,
    ) {}

    /**
     * Checkout link for the BTC QR on an EPHEMERAL render (PDF/e-mail).
     *
     * This used to call createEphemeralCheckout() unconditionally and never
     * registered the row - every rendered PDF of a BTC-enabled document
     * minted an invisible stray BTCPay invoice (production 2026-07-15).
     * Now it shares the dedupe semantics:
     *  - paid documents (status or amount) get NO payment QR,
     *  - an already paid checkout gets NO payment QR (and is marked paid),
     *  - a still-payable checkout with a matching amount is reused,
     *  - an unknown BTCPay state renders WITHOUT a QR - never mints blindly,
     *  - only a genuinely unpaid document without a usable checkout mints,
     *    and the new checkout is REGISTERED so future dedupe sees it.
     */
    public function qrCheckoutLinkForEphemeralRender(
        BusinessDocument $document,
        Store $store,
        ?string $evoluDocumentId,
    ): ?string {
        // Paid documents render without a payment QR. The status check works
        // for both worlds (the model casts to the enum at runtime; static
        // analysis types it string in the ephemeral context, hence in_array).
        if (in_array($document->status, [BusinessDocumentStatus::Paid, BusinessDocumentStatus::Paid->value], true)) {
            return null;
        }
        $total = (float) $document->total;
        if ($total <= 0 || (float) $document->amount_paid >= $total - 0.005) {
            return null;
        }

        // Without a stable dedupe key a minted invoice could never be found
        // again by any later render/view/create - refuse to mint and render
        // without a QR instead of leaving untrackable strays behind.
        $user = $store->user;
        if (! $user instanceof User || ! is_string($evoluDocumentId) || $evoluDocumentId === '') {
            return null;
        }

        // Serialize per document: the auto-issue email job and the WooCommerce
        // attachment render fire within seconds of each other - both finding
        // "no pending checkout" would mint twice. Atomic on redis (prod).
        $lock = Cache::lock('ephemeral-qr-checkout:'.$store->id.':'.$evoluDocumentId, 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            // The concurrent holder is resolving the same checkout - render
            // without a QR rather than risk a duplicate mint.
            return null;
        }

        try {
            if ($this->ephemeralCheckoutService->findLatestPaid($user, $store, $evoluDocumentId)) {
                return null;
            }

            $pending = $this->ephemeralCheckoutService->findLatestPending($user, $store, $evoluDocumentId);
            if ($pending) {
                $state = $this->ephemeralCheckoutState($store, $pending->btcpay_invoice_id);
                if ($state['state'] === 'paid') {
                    $this->ephemeralCheckoutService->markPaid($pending);

                    return null;
                }
                if ($state['state'] === 'unknown') {
                    // Fail safe: render without a QR rather than mint a
                    // possible duplicate of an unverifiable invoice.
                    return null;
                }
                if (
                    $state['state'] === 'payable'
                    && abs((float) $pending->amount - $total) < 0.005
                    && strcasecmp((string) $pending->currency, (string) $document->currency) === 0
                ) {
                    return $state['checkout_link'];
                }
                // replaceable (expired/invalid) falls through to a fresh one.
            }

            $result = $this->createEphemeralCheckout($document, $store, $evoluDocumentId);

            if (is_string($result['btcpay_invoice_id'] ?? null) && $result['btcpay_invoice_id'] !== '') {
                $this->ephemeralCheckoutService->registerCheckout(
                    $user,
                    $store,
                    $evoluDocumentId,
                    $result['btcpay_invoice_id'],
                    $total,
                    $document->currency,
                );
            }

            return $result['checkout_link'] ?? null;
        } finally {
            $lock->release();
        }
    }

    /**
     * Attach or refresh BTCPay checkout for an issued document when Bitcoin payment is enabled.
     */
    public function syncForDocument(BusinessDocument $document, bool $forceRefresh = false): void
    {
        $document->loadMissing(['company', 'store']);

        if (! $document->payment_btc_enabled || ! $document->store_id) {
            if ($document->btcpay_invoice_id || $document->btcpay_checkout_link) {
                $document->btcpay_invoice_id = null;
                $document->btcpay_checkout_link = null;
                $document->save();
            }

            return;
        }

        if ($this->syncPaidFromBtcpayIfSettled($document)) {
            return;
        }

        if (
            ! $forceRefresh
            && $document->btcpay_invoice_id
            && $document->btcpay_checkout_link
            && ! $this->shouldRefreshCheckout($document)
        ) {
            return;
        }

        $this->attachCheckout($document);
        $document->save();
    }

    /**
     * If BTCPay shows the linked invoice as paid, mark the business document paid locally.
     */
    public function syncPaidFromBtcpayIfSettled(BusinessDocument $document): bool
    {
        if ($document->status === BusinessDocumentStatus::Paid) {
            return true;
        }

        if ($document->status !== BusinessDocumentStatus::Issued || ! $document->btcpay_invoice_id) {
            return false;
        }

        $document->loadMissing(['store.user']);

        $invoice = $this->fetchBtcpayInvoice($document);
        if ($invoice === null || ! $this->invoiceIndicatesPaid($invoice)) {
            return false;
        }

        app(BusinessDocumentMarkPaidService::class)->markPaid(
            $document,
            (float) $document->total,
            null,
            'btcpay_sync',
        );

        return true;
    }

    /**
     * Resolve business document for a BTCPay invoice (DB id, then API metadata).
     */
    public function resolveDocumentForBtcpayInvoice(Store $store, string $invoiceId): ?BusinessDocument
    {
        $document = BusinessDocument::query()
            ->where('store_id', $store->id)
            ->where('btcpay_invoice_id', $invoiceId)
            ->first();

        if ($document) {
            return $document;
        }

        $invoice = $this->fetchBtcpayInvoiceForStore($store, $invoiceId);
        if ($invoice === null) {
            return null;
        }

        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];

        foreach (['businessDocumentId', 'business_document_id'] as $key) {
            $documentId = $metadata[$key] ?? null;
            if (! is_string($documentId) || $documentId === '') {
                continue;
            }

            $document = BusinessDocument::query()
                ->where('id', $documentId)
                ->where('store_id', $store->id)
                ->first();

            if ($document) {
                if (! $document->btcpay_invoice_id) {
                    $document->update([
                        'btcpay_invoice_id' => $invoiceId,
                        'btcpay_checkout_link' => $invoice['checkoutLink'] ?? $document->btcpay_checkout_link,
                    ]);
                }

                return $document->fresh();
            }
        }

        return null;
    }

    public function shouldRefreshAfterUpdate(
        BusinessDocument $document,
        float $previousTotal,
        ?string $previousStoreId,
        bool $previousPaymentBtcEnabled
    ): bool {
        if (! $document->payment_btc_enabled || ! $document->store_id) {
            return false;
        }

        if (! $previousPaymentBtcEnabled || ! $document->btcpay_invoice_id) {
            return true;
        }

        if ($previousStoreId !== $document->store_id) {
            return true;
        }

        return abs((float) $document->total - $previousTotal) > 0.001;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function invoiceIndicatesPaid(array $invoice): bool
    {
        $status = (string) ($invoice['status'] ?? '');
        $additional = (string) ($invoice['additionalStatus'] ?? '');

        if ($additional === 'PaidPartial') {
            return false;
        }

        return in_array($status, ['Settled', 'Processing'], true);
    }

    protected function shouldRefreshCheckout(BusinessDocument $document): bool
    {
        if (! $document->btcpay_invoice_id) {
            return true;
        }

        $invoice = $this->fetchBtcpayInvoice($document);
        if ($invoice === null) {
            return true;
        }

        if ($this->invoiceIndicatesPaid($invoice)) {
            return false;
        }

        return in_array((string) ($invoice['status'] ?? ''), ['Expired', 'Invalid'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchBtcpayInvoice(BusinessDocument $document): ?array
    {
        $store = $document->store;
        if (! $store || ! $document->btcpay_invoice_id) {
            return null;
        }

        return $this->fetchBtcpayInvoiceForStore($store, $document->btcpay_invoice_id);
    }

    /**
     * BTCPay-side state of an existing ephemeral checkout (fresh fetch):
     *  - paid: the invoice settled - the caller must surface the payment,
     *    NEVER replace it with a fresh invoice (PR #144 root cause),
     *  - payable: New/Processing - reusable instead of minting another one
     *    (Processing means the customer already broadcast the payment, so
     *    the link is reused and settlement lands via webhook/polling - it
     *    is deliberately NOT reported paid here),
     *  - replaceable: expired/invalid - a fresh invoice may be created,
     *  - unknown: BTCPay could not be reached / did not answer - the caller
     *    must fail safe and never mint a replacement blindly.
     *
     * @return array{state: 'paid'|'payable'|'replaceable'|'unknown', checkout_link: string|null, btcpay_invoice_id: string}
     */
    public function ephemeralCheckoutState(Store $store, string $btcpayInvoiceId): array
    {
        $invoice = $this->fetchBtcpayInvoiceForStore($store, $btcpayInvoiceId);
        if (! is_array($invoice)) {
            return [
                'state' => 'unknown',
                'checkout_link' => null,
                'btcpay_invoice_id' => $btcpayInvoiceId,
            ];
        }

        $checkoutLink = isset($invoice['checkoutLink']) ? (string) $invoice['checkoutLink'] : null;
        $status = strtolower((string) ($invoice['status'] ?? ''));

        // Payable takes precedence: invoiceIndicatesPaid() counts Processing
        // as paid for the webhook/settlement flows, but an unconfirmed
        // payment must not mark the document paid here.
        if (in_array($status, ['new', 'processing'], true)) {
            return [
                'state' => 'payable',
                'checkout_link' => $checkoutLink,
                'btcpay_invoice_id' => $btcpayInvoiceId,
            ];
        }

        return [
            'state' => $this->invoiceIndicatesPaid($invoice) ? 'paid' : 'replaceable',
            'checkout_link' => $checkoutLink,
            'btcpay_invoice_id' => $btcpayInvoiceId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchBtcpayInvoiceForStore(Store $store, string $invoiceId): ?array
    {
        try {
            $userApiKey = $store->user->getBtcPayApiKeyOrFail();
            $this->btcPayInvoiceService->forgetInvoiceCache(
                $store->btcpay_store_id,
                $invoiceId,
                $userApiKey,
            );

            return $this->btcPayInvoiceService->getInvoice(
                $store->btcpay_store_id,
                $invoiceId,
                $userApiKey,
            );
        } catch (\Throwable $e) {
            Log::warning('Business document: failed to fetch BTCPay invoice', [
                'store_id' => $store->id,
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function attachCheckout(BusinessDocument $document): void
    {
        /** @var Store|null $store */
        $store = $document->store;
        if (! $store || $store->company_id !== $document->company_id || $store->user_id !== $document->company->user_id) {
            throw ValidationException::withMessages([
                'store_id' => ['A valid store linked to this company is required for Bitcoin payments.'],
            ]);
        }

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();
        $result = $this->btcPayInvoiceService->createInvoice(
            $store->btcpay_store_id,
            [
                'amount' => (string) $document->total,
                'currency' => $document->currency,
                'metadata' => [
                    'businessDocumentId' => $document->id,
                    'companyId' => $document->company_id,
                    'documentNumber' => $document->number,
                ],
                'checkout' => [
                    'expirationMinutes' => 60,
                    'monitoringMinutes' => 1440,
                ],
            ],
            $userApiKey
        );

        $document->btcpay_invoice_id = $result['id'] ?? null;
        $document->btcpay_checkout_link = $result['checkoutLink'] ?? null;
        $document->btcpay_checkout_created_at = now();
    }

    /**
     * Create a BTCPay checkout for an in-memory document without persisting business document rows.
     *
     * @return array{checkout_link: string|null, btcpay_invoice_id: string|null}
     */
    public function createEphemeralCheckout(BusinessDocument $document, Store $store, ?string $evoluDocumentId = null): array
    {
        if (! $document->payment_btc_enabled) {
            throw ValidationException::withMessages([
                'document' => ['Bitcoin payment is not enabled for this document.'],
            ]);
        }

        if ($store->user_id !== $document->company?->user_id) {
            throw ValidationException::withMessages([
                'store_id' => ['The selected store does not belong to your account.'],
            ]);
        }

        $document->store_id = $store->id;
        $document->setRelation('store', $store);

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $metadata = [
            'ephemeral' => true,
            'documentNumber' => $document->number,
        ];

        if (is_string($evoluDocumentId) && $evoluDocumentId !== '') {
            $metadata['evoluDocumentId'] = $evoluDocumentId;
        }

        $result = $this->btcPayInvoiceService->createInvoice(
            $store->btcpay_store_id,
            [
                'amount' => (string) $document->total,
                'currency' => $document->currency,
                'metadata' => $metadata,
                'checkout' => [
                    'expirationMinutes' => 60,
                    'monitoringMinutes' => 1440,
                ],
            ],
            $userApiKey
        );

        return [
            'checkout_link' => $result['checkoutLink'] ?? null,
            'btcpay_invoice_id' => $result['id'] ?? null,
        ];
    }
}
