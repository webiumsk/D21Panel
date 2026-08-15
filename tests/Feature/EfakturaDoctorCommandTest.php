<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EfakturaDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    private function skCompany(array $appSettings = []): Company
    {
        return Company::create([
            'user_id' => User::factory()->create()->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'country' => 'SK',
            'tax_id' => '2023980035',
            'registration_number' => '47615681',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'app_settings' => $appSettings,
        ]);
    }

    #[Test]
    public function reports_the_globally_disabled_module(): void
    {
        config(['efaktura.enabled' => false]);

        $this->artisan('efaktura:doctor')
            ->expectsOutputToContain('EFAKTURA_ENABLED: false')
            ->expectsOutputToContain('Module is globally OFF')
            ->assertSuccessful();
    }

    #[Test]
    public function reports_a_configured_company_with_the_derived_participant_id(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test']]);
        $this->skCompany([
            'efaktura_enabled' => true,
            'efaktura_sapi_base_url' => 'https://sapi.test',
            'efaktura_sapi_client_id' => 'client-test',
            'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
        ]);

        $this->artisan('efaktura:doctor')
            ->expectsOutputToContain('eligible: yes')
            ->expectsOutputToContain('base URL: https://sapi.test (host allowed)')
            ->expectsOutputToContain('participant ID: 0245:2023980035 (derived from DIČ/IČO)')
            ->expectsOutputToContain('configured: yes')
            ->assertSuccessful();
    }

    #[Test]
    public function live_mode_authenticates_against_the_provider(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test']]);
        Http::fake([
            'https://sapi.test/sapi/v1/auth/token' => Http::response(['access_token' => 'tok']),
        ]);
        $this->skCompany([
            'efaktura_enabled' => true,
            'efaktura_sapi_base_url' => 'https://sapi.test',
            'efaktura_sapi_client_id' => 'client-test',
            'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
        ]);

        $this->artisan('efaktura:doctor --live')
            ->expectsOutputToContain('live authentication: OK')
            ->assertSuccessful();
    }

    #[Test]
    public function live_mode_skips_authentication_when_the_base_url_is_rejected(): void
    {
        // Host is neither allowlisted nor resolvable - the URL guard rejects
        // it, the verdict downgrades and no authentication is attempted.
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => []]);
        Http::fake();
        $this->skCompany([
            'efaktura_enabled' => true,
            'efaktura_sapi_base_url' => 'https://rejected.invalid',
            'efaktura_sapi_client_id' => 'client-test',
            'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
        ]);

        $this->artisan('efaktura:doctor --live')
            ->expectsOutputToContain('REJECTED')
            ->expectsOutputToContain('configured: NO (base URL rejected)')
            ->doesntExpectOutputToContain('live authentication')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function unknown_company_fails_and_ineligible_companies_are_labelled(): void
    {
        config(['efaktura.enabled' => true]);

        $this->artisan('efaktura:doctor --company=00000000-0000-0000-0000-000000000000')
            ->assertFailed();

        $nonPayer = $this->skCompany();
        $nonPayer->forceFill(['vat_status' => 'none', 'vat_payer' => false])->save();

        $this->artisan('efaktura:doctor')
            ->expectsOutputToContain('eligible (outbound): no - only eu_sk full VAT payers issue e-invoices')
            ->assertSuccessful();
    }
}
