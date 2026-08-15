<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\User;
use App\Notifications\BlinkWalletMigrationNotification;
use App\Services\BlinkMigrationAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class NotifyBlinkMigrationCommand extends Command
{
    protected $signature = 'wallet:notify-blink-migration
                            {--dry-run : List recipients without sending email}';

    protected $description = 'Send one-shot Blink wallet migration emails (deduped per user, skips dismissed stores and stores already on the Lightning address format)';

    public function __construct(
        protected BlinkMigrationAlertService $alertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $blinkStores = Store::query()
            ->where('wallet_type', 'blink')
            ->whereNull('blink_alert_dismissed_at')
            ->with(['user', 'walletConnection'])
            ->orderBy('user_id')
            ->get()
            ->filter(fn (Store $store) => $this->alertService->usesLegacyBlinkFormat($store))
            ->values();

        if ($blinkStores->isEmpty()) {
            $this->info('No Blink stores eligible for migration notification.');

            return self::SUCCESS;
        }

        /** @var Collection<int, Collection<int, Store>> $byUser */
        $byUser = $blinkStores->groupBy('user_id');

        $sent = 0;

        foreach ($byUser as $userId => $stores) {
            /** @var Store $first */
            $first = $stores->first();
            $user = $first->user;

            if (! $user instanceof User) {
                $this->warn("Skipping user_id={$userId}: user not found.");

                continue;
            }

            $storeNames = $stores->pluck('name')->implode(', ');
            $line = "User {$user->email} ({$stores->count()} store(s)): {$storeNames}";

            if ($dryRun) {
                $this->line("[dry-run] {$line}");

                continue;
            }

            $user->notify(new BlinkWalletMigrationNotification($user, $stores));
            $this->info("Sent: {$line}");
            $sent++;
        }

        if ($dryRun) {
            $this->info("Dry run complete. {$byUser->count()} user(s) would receive email.");
        } else {
            $this->info("Sent {$sent} email(s) to {$byUser->count()} user(s).");
        }

        return self::SUCCESS;
    }
}
