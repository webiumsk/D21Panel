<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected int $freePlanId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
        $this->seedPlans();
    }

    protected function seedPlans(): void
    {
        $free = SubscriptionPlan::create([
            'code' => 'free',
            'name' => 'free',
            'display_name' => 'Free',
            'price_eur' => 0,
            'billing_period' => 'year',
            'max_stores' => 1,
            'max_api_keys' => 1,
            'max_ln_addresses' => 1,
            'features' => [],
            'is_active' => true,
        ]);
        SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['automatic_csv_exports', 'offline_payment_methods'],
            'is_active' => true,
        ]);

        $this->freePlanId = $free->id;
    }

    #[Test]
    public function store_creation_blocked_when_free_user_has_one_store(): void
    {
        Http::fake([
            'https://btcpay.test/*' => Http::response([], 200),
        ]);

        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->freePlanId,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        $response = $this->actingAs($user)->postJson('/api/stores', [
            'name' => 'Second Store',
            'default_currency' => 'EUR',
            'timezone' => 'UTC',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'maximum number of stores'))
            ->assertJsonPath('max_allowed', 1);
    }
}
