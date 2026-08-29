<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Plans seeded before newer features existed never got them: the seeder
     * is only run on fresh installs, so a production `pro` row can lack
     * `business_invoicing` - the UI then shows a Pro (trial) badge while every
     * invoicing gate treats the user as Free. Merge the canonical feature
     * keys into existing rows (union - keeps any extra keys, and deliberately
     * touches NO other column so production-tuned prices/limits survive).
     */
    private const CANONICAL_FEATURES = [
        'pro' => [
            'manual_csv_exports',
            'automatic_csv_exports',
            'advanced_statistics',
            'basic_payment_overview',
            'offline_payment_methods',
            'priority_support',
            'stripe',
            'business_invoicing',
        ],
        'enterprise' => [
            'manual_csv_exports',
            'automatic_csv_exports',
            'advanced_statistics',
            'basic_payment_overview',
            'offline_payment_methods',
            'per_store_user_management',
            'priority_support',
            'stripe',
            'business_invoicing',
            'expense_isdoc_extract_unlimited',
        ],
    ];

    public function up(): void
    {
        foreach (self::CANONICAL_FEATURES as $code => $canonical) {
            $row = DB::table('subscription_plans')->where('code', $code)->first();
            if (! $row) {
                continue;
            }

            $existing = json_decode((string) $row->features, true);
            $existing = is_array($existing) ? $existing : [];

            $merged = array_values(array_unique(array_merge($existing, $canonical)));
            if ($merged === $existing) {
                continue;
            }

            DB::table('subscription_plans')
                ->where('id', $row->id)
                ->update(['features' => json_encode($merged)]);
        }
    }

    public function down(): void
    {
        // Feature backfill - removing keys could re-break entitlements.
    }
};
