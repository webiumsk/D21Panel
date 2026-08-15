<?php

namespace App\Services\Boltz;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BoltzService;
use App\Services\BtcPay\LightningService;
use App\Services\BtcPay\StoreService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Informational Boltz readiness snapshot for one store.
 *
 * This is NOT a validation of any specific payment. The authoritative per-invoice check
 * (amount vs current limits, swap creation) happens inside the BTCPay Boltz plugin when
 * the invoice payment prompt is created. This snapshot only tells the merchant whether
 * the pieces look wired up and what the current orientational limits are.
 *
 * Statuses (in evaluation order):
 * - unsupported:   store wallet type does not settle via Boltz
 * - misconfigured: plugin reachable but setup disabled, or Lightning method not configured
 * - unavailable:   Boltz plugin/daemon unreachable from BTCPay
 * - stale:         everything wired, but the limits snapshot is older than the staleness threshold
 * - degraded:      everything wired, but some informational data could not be loaded
 * - ready:         plugin enabled, Lightning active, fresh limits available
 */
class BoltzStoreReadinessService
{
    /** Machine-readable reason codes (also used as i18n suffixes in the frontend). */
    public const REASON_WALLET_TYPE_NOT_BOLTZ = 'wallet_type_not_boltz';

    public const REASON_MISSING_MERCHANT_API_KEY = 'missing_merchant_api_key';

    public const REASON_PLUGIN_UNREACHABLE = 'plugin_unreachable';

    public const REASON_SETUP_DISABLED = 'setup_disabled';

    public const REASON_LIGHTNING_NOT_CONFIGURED = 'lightning_not_configured';

    public const REASON_LIGHTNING_PROBE_FAILED = 'lightning_probe_failed';

    public const REASON_LIMITS_UNAVAILABLE = 'limits_unavailable';

    public const REASON_LIMITS_STALE = 'limits_stale';

    public const REASON_ONCHAIN_FALLBACK_MISSING = 'onchain_fallback_missing';

    public const REASON_PAYMENT_METHODS_UNAVAILABLE = 'payment_methods_unavailable';

    public function __construct(
        protected BoltzBackendClient $backendClient,
        protected BoltzService $boltzService,
        protected LightningService $lightningService,
        protected StoreService $storeService,
    ) {}

    /**
     * @return array{
     *     status: string, reasons: list<string>, wallet_type: string|null,
     *     plugin: array, lightning_active: bool|null, onchain_fallback: bool|null,
     *     limits: array|null, fees: array|null, checked_at: string
     * }
     */
    public function readiness(Store $store, bool $refresh = false): array
    {
        $cacheKey = "boltz:readiness:{$store->id}";

        if (! $refresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->buildReadiness($store);
        Cache::put($cacheKey, $result, (int) config('services.boltz.readiness_cache_ttl'));

        return $result;
    }

    protected function buildReadiness(Store $store): array
    {
        $reasons = [];
        $plugin = ['reachable' => null, 'enabled' => null, 'wallet_readonly' => null];
        $lightningActive = null;
        $onchainFallback = null;

        if ($store->wallet_type !== 'aqua_boltz') {
            return $this->result('unsupported', [self::REASON_WALLET_TYPE_NOT_BOLTZ], $store, $plugin, null, null, null, null);
        }

        $limitsAndFees = $this->limitsSnapshot($reasons);

        $owner = $store->user;
        $userApiKey = $owner instanceof User ? $owner->btcpay_api_key : null;
        if (! filled($userApiKey)) {
            $reasons[] = self::REASON_MISSING_MERCHANT_API_KEY;

            return $this->result('misconfigured', $reasons, $store, $plugin, null, null, ...$limitsAndFees);
        }

        $setup = $this->boltzService->getSetup((string) $store->btcpay_store_id, $userApiKey);
        $plugin['reachable'] = $setup['reachable'];

        if (! $setup['reachable']) {
            $reasons[] = self::REASON_PLUGIN_UNREACHABLE;
            Log::info('Boltz readiness: plugin unreachable', [
                'store_id' => $store->id,
                'status' => $setup['status'] ?? null,
            ]);

            return $this->result('unavailable', $reasons, $store, $plugin, null, null, ...$limitsAndFees);
        }

        $plugin['enabled'] = (bool) ($setup['enabled'] ?? false);
        $plugin['wallet_readonly'] = isset($setup['wallet']['readonly']) ? (bool) $setup['wallet']['readonly'] : null;

        if (! $plugin['enabled']) {
            $reasons[] = self::REASON_SETUP_DISABLED;

            return $this->result('misconfigured', $reasons, $store, $plugin, null, null, ...$limitsAndFees);
        }

        $lightningActive = $this->probeLightning($store, $userApiKey, $reasons);
        if ($lightningActive === false) {
            return $this->result('misconfigured', $reasons, $store, $plugin, false, null, ...$limitsAndFees);
        }

        $onchainFallback = $this->probeOnchainFallback($store, $userApiKey, $reasons);

        [$limits, $fees] = $limitsAndFees;
        $status = 'ready';
        if ($limits === null) {
            $status = 'degraded';
        } elseif ($limits['is_stale']) {
            $status = 'stale';
        } elseif ($lightningActive === null || $onchainFallback === null) {
            $status = 'degraded';
        }

        return $this->result($status, $reasons, $store, $plugin, $lightningActive, $onchainFallback, $limits, $fees);
    }

    /**
     * @return array{0: array|null, 1: array|null} [limits, fees]
     */
    protected function limitsSnapshot(array &$reasons): array
    {
        $pair = $this->backendClient->getReversePairBtcToLbtc();
        if ($pair === null) {
            $reasons[] = self::REASON_LIMITS_UNAVAILABLE;

            return [null, null];
        }

        $isStale = $this->backendClient->isStale($pair);
        if ($isStale) {
            $reasons[] = self::REASON_LIMITS_STALE;
        }

        $limits = [
            'min' => $pair['min'],
            'max' => $pair['max'],
            'source' => BoltzBackendClient::SOURCE,
            'fetched_at' => $pair['fetched_at'],
            'is_stale' => $isStale,
        ];
        $fees = [
            'percentage' => $pair['fee_percentage'],
            'miner_fees' => $pair['miner_fees'],
        ];

        return [$limits, $fees];
    }

    /** true = LN configured, false = definitively not configured, null = probe failed (unknown). */
    protected function probeLightning(Store $store, string $userApiKey, array &$reasons): ?bool
    {
        try {
            $nodeInfo = $this->lightningService->getLightningNodeInfo(
                (string) $store->btcpay_store_id,
                'BTC',
                $userApiKey
            );

            if ($nodeInfo === []) {
                $reasons[] = self::REASON_LIGHTNING_NOT_CONFIGURED;

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $reasons[] = self::REASON_LIGHTNING_PROBE_FAILED;
            Log::info('Boltz readiness: lightning probe failed', [
                'store_id' => $store->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** true = BTC-CHAIN configured, false = not configured, null = could not determine. */
    protected function probeOnchainFallback(Store $store, string $userApiKey, array &$reasons): ?bool
    {
        try {
            $methods = $this->storeService->getStorePaymentMethods((string) $store->btcpay_store_id, $userApiKey);

            $hasOnchain = collect($methods)->contains(function ($method) {
                $id = is_array($method) ? ($method['paymentMethodId'] ?? $method['paymentMethod'] ?? '') : '';

                return $id === 'BTC-CHAIN' && (bool) ($method['enabled'] ?? false);
            });

            if (! $hasOnchain) {
                $reasons[] = self::REASON_ONCHAIN_FALLBACK_MISSING;
            }

            return $hasOnchain;
        } catch (\Throwable $e) {
            $reasons[] = self::REASON_PAYMENT_METHODS_UNAVAILABLE;
            Log::info('Boltz readiness: payment methods probe failed', [
                'store_id' => $store->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function result(
        string $status,
        array $reasons,
        Store $store,
        array $plugin,
        ?bool $lightningActive,
        ?bool $onchainFallback,
        ?array $limits,
        ?array $fees,
    ): array {
        return [
            'status' => $status,
            'reasons' => array_values(array_unique($reasons)),
            'wallet_type' => $store->wallet_type,
            'plugin' => $plugin,
            'lightning_active' => $lightningActive,
            'onchain_fallback' => $onchainFallback,
            'limits' => $limits,
            'fees' => $fees,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
