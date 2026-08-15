<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EphemeralEfakturaSubmission;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EphemeralBusinessDocumentEfakturaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bridge_endpoint_reports_configured_sk_company(): void
    {
        config(['efaktura.enabled' => true]);

        $user = $this->createProUser();
        $company = $this->createBridgeCompany($user);

        $response = $this->actingAs($user)->getJson('/api/invoicing/ephemeral/efaktura/bridge');

        $response->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.bridge_company_id', $company->id);
    }

    #[Test]
    public function authenticated_user_can_send_ephemeral_efaktura_without_persisting_document(): void
    {
        Cache::flush();

        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
        ]);

        Http::fake([
            'https://sapi.test/sapi/v1/auth/token' => Http::response([
                'access_token' => 'token-abc',
                'expires_in' => 3600,
            ]),
            'https://sapi.test/sapi/v1/document/send' => Http::response([
                'providerDocumentId' => 'doc-remote-ephemeral',
                'status' => 'ACCEPTED',
            ], 202),
        ]);

        $user = $this->createProUser();
        $company = $this->createBridgeCompany($user);

        $payload = $this->ephemeralPayload();
        $payload['evolu_document_id'] = 'evolu-doc-efaktura-1';

        $response = $this->actingAs($user)->postJson('/api/invoicing/ephemeral/efaktura/send', $payload);

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.external_id', 'doc-remote-ephemeral');

        $this->assertDatabaseCount('business_documents', 0);
        $this->assertDatabaseCount('business_document_compliance', 0);
        $this->assertDatabaseHas('ephemeral_efaktura_submissions', [
            'user_id' => $user->id,
            'bridge_company_id' => $company->id,
            'evolu_document_id' => 'evolu-doc-efaktura-1',
            'external_id' => 'doc-remote-ephemeral',
            'status' => 'approved',
        ]);
    }

    #[Test]
    public function authenticated_user_can_poll_ephemeral_efaktura_status(): void
    {
        $user = $this->createProUser();
        $company = $this->createBridgeCompany($user);

        EphemeralEfakturaSubmission::query()->create([
            'user_id' => $user->id,
            'bridge_company_id' => $company->id,
            'evolu_document_id' => 'evolu-doc-status',
            'provider' => 'peppol',
            'status' => 'submitted',
            'external_id' => 'remote-1',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/invoicing/ephemeral/efaktura/status?'.http_build_query([
            'evolu_document_id' => 'evolu-doc-status',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'submitted')
            ->assertJsonPath('data.0.external_id', 'remote-1');
    }

    #[Test]
    public function authenticated_user_can_send_ephemeral_efaktura_with_snapshot_credentials(): void
    {
        Cache::flush();

        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
        ]);

        Http::fake([
            'https://sapi.test/sapi/v1/auth/token' => Http::response([
                'access_token' => 'token-abc',
                'expires_in' => 3600,
            ]),
            'https://sapi.test/sapi/v1/document/send' => Http::response([
                'providerDocumentId' => 'doc-remote-snapshot-creds',
                'status' => 'ACCEPTED',
            ], 202),
        ]);

        $user = $this->createProUser();
        Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Audit s.r.o.',
            'jurisdiction' => 'eu_sk',
            'default_currency' => 'EUR',
            'vat_payer' => true,
            'vat_status' => 'payer',
        ]);
        $bridgeCompany = $this->createBridgeCompany($user);

        $payload = $this->ephemeralPayload();
        $payload['evolu_document_id'] = 'evolu-doc-snapshot-creds';
        $payload['company']['app_settings'] = [
            'efaktura_enabled' => true,
            'efaktura_sapi_base_url' => 'https://sapi.test',
            'efaktura_peppol_participant_id' => '0245:2023980035',
            'efaktura_sapi_client_id' => 'client-test',
            'efaktura_sapi_client_secret' => 'secret-test',
        ];

        $response = $this->actingAs($user)->postJson('/api/invoicing/ephemeral/efaktura/send', $payload);

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.external_id', 'doc-remote-snapshot-creds');

        $this->assertDatabaseHas('ephemeral_efaktura_submissions', [
            'user_id' => $user->id,
            'bridge_company_id' => $bridgeCompany->id,
            'evolu_document_id' => 'evolu-doc-snapshot-creds',
        ]);
    }

    #[Test]
    public function send_without_bridge_company_returns_validation_error(): void
    {
        config(['efaktura.enabled' => true]);

        $user = $this->createProUser();
        $payload = $this->ephemeralPayload();
        $payload['evolu_document_id'] = 'evolu-doc-missing-bridge';

        $response = $this->actingAs($user)->postJson('/api/invoicing/ephemeral/efaktura/send', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['efaktura']);
    }

    protected function createProUser(): User
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

    protected function createBridgeCompany(User $user): Company
    {
        return Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Bridge s.r.o.',
            'jurisdiction' => 'eu_sk',
            'default_currency' => 'EUR',
            'registration_number' => '47615681',
            'tax_id' => '2023980035',
            'vat_number' => 'SK2023980035',
            'iban' => 'SK3112000000198742637541',
            'street' => 'Bohunice 47',
            'city' => 'Bohunice',
            'postal_code' => '93505',
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'vat_rate_default' => 23,
            'app_settings' => [
                'efaktura_enabled' => true,
                'efaktura_auto_send' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_peppol_participant_id' => '0245:2023980035',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ephemeralPayload(): array
    {
        return [
            'company' => [
                'legal_name' => 'Local Studio s.r.o.',
                'registration_number' => '47615681',
                'tax_id' => '2023980035',
                'vat_number' => 'SK2023980035',
                'street' => 'Main 1',
                'city' => 'Bratislava',
                'postal_code' => '81101',
                'country' => 'SK',
                'default_currency' => 'EUR',
                'jurisdiction' => 'eu_sk',
                'vat_payer' => true,
                'vat_rate_default' => 23,
            ],
            'contact' => [
                'name' => 'Client Ltd',
                'email' => 'client@example.com',
                'registration_number' => '12345678',
                'tax_id' => '2123456789',
                'country' => 'SK',
            ],
            'document' => [
                'type' => 'invoice',
                'status' => 'issued',
                'number' => 'LOCAL-2026-001',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'currency' => 'EUR',
                'discount_percent' => 0,
                'pdf_locale' => 'sk',
            ],
            'lines' => [
                [
                    'name' => 'Consulting',
                    'quantity' => 1,
                    'unit' => 'h',
                    'unit_price' => 100,
                    'tax_rate' => 23,
                ],
            ],
        ];
    }
}
