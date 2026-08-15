<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\BlinkMigrationAlertService;
use Illuminate\Console\Command;

class BlinkMigrationReportCommand extends Command
{
    protected $signature = 'wallet:blink-migration-report';

    protected $description = 'Report Blink store counts for migration alert status (active, snoozed, dismissed)';

    public function __construct(
        protected BlinkMigrationAlertService $alertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $blinkStores = Store::query()
            ->where('wallet_type', 'blink')
            ->with('walletConnection')
            ->get();

        $active = 0;
        $snoozed = 0;
        $dismissed = 0;
        $migrated = 0;

        foreach ($blinkStores as $store) {
            if (! $this->alertService->usesLegacyBlinkFormat($store)) {
                $migrated++;

                continue;
            }

            if ($store->blink_alert_dismissed_at !== null) {
                $dismissed++;

                continue;
            }

            if ($store->blink_alert_snoozed_until !== null && $store->blink_alert_snoozed_until->isFuture()) {
                $snoozed++;

                continue;
            }

            if ($this->alertService->isActive($store)) {
                $active++;
            }
        }

        $this->info('Blink migration alert report');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Blink stores', $blinkStores->count()],
                ['Migrated (Lightning address)', $migrated],
                ['Active alert', $active],
                ['Snoozed (24h)', $snoozed],
                ['Dismissed (per store)', $dismissed],
            ],
        );

        return self::SUCCESS;
    }
}
