<?php

use App\Jobs\ProcessMonthlyExports;
use Illuminate\Support\Facades\Schedule;

// Check subscription statuses daily at 2 AM
Schedule::command('subscriptions:check-statuses')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Backup: handled via cron on host, not by Laravel schedule.
// Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping()->runInBackground()...

// Wallet connection auto-config disabled: BTCPay does not support custom Lightning connection strings via API; configure via cron/manual if needed.
// Schedule::command('wallet-connections:attempt-config', ['--limit' => 10])
//     ->everyFifteenMinutes()
//     ->withoutOverlapping(10)
//     ->runInBackground();

// Automatic monthly CSV exports for PRO users (1st of month at 03:00 for previous month)
Schedule::job(new ProcessMonthlyExports)->monthlyOn(1, '03:00')->withoutOverlapping();

// Inactive guest purge (opt-in via GUEST_PURGE_ENABLED in .env)
Schedule::command('guests:purge-inactive')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

// System health (P1 phase 8): snapshot + e-mail alerting on failures/recovery
Schedule::command('system:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Failed jobs monitoring - alert if more than 5 failures in the last hour
Schedule::command('jobs:monitor-failed --hours=1 --threshold=5')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('invoicing:process-recurring')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Data minimization retention (opt-in via DATA_RETENTION_ENABLED)
Schedule::command('data:retention-run')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// Settlement ledger reconciliation: re-sync recent settled invoices from BTCPay,
// flag stuck payments and settlement-asset mismatches (idempotent upserts).
Schedule::command('boltz:reconcile-settlements')
    ->dailyAt('04:45')
    ->withoutOverlapping()
    ->runInBackground();

// MaxMind GeoLite2 refresh for compliance geo-blocking (monthly)
Schedule::command('compliance:update-geoip')
    ->monthlyOn(1, '04:15')
    ->withoutOverlapping()
    ->runInBackground();

// OFAC SDN + EU consolidated sanctions lists (daily)
Schedule::command('compliance:sync-sanctions-lists')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

// RegWatch legislative monitoring (docs/LEGAL.md): fetch official sources,
// diff snapshots, record detections for human review (opt-in via REGWATCH_ENABLED).
if (config('regwatch.enabled')) {
    Schedule::command('regwatch:monitor')
        ->dailyAt('05:15')
        ->withoutOverlapping()
        ->runInBackground();
}

if (config('efaktura.enabled')) {
    Schedule::command('efaktura:poll-inbound')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->runInBackground();

    Schedule::command('efaktura:sync-compliance-status')
        ->everyThirtyMinutes()
        ->withoutOverlapping()
        ->runInBackground();
}
