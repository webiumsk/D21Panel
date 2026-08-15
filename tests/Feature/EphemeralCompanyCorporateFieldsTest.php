<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\EphemeralDocumentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EphemeralCompanyCorporateFieldsTest extends TestCase
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
    public function ephemeral_company_carries_the_de_corporate_footer_fields(): void
    {
        $user = User::factory()->create();
        $factory = app(EphemeralDocumentFactory::class);

        $company = $factory->payloadOnlyCompany($user, [
            'legal_name' => 'Muster GmbH',
            'jurisdiction' => 'eu_de',
            'city' => 'Berlin',
            'register_court' => 'Amtsgericht Charlottenburg',
            'register_number' => 'HRB 12345',
            'managing_directors' => 'Max Mustermann, Erika Musterfrau',
            'supervisory_board_chair' => 'Dr. A. Vorsitz',
        ]);

        $this->assertSame('Amtsgericht Charlottenburg', $company->register_court);
        $this->assertSame('HRB 12345', $company->register_number);
        $this->assertSame('Max Mustermann, Erika Musterfrau', $company->managing_directors);
        $this->assertSame('Dr. A. Vorsitz', $company->supervisory_board_chair);
    }

    #[Test]
    public function persisted_company_accepts_and_stores_the_fields(): void
    {
        $user = $this->proUser();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Muster GmbH',
            'jurisdiction' => 'eu_de',
            'country' => 'DE',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/invoicing/companies/{$company->id}", [
                'register_court' => 'Amtsgericht Charlottenburg',
                'register_number' => 'HRB 12345',
                'managing_directors' => 'Max Mustermann',
                'supervisory_board_chair' => 'Dr. A. Vorsitz',
            ])
            ->assertOk();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'register_court' => 'Amtsgericht Charlottenburg',
            'register_number' => 'HRB 12345',
            'managing_directors' => 'Max Mustermann',
            'supervisory_board_chair' => 'Dr. A. Vorsitz',
        ]);
    }
}
