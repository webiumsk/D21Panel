<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\BusinessDocumentUblService;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessDocumentUblTest extends TestCase
{
    use RefreshDatabase;

    private function proUserWithCompany(): array
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

        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
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
            'vat_rate_default' => 23,
        ]);

        return [$user, $company];
    }

    #[Test]
    public function ubl_xml_contains_invoice_root_and_canonical_totals(): void
    {
        [, $company] = $this->proUserWithCompany();

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260210',
            'subtotal' => 100,
            'tax_total' => 23,
            'total' => 123,
            'currency' => 'EUR',
            'issue_date' => now(),
            'due_date' => now()->addDays(14),
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Služba',
            'quantity' => 1,
            'unit' => 'ks.',
            'unit_price' => 100,
            'tax_rate' => 23,
            'line_total' => 123,
        ]);

        $doc = $doc->fresh(['company', 'contact', 'lines']);
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument($doc);
        $xml = app(BusinessDocumentUblService::class)->xml($doc);

        $this->assertStringContainsString('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $xml);
        $this->assertStringContainsString('<cbc:ID>20260210</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="EUR">'.$canonical->taxTotal.'</cbc:TaxAmount>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="EUR">'.$canonical->amountDue.'</cbc:PayableAmount>', $xml);
    }

    #[Test]
    public function sk_ubl_contains_cius_fields(): void
    {
        [, $company] = $this->proUserWithCompany();

        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Odberateľ s.r.o.',
            'registration_number' => '87654321',
            'tax_id' => '2123456789',
            'country' => 'SK',
        ]);

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20261203',
            'subtotal' => 100,
            'tax_total' => 23,
            'total' => 123,
            'currency' => 'EUR',
            'issue_date' => now(),
            'variable_symbol' => '20261203',
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Služba',
            'quantity' => 1,
            'unit' => 'ks.',
            'unit_price' => 100,
            'tax_rate' => 23,
            'line_total' => 123,
        ]);

        $xml = app(BusinessDocumentUblService::class)->xml($doc->fresh(['company', 'contact', 'lines']));

        $this->assertStringContainsString('schemeID="0245"', $xml);
        $this->assertStringContainsString('PartyLegalEntity', $xml);
        $this->assertStringContainsString('PayeeFinancialAccount', $xml);
        $this->assertStringContainsString('SK3112000000198742637541', $xml);
        $this->assertStringContainsString('unitCode="C62"', $xml);
    }

    #[Test]
    public function de_company_exports_the_xrechnung_cius(): void
    {
        [$user] = $this->proUserWithCompany();

        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Beispiel GmbH',
            'jurisdiction' => CompanyJurisdiction::EuDe,
            'default_currency' => 'EUR',
            'registration_number' => 'HRB 12345 B',
            'vat_number' => 'DE123456789',
            'iban' => 'DE89370400440532013000',
            'street' => 'Beispielstraße 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
            'vat_payer' => true,
            'vat_rate_default' => 19,
            'issuer_name' => 'Max Mustermann',
            'issuer_phone' => '+49 30 1234567',
            'issuer_email' => 'rechnung@beispiel.de',
        ]);

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => 'RE20260001',
            'subtotal' => 100,
            'tax_total' => 19,
            'total' => 119,
            'currency' => 'EUR',
            'issue_date' => now(),
            'due_date' => now()->addDays(14),
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Leistung',
            'quantity' => 1,
            'unit' => 'ks.',
            'unit_price' => 100,
            'tax_rate' => 19,
            'line_total' => 119,
        ]);

        $xml = app(BusinessDocumentUblService::class)->xml($doc->fresh(['company', 'contact', 'lines']));

        // XRechnung CIUS instead of plain Peppol BIS.
        $this->assertStringContainsString('urn:xeinkauf.de:kosit:xrechnung_3.0', $xml);
        // BT-10 is mandatory - no variable symbol, so the number backfills it.
        $this->assertStringContainsString('<cbc:BuyerReference>RE20260001</cbc:BuyerReference>', $xml);
        // SEPA credit transfer.
        $this->assertStringContainsString('<cbc:PaymentMeansCode>58</cbc:PaymentMeansCode>', $xml);
        // Electronic address = e-mail (EM), seller contact block present.
        $this->assertStringContainsString('schemeID="EM">rechnung@beispiel.de', $xml);
        $this->assertStringContainsString('<cbc:ElectronicMail>rechnung@beispiel.de</cbc:ElectronicMail>', $xml);
        $this->assertStringContainsString('<cbc:Telephone>+49 30 1234567</cbc:Telephone>', $xml);
        // German register id stays verbatim, without an ISO 6523 scheme.
        $this->assertStringContainsString('<cbc:CompanyID>HRB 12345 B</cbc:CompanyID>', $xml);
        $this->assertStringNotContainsString('schemeID="0208"', $xml);
        // UBL schema order: PartyTaxScheme precedes PartyLegalEntity. Both
        // must exist first - strpos(false) would fake position 0.
        $taxSchemePos = strpos($xml, '<cac:PartyTaxScheme>');
        $legalEntityPos = strpos($xml, '<cac:PartyLegalEntity>');
        $this->assertNotFalse($taxSchemePos);
        $this->assertNotFalse($legalEntityPos);
        $this->assertLessThan($legalEntityPos, $taxSchemePos);
    }

    #[Test]
    public function authenticated_user_can_download_ubl_via_web_route(): void
    {
        [$user, $company] = $this->proUserWithCompany();

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260211',
            'total' => 50,
            'subtotal' => 50,
            'tax_total' => 0,
            'currency' => 'EUR',
            'issue_date' => now(),
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Položka',
            'quantity' => 1,
            'unit_price' => 50,
            'line_total' => 50,
        ]);

        $response = $this->actingAs($user)->get(
            "/invoicing/companies/{$company->id}/documents/{$doc->id}/ubl"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('CustomizationID', $response->getContent());
    }

    private function deCompanyDocument(
        string $vatStatus,
        ?array $contactAttributes,
        float $taxRate = 0,
    ): BusinessDocument {
        [$user, $company] = $this->proUserWithCompany();
        $company->forceFill([
            'legal_name' => 'Muster GmbH',
            'jurisdiction' => CompanyJurisdiction::EuDe,
            'country' => 'DE',
            'vat_number' => 'DE123456789',
            'vat_payer' => $vatStatus !== 'none',
            'vat_status' => $vatStatus,
            'vat_rate_default' => 19,
        ])->save();

        $contact = null;
        if ($contactAttributes !== null) {
            $contact = CompanyContact::create(array_merge(
                ['company_id' => $company->id, 'name' => 'Buyer'],
                $contactAttributes,
            ));
        }

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'company_contact_id' => $contact?->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => 'RE20260001',
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'currency' => 'EUR',
            'issue_date' => now(),
        ]);
        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Leistung',
            'quantity' => 1,
            'unit' => 'ks.',
            'unit_price' => 100,
            'tax_rate' => $taxRate,
            'line_total' => 100,
        ]);

        return $doc->fresh(['company', 'contact', 'lines']);
    }

    #[Test]
    public function de_kleinunternehmer_exports_category_e_with_the_statutory_reason(): void
    {
        $doc = $this->deCompanyDocument('none', ['country' => 'DE']);
        $xml = app(BusinessDocumentUblService::class)->xml($doc);

        $this->assertStringContainsString('<cbc:ID>E</cbc:ID>', $xml);
        $this->assertStringContainsString('Kleinunternehmerregelung', $xml);
        $this->assertStringNotContainsString('<cbc:ID>Z</cbc:ID>', $xml);
    }

    #[Test]
    public function de_eu_b2b_reverse_charge_exports_category_ae(): void
    {
        $doc = $this->deCompanyDocument('payer', ['country' => 'FR', 'vat_id' => 'FR12345678901']);
        $xml = app(BusinessDocumentUblService::class)->xml($doc);

        $this->assertStringContainsString('<cbc:ID>AE</cbc:ID>', $xml);
        $this->assertStringContainsString('Steuerschuldnerschaft des Leistungsempfängers', $xml);
    }

    #[Test]
    public function de_non_eu_export_exports_category_o_for_services(): void
    {
        $doc = $this->deCompanyDocument('payer', ['country' => 'US']);
        $xml = app(BusinessDocumentUblService::class)->xml($doc);

        $this->assertStringContainsString('<cbc:ID>O</cbc:ID>', $xml);
        $this->assertStringContainsString('Nicht im Inland steuerbare Leistung', $xml);
    }

    #[Test]
    public function de_non_eu_goods_export_exports_category_g(): void
    {
        $doc = $this->deCompanyDocument('payer', ['country' => 'US']);
        // The export_goods app setting flips the category from O (services,
        // default) to G (free export of goods) and the default clause with it.
        $doc->company->forceFill([
            'app_settings' => ['export_goods' => true],
        ])->save();
        $xml = app(BusinessDocumentUblService::class)->xml($doc->fresh(['company', 'contact', 'lines']));

        $this->assertStringContainsString('<cbc:ID>G</cbc:ID>', $xml);
        $this->assertStringContainsString('Steuerfreie Ausfuhrlieferung', $xml);
        $this->assertStringNotContainsString('<cbc:ID>O</cbc:ID>', $xml);
    }

    #[Test]
    public function normal_taxed_invoice_keeps_category_s(): void
    {
        $doc = $this->deCompanyDocument('payer', ['country' => 'DE'], 19.0);
        $doc->forceFill(['tax_total' => 19, 'total' => 119])->save();
        $xml = app(BusinessDocumentUblService::class)->xml($doc->fresh(['company', 'contact', 'lines']));

        $this->assertStringContainsString('<cbc:ID>S</cbc:ID>', $xml);
        $this->assertStringNotContainsString('<cbc:ID>AE</cbc:ID>', $xml);
        $this->assertStringNotContainsString('TaxExemptionReason', $xml);
    }
}
