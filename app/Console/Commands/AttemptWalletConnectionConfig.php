<?php

namespace App\Console\Commands;

use App\Models\WalletConnection;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\LightningService;
use App\Services\WalletConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class AttemptWalletConnectionConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet-connections:attempt-config
                            {--limit=10 : Max number of connections to attempt per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Attempt to configure Lightning for wallet connections with needs_support status (retry via BTCPay API)';

    public function __construct(
        protected LightningService $lightningService,
        protected WalletConnectionService $walletConnectionService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $connections = WalletConnection::where('status', 'needs_support')
            ->with(['store.user'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No wallet connections in needs_support status.');

            return self::SUCCESS;
        }

        $this->info("Attempting to configure {$connections->count()} wallet connection(s) via BTCPay API.");

        $attempted = 0;
        $configured = 0;

        /** @var WalletConnection $connection */
        foreach ($connections as $connection) {
            $store = $connection->store;
            if (! $store || ! $store->btcpay_store_id) {
                Log::warning('AttemptWalletConnectionConfig: store or btcpay_store_id missing', [
                    'connection_id' => $connection->id,
                ]);

                continue;
            }

            $user = $store->user;
            if (! $user) {
                Log::warning('AttemptWalletConnectionConfig: store has no user', [
                    'connection_id' => $connection->id,
                    'store_id' => $store->id,
                ]);

                continue;
            }

            try {
                $apiKey = $user->getBtcPayApiKeyOrFail();
            } catch (\Throwable $e) {
                Log::debug('AttemptWalletConnectionConfig: user has no BTCPay API key', [
                    'connection_id' => $connection->id,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            try {
                $plaintext = Crypt::decryptString($connection->encrypted_secret);
            } catch (\Throwable $e) {
                Log::warning('AttemptWalletConnectionConfig: failed to decrypt secret', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $attempted++;

            try {
                $result = $this->lightningService->connectLightningNode(
                    $store->btcpay_store_id,
                    'BTC',
                    $plaintext,
                    $apiKey
                );

                if ($result['success'] ?? false) {
                    $this->walletConnectionService->markConnected($connection, $user);
                    $configured++;
                    $this->info("  [OK] Connection {$connection->id} (store: {$store->name}) configured.");
                    Log::info('AttemptWalletConnectionConfig: connection configured successfully', [
                        'connection_id' => $connection->id,
                        'store_id' => $store->id,
                    ]);
                } else {
                    Log::debug('AttemptWalletConnectionConfig: BTCPay API did not accept connection', [
                        'connection_id' => $connection->id,
                        'message' => $result['message'] ?? 'unknown',
                    ]);
                }
            } catch (BtcPayException $e) {
                Log::debug('AttemptWalletConnectionConfig: BTCPay API error', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('AttemptWalletConnectionConfig: error', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Attempted: {$attempted}, configured: {$configured}.");

        return self::SUCCESS;
    }
}
