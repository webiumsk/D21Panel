<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EfakturaTestConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function skUserWithCompany(array $companyAttributes = []): array
    {
        $proPlan = SubscriptionPlan::create([
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
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $company = Company::create(array_merge([
            'user_id' => $user->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'registration_number' => '47615681',
            'tax_id' => '2023980035',
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'app_settings' => [
                'efaktura_enabled' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
            ],
        ], $companyAttributes));

        return [$user, $company];
    }

    #[Test]
    public function stored_credentials_are_tested_and_success_is_stamped(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test']]);
        Http::fake([
            'https://sapi.test/sapi/v1/auth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        ]);

        [$user, $company] = $this->skUserWithCompany();

        $response = $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/test-connection", [])
            ->assertOk();

        $this->assertTrue($response->json('data.ok'));
        $this->assertNotNull($response->json('data.tested_at'));
        $this->assertNotNull($company->fresh()->app_settings['efaktura_connection_tested_at'] ?? null);
    }

    #[Test]
    public function invalid_credentials_map_to_a_stable_code_without_a_stamp(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test']]);
        Http::fake([
            'https://sapi.test/sapi/v1/auth/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        [$user, $company] = $this->skUserWithCompany();

        $response = $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/test-connection", [])
            ->assertOk();

        $this->assertFalse($response->json('data.ok'));
        $this->assertSame('invalid_credentials', $response->json('data.code'));
        $this->assertNull($company->fresh()->app_settings['efaktura_connection_tested_at'] ?? null);
    }

    #[Test]
    public function body_credentials_override_the_stored_settings(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test', 'other.test']]);
        Http::fake([
            'https://other.test/sapi/v1/auth/token' => Http::response(['access_token' => 'tok']),
        ]);

        [$user, $company] = $this->skUserWithCompany();

        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/test-connection", [
                'efaktura_sapi_base_url' => 'https://other.test',
                'efaktura_sapi_client_id' => 'other-client',
                'efaktura_sapi_client_secret' => 'other-secret',
            ])
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://other.test/')
                && $request['client_id'] === 'other-client';
        });
    }

    #[Test]
    public function globally_disabled_or_ineligible_companies_are_rejected(): void
    {
        config(['efaktura.enabled' => false]);
        [$user, $company] = $this->skUserWithCompany();

        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/test-connection", [])
            ->assertStatus(422);

        config(['efaktura.enabled' => true]);
        $company->forceFill(['vat_status' => 'none', 'vat_payer' => false])->save();

        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/test-connection", [])
            ->assertStatus(422);
    }

    #[Test]
    public function ephemeral_test_requires_all_fields_in_the_body(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test']]);
        Http::fake([
            'https://sapi.test/sapi/v1/auth/token' => Http::response(['access_token' => 'tok']),
        ]);

        [$user] = $this->skUserWithCompany();

        // Local-first credentials live in Evolu - nothing stored server-side
        // can back-fill them, so missing fields come back as a stable code.
        $response = $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/efaktura/test-connection', [
                'efaktura_sapi_base_url' => 'https://sapi.test',
            ])
            ->assertOk();
        $this->assertFalse($response->json('data.ok'));
        $this->assertSame('missing_fields', $response->json('data.code'));

        $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/efaktura/test-connection', [
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret' => 'secret-test',
            ])
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }
}
