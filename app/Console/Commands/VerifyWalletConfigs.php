<?php

namespace App\Console\Commands;

use App\Models\WalletConnection;
use App\Services\WalletSecurity\WalletConfigIntegrityService;
use Illuminate\Console\Command;

class VerifyWalletConfigs extends Command
{
    protected $signature = 'wallet-connections:verify-config
                            {--all : Verify every connected wallet, ignoring the recheck window}
                            {--store= : Verify a single store (local UUID)}';

    protected $description = 'Compare each connected wallet\'s BTCPay payment-method config with the fingerprint Satflux recorded; flag drift';

    public function handle(WalletConfigIntegrityService $integrity): int
    {
        $query = WalletConnection::query()->where('status', 'connected');
        if ($store = $this->option('store')) {
            $query->where('store_id', $store);
        } elseif (! $this->option('all')) {
            $query->where(function ($q) {
                $q->whereNull('config_verified_at')
                    ->orWhere('config_verified_at', '<', now()->subMinutes(WalletConfigIntegrityService::RECHECK_MINUTES));
            });
        }

        $counts = ['ok' => 0, 'drift' => 0, 'baselined' => 0, 'skipped' => 0, 'error' => 0];
        $query->orderBy('id')->chunkById(50, function ($connections) use ($integrity, &$counts) {
            foreach ($connections as $connection) {
                $result = $integrity->verify($connection);
                $counts[$result['status']]++;
                if ($result['status'] === 'drift') {
                    $this->warn("DRIFT store {$connection->store_id}: ".json_encode($result['diff']));
                }
            }
        });

        $this->info(sprintf(
            'Verified wallet configs - ok: %d, drift: %d, baselined: %d, skipped: %d, error: %d',
            $counts['ok'], $counts['drift'], $counts['baselined'], $counts['skipped'], $counts['error'],
        ));

        return $counts['drift'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
