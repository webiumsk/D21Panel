<?php

namespace Tests\Unit;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\User;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyVatPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyVatPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function skPartialCompany(): Company
    {
        $user = User::factory()->create();

        return Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'partial',
            'vat_rate_default' => 23,
            'default_currency' => 'EUR',
        ]);
    }

    #[Test]
    public function partial_payer_domestic_supply_hides_vat_and_calculates_no_tax(): void
    {
        $company = $this->skPartialCompany();
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Domáci',
            'country' => 'SK',
        ]);

        $policy = app(CompanyVatPolicy::class);

        $this->assertTrue($policy->isPartialPayer($company));
        $this->assertTrue($policy->isDomesticSupply($company, $contact));
        $this->assertFalse($policy->showsVatRateColumn($company, $contact));
        $this->assertFalse($policy->showsVatBreakdown($company, $contact));
        $this->assertFalse($policy->calculatesVatAmounts($company, $contact));

        $canonical = app(CanonicalInvoiceBuilder::class)->fromLinePayloads($company, [
            ['name' => 'Služba', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 23],
        ], 0, null, $contact);

        $this->assertSame('0.00', $canonical->taxTotal);
        $this->assertSame('100.00', $canonical->total);
        $this->assertFalse($canonical->vatApplicable());
    }

    #[Test]
    public function partial_payer_foreign_supply_shows_zero_vat_and_reverse_charge_note(): void
    {
        $company = $this->skPartialCompany();
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Zahraničný',
            'country' => 'DE',
        ]);

        $policy = app(CompanyVatPolicy::class);

        $this->assertTrue($policy->isForeignSupply($company, $contact));
        $this->assertSame('eu', $policy->supplyRegion($company, $contact));
        $this->assertTrue($policy->showsVatRateColumn($company, $contact));
        // EU reverse charge shows the summary with VAT 0.
        $this->assertTrue($policy->showsVatBreakdown($company, $contact));
        $this->assertFalse($policy->calculatesVatAmounts($company, $contact));

        $canonical = app(CanonicalInvoiceBuilder::class)->fromLinePayloads($company, [
            ['name' => 'Služba', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 23],
        ], 0, null, $contact);

        $this->assertSame(0.0, $canonical->lines[0]->taxRate);
        $this->assertSame('0.00', $canonical->taxTotal);
        $this->assertSame('100.00', $canonical->total);

        $note = $policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([]));
        $this->assertSame(
            __(CompanyVatPolicy::PARTIAL_REVERSE_CHARGE_NOTE),
            $note,
        );
    }

    #[Test]
    public function partial_payer_non_eu_supply_hides_vat_and_carries_no_note(): void
    {
        $company = $this->skPartialCompany();
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'US klient',
            'country' => 'US',
        ]);

        $policy = app(CompanyVatPolicy::class);

        $this->assertSame('non_eu', $policy->supplyRegion($company, $contact));
        $this->assertFalse($policy->showsVatRateColumn($company, $contact));
        $this->assertFalse($policy->showsVatBreakdown($company, $contact));
        $this->assertNull($policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([])));
    }

    #[Test]
    public function partial_payer_unrecognized_country_never_triggers_eu_reverse_charge(): void
    {
        $company = $this->skPartialCompany();
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Voľný text',
            'country' => 'Nemecká spolková republika',
        ]);

        $policy = app(CompanyVatPolicy::class);

        // A country that is not a recognizable ISO2 code must never trigger
        // the EU reverse-charge path.
        $this->assertSame('non_eu', $policy->supplyRegion($company, $contact));
        $this->assertFalse($policy->showsVatBreakdown($company, $contact));
        $this->assertNull($policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([])));
    }

    #[Test]
    public function full_payer_always_shows_vat_regardless_of_counterparty(): void
    {
        $company = $this->skPartialCompany();
        $company->vat_status = 'payer';
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'US klient',
            'country' => 'US',
        ]);

        $policy = app(CompanyVatPolicy::class);

        $this->assertTrue($policy->showsVatBreakdown($company, $contact));
        $this->assertTrue($policy->showsVatBreakdown($company, null));
        $this->assertTrue($policy->calculatesVatAmounts($company, $contact));
        $this->assertNull($policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([])));
    }

    #[Test]
    public function full_payer_eu_b2b_with_vat_id_reverse_charges_automatically(): void
    {
        $company = $this->skPartialCompany();
        $company->vat_status = 'payer';
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'CZ firma',
            'country' => 'CZ',
            'vat_id' => 'CZ12345678',
        ]);

        $policy = app(CompanyVatPolicy::class);

        $this->assertTrue($policy->euB2bReverseCharge($company, $contact));
        // No VAT is charged, but the summary still shows VAT 0 + the note.
        $this->assertFalse($policy->calculatesVatAmounts($company, $contact));
        $this->assertSame(0.0, $policy->resolveLineTaxRate($company, $contact, 23.0));
        $this->assertTrue($policy->showsVatBreakdown($company, $contact));
        $this->assertSame(
            __(CompanyVatPolicy::PARTIAL_REVERSE_CHARGE_NOTE),
            $policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([])),
        );

        // A custom company note wins over the statutory default.
        $custom = $policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([
            'reverse_charge' => true,
            'reverse_charge_note' => 'Vlastná nota o prenesení.',
        ]));
        $this->assertSame('Vlastná nota o prenesení.', $custom);
    }

    #[Test]
    public function full_payer_eu_b2c_without_vat_id_keeps_normal_vat(): void
    {
        $company = $this->skPartialCompany();
        $company->vat_status = 'payer';
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'CZ spotrebiteľ',
            'country' => 'CZ',
        ]);

        $policy = app(CompanyVatPolicy::class);

        $this->assertFalse($policy->euB2bReverseCharge($company, $contact));
        $this->assertTrue($policy->calculatesVatAmounts($company, $contact));
        $this->assertSame(23.0, $policy->resolveLineTaxRate($company, $contact, 23.0));
        $this->assertNull($policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([])));
    }

    #[Test]
    public function cz_company_gets_the_same_three_tier_behavior(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Test s.r.o. (CZ)',
            'jurisdiction' => CompanyJurisdiction::EuCz,
            'country' => 'CZ',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'vat_rate_default' => 21,
            'default_currency' => 'CZK',
        ]);
        $policy = app(CompanyVatPolicy::class);

        // Domestic CZ buyer: normal VAT.
        $domestic = CompanyContact::create(['company_id' => $company->id, 'name' => 'CZ', 'country' => 'CZ']);
        $this->assertSame('domestic', $policy->supplyRegion($company, $domestic));
        $this->assertTrue($policy->calculatesVatAmounts($company, $domestic));

        // SK VAT-registered business: automatic reverse charge.
        $skB2b = CompanyContact::create(['company_id' => $company->id, 'name' => 'SK', 'country' => 'SK', 'vat_id' => 'SK2020...']);
        $this->assertTrue($policy->euB2bReverseCharge($company, $skB2b));
        $this->assertFalse($policy->calculatesVatAmounts($company, $skB2b));
        $this->assertNotNull($policy->reverseChargeNote($company, $skB2b, CompanyAppSettings::from([])));

        // Identifikovana osoba (vat_status 'partial') + SK buyer: VAT 0 + note.
        $company->vat_status = 'partial';
        $this->assertTrue($policy->showsVatBreakdown($company, $skB2b));
        $this->assertFalse($policy->calculatesVatAmounts($company, $skB2b));
    }

    #[Test]
    public function seller_country_falls_back_to_the_jurisdiction(): void
    {
        // The DB column is NOT NULL - the fallback matters for ephemeral
        // payload-only companies, so in-memory models mirror that path.
        $company = new Company([
            'legal_name' => 'Bez krajiny s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuCz,
            'vat_payer' => true,
            'vat_status' => 'payer',
            'default_currency' => 'CZK',
        ]);
        $policy = app(CompanyVatPolicy::class);

        // A CZ-jurisdiction company without a country must treat a CZ buyer
        // as domestic - never as EU reverse charge.
        $czB2b = new CompanyContact(['name' => 'CZ', 'country' => 'CZ', 'vat_id' => 'CZ123']);
        $this->assertSame('domestic', $policy->supplyRegion($company, $czB2b));
        $this->assertFalse($policy->euB2bReverseCharge($company, $czB2b));

        // Multi-country buckets have no fallback: everything is domestic-safe.
        $company->jurisdiction = CompanyJurisdiction::EuOther;
        $de = new CompanyContact(['name' => 'DE', 'country' => 'DE', 'vat_id' => 'DE123']);
        $this->assertSame('domestic', $policy->supplyRegion($company, $de));
    }

    private function deCompany(string $vatStatus = 'payer'): Company
    {
        $user = User::factory()->create();

        return Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Test GmbH',
            'jurisdiction' => CompanyJurisdiction::EuDe,
            'country' => 'DE',
            'vat_payer' => $vatStatus !== 'none',
            'vat_status' => $vatStatus,
            'vat_rate_default' => 19,
            'default_currency' => 'EUR',
        ]);
    }

    #[Test]
    public function de_non_payer_carries_the_kleinunternehmer_clause(): void
    {
        $company = $this->deCompany('none');
        $contact = new CompanyContact(['name' => 'DE klient', 'country' => 'DE']);
        $policy = app(CompanyVatPolicy::class);

        $this->assertFalse($policy->calculatesVatAmounts($company, $contact));
        $this->assertFalse($policy->showsVatBreakdown($company, $contact));
        $this->assertSame(
            CompanyVatPolicy::DE_KLEINUNTERNEHMER_NOTE,
            $policy->taxClause($company, $contact, CompanyAppSettings::from([])),
        );
        // The clause applies on every DE non-payer invoice, contact or not.
        $this->assertSame(
            CompanyVatPolicy::DE_KLEINUNTERNEHMER_NOTE,
            $policy->taxClause($company, null, CompanyAppSettings::from([])),
        );
    }

    #[Test]
    public function de_payer_eu_b2b_uses_the_german_reverse_charge_wording(): void
    {
        $company = $this->deCompany();
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'FR firma',
            'country' => 'FR',
            'vat_id' => 'FR12345678901',
        ]);
        $policy = app(CompanyVatPolicy::class);

        $this->assertFalse($policy->calculatesVatAmounts($company, $contact));
        $this->assertSame(
            CompanyVatPolicy::DE_REVERSE_CHARGE_NOTE,
            $policy->taxClause($company, $contact, CompanyAppSettings::from([])),
        );

        // A custom company note is appended - the mandatory German wording
        // always stays on the invoice.
        $custom = $policy->taxClause($company, $contact, CompanyAppSettings::from([
            'reverse_charge' => true,
            'reverse_charge_note' => 'Eigener Hinweis.',
        ]));
        $this->assertStringContainsString(CompanyVatPolicy::DE_REVERSE_CHARGE_NOTE, (string) $custom);
        $this->assertStringContainsString('Eigener Hinweis.', (string) $custom);
    }

    #[Test]
    public function de_payer_non_eu_export_is_vat_free_with_the_export_clause(): void
    {
        $company = $this->deCompany();
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'US klient',
            'country' => 'US',
        ]);
        $policy = app(CompanyVatPolicy::class);

        $this->assertTrue($policy->exportExemptionApplies($company, $contact));
        $this->assertFalse($policy->calculatesVatAmounts($company, $contact));
        $this->assertSame(0.0, $policy->resolveLineTaxRate($company, $contact, 19.0));
        $this->assertSame(
            CompanyVatPolicy::DE_EXPORT_SERVICES_NOTE,
            $policy->taxClause($company, $contact, CompanyAppSettings::from([])),
        );

        // Goods sellers override the clause via the export_note setting.
        $goods = $policy->taxClause($company, $contact, CompanyAppSettings::from([
            'export_note' => CompanyVatPolicy::DE_EXPORT_GOODS_NOTE,
        ]));
        $this->assertSame(CompanyVatPolicy::DE_EXPORT_GOODS_NOTE, $goods);
    }

    #[Test]
    public function non_de_companies_keep_their_existing_clause_behavior(): void
    {
        // SK payer + non-EU: no export exemption, VAT still charged, no clause.
        $company = $this->skPartialCompany();
        $company->vat_status = 'payer';
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'US klient',
            'country' => 'US',
        ]);
        $policy = app(CompanyVatPolicy::class);

        $this->assertFalse($policy->exportExemptionApplies($company, $contact));
        $this->assertTrue($policy->calculatesVatAmounts($company, $contact));
        $this->assertNull($policy->taxClause($company, $contact, CompanyAppSettings::from([])));

        // SK non-payer gets no Kleinunternehmer clause (DE-only wording).
        $company->vat_status = 'none';
        $this->assertNull($policy->taxClause($company, $contact, CompanyAppSettings::from([])));

        // SK §7a EU reverse charge keeps the existing translated wording.
        $company->vat_status = 'partial';
        $cz = CompanyContact::create(['company_id' => $company->id, 'name' => 'CZ', 'country' => 'CZ']);
        $this->assertSame(
            __(CompanyVatPolicy::PARTIAL_REVERSE_CHARGE_NOTE),
            $policy->taxClause($company, $cz, CompanyAppSettings::from([])),
        );
    }

    #[Test]
    public function greek_vies_alias_el_is_domestic_for_a_gr_seller(): void
    {
        $company = $this->skPartialCompany();
        $company->country = 'GR';
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Grécky klient',
            'country' => 'EL',
        ]);

        $policy = app(CompanyVatPolicy::class);

        $this->assertTrue($policy->isDomesticSupply($company, $contact));
        $this->assertSame('domestic', $policy->supplyRegion($company, $contact));
        $this->assertNull($policy->reverseChargeNote($company, $contact, CompanyAppSettings::from([])));
    }
}
