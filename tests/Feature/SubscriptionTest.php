<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use App\Services\SubscriptionCheckoutRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.btcpay.base_url' => 'https://btcpay.example.test']);
        $this->app->forgetInstance(BtcPayClient::class);
    }

    /** BTCPay fake: success for offering, plan, and plan-checkout. Use in tests that expect 200. */
    protected function fakeBtcPayCheckoutSuccess(int $trialDays = 30): void
    {
        Http::fake(function ($request) use ($trialDays) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/stores/') && str_contains($url, '/offerings/') && str_contains($url, '/plans/')) {
                return Http::response([
                    'id' => 'plan_pro_test',
                    'name' => 'Pro Plan',
                    'trialDays' => $trialDays,
                ]);
            }
            if (str_contains($url, '/api/v1/stores/') && str_contains($url, '/offerings/')) {
                return Http::response(['id' => 'offering_test', 'name' => 'Test Offering']);
            }
            if (str_contains($url, '/api/v1/plan-checkout') && $request->method() === 'POST') {
                return Http::response([
                    'id' => 'checkout_test123',
                    'url' => 'https://btcpay.example.test/plan-checkout/checkout_test123',
                    'expiration' => now()->addHours(24)->timestamp,
                ]);
            }

            return Http::response([], 404);
        });
    }

    #[Test]
    public function authenticated_user_can_create_checkout_with_plan_name()
    {
        $user = User::factory()->create();

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        $this->fakeBtcPayCheckoutSuccess();

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'checkoutUrl',
                'checkoutId',
                'expiresAt',
            ])
            ->assertJson([
                'checkoutUrl' => 'https://btcpay.example.test/plan-checkout/checkout_test123',
                'checkoutId' => 'checkout_test123',
            ]);

        // Verify we never expose BTCPay store ID in response
        $responseData = $response->json();
        $this->assertStringNotContainsString('test_subscription_btcpay_store', json_encode($responseData));
    }

    #[Test]
    public function checkout_validates_plan_and_offering_belong_to_store()
    {
        $user = User::factory()->create();

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        $this->app->forgetInstance(BtcPayClient::class);

        // Mock BTCPay: return 404 for every request so offering validation fails
        Http::fake([
            '*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function unauthenticated_user_cannot_create_checkout_by_default()
    {
        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);
        config(['services.btcpay.allow_guest_subscriptions' => false]);

        $response = $this->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function guest_user_cannot_create_subscription_checkout(): void
    {
        Http::fake();

        $guest = User::factory()->guest()->create();

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        $response = $this->actingAs($guest)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'guest_subscription_blocked');

        Http::assertNothingSent();
    }

    #[Test]
    public function checkout_requires_valid_plan_name()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'invalid_plan',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    #[Test]
    public function checkout_includes_user_email_when_available()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        $this->fakeBtcPayCheckoutSuccess();

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function checkout_requests_btcpay_trial_when_plan_has_trial_days(): void
    {
        $user = User::factory()->create();

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        $this->fakeBtcPayCheckoutSuccess(trialDays: 30);

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains((string) $request->url(), '/api/v1/plan-checkout')) {
                return false;
            }

            $body = $request->data();

            return ($body['isTrial'] ?? false) === true;
        });
    }

    #[Test]
    public function checkout_does_not_request_btcpay_trial_when_plan_has_no_trial_days(): void
    {
        $user = User::factory()->create();

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        $this->fakeBtcPayCheckoutSuccess(trialDays: 0);

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains((string) $request->url(), '/api/v1/plan-checkout')) {
                return false;
            }

            $body = $request->data();

            return ! array_key_exists('isTrial', $body);
        });
    }

    #[Test]
    public function checkout_handles_btcpay_api_errors_gracefully()
    {
        $user = User::factory()->create();

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        $this->app->forgetInstance(BtcPayClient::class);

        // Mock BTCPay: offering and plan succeed, plan-checkout POST returns 422
        Http::fake(function ($request) {
            $url = (string) $request->url();
            $method = $request->method();
            if (($method === 'POST') && (str_contains($url, 'plan-checkout') || str_contains($url, '/api/v1/plan-checkout'))) {
                return Http::response(['message' => 'Invalid request'], 422);
            }
            if (str_contains($url, '/api/v1/stores/') && str_contains($url, '/offerings/') && str_contains($url, '/plans/')) {
                return Http::response(['id' => 'plan_pro_test', 'name' => 'Pro Plan']);
            }
            if (str_contains($url, '/api/v1/stores/') && str_contains($url, '/offerings/')) {
                return Http::response(['id' => 'offering_test', 'name' => 'Test Offering']);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function authenticated_user_can_create_credit_purchase_checkout(): void
    {
        $user = User::factory()->create([
            'email' => 'subscriber@example.com',
        ]);

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);
        config(['services.btcpay.subscription_plans.pro' => 'plan_pro_test']);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/subscribers/subscriber%40example.com') && $request->method() === 'GET') {
                return Http::response([
                    'plan' => ['id' => 'plan_pro_test', 'name' => 'Pro Plan'],
                    'isActive' => true,
                ]);
            }
            if (str_contains($url, '/api/v1/plan-checkout/checkout_credit123') && $request->method() === 'POST') {
                return Http::response([
                    'id' => 'checkout_credit123',
                    'invoiceId' => 'inv_credit123',
                    'redirectUrl' => 'https://btcpay.example.test/i/inv_credit123',
                    'expiration' => now()->addHours(24)->timestamp,
                ]);
            }
            if (preg_match('#/api/v1/plan-checkout$#', (string) parse_url($url, PHP_URL_PATH)) && $request->method() === 'POST') {
                return Http::response([
                    'id' => 'checkout_credit123',
                    'url' => 'https://btcpay.example.test/plan-checkout/checkout_credit123',
                    'expiration' => now()->addHours(24)->timestamp,
                ]);
            }
            if (str_contains($url, '/api/v1/stores/test_subscription_btcpay_store/invoices/inv_credit123') && $request->method() === 'GET') {
                return Http::response([
                    'id' => 'inv_credit123',
                    'checkoutLink' => 'https://btcpay.example.test/i/inv_credit123',
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->postJson('/api/subscriptions/credits', [
            'amount' => 5000,
            'currency' => 'SATS',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'paymentUrl' => 'https://btcpay.example.test/i/inv_credit123',
                'checkoutUrl' => 'https://btcpay.example.test/i/inv_credit123',
                'checkoutId' => 'checkout_credit123',
                'invoiceId' => 'inv_credit123',
                'invoiceUrl' => 'https://btcpay.example.test/i/inv_credit123',
            ]);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! preg_match('#/api/v1/plan-checkout$#', (string) parse_url($request->url(), PHP_URL_PATH))) {
                return false;
            }

            $body = $request->data();

            return ($body['creditPurchase'] ?? null) === '5000'
                && ($body['customerSelector'] ?? null) === 'subscriber@example.com'
                && ($body['planId'] ?? null) === 'plan_pro_test'
                && ($body['isTrial'] ?? null) === false;
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/api/v1/plan-checkout/checkout_credit123');
        });
    }

    #[Test]
    public function credit_purchase_requires_active_subscription(): void
    {
        $user = User::factory()->create([
            'email' => 'nosub@example.com',
        ]);

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);

        Http::fake([
            '*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($user)->postJson('/api/subscriptions/credits', [
            'amount' => 1000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Active subscription required before purchasing credits.');

        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/api/v1/plan-checkout');
        });
    }

    #[Test]
    public function subscription_details_include_trial_billing_and_credit_history(): void
    {
        $user = User::factory()->create([
            'email' => 'trial@example.com',
        ]);

        config(['services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store']);
        config(['services.btcpay.subscription_offering_id' => 'offering_test']);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/subscribers/trial%40example.com') && $request->method() === 'GET' && ! str_contains($url, '/credits/')) {
                return Http::response([
                    'plan' => ['id' => 'plan_pro_test', 'price' => '210000'],
                    'phase' => 'Trial',
                    'trialEnd' => now()->addDays(30)->timestamp,
                    'periodEnd' => now()->addDays(30)->timestamp,
                    'isActive' => true,
                    'autoRenew' => true,
                ]);
            }
            if (str_contains($url, '/credits/SATS/history')) {
                return Http::response([
                    [
                        'createdAt' => now()->subDay()->timestamp,
                        'description' => 'Credit purchase',
                        'credit' => '2000',
                        'balance' => '4000',
                    ],
                ]);
            }
            if (str_contains($url, '/credits/SATS') && $request->method() === 'GET') {
                return Http::response([
                    'currency' => 'SATS',
                    'value' => '4000',
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson('/api/subscriptions/details');

        $response->assertStatus(200)
            ->assertJsonPath('creditBalance', 4000)
            ->assertJsonPath('billing.isTrial', true)
            ->assertJsonPath('billing.planPriceSats', 210000)
            ->assertJsonPath('billing.creditAppliedSats', 4000)
            ->assertJsonPath('billing.nextChargeSats', 206000)
            ->assertJsonPath('creditHistory.0.description', 'Credit purchase')
            ->assertJsonPath('creditHistory.0.amount', 2000)
            ->assertJsonPath('creditHistory.0.balance', 4000);
    }

    protected function seedSubscriptionPlans(): void
    {
        SubscriptionPlan::create([
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

        SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);

        SubscriptionPlan::create([
            'code' => 'enterprise',
            'name' => 'enterprise',
            'display_name' => 'Enterprise',
            'price_eur' => 299,
            'billing_period' => 'year',
            'max_stores' => null,
            'max_api_keys' => null,
            'max_ln_addresses' => null,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);
    }

    #[Test]
    public function subscription_success_requires_authentication(): void
    {
        $response = $this->getJson('/api/subscriptions/success?checkoutPlanId=checkout_attack');

        $response->assertStatus(401);
    }

    #[Test]
    public function subscription_success_does_not_activate_unpaid_checkout(): void
    {
        $this->seedSubscriptionPlans();

        $user = User::factory()->create([
            'role' => 'free',
            'email' => 'attacker@example.com',
        ]);

        config([
            'services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store',
            'services.btcpay.subscription_plans.enterprise' => 'plan_enterprise_test',
        ]);

        app(SubscriptionCheckoutRegistry::class)->bind('checkout_attack123', $user->id, 'enterprise');

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/plan-checkout/checkout_attack123') && $request->method() === 'GET') {
                return Http::response([
                    'id' => 'checkout_attack123',
                    'plan' => ['id' => 'plan_enterprise_test'],
                    'subscriber' => [
                        'isActive' => false,
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson('/api/subscriptions/success?checkoutPlanId=checkout_attack123');

        $response->assertStatus(200)
            ->assertJsonPath('activated', false)
            ->assertJsonPath('plan', 'enterprise');

        $user->refresh();
        $this->assertSame('free', $user->role);
    }

    #[Test]
    public function subscription_success_rejects_checkout_not_bound_to_user(): void
    {
        $this->seedSubscriptionPlans();

        $attacker = User::factory()->create(['role' => 'free']);
        $victim = User::factory()->create(['role' => 'free']);

        config([
            'services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store',
            'services.btcpay.subscription_plans.enterprise' => 'plan_enterprise_test',
        ]);

        app(SubscriptionCheckoutRegistry::class)->bind('checkout_victim', $victim->id, 'enterprise');

        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/api/v1/plan-checkout/checkout_victim')) {
                return Http::response([
                    'id' => 'checkout_victim',
                    'plan' => ['id' => 'plan_enterprise_test'],
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($attacker)->getJson('/api/subscriptions/success?checkoutPlanId=checkout_victim');

        $response->assertStatus(403);

        $attacker->refresh();
        $this->assertSame('free', $attacker->role);
    }

    #[Test]
    public function subscription_success_activates_when_invoice_is_settled(): void
    {
        $this->seedSubscriptionPlans();

        $user = User::factory()->create([
            'role' => 'free',
            'email' => 'buyer@example.com',
        ]);

        config([
            'services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store',
            'services.btcpay.subscription_plans.pro' => 'plan_pro_test',
        ]);

        app(SubscriptionCheckoutRegistry::class)->bind('checkout_paid123', $user->id, 'pro');

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/plan-checkout/checkout_paid123') && $request->method() === 'GET') {
                return Http::response([
                    'id' => 'checkout_paid123',
                    'invoiceId' => 'inv_settled123',
                    'plan' => ['id' => 'plan_pro_test'],
                    'subscriber' => [
                        'customer' => [
                            'id' => 'sub-customer-1',
                            'identities' => ['Email' => 'buyer@example.com'],
                        ],
                    ],
                ]);
            }
            if (str_contains($url, '/invoices/inv_settled123') && $request->method() === 'GET') {
                return Http::response([
                    'id' => 'inv_settled123',
                    'status' => 'Settled',
                    'currency' => 'EUR',
                    'amount' => 99,
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson('/api/subscriptions/success?checkoutPlanId=checkout_paid123');

        $response->assertStatus(200)
            ->assertJsonPath('activated', true)
            ->assertJsonPath('plan', 'pro');

        $user->refresh();
        $this->assertSame('pro', $user->role);
    }

    #[Test]
    public function subscription_success_does_not_activate_when_invoice_is_not_settled(): void
    {
        $this->seedSubscriptionPlans();

        $user = User::factory()->create([
            'role' => 'free',
            'email' => 'buyer@example.com',
        ]);

        config([
            'services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store',
            'services.btcpay.subscription_plans.pro' => 'plan_pro_test',
        ]);

        app(SubscriptionCheckoutRegistry::class)->bind('checkout_pending123', $user->id, 'pro');

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/plan-checkout/checkout_pending123') && $request->method() === 'GET') {
                return Http::response([
                    'id' => 'checkout_pending123',
                    'invoiceId' => 'inv_pending123',
                    'plan' => ['id' => 'plan_pro_test'],
                ]);
            }
            if (str_contains($url, '/invoices/inv_pending123') && $request->method() === 'GET') {
                return Http::response([
                    'id' => 'inv_pending123',
                    'status' => 'Processing',
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson('/api/subscriptions/success?checkoutPlanId=checkout_pending123');

        $response->assertStatus(200)
            ->assertJsonPath('activated', false);

        $user->refresh();
        $this->assertSame('free', $user->role);
    }

    #[Test]
    public function checkout_binds_session_to_authenticated_user(): void
    {
        $user = User::factory()->create();

        config([
            'services.btcpay.subscription_store_id' => 'test_subscription_btcpay_store',
            'services.btcpay.subscription_offering_id' => 'offering_test',
            'services.btcpay.subscription_plans.pro' => 'plan_pro_test',
        ]);

        $this->fakeBtcPayCheckoutSuccess();

        $response = $this->actingAs($user)->postJson('/api/subscriptions/checkout', [
            'plan' => 'pro',
        ]);

        $response->assertStatus(200);

        $binding = app(SubscriptionCheckoutRegistry::class)->resolve('checkout_test123');
        $this->assertNotNull($binding);
        $this->assertSame($user->id, $binding['user_id']);
        $this->assertSame('pro', $binding['plan']);
    }
}
