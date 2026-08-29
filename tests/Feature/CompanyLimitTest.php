<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySlotPurchase;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyLimitTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionPlan $proPlan;

    protected User $proUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['invoicing.beta_pro_max_companies' => null]);

        SubscriptionPlan::create([
            'code' => 'free',
            'name' => 'free',
            'display_name' => 'Free',
            'price_eur' => 0,
            'billing_period' => 'year',
            'max_stores' => 1,
            'max_api_keys' => 1,
            'max_ln_addresses' => 1,
            'max_companies' => 0,
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
            'max_companies' => 2,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);

        $this->proUser = User::factory()->create();
        Subscription::create([
            'user_id' => $this->proUser->id,
            'plan_id' => $this->proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
    }

    #[Test]
    public function pro_user_cannot_create_third_company(): void
    {
        for ($i = 0; $i < 2; $i++) {
            Company::create([
                'user_id' => $this->proUser->id,
                'legal_name' => "Company {$i}",
                'jurisdiction' => 'eu_sk',
                'country' => 'SK',
            ]);
        }

        $this->actingAs($this->proUser)
            ->postJson('/api/invoicing/companies', [
                'legal_name' => 'Third s.r.o.',
                'jurisdiction' => 'eu_sk',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'company_limit')
            ->assertJsonPath('max_allowed', 2);
    }

    #[Test]
    public function beta_override_allows_five_companies_for_pro(): void
    {
        config(['invoicing.beta_pro_max_companies' => 5]);

        for ($i = 0; $i < 4; $i++) {
            Company::create([
                'user_id' => $this->proUser->id,
                'legal_name' => "Beta Co {$i}",
                'jurisdiction' => 'eu_sk',
                'country' => 'SK',
            ]);
        }

        $this->actingAs($this->proUser)
            ->postJson('/api/invoicing/companies', [
                'legal_name' => 'Fifth s.r.o.',
                'jurisdiction' => 'eu_sk',
            ])
            ->assertCreated();

        $this->actingAs($this->proUser)
            ->postJson('/api/invoicing/companies', [
                'legal_name' => 'Sixth s.r.o.',
                'jurisdiction' => 'eu_sk',
            ])
            ->assertForbidden()
            ->assertJsonPath('max_allowed', 5);
    }

    #[Test]
    public function paid_slot_raises_the_company_limit(): void
    {
        for ($i = 0; $i < 2; $i++) {
            Company::create([
                'user_id' => $this->proUser->id,
                'legal_name' => "Company {$i}",
                'jurisdiction' => 'eu_sk',
                'country' => 'SK',
            ]);
        }

        CompanySlotPurchase::create([
            'user_id' => $this->proUser->id,
            'slots' => 1,
            'price_sats' => 30_000,
            'btcpay_invoice_id' => 'inv-limit-1',
            'status' => CompanySlotPurchase::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($this->proUser)
            ->postJson('/api/invoicing/companies', [
                'legal_name' => 'Third s.r.o.',
                'jurisdiction' => 'eu_sk',
            ])
            ->assertCreated();

        $this->actingAs($this->proUser)
            ->postJson('/api/invoicing/companies', [
                'legal_name' => 'Fourth s.r.o.',
                'jurisdiction' => 'eu_sk',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'company_limit')
            ->assertJsonPath('max_allowed', 3);
    }

    #[Test]
    public function pending_slot_purchase_does_not_raise_the_limit(): void
    {
        CompanySlotPurchase::create([
            'user_id' => $this->proUser->id,
            'slots' => 5,
            'price_sats' => 120_000,
            'btcpay_invoice_id' => 'inv-limit-2',
            'status' => CompanySlotPurchase::STATUS_PENDING,
        ]);

        $this->assertSame(
            2,
            app(SubscriptionEntitlementService::class)->maxCompaniesForUser($this->proUser),
        );
    }

    #[Test]
    public function beta_override_and_slots_stack(): void
    {
        config(['invoicing.beta_pro_max_companies' => 5]);

        CompanySlotPurchase::create([
            'user_id' => $this->proUser->id,
            'slots' => 2,
            'price_sats' => 60_000,
            'btcpay_invoice_id' => 'inv-limit-3',
            'status' => CompanySlotPurchase::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->assertSame(
            7,
            app(SubscriptionEntitlementService::class)->maxCompaniesForUser($this->proUser),
        );
    }

    #[Test]
    public function free_user_with_paid_slots_still_has_no_invoicing(): void
    {
        $freePlan = SubscriptionPlan::where('code', 'free')->firstOrFail();
        $freeUser = User::factory()->create();
        Subscription::create([
            'user_id' => $freeUser->id,
            'plan_id' => $freePlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        CompanySlotPurchase::create([
            'user_id' => $freeUser->id,
            'slots' => 5,
            'price_sats' => 120_000,
            'btcpay_invoice_id' => 'inv-limit-4',
            'status' => CompanySlotPurchase::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->assertSame(
            0,
            app(SubscriptionEntitlementService::class)->maxCompaniesForUser($freeUser),
        );
    }

    #[Test]
    public function user_payload_reflects_slot_inclusive_limit(): void
    {
        CompanySlotPurchase::create([
            'user_id' => $this->proUser->id,
            'slots' => 3,
            'price_sats' => 90_000,
            'btcpay_invoice_id' => 'inv-limit-5',
            'status' => CompanySlotPurchase::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($this->proUser)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('plan.max_companies', 5)
            ->assertJsonPath('plan.included_companies', 2)
            ->assertJsonPath('plan.extra_company_slots', 3);
    }

    #[Test]
    public function expired_subscription_loses_invoicing_access(): void
    {
        $subscription = $this->proUser->currentSubscription();
        $subscription->update([
            'status' => 'expired',
            'expires_at' => now()->subMonth(),
            'grace_ends_at' => now()->subDays(1),
        ]);

        $this->actingAs($this->proUser)
            ->getJson('/api/invoicing/companies')
            ->assertForbidden();
    }
}
