<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill: paid-plan activation never cleared is_guest, so accounts
     * upgraded to pro/enterprise (via webhook or admin) kept the guest
     * hard-gates and saw the Free/guest UI. Going forward
     * SubscriptionEntitlementService::activateSubscription clears the flag.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('is_guest', true)
            ->whereIn('role', ['pro', 'enterprise'])
            ->update(['is_guest' => false]);
    }

    public function down(): void
    {
        // Irreversible data fix - the previous state was a bug.
    }
};
