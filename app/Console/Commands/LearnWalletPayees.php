<?php

namespace App\Console\Commands;

use App\Models\WalletConnection;
use App\Services\WalletSecurity\PayeeAttestationService;
use Illuminate\Console\Command;

class LearnWalletPayees extends Command
{
    protected $signature = 'wallet-connections:learn-payees
                            {--all : Relearn every connected wallet, not only those without an allow-list}
                            {--store= : One store (local UUID)}';

    protected $description = 'Learn the Lightning payee node of connected wallets from a canary invoice (payee attestation allow-list)';

    public function handle(PayeeAttestationService $payees): int
    {
        $query = WalletConnection::query()->where('status', 'connected');
        if ($store = $this->option('store')) {
            $query->where('store_id', $store);
        } elseif (! $this->option('all')) {
            $query->whereNull('payee_pubkeys');
        }

        $learned = 0;
        $skipped = 0;
        $query->orderBy('id')->chunkById(25, function ($connections) use ($payees, &$learned, &$skipped) {
            foreach ($connections as $connection) {
                if ($payees->learn($connection, null, 'command')) {
                    $learned++;
                    $this->line("learned store {$connection->store_id}: ".implode(',', $connection->fresh()->payee_pubkeys ?? []));
                } else {
                    $skipped++;
                }
            }
        });

        $this->info("Payee allow-lists - learned: {$learned}, skipped: {$skipped}");

        return self::SUCCESS;
    }
}
