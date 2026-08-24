<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\CompanyPdfFilenameBuilder;
use App\Services\Invoicing\PayBySquareGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $proUser;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->proUser = User::factory()->create();
        Subscription::create([
            'user_id' => $this->proUser->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->company = Company::create([
            'user_id' => $this->proUser->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'iban' => 'SK3112000000198747547509',
            'vat_payer' => false,
        ]);
    }

    #[Test]
    public function company_show_includes_resolved_app_settings_defaults(): void
    {
        $response = $this->actingAs($this->proUser)
            ->getJson("/api/invoicing/companies/{$this->company->id}");

        $response->assertOk();
        $response->assertJsonPath('data.app_settings.default_constant_symbol', '0308');
        $response->assertJsonPath('data.app_settings.show_pay_by_square', true);
        $response->assertJsonPath('data.app_settings.default_invoice_payment_terms_days', 14);
    }

    #[Test]
    public function company_payload_redacts_write_only_app_settings_secrets(): void
    {
        $this->company->update([
            'app_settings' => [
                'stripe_tax_secret_key' => 'sk_live_secret',
                'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('sapi-secret'),
            ],
        ]);

        $this->actingAs($this->proUser)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.app_settings.stripe_tax_secret_key')
            ->assertJsonPath('data.app_settings.stripe_tax_secret_key_set', true)
            ->assertJsonMissingPath('data.app_settings.efaktura_sapi_client_secret_encrypted')
            ->assertJsonPath('data.app_settings.efaktura_sapi_client_secret_set', true);
    }

    #[Test]
    public function company_index_does_not_expose_raw_settings_columns(): void
    {
        $this->company->update([
            'app_settings' => ['stripe_tax_secret_key' => 'sk_live_secret'],
            'email_settings' => [
                'smtp' => ['password_encrypted' => Crypt::encryptString('smtp-secret')],
            ],
        ]);

        $this->actingAs($this->proUser)
            ->getJson('/api/invoicing/companies')
            ->assertOk()
            ->assertJsonMissingPath('data.0.app_settings')
            ->assertJsonMissingPath('data.0.email_settings');
    }

    #[Test]
    public function pro_user_can_update_app_settings(): void
    {
        $response = $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/app-settings", [
                'default_constant_symbol' => '0558',
                'show_pay_by_square' => false,
                'default_invoice_payment_terms_days' => 21,
                'pdf_filename_pattern' => '#TYPE#_#NUMBER#',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.app_settings.default_constant_symbol', '0558');
        $response->assertJsonPath('data.app_settings.show_pay_by_square', false);

        $this->company->refresh();
        $this->assertSame('0558', $this->company->app_settings['default_constant_symbol']);
    }

    #[Test]
    public function empty_stripe_tax_secret_is_write_only_and_does_not_clear_existing_secret(): void
    {
        $this->company->update([
            'app_settings' => [
                'us_sales_tax_provider' => 'stripe_tax',
                'stripe_tax_secret_key' => 'sk_test_existing',
            ],
        ]);

        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/app-settings", [
                'default_constant_symbol' => '0558',
                'stripe_tax_secret_key' => '',
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.app_settings.stripe_tax_secret_key')
            ->assertJsonPath('data.app_settings.stripe_tax_secret_key_set', true);

        $this->company->refresh();
        $this->assertSame('sk_test_existing', $this->company->app_settings['stripe_tax_secret_key']);
        $this->assertSame('0558', $this->company->app_settings['default_constant_symbol']);
    }

    #[Test]
    public function non_eligible_company_can_save_generic_settings_with_noop_efaktura_defaults(): void
    {
        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/app-settings", [
                'default_constant_symbol' => '0558',
                'efaktura_enabled' => false,
                'efaktura_auto_send' => false,
                'efaktura_inbound_enabled' => false,
                'efaktura_provider' => 'sapi_sk',
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_peppol_participant_id' => '',
                'efaktura_sapi_client_id' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.app_settings.default_constant_symbol', '0558');
    }

    #[Test]
    public function non_payer_sk_company_can_enable_inbound_only_efaktura(): void
    {
        // The statutory receiving obligation covers every SK taxable entity,
        // so a non-payer may store CPDS credentials and switch inbound on.
        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/app-settings", [
                'efaktura_enabled' => true,
                'efaktura_auto_send' => false,
                'efaktura_inbound_enabled' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret' => 'secret-test',
            ])
            ->assertOk()
            ->assertJsonPath('data.app_settings.efaktura_inbound_enabled', true)
            ->assertJsonPath('data.app_settings.efaktura_sapi_client_secret_set', true);
    }

    #[Test]
    public function non_payer_sk_company_cannot_enable_auto_send(): void
    {
        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/app-settings", [
                'efaktura_enabled' => true,
                'efaktura_auto_send' => true,
                'efaktura_inbound_enabled' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret' => 'secret-test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['efaktura_auto_send']);

        $this->company->refresh();
        $this->assertNull($this->company->app_settings['efaktura_sapi_client_id'] ?? null);
    }

    #[Test]
    public function non_slovak_company_cannot_store_efaktura_settings(): void
    {
        $this->company->forceFill(['jurisdiction' => CompanyJurisdiction::EuCz])->save();

        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/app-settings", [
                'efaktura_enabled' => true,
                'efaktura_inbound_enabled' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['efaktura']);
    }

    #[Test]
    public function pay_by_square_respects_company_setting(): void
    {
        $this->company->update([
            'app_settings' => ['show_pay_by_square' => false],
        ]);

        $document = BusinessDocument::create([
            'company_id' => $this->company->id,
            'type' => BusinessDocumentType::Invoice,
            'status' => BusinessDocumentStatus::Draft,
            'total' => 100,
            'currency' => 'EUR',
            'issue_date' => now(),
            'payment_bank_enabled' => true,
            'lines' => [],
        ]);
        $document->setRelation('company', $this->company->fresh());

        $generator = app(PayBySquareGenerator::class);
        $this->assertFalse($generator->canGenerate($this->company->fresh(), $document));
    }

    #[Test]
    public function pdf_filename_builder_replaces_tokens(): void
    {
        $this->company->update([
            'trade_name' => 'Acme',
            'app_settings' => ['pdf_filename_pattern' => '#TYPE#_#COMPANY#_#NUMBER#'],
        ]);

        $document = BusinessDocument::create([
            'company_id' => $this->company->id,
            'type' => BusinessDocumentType::Invoice,
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260001',
            'total' => 120.5,
            'currency' => 'EUR',
            'issue_date' => now()->parse('2026-06-01'),
            'lines' => [],
        ]);

        $filename = app(CompanyPdfFilenameBuilder::class)->build($document->fresh(['company']));

        $this->assertStringContainsString('fa_Acme_20260001', $filename);
        $this->assertStringEndsWith('.pdf', $filename);
    }
}
