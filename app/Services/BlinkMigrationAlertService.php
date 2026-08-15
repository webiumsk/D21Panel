<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Validation\ValidationException;

class BlinkMigrationAlertService
{
    public function __construct(
        protected WalletConnectionValidator $validator,
    ) {}

    /**
     * @return array{active: bool, snoozed_until: ?string, dismissed_at: ?string}
     */
    public function payload(Store $store): array
    {
        return [
            'active' => $this->isActive($store),
            'snoozed_until' => $store->blink_alert_snoozed_until?->toIso8601String(),
            'dismissed_at' => $store->blink_alert_dismissed_at?->toIso8601String(),
        ];
    }

    public function isActive(Store $store): bool
    {
        if (($store->wallet_type ?? null) !== 'blink') {
            return false;
        }

        if ($store->blink_alert_dismissed_at !== null) {
            return false;
        }

        if ($store->blink_alert_snoozed_until !== null && $store->blink_alert_snoozed_until->isFuture()) {
            return false;
        }

        return $this->usesLegacyBlinkFormat($store);
    }

    /**
     * True when the store's Blink secret is still the custodial API key format (or
     * undeterminable). The non-custodial Lightning address format works in the EU,
     * so those stores get no migration warning.
     */
    public function usesLegacyBlinkFormat(Store $store): bool
    {
        $connection = $store->walletConnection;
        if (! $connection || $connection->type !== 'blink') {
            return true;
        }

        try {
            return $this->validator->blinkVariant($connection->reveal()) !== 'ln_address';
        } catch (\Throwable) {
            return true;
        }
    }

    public function snooze(Store $store): Store
    {
        $this->assertBlinkStore($store);

        $store->update([
            'blink_alert_snoozed_until' => now()->addDay(),
        ]);

        return $store->fresh();
    }

    public function dismiss(Store $store): Store
    {
        $this->assertBlinkStore($store);

        $store->update([
            'blink_alert_dismissed_at' => now(),
        ]);

        return $store->fresh();
    }

    protected function assertBlinkStore(Store $store): void
    {
        if (($store->wallet_type ?? null) !== 'blink') {
            throw ValidationException::withMessages([
                'wallet_type' => ['Blink migration alert applies only to Blink wallet stores.'],
            ]);
        }
    }
}
