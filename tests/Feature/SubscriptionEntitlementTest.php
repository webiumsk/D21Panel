<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionPlan $freePlan;

    protected SubscriptionPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.btcpay.base_url' => 'https://btcpay.example.test']);
        $this->app->forgetInstance(BtcPayClient::class);

        $this->freePlan = SubscriptionPlan::create([
            'code' => 'free',
            'name' => 'free',
            'display_name' => 'Free',
            'price_eur' => 0,
            'billing_period' => 'year',
            'max_stores' => 1,
            'max_api_keys' => 1,
            'max_ln_addresses' => 2,
            'features' => [],
            'is_active' => true,
        ]);

        $this->proPlan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['business_invoicing', 'automatic_csv_exports', 'advanced_statistics'],
            'is_active' => true,
        ]);
    }

    #[Test]
    public function trial_activation_sets_expiry_to_trial_end_not_one_year(): void
    {
        $user = User::factory()->create();
        $trialEndsAt = now()->addDays(30);

        $subscription = app(SubscriptionEntitlementService::class)->activateTrialSubscription(
            $user,
            'pro',
            $trialEndsAt,
            'btcpay-sub-trial',
        );

        $this->assertSame('trial', $subscription->billing_phase);
        $this->assertSame(
            $trialEndsAt->format('Y-m-d H:i:s'),
            $subscription->expires_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            $trialEndsAt->format('Y-m-d H:i:s'),
            $subscription->trial_ends_at->format('Y-m-d H:i:s'),
        );
        $this->assertNull($subscription->grace_ends_at);
        $this->assertNotNull($user->fresh()->trial_consumed_at);
    }

    #[Test]
    public function trial_activation_does_not_downgrade_existing_paid_subscription(): void
    {
        $user = User::factory()->create(['role' => 'pro']);
        $paidExpiresAt = now()->addYear();

        $paidSubscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'active',
            'billing_phase' => Subscription::BILLING_PAID,
            'starts_at' => now()->subMonth(),
            'expires_at' => $paidExpiresAt,
            'grace_ends_at' => $paidExpiresAt->copy()->addDays(30),
            'btcpay_subscription_id' => 'btcpay-paid-sub',
        ]);

        $subscription = app(SubscriptionEntitlementService::class)->activateTrialSubscription(
            $user,
            'pro',
            now()->addDays(30),
            'btcpay-delayed-trial-sub',
        );

        $this->assertSame($paidSubscription->id, $subscription->id);
        $this->assertSame(Subscription::BILLING_PAID, $subscription->billing_phase);
        $this->assertSame($paidExpiresAt->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertNull($subscription->trial_ends_at);
        $this->assertSame('btcpay-paid-sub', $subscription->btcpay_subscription_id);
    }

    #[Test]
    public function active_pro_entitlement_is_not_hidden_by_long_lived_free_subscription(): void
    {
        $user = User::factory()->create(['role' => 'pro']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->freePlan->id,
            'status' => 'active',
            'billing_phase' => Subscription::BILLING_PAID,
            'starts_at' => now(),
            'expires_at' => now()->addYears(100),
        ]);

        $proSubscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'active',
            'billing_phase' => Subscription::BILLING_TRIAL,
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
            'trial_ends_at' => now()->addDays(30),
        ]);

        $this->assertSame($proSubscription->id, $user->fresh()->currentSubscription()->id);
        $this->assertTrue($user->fresh()->hasActiveProEntitlement());
    }

    #[Test]
    public function expired_trial_blocks_invoicing_even_when_role_still_pro(): void
    {
        $user = User::factory()->create([
            'role' => 'pro',
            'trial_consumed_at' => now()->subDays(40),
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'expired',
            'billing_phase' => Subscription::BILLING_EXPIRED,
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->subDays(30),
            'trial_ends_at' => now()->subDays(30),
        ]);

        $this->actingAs($user)
            ->getJson('/api/invoicing/companies')
            ->assertForbidden();
    }

    #[Test]
    public function active_trial_allows_invoicing(): void
    {
        $user = User::factory()->create(['role' => 'pro']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'active',
            'billing_phase' => Subscription::BILLING_TRIAL,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(20),
            'trial_ends_at' => now()->addDays(20),
        ]);

        $this->actingAs($user)
            ->getJson('/api/invoicing/companies')
            ->assertOk();
    }

    #[Test]
    public function paid_subscription_in_grace_period_allows_invoicing(): void
    {
        $user = User::factory()->create(['role' => 'pro']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'grace',
            'billing_phase' => Subscription::BILLING_GRACE,
            'starts_at' => now()->subYear(),
            'expires_at' => now()->subDays(5),
            'grace_ends_at' => now()->addDays(25),
        ]);

        $this->actingAs($user)
            ->getJson('/api/invoicing/companies')
            ->assertOk();
    }

    #[Test]
    public function expired_paid_subscription_blocks_invoicing(): void
    {
        $user = User::factory()->create(['role' => 'pro']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'expired',
            'billing_phase' => Subscription::BILLING_EXPIRED,
            'starts_at' => now()->subYear(),
            'expires_at' => now()->subDays(40),
            'grace_ends_at' => now()->subDays(10),
        ]);

        $this->actingAs($user)
            ->getJson('/api/invoicing/companies')
            ->assertForbidden();
    }

    #[Test]
    public function expired_trial_user_can_still_list_store_invoices(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/stores/store-1/invoices')) {
                return Http::response([
                    ['id' => 'inv-1', 'status' => 'Settled', 'amount' => 10, 'currency' => 'EUR'],
                ], 200);
            }

            return Http::response([], 404);
        });

        $user = User::factory()->create([
            'role' => 'free',
            'btcpay_api_key' => 'merchant-key',
        ]);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->freePlan->id,
            'status' => 'active',
            'billing_phase' => Subscription::BILLING_PAID,
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'expired',
            'billing_phase' => Subscription::BILLING_EXPIRED,
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->subDays(30),
            'trial_ends_at' => now()->subDays(30),
        ]);

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}/invoices")
            ->assertOk()
            ->assertJsonPath('data.0.id', 'inv-1');
    }

    #[Test]
    public function public_business_invoice_pay_link_works_when_owner_subscription_expired(): void
    {
        $user = User::factory()->create(['role' => 'free']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-pay-1']);
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Trial Expired s.r.o.',
            'jurisdiction' => 'eu_sk',
            'default_currency' => 'EUR',
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'expired',
            'billing_phase' => Subscription::BILLING_EXPIRED,
            'starts_at' => now()->subYear(),
            'expires_at' => now()->subDays(40),
            'grace_ends_at' => now()->subDays(10),
        ]);

        $document = BusinessDocument::create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'status' => BusinessDocumentStatus::Issued,
            'payment_btc_enabled' => true,
            'payment_token' => str_repeat('a', 64),
            'btcpay_checkout_link' => 'https://btcpay.example.test/i/public-pay',
            'currency' => 'EUR',
            'total' => 100,
        ]);

        $this->get('/pay/i/'.$document->payment_token)
            ->assertOk();
    }

    #[Test]
    public function checkout_skips_trial_when_user_already_consumed_trial(): void
    {
        $user = User::factory()->create(['trial_consumed_at' => now()->subMonth()]);

        config([
            'services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store',
            'services.btcpay.subscription_offering_id' => 'offering_test',
            'services.btcpay.subscription_plans.pro' => 'plan_pro_test',
        ]);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/stores/') && str_contains($url, '/offerings/') && str_contains($url, '/plans/')) {
                return Http::response([
                    'id' => 'plan_pro_test',
                    'name' => 'Pro Plan',
                    'trialDays' => 30,
                ]);
            }
            if (str_contains($url, '/api/v1/stores/') && str_contains($url, '/offerings/')) {
                return Http::response(['id' => 'offering_test', 'name' => 'Test Offering']);
            }
            if (str_contains($url, '/api/v1/plan-checkout') && $request->method() === 'POST') {
                return Http::response([
                    'id' => 'checkout_no_trial',
                    'url' => 'https://btcpay.example.test/plan-checkout/checkout_no_trial',
                    'expiration' => now()->addHours(24)->timestamp,
                ]);
            }

            return Http::response([], 404);
        });

        $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains((string) $request->url(), '/api/v1/plan-checkout')) {
                return false;
            }

            $body = $request->data();

            return ($body['isTrial'] ?? false) !== true;
        });
    }

    #[Test]
    public function update_all_subscription_statuses_expires_ended_trial_and_downgrades_user(): void
    {
        $user = User::factory()->create(['role' => 'pro']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'active',
            'billing_phase' => Subscription::BILLING_TRIAL,
            'starts_at' => now()->subDays(40),
            'expires_at' => now()->subDay(),
            'trial_ends_at' => now()->subDay(),
        ]);

        app(SubscriptionEntitlementService::class)->updateAllSubscriptionStatuses();

        $user->refresh();
        $this->assertSame('free', $user->role);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'status' => 'expired',
            'billing_phase' => Subscription::BILLING_EXPIRED,
        ]);
    }
}
