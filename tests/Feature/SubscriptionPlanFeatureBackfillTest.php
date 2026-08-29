<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionPlanFeatureBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stale_pro_plan_gains_missing_features_without_losing_custom_state(): void
    {
        // A production-style stale row: old feature list, prod-tuned price/limits.
        $plan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 123.45,
            'billing_period' => 'year',
            'max_stores' => 7,
            'max_api_keys' => 3,
            'max_companies' => 5,
            'features' => ['manual_csv_exports', 'stripe', 'some_custom_flag'],
            'is_active' => true,
        ]);

        // RefreshDatabase already ran the migration on the empty table - invoke
        // it directly against the stale row.
        $migration = require database_path('migrations/2026_08_30_090000_backfill_subscription_plan_features.php');
        $migration->up();

        $fresh = $plan->fresh();
        $this->assertContains('business_invoicing', $fresh->features);
        $this->assertContains('some_custom_flag', $fresh->features);
        // Non-feature columns untouched.
        $this->assertSame('123.45', (string) $fresh->price_eur);
        $this->assertSame(5, $fresh->max_companies);
        $this->assertSame(7, $fresh->max_stores);
    }
}
