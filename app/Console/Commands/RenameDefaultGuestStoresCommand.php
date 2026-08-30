<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\StoreService;
use App\Services\GuestProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off backfill: guest stores provisioned before the unique-name change are all
 * called "My Store". Rename them to "My Store - XXXXXXXX" (see
 * GuestProvisioningService::guestStoreName) both in BTCPay and locally.
 */
class RenameDefaultGuestStoresCommand extends Command
{
    protected $signature = 'guests:rename-default-stores
                            {--dry-run : List stores that would be renamed without changing anything}
                            {--limit= : Stop after this many renames}';

    protected $description = 'Rename legacy guest stores still called "My Store" to the unique "My Store - XXXXXXXX" form (BTCPay + local).';

    public function handle(GuestProvisioningService $provisioning, StoreService $storeService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null && $limitOption !== '' ? max(1, (int) $limitOption) : null;

        if ($dryRun) {
            $this->info('Dry run: nothing will be renamed.');
        }

        $query = Store::query()
            ->with('user')
            ->where('name', 'My Store')
            ->whereHas('user', fn ($q) => $q->where('is_guest', true)->where('email', 'like', 'guest+%'))
            ->orderBy('created_at');

        $considered = 0;
        $renamed = 0;
        $failed = 0;
        $rows = [];

        foreach ($query->lazyById(100) as $store) {
            if ($limit !== null && $renamed >= $limit) {
                break;
            }
            $considered++;

            /** @var User $user */
            $user = $store->user;
            $newName = $provisioning->guestStoreName((string) $user->email);
            $btcpayStoreId = (string) $store->btcpay_store_id;

            if ($dryRun) {
                $rows[] = [$store->id, $user->email, $newName, 'would rename'];
                $renamed++;

                continue;
            }

            try {
                $apiKey = $user->btcpay_api_key ?: null;
                if ($btcpayStoreId !== '') {
                    $storeService->updateStore($btcpayStoreId, ['name' => $newName], $apiKey);
                }
                $store->update(['name' => $newName]);
                $rows[] = [$store->id, $user->email, $newName, 'renamed'];
                $renamed++;
            } catch (\Throwable $e) {
                $failed++;
                $rows[] = [$store->id, $user->email, $newName, 'FAILED: '.$e->getMessage()];
                Log::warning('guests:rename-default-stores failed for store', [
                    'store_id' => $store->id,
                    'btcpay_store_id' => $btcpayStoreId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($rows !== []) {
            $this->table(['Store', 'Guest email', 'New name', 'Result'], $rows);
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Stores considered', $considered],
                [$dryRun ? 'Would rename' : 'Renamed', $renamed],
                ['Failed', $failed],
            ],
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
