<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyJurisdictionGatingTest extends TestCase
{
    use RefreshDatabase;

    private function proUser(): User
    {
        $plan = SubscriptionPlan::create([
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
    public function new_companies_accept_only_the_enabled_jurisdictions(): void
    {
        $user = $this->proUser();

        foreach (['eu_sk', 'eu_cz', 'eu_de', 'us'] as $enabled) {
            $this->actingAs($user)
                ->postJson('/api/invoicing/companies', [
                    'legal_name' => "Company {$enabled}",
                    'jurisdiction' => $enabled,
                ])
                ->assertCreated();
        }
    }

    #[Test]
    public function disabled_jurisdictions_are_rejected_on_create(): void
    {
        $user = $this->proUser();

        foreach (['eu_at', 'eu_other', 'ch', 'uk', 'offshore', 'asia'] as $disabled) {
            $this->actingAs($user)
                ->postJson('/api/invoicing/companies', [
                    'legal_name' => "Company {$disabled}",
                    'jurisdiction' => $disabled,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['jurisdiction']);
        }
    }

    #[Test]
    public function an_existing_company_keeps_its_disabled_jurisdiction_on_update(): void
    {
        $user = $this->proUser();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Swiss AG',
            'jurisdiction' => CompanyJurisdiction::Ch,
            'country' => 'CH',
        ]);

        // Re-submitting the current (disabled) jurisdiction with a profile
        // edit must keep working - existing companies are not forced out.
        $this->actingAs($user)
            ->patchJson("/api/invoicing/companies/{$company->id}", [
                'legal_name' => 'Swiss AG (renamed)',
                'jurisdiction' => 'ch',
            ])
            ->assertOk();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'legal_name' => 'Swiss AG (renamed)',
            'jurisdiction' => 'ch',
        ]);
    }

    #[Test]
    public function switching_to_another_disabled_jurisdiction_is_rejected(): void
    {
        $user = $this->proUser();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Swiss AG',
            'jurisdiction' => CompanyJurisdiction::Ch,
            'country' => 'CH',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/invoicing/companies/{$company->id}", ['jurisdiction' => 'eu_at'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['jurisdiction']);

        // Switching to an enabled one stays possible.
        $this->actingAs($user)
            ->patchJson("/api/invoicing/companies/{$company->id}", ['jurisdiction' => 'eu_sk'])
            ->assertOk();
    }
}
