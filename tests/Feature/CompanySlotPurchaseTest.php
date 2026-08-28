<?php

namespace Tests\Feature;

use App\Models\CompanySlotPurchase;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\CompanySlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanySlotPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $proUser;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-1',
            'services.btcpay.base_url' => 'https://btcpay.test',
            'services.btcpay.api_key' => 'test-key',
            'invoicing.beta_pro_max_companies' => null,
            'pricing.company_slot_packs' => [
                ['slots' => 1, 'sats' => 30_000],
                ['slots' => 5, 'sats' => 120_000],
                ['slots' => 10, 'sats' => 0], // placeholder - not purchasable
            ],
        ]);

        $proPlan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_companies' => 2,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);
        $this->proUser = User::factory()->create();
        Subscription::create([
            'user_id' => $this->proUser->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
    }

    protected function makeUserOnPlan(array $planAttributes): User
    {
        $plan = SubscriptionPlan::create(array_merge([
            'price_eur' => 0,
            'billing_period' => 'year',
            'max_stores' => 1,
            'max_api_keys' => 1,
            'features' => [],
            'is_active' => true,
        ], $planAttributes));

        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        return $user;
    }

    #[Test]
    public function pro_user_can_start_slot_purchase(): void
    {
        Http::fake([
            'https://btcpay.test/api/v1/stores/sub-store-1/invoices' => Http::response([
                'id' => 'inv-slot-1',
                'checkoutLink' => 'https://btcpay.test/i/slot1',
            ], 201),
        ]);

        $response = $this->actingAs($this->proUser)->postJson(
            '/api/invoicing/company-slots/purchase',
            ['slots' => 5],
        );

        $response->assertOk();
        $response->assertJsonPath('data.checkoutLink', 'https://btcpay.test/i/slot1');
        $response->assertJsonPath('data.slots', 5);
        $response->assertJsonPath('data.price_sats', 120_000);

        $this->assertDatabaseHas('company_slot_purchases', [
            'user_id' => $this->proUser->id,
            'slots' => 5,
            'price_sats' => 120_000,
            'btcpay_invoice_id' => 'inv-slot-1',
            'status' => 'pending',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['currency'] ?? null) === 'SATS'
                && ($body['metadata']['purpose'] ?? null) === 'company_slot_pack';
        });
    }

    #[Test]
    public function unknown_and_placeholder_packs_are_rejected(): void
    {
        $this->actingAs($this->proUser)
            ->postJson('/api/invoicing/company-slots/purchase', ['slots' => 3])
            ->assertUnprocessable();

        // Pack with placeholder price (sats 0) is not purchasable.
        $this->actingAs($this->proUser)
            ->postJson('/api/invoicing/company-slots/purchase', ['slots' => 10])
            ->assertUnprocessable();

        $this->assertDatabaseCount('company_slot_purchases', 0);
    }

    #[Test]
    public function free_user_cannot_purchase_slots(): void
    {
        $freeUser = $this->makeUserOnPlan([
            'code' => 'free',
            'name' => 'free',
            'display_name' => 'Free',
            'max_companies' => 0,
        ]);

        // Free users are stopped by the invoicing plan middleware already.
        $this->actingAs($freeUser)
            ->postJson('/api/invoicing/company-slots/purchase', ['slots' => 1])
            ->assertForbidden();
    }

    #[Test]
    public function enterprise_user_with_unlimited_companies_cannot_purchase_slots(): void
    {
        $enterpriseUser = $this->makeUserOnPlan([
            'code' => 'enterprise',
            'name' => 'enterprise',
            'display_name' => 'Enterprise',
            'max_companies' => null,
            'features' => ['business_invoicing'],
        ]);

        $this->actingAs($enterpriseUser)
            ->postJson('/api/invoicing/company-slots/purchase', ['slots' => 1])
            ->assertUnprocessable();
    }

    #[Test]
    public function guest_is_unauthorized(): void
    {
        $this->postJson('/api/invoicing/company-slots/purchase', ['slots' => 1])
            ->assertUnauthorized();
    }

    #[Test]
    public function fulfillment_is_idempotent(): void
    {
        CompanySlotPurchase::create([
            'user_id' => $this->proUser->id,
            'slots' => 5,
            'price_sats' => 120_000,
            'btcpay_invoice_id' => 'inv-slot-2',
            'status' => CompanySlotPurchase::STATUS_PENDING,
        ]);

        $service = app(CompanySlotService::class);
        $this->assertTrue($service->fulfillPaidInvoice('inv-slot-2', (string) $this->proUser->id));
        $this->assertFalse($service->fulfillPaidInvoice('inv-slot-2', (string) $this->proUser->id));

        $this->assertSame(5, $service->paidSlotCount($this->proUser));
    }

    #[Test]
    public function fulfillment_ignores_user_mismatch(): void
    {
        CompanySlotPurchase::create([
            'user_id' => $this->proUser->id,
            'slots' => 1,
            'price_sats' => 30_000,
            'btcpay_invoice_id' => 'inv-slot-3',
            'status' => CompanySlotPurchase::STATUS_PENDING,
        ]);

        $service = app(CompanySlotService::class);
        $this->assertFalse($service->fulfillPaidInvoice('inv-slot-3', 'user-id-of-someone-else'));
        $this->assertSame(0, $service->paidSlotCount($this->proUser));
    }

    #[Test]
    public function fulfillment_busts_the_limits_cache(): void
    {
        CompanySlotPurchase::create([
            'user_id' => $this->proUser->id,
            'slots' => 1,
            'price_sats' => 30_000,
            'btcpay_invoice_id' => 'inv-slot-4',
            'status' => CompanySlotPurchase::STATUS_PENDING,
        ]);

        Cache::put('user_limits_'.$this->proUser->id, ['stale' => true], 60);

        app(CompanySlotService::class)->fulfillPaidInvoice('inv-slot-4');

        $this->assertFalse(Cache::has('user_limits_'.$this->proUser->id));
    }
}
