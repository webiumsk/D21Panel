<?php

namespace App\Jobs;

use App\Models\WalletConnection;
use App\Services\WalletSecurity\WalletConfigIntegrityService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Webhook-triggered drift check for one store (invoice activity is the moment
 * a hijacked wallet matters). Skips rows verified within RECHECK_MINUTES so a
 * busy store does not hammer BTCPay; the scheduler covers idle stores.
 */
class VerifyWalletConfig implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(public string $storeId) {}

    public function uniqueId(): string
    {
        return $this->storeId;
    }

    public function handle(WalletConfigIntegrityService $integrity): void
    {
        $connection = WalletConnection::query()
            ->where('store_id', $this->storeId)
            ->where('status', 'connected')
            ->first();
        if (! $connection) {
            return;
        }
        if ($connection->config_verified_at && $connection->config_verified_at->gt(now()->subMinutes(WalletConfigIntegrityService::RECHECK_MINUTES))) {
            return;
        }

        $integrity->verify($connection);
    }
}
