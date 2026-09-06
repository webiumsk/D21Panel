<?php

namespace App\Services\WalletSecurity;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\BtcPay\StoreService;
use Illuminate\Support\Facades\Log;

/**
 * Detects wallet configuration changes made outside Satflux.
 *
 * When Satflux (or its config bot) connects a wallet, the store's BTCPay
 * payment-method configs are hashed per method and stored on the row as the
 * expected fingerprint. verify() re-reads the live config (includeConfig=true,
 * merchant key) and compares: any difference - a swapped Lightning connection
 * string, an on-chain descriptor, a method enabled or disabled - is drift.
 * Merchants have no BTCPay UI access, so drift is never a legitimate edit.
 *
 * Detection only: nothing is reverted automatically (phase 1).
 */
class WalletConfigIntegrityService
{
    /** A row verified within this window is skipped by the scheduler and webhook triggers. */
    public const RECHECK_MINUTES = 5;

    public function __construct(
        protected StoreService $stores,
        protected WalletSecurityNotifier $notifier,
    ) {}

    /**
     * Payment methods that never decide where funds go (LNURL only shapes how
     * Lightning invoices are presented and is toggled by Satflux's own store
     * settings) - excluded so they cannot raise false drift.
     */
    public static function isIgnoredMethod(string $paymentMethodId): bool
    {
        return str_ends_with(strtoupper($paymentMethodId), '-LNURL');
    }

    /**
     * Live per-method fingerprint of the store's BTCPay payment methods plus
     * the canonical configs behind it.
     *
     * @return array{fingerprint: array<string, string>, configs: array<string, mixed>}
     *
     * @throws \Throwable when BTCPay or the merchant key is unavailable
     */
    public function snapshot(Store $store): array
    {
        $owner = $store->user;
        if (! $owner instanceof User) {
            throw new \RuntimeException('Store has no owner');
        }
        $methods = $this->stores->getStorePaymentMethods(
            (string) $store->btcpay_store_id,
            $owner->getBtcPayApiKeyOrFail(),
            includeConfig: true,
        );

        $fingerprint = [];
        $configs = [];
        foreach ($methods as $method) {
            $id = isset($method['paymentMethodId']) ? (string) $method['paymentMethodId'] : '';
            if ($id === '' || self::isIgnoredMethod($id)) {
                continue;
            }
            $canonical = [
                'enabled' => (bool) ($method['enabled'] ?? false),
                'config' => self::canonical($method['config'] ?? null),
            ];
            $configs[$id] = $canonical;
            $fingerprint[$id] = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        ksort($fingerprint);
        ksort($configs);

        return ['fingerprint' => $fingerprint, 'configs' => $configs];
    }

    /**
     * Record the current BTCPay config as the expected one. Called after every
     * Satflux-driven change; best-effort (a failed read leaves the previous
     * fingerprint in place and the next verify retries).
     */
    public function baseline(WalletConnection $connection, ?User $by = null, string $reason = 'connected'): bool
    {
        $store = $connection->store;
        if (! $store instanceof Store) {
            return false;
        }

        try {
            $snapshot = $this->snapshot($store);
        } catch (\Throwable $e) {
            Log::warning('Wallet config baseline skipped', [
                'connection_id' => $connection->id,
                'store_id' => $store->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $hadDrift = $connection->drift_detected_at !== null;
        $connection->forceFill([
            'config_fingerprint' => $snapshot['fingerprint'],
            'config_snapshot' => $snapshot['configs'],
            'config_verified_at' => now(),
            'drift_detected_at' => null,
            'drift_details' => null,
        ])->save();

        AuditLog::log('wallet_connection.config_baselined', 'wallet_connection', $connection->id, [
            'store_id' => $store->id,
            'reason' => $reason,
            'methods' => array_keys($snapshot['fingerprint']),
            'cleared_drift' => $hadDrift,
        ], $by?->id);

        return true;
    }

    /**
     * Compare the live BTCPay config with the expected fingerprint.
     *
     * @return array{status: 'ok'|'drift'|'baselined'|'skipped'|'error', diff?: array{changed: string[], added: string[], removed: string[], details?: array<string, array{expected: string|null, actual: string|null}>}}
     */
    public function verify(WalletConnection $connection): array
    {
        if ($connection->status !== 'connected') {
            return ['status' => 'skipped'];
        }
        $store = $connection->store;
        if (! $store instanceof Store) {
            return ['status' => 'skipped'];
        }

        if (empty($connection->config_fingerprint)) {
            // Connected before monitoring existed: the current config is the
            // best baseline we have - noted in the audit trail as "late".
            return ['status' => $this->baseline($connection, null, 'late') ? 'baselined' : 'error'];
        }

        try {
            $snapshot = $this->snapshot($store);
        } catch (\Throwable $e) {
            Log::warning('Wallet config verification failed', [
                'connection_id' => $connection->id,
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error'];
        }

        $diff = self::diff($connection->config_fingerprint ?? [], $snapshot['fingerprint']);
        $connection->config_verified_at = now();

        if ($diff['changed'] === [] && $diff['added'] === [] && $diff['removed'] === []) {
            if ($connection->drift_detected_at !== null) {
                // Someone put the original config back: close the incident.
                $connection->drift_detected_at = null;
                $connection->drift_details = null;
                $connection->save();
                AuditLog::log('wallet_connection.drift_resolved', 'wallet_connection', $connection->id, [
                    'store_id' => $store->id,
                ], null);
                $this->notifier->driftResolved($store, $connection);

                return ['status' => 'ok', 'diff' => $diff];
            }
            $connection->save();

            return ['status' => 'ok', 'diff' => $diff];
        }

        $diff['details'] = self::describe($connection->config_snapshot ?? [], $snapshot['configs'], $diff);
        $firstDetection = $connection->drift_detected_at === null;
        if ($firstDetection) {
            $connection->drift_detected_at = now();
        }
        $connection->drift_details = $diff;
        $connection->save();

        if ($firstDetection) {
            AuditLog::log('wallet_connection.drift_detected', 'wallet_connection', $connection->id, [
                'store_id' => $store->id,
                'diff' => $diff,
            ], null);
            Log::error('Wallet config drift detected', [
                'connection_id' => $connection->id,
                'store_id' => $store->id,
                'diff' => $diff,
            ]);
            $this->notifier->driftDetected($store, $connection, $diff);
        }

        return ['status' => 'drift', 'diff' => $diff];
    }

    /**
     * Masked expected/actual per differing method - safe to store and show.
     *
     * @param  array<string, mixed>  $expectedConfigs
     * @param  array<string, mixed>  $actualConfigs
     * @param  array{changed: string[], added: string[], removed: string[]}  $diff
     * @return array<string, array{expected: string|null, actual: string|null}>
     */
    public static function describe(array $expectedConfigs, array $actualConfigs, array $diff): array
    {
        $details = [];
        foreach (array_merge($diff['changed'], $diff['added'], $diff['removed']) as $id) {
            $details[$id] = [
                'expected' => array_key_exists($id, $expectedConfigs) ? self::summarize($expectedConfigs[$id]) : null,
                'actual' => array_key_exists($id, $actualConfigs) ? self::summarize($actualConfigs[$id]) : null,
            ];
        }

        return $details;
    }

    /** One-line, credential-masked rendering of a canonical method config. */
    public static function summarize(mixed $canonical): string
    {
        if (! is_array($canonical)) {
            return WalletConnection::maskSecret((string) $canonical);
        }
        $enabled = ($canonical['enabled'] ?? true) ? 'enabled' : 'disabled';
        $config = $canonical['config'] ?? null;
        if (! is_array($config)) {
            return $enabled;
        }
        $parts = [];
        foreach ($config as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_string($value)) {
                $parts[] = $key.'='.(strlen($value) > 24 || str_contains($value, '=') ? WalletConnection::maskSecret($value) : $value);
            } elseif (is_scalar($value)) {
                $parts[] = $key.'='.var_export($value, true);
            } else {
                $parts[] = $key.'='.WalletConnection::maskSecret(json_encode($value, JSON_UNESCAPED_SLASHES) ?: '');
            }
        }

        return $enabled.($parts ? ' '.implode(' ', $parts) : '');
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $actual
     * @return array{changed: string[], added: string[], removed: string[]}
     */
    public static function diff(array $expected, array $actual): array
    {
        $changed = [];
        foreach ($expected as $id => $hash) {
            if (array_key_exists($id, $actual) && $actual[$id] !== $hash) {
                $changed[] = (string) $id;
            }
        }
        $added = array_map('strval', array_keys(array_diff_key($actual, $expected)));
        $removed = array_map('strval', array_keys(array_diff_key($expected, $actual)));
        sort($changed);
        sort($added);
        sort($removed);

        return ['changed' => $changed, 'added' => $added, 'removed' => $removed];
    }

    /** Recursively key-sorted copy so hashing is independent of BTCPay's field order. */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonical($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
