<?php

namespace App\Services\Integrations;

use App\Enums\BusinessDocumentType;
use App\Enums\IntegrationDocumentInboxStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\IntegrationDocumentInbox;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\User;
use App\Services\Invoicing\DocumentSequenceService;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\JurisdictionRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationDocumentInboxService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enqueueFromWoo(StoreIntegration $integration, array $payload): array
    {
        $company = $this->resolveCompany($integration);
        $store = $integration->store;

        $type = BusinessDocumentType::tryFrom((string) ($payload['type'] ?? 'invoice')) ?? BusinessDocumentType::Invoice;

        $wcOrderId = isset($payload['woocommerce_order_id']) ? (int) $payload['woocommerce_order_id'] : null;
        if ($wcOrderId !== null && $wcOrderId > 0) {
            // Dedupe per (order, TYPE): one order legitimately carries both a
            // proforma (deferred payment) and its later final invoice - the
            // invoice request must never return the pending proforma entry.
            $existing = IntegrationDocumentInbox::query()
                ->where('store_integration_id', $integration->id)
                ->where('woocommerce_order_id', $wcOrderId)
                ->where('document_type', $type->value)
                ->where('status', IntegrationDocumentInboxStatus::Pending)
                ->first();
            if ($existing) {
                return $this->serializeEntry($existing);
            }
        }
        if (! $type->isMvpEnabled()) {
            throw ValidationException::withMessages(['type' => ['Document type not supported.']]);
        }

        $appSettings = CompanyAppSettings::from($company->app_settings);
        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $normalizedLines = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $normalizedLines[] = [
                'name' => (string) ($line['name'] ?? 'Item'),
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'tax_rate' => (float) ($line['tax_rate'] ?? 0),
                'unit' => (string) ($line['unit'] ?? 'pcs'),
            ];
        }

        $documentPayload = [
            'type' => $type->value,
            'currency' => (string) ($payload['currency'] ?? $company->default_currency),
            'store_id' => $store->id,
            'buyer' => $buyer,
            'lines' => $normalizedLines,
            'woocommerce_order_id' => $wcOrderId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays((int) $appSettings->get('default_invoice_payment_terms_days', 14))->toDateString(),
            'delivery_date' => now()->toDateString(),
            'payment_btc_enabled' => (bool) $store->company_id,
            'payment_bank_enabled' => true,
            'internal_note' => $wcOrderId ? 'woocommerce_order_id='.$wcOrderId : null,
            'note_above_lines' => $wcOrderId ? 'WooCommerce order #'.$wcOrderId : null,
            'tags' => $wcOrderId ? ['woocommerce', 'wc_order:'.$wcOrderId] : ['woocommerce'],
            // Headless auto-issue renders the PDF before any browser touches
            // the document - pick the merchant's language up front.
            'pdf_locale' => JurisdictionRules::defaultPdfLocale($company->jurisdiction),
        ];

        foreach ([
            'payment_method',
            'is_paid',
            'paid_at',
            'order_total',
            'discount_percent',
            'btcpay_invoice_id',
            // Link to the earlier proforma entry (deferred-payment flow) -
            // the client import resolves it to a local sourceDocumentId.
            'source_evolu_document_id',
        ] as $passthroughKey) {
            if (array_key_exists($passthroughKey, $payload)) {
                $documentPayload[$passthroughKey] = $payload[$passthroughKey];
            }
        }

        // Deferred-payment flow: the plugin sends the proforma's number with
        // the final invoice - surface it on the document itself (PDF + local
        // import), the proforma inbox row may already be gone.
        $sourceNumber = trim((string) ($payload['source_document_number'] ?? ''));
        if ($sourceNumber !== '' && $type === BusinessDocumentType::Invoice) {
            $documentPayload['source_document_number'] = $sourceNumber;
            $paidByProforma = __(
                'invoicing.paid_by_proforma',
                ['number' => $sourceNumber],
                JurisdictionRules::documentNoteLocale($company->jurisdiction),
            );
            $note = trim((string) ($documentPayload['note_above_lines'] ?? ''));
            $documentPayload['note_above_lines'] = $note === '' ? $paidByProforma : $note.' - '.$paidByProforma;
        }

        if (! isset($documentPayload['is_paid']) && isset($payload['payment_method'])) {
            $method = strtolower((string) $payload['payment_method']);
            if (str_contains($method, 'btcpay') || str_contains($method, 'satflux') || str_contains($method, 'bitcoin') || str_contains($method, 'satoshi')) {
                $documentPayload['is_paid'] = true;
            }
        }

        $entry = IntegrationDocumentInbox::create([
            'store_integration_id' => $integration->id,
            'woocommerce_order_id' => $wcOrderId,
            'evolu_document_id' => (string) Str::uuid(),
            'payload_json' => $documentPayload,
            'document_type' => $type->value,
            'status' => IntegrationDocumentInboxStatus::Pending,
        ]);

        return $this->serializeEntry($entry);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForUser(User $user, Company $company): Collection
    {
        $this->assertCompanyAccess($user, $company);

        return IntegrationDocumentInbox::query()
            ->where('status', IntegrationDocumentInboxStatus::Pending)
            ->whereHas('storeIntegration', function ($query) use ($company) {
                $query->where(function ($inner) use ($company) {
                    $inner->where('company_id', $company->id)
                        ->orWhereHas('store', fn ($storeQuery) => $storeQuery->where('company_id', $company->id));
                });
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IntegrationDocumentInbox $entry) => $this->serializeEntry($entry));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForStore(User $user, Store $store): Collection
    {
        if ($store->user_id !== $user->id && ! $user->isSupport() && ! $user->isAdmin()) {
            abort(403, 'Unauthorized access to store');
        }

        return IntegrationDocumentInbox::query()
            ->where('status', IntegrationDocumentInboxStatus::Pending)
            ->whereHas('storeIntegration', fn ($query) => $query->where('store_id', $store->id))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IntegrationDocumentInbox $entry) => $this->serializeEntry($entry));
    }

    public function markImported(IntegrationDocumentInbox $entry): void
    {
        if ($entry->status !== IntegrationDocumentInboxStatus::Pending) {
            throw ValidationException::withMessages([
                'inbox' => ['Inbox item is not pending.'],
            ]);
        }

        $entry->delete();
    }

    public function issuePendingEntry(IntegrationDocumentInbox $entry, Company $company): IntegrationDocumentInbox
    {
        $payload = $entry->payload_json;

        if (! empty($payload['number'])) {
            return $entry;
        }

        if ($entry->status !== IntegrationDocumentInboxStatus::Pending) {
            throw ValidationException::withMessages([
                'inbox' => ['Inbox item is not pending.'],
            ]);
        }

        $type = (string) ($payload['type'] ?? 'invoice');
        $number = $this->sequenceService->nextNumber($company, $type);
        $payload['number'] = $number;
        $payload['variable_symbol'] = preg_replace('/\D/', '', $number) ?: null;
        $payload['issued_at'] = now()->toIso8601String();
        $entry->payload_json = $payload;
        $entry->save();

        AuditLog::log('integration_inbox.document_number_issued', 'company', $company->id, [
            'inbox_id' => $entry->id,
            'document_type' => $type,
            'number' => $number,
        ]);

        return $entry->fresh();
    }

    public function dismiss(IntegrationDocumentInbox $entry): void
    {
        if ($entry->status !== IntegrationDocumentInboxStatus::Pending) {
            throw ValidationException::withMessages([
                'inbox' => ['Inbox item is not pending.'],
            ]);
        }

        $entry->delete();
    }

    public function assertEntryBelongsToCompany(IntegrationDocumentInbox $entry, Company $company): void
    {
        $integration = $entry->storeIntegration()->with('store')->first();
        if (! $integration) {
            abort(404);
        }

        $linkedCompanyId = $integration->company_id ?? $integration->store?->company_id;
        if ($linkedCompanyId !== $company->id) {
            abort(404);
        }
    }

    public function assertEntryBelongsToStore(IntegrationDocumentInbox $entry, Store $store): void
    {
        $integration = $entry->storeIntegration()->first();
        if (! $integration || $integration->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeEntry(IntegrationDocumentInbox $entry): array
    {
        $payload = $entry->payload_json;

        return [
            'inbox_id' => $entry->id,
            'evolu_document_id' => $entry->evolu_document_id,
            'woocommerce_order_id' => $entry->woocommerce_order_id,
            'status' => $entry->status->value,
            'created_at' => $entry->created_at?->toIso8601String(),
            'payload' => $payload,
            // Auto-issue evidence (P3): present when the server issued the
            // paid order headlessly; the plugin notes these on the WC order.
            'number' => $payload['number'] ?? null,
            'auto_issued' => ! empty($payload['auto_issued_at']),
            'email_queued' => ! empty($payload['email_queued_at']),
            'emailed' => ! empty($payload['emailed_at']),
            'pdf_available' => ! empty($payload['number']),
            'summary' => [
                'type' => (string) ($payload['type'] ?? 'invoice'),
                'currency' => (string) ($payload['currency'] ?? 'EUR'),
                'line_count' => count($payload['lines'] ?? []),
                'buyer_name' => (string) (($payload['buyer']['name'] ?? '') ?: ($payload['buyer']['email'] ?? '')),
            ],
        ];
    }

    protected function resolveCompany(StoreIntegration $integration): Company
    {
        $company = $integration->company ?? $integration->store->company;
        if (! $company) {
            throw ValidationException::withMessages([
                'company' => ['Link a company to this store in Satflux invoicing settings.'],
            ]);
        }

        return $company;
    }

    protected function assertCompanyAccess(User $user, Company $company): void
    {
        if ($company->user_id !== $user->id && ! $user->isSupport() && ! $user->isAdmin()) {
            abort(403, 'Unauthorized access to company');
        }
    }
}
