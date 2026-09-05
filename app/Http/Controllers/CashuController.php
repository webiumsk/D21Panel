<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Auth\SensitiveActionAuthorization;
use App\Services\BtcPay\CashuService;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\LightningService;
use App\Services\StoreChecklistService;
use App\Services\WalletChangeConfirmationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashuController extends Controller
{
    public function __construct(
        protected CashuService $cashuService,
        protected LightningService $lightningService,
    ) {}

    /**
     * Confirm account password (or LNURL / Nostr challenge) before editing Cashu settings in the UI.
     * Recovery-phrase accounts may confirm with their authenticated session only.
     */
    public function confirmEdit(Request $request, Store $store): JsonResponse
    {
        if (($store->wallet_type ?? null) !== 'cashu') {
            throw ValidationException::withMessages([
                'store' => ['Cashu is not the wallet type for this store.'],
            ]);
        }

        $request->validate([
            'password' => ['nullable', 'string'],
        ]);

        SensitiveActionAuthorization::assertAllowed($request->user(), $request);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function getSettings(Store $store): JsonResponse
    {
        $wt = $store->wallet_type ?? null;

        if ($this->isLightningWalletTypeForCashuSwitch($wt) && ! $store->cashu_fallback_enabled) {
            return response()->json([
                'data' => $this->emptyCashuSettingsPayload(),
            ]);
        }

        $this->ensureCashuStore($store);

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $settings = $this->cashuService->getSettings($store->btcpay_store_id, $userApiKey);

        return response()->json([
            'data' => $this->formatCashuSettingsForApi($settings),
        ]);
    }

    public function updateSettings(Request $request, Store $store): JsonResponse
    {
        $previousWalletType = $store->wallet_type ?? null;
        $switchingFromLightning = $this->isLightningWalletTypeForCashuSwitch($previousWalletType)
            && $previousWalletType !== null;

        $allowed = $previousWalletType === null
            || $previousWalletType === 'cashu'
            || $switchingFromLightning;

        if (! $allowed) {
            abort(422, 'Cashu is not the wallet type for this store.');
        }

        $request->validate([
            'mint_url' => ['required', 'string', 'url', 'starts_with:https://'],
            'lightning_address' => ['required', 'string', 'regex:/^[^@\s]+@[^@\s]*\.[^@\s]{2,}$/'],
            'enabled' => ['sometimes', 'boolean'],
            'unit' => ['sometimes', 'nullable', Rule::in(['sat', 'usd'])],
            'trusted_mint_urls' => ['sometimes', 'nullable', 'string'],
            'max_melt_fee_reserve_sats' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_melt_fee_reserve_percent_of_minted' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
        ]);

        $changeGuard = app(WalletChangeConfirmationGuard::class);
        if ($switchingFromLightning) {
            // Lightning -> Cashu drops the connected wallet: email-code gate applies.
            $changeGuard->assert($store, $request->user());
        }

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $payload = $this->buildCashuMeltSettingsPayloadFromRequest($request);

        try {
            $updated = DB::transaction(function () use ($store, $payload, $userApiKey, $switchingFromLightning) {
                $store->markAsPureCashu($switchingFromLightning);

                return $this->cashuService->saveSettings($store->btcpay_store_id, $payload, $userApiKey);
            });
            if ($switchingFromLightning) {
                $changeGuard->consumeGrant($store, $request->user());
            }
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 400
                && str_contains($e->getMessage(), 'Request body must be a JSON object')) {
                throw ValidationException::withMessages([
                    'cashu' => [
                        'CashuMelt plugin on BTCPay Server must be updated to version 1.2.0.5 or later '
                        .'(BTCPay Server → Settings → Plugins). Saving Cashu settings via API is broken on older 1.1.x–1.2.0.4 builds.',
                    ],
                ]);
            }

            throw $e;
        }

        $store->refresh();

        $enabledAfterSave = (bool) ($updated['enabled'] ?? true);
        if ($enabledAfterSave) {
            try {
                $this->lightningService->tryRemoveLightningCheckoutPaymentMethods(
                    $store->btcpay_store_id,
                    $userApiKey
                );
            } catch (\Throwable $e) {
                Log::error('Could not remove Lightning payment methods at BTCPay after Cashu save', [
                    'store_id' => $store->id,
                    'btcpay_store_id' => $store->btcpay_store_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        StoreChecklistService::ensureChecklistInitialized($store);

        $data = $this->formatCashuSettingsForApi($updated);
        if (($data['mint_url'] ?? null) === null) {
            $data['mint_url'] = $request->mint_url;
        }
        if (($data['lightning_address'] ?? null) === null) {
            $data['lightning_address'] = $request->lightning_address;
        }

        return response()->json(['data' => $data]);
    }

    public function listPayments(Request $request, Store $store): JsonResponse
    {
        $this->ensureCashuStore($store);

        $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'settlementState' => ['sometimes', 'string', Rule::in(['SETTLED', 'PENDING', 'FAILED', 'MELT_COMPLETE'])],
        ]);

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $limit = (int) $request->input('limit', 50);
        $offset = (int) $request->input('offset', 0);

        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        $settlementState = $request->input('settlementState');
        if ($settlementState) {
            $params['settlementState'] = $settlementState;
        }

        $raw = $this->cashuService->listPayments($store->btcpay_store_id, $userApiKey, $params);

        $items = collect($raw['items'] ?? [])->map(fn (array $item) => $this->formatCashuPaymentItem($item))->values();

        return response()->json([
            'data' => [
                'total' => (int) ($raw['total'] ?? 0),
                'offset' => (int) ($raw['offset'] ?? $offset),
                'limit' => (int) ($raw['limit'] ?? $limit),
                'items' => $items,
            ],
        ]);
    }

    public function retryPayment(Request $request, Store $store, string $quoteId): JsonResponse
    {
        $this->ensureCashuStore($store);

        $request->validate([
            'quoteId' => ['sometimes', 'string'],
        ]);

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $result = $this->cashuService->retryPayment($store->btcpay_store_id, $quoteId, $userApiKey);

        return response()->json([
            'data' => [
                'settled' => $result['settled'] ?? null,
                'error' => $result['error'] ?? null,
                'retry_after_seconds' => $result['retryAfterSeconds'] ?? $result['retry_after_seconds'] ?? null,
            ],
        ]);
    }

    protected function ensureCashuStore(Store $store): void
    {
        if (($store->wallet_type ?? null) === 'cashu' || $store->cashu_fallback_enabled) {
            return;
        }

        abort(404, 'Cashu wallet is not configured for this store.');
    }

    /**
     * Lightning wallet types that may load empty Cashu defaults or switch to Cashu via updateSettings.
     */
    private function isLightningWalletTypeForCashuSwitch(?string $walletType): bool
    {
        return $walletType === null
            || in_array($walletType, ['blink', 'blitz', 'flash', 'lnaddress', 'aqua_boltz', 'nwc'], true);
    }

    /**
     * Map BTCPay Cashu plugin payment row to API shape.
     *
     * The plugin sometimes leaves settlementState as PENDING even after settlement
     * finished; settledAt is the reliable completion timestamp from the plugin.
     */
    private function formatCashuPaymentItem(array $item): array
    {
        $reportedRaw = $item['settlementState'] ?? $item['settlement_state'] ?? null;
        $reported = is_string($reportedRaw) ? strtoupper(trim($reportedRaw)) : null;
        $settledAt = $item['settledAt'] ?? $item['settled_at'] ?? null;
        $hasSettledAt = $settledAt !== null && $settledAt !== '';

        $settlementState = $reported;
        if ($hasSettledAt && $reported === 'PENDING') {
            $settlementState = 'SETTLED';
        }
        if ($hasSettledAt && ($reported === null || $reported === '')) {
            $settlementState = 'SETTLED';
        }

        $pollUrl = $item['mintQuotePollUrl'] ?? $item['mint_quote_poll_url'] ?? null;

        return [
            'quote_id' => $item['quoteId'] ?? $item['quote_id'] ?? null,
            'invoice_id' => $item['invoiceId'] ?? $item['invoice_id'] ?? null,
            'amount_sats' => $item['amountSats'] ?? $item['amount_sats'] ?? null,
            'state' => $item['state'] ?? null,
            'settlement_state' => $settlementState,
            'settlement_error' => $item['settlementError'] ?? $item['settlement_error'] ?? null,
            'settlement_reference' => $item['settlementReference'] ?? $item['settlement_reference'] ?? null,
            'created_at' => $item['createdAt'] ?? $item['created_at'] ?? null,
            'paid_at' => $item['paidAt'] ?? $item['paid_at'] ?? null,
            'settled_at' => $settledAt,
            'mint_quote_poll_url' => is_string($pollUrl) && $pollUrl !== '' ? $pollUrl : null,
            'retry_count' => (int) ($item['retryCount'] ?? $item['retry_count'] ?? 0),
            'needs_manual_review' => (bool) ($item['needsManualReview'] ?? $item['needs_manual_review'] ?? false),
            'failure_reason_code' => $item['failureReasonCode'] ?? $item['failure_reason_code'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCashuSettingsPayload(): array
    {
        return [
            'mint_url' => null,
            'lightning_address' => null,
            'enabled' => true,
            'unit' => null,
            'trusted_mint_urls' => null,
            'max_melt_fee_reserve_sats' => null,
            'max_melt_fee_reserve_percent_of_minted' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings  BTCPay plugin response (camelCase)
     * @return array<string, mixed>
     */
    private function formatCashuSettingsForApi(array $settings): array
    {
        return [
            'mint_url' => $settings['mintUrl'] ?? null,
            'lightning_address' => $settings['lightningAddress'] ?? null,
            'enabled' => $settings['enabled'] ?? true,
            'unit' => $settings['unit'] ?? null,
            'trusted_mint_urls' => $settings['trustedMintUrls'] ?? null,
            'max_melt_fee_reserve_sats' => $settings['maxMeltFeeReserveSats'] ?? null,
            'max_melt_fee_reserve_percent_of_minted' => $settings['maxMeltFeeReservePercentOfMinted'] ?? null,
        ];
    }

    /**
     * Build BTCPay PUT body: only include optional keys when present on the request (merge semantics).
     */
    private function buildCashuMeltSettingsPayloadFromRequest(Request $request): array
    {
        $payload = [
            'mintUrl' => $request->string('mint_url')->toString(),
            'lightningAddress' => $request->string('lightning_address')->toString(),
        ];

        if ($request->exists('enabled')) {
            $payload['enabled'] = $request->boolean('enabled');
        }

        if ($request->exists('unit')) {
            $payload['unit'] = $request->input('unit');
        }

        if ($request->exists('trusted_mint_urls')) {
            $raw = $request->input('trusted_mint_urls');
            if ($raw === null) {
                $payload['trustedMintUrls'] = null;
            } else {
                $s = is_string($raw) ? trim($raw) : '';
                $payload['trustedMintUrls'] = $s === '' ? null : $s;
            }
        }

        if ($request->exists('max_melt_fee_reserve_sats')) {
            $v = $request->input('max_melt_fee_reserve_sats');
            $payload['maxMeltFeeReserveSats'] = ($v === null || $v === '')
                ? null
                : (int) $v;
        }

        if ($request->exists('max_melt_fee_reserve_percent_of_minted')) {
            $v = $request->input('max_melt_fee_reserve_percent_of_minted');
            $payload['maxMeltFeeReservePercentOfMinted'] = ($v === null || $v === '')
                ? null
                : (float) $v;
        }

        return $payload;
    }
}
