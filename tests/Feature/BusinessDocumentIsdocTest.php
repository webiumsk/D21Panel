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
use App\Services\Invoicing\BusinessDocumentIsdocService;
use App\Services\Invoicing\BusinessDocumentPdfService;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessDocumentIsdocTest extends TestCase
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
            'street' => 'Bohunice 47',
            'city' => 'Bohunice',
            'postal_code' => '93505',
            'country' => 'SK',
            'iban' => 'SK3112000000198747547509',
            'bank_name' => 'Tatra banka',
            'bank_account' => '8747547509',
            'bank_code' => '1100',
            'vat_payer' => false,
        ]);

        return [$user, $company];
    }

    #[Test]
    public function isdoc_xml_contains_invoice_root_for_slovak_company(): void
    {
        [, $company] = $this->proUserWithCompany();

        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Klient s.r.o.',
            'registration_number' => '12345678',
            'street' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
            'country' => 'SK',
        ]);

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260099',
            'variable_symbol' => '20260099',
            'total' => 100,
            'subtotal' => 100,
            'tax_total' => 0,
            'currency' => 'EUR',
            'issue_date' => now(),
            'due_date' => now()->addDays(14),
            'payment_bank_enabled' => true,
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Služba',
            'quantity' => 1,
            'unit' => 'ks.',
            'unit_price' => 100,
            'line_total' => 100,
            'tax_rate' => 0,
        ]);

        $xml = app(BusinessDocumentIsdocService::class)->xml($doc->fresh(['company', 'contact', 'lines']));

        $this->assertStringContainsString('http://isdoc.cz/namespace/2013', $xml);
        $this->assertStringContainsString('<ID>20260099</ID>', $xml);
        $this->assertStringContainsString('<ID>47615681</ID>', $xml);
    }

    #[Test]
    public function issued_eu_pdf_contains_embedded_isdoc_attachment(): void
    {
        [$user, $company] = $this->proUserWithCompany();

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260100',
            'total' => 50,
            'subtotal' => 50,
            'tax_total' => 0,
            'currency' => 'EUR',
            'issue_date' => now(),
            'due_date' => now()->addDays(14),
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Položka',
            'quantity' => 1,
            'unit' => 'ks.',
            'unit_price' => 50,
            'line_total' => 50,
        ]);

        $binary = app(BusinessDocumentPdfService::class)->renderBinary($doc->fresh(['company', 'contact', 'lines']));

        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertStringContainsString('invoice.isdoc', $binary);
    }

    #[Test]
    public function authenticated_user_can_download_isdoc_via_web_route(): void
    {
        [$user, $company] = $this->proUserWithCompany();

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260102',
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
            'unit' => 'ks.',
            'unit_price' => 50,
            'line_total' => 50,
        ]);

        $response = $this->actingAs($user)->get(
            "/invoicing/companies/{$company->id}/documents/{$doc->id}/isdoc"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('http://isdoc.cz/namespace/2013', $response->getContent());
    }

    #[Test]
    public function isdoc_xml_totals_match_canonical_builder(): void
    {
        [, $company] = $this->proUserWithCompany();
        $company->update(['vat_payer' => true, 'vat_rate_default' => 23]);

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260103',
            'subtotal' => 200,
            'tax_total' => 46,
            'total' => 246,
            'currency' => 'EUR',
            'issue_date' => now(),
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'A',
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 23,
            'line_total' => 123,
        ]);
        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 1,
            'name' => 'B',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'line_total' => 100,
        ]);

        $doc = $doc->fresh(['company', 'contact', 'lines']);
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument($doc);
        $xml = app(BusinessDocumentIsdocService::class)->xml($doc);

        $this->assertStringContainsString(
            '<TaxAmount>'.$canonical->taxTotal.'</TaxAmount>',
            $xml
        );
        $this->assertStringContainsString(
            '<TaxExclusiveAmount>'.$canonical->subtotal.'</TaxExclusiveAmount>',
            $xml
        );
        $this->assertStringContainsString(
            '<TaxInclusiveAmount>'.$canonical->total.'</TaxInclusiveAmount>',
            $xml
        );
    }

    #[Test]
    public function isdoc_embed_in_pdf_can_be_disabled_without_blocking_file_export(): void
    {
        [, $company] = $this->proUserWithCompany();
        $company->update([
            'app_settings' => array_merge($company->app_settings ?? [], ['embed_isdoc_in_pdf' => false]),
        ]);

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260101',
            'total' => 10,
            'subtotal' => 10,
            'tax_total' => 0,
            'currency' => 'EUR',
            'issue_date' => now(),
        ]);

        BusinessDocumentLine::create([
            'business_document_id' => $doc->id,
            'sort_order' => 0,
            'name' => 'Položka',
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10,
        ]);

        $service = app(BusinessDocumentIsdocService::class);
        $doc = $doc->fresh(['company', 'contact', 'lines']);

        $this->assertTrue($service->supports($doc));
        $this->assertFalse($service->supportsEmbedInPdf($doc));
        $this->assertStringContainsString('isdoc.cz', $service->xml($doc));
    }
}
