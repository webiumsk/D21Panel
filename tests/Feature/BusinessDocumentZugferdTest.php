<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\User;
use App\Services\Invoicing\BusinessDocumentZugferdService;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessDocumentZugferdTest extends TestCase
{
    use RefreshDatabase;

    private function deDocument(?array $contactAttributes = null, float $taxRate = 19.0): BusinessDocument
    {
        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Muster GmbH',
            'jurisdiction' => CompanyJurisdiction::EuDe,
            'country' => 'DE',
            'default_currency' => 'EUR',
            'vat_number' => 'DE123456789',
            'iban' => 'DE89370400440532013000',
            'street' => 'Hauptstraße 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'vat_rate_default' => 19,
        ]);

        $contact = null;
        if ($contactAttributes !== null) {
            $contact = CompanyContact::create(array_merge(
                ['company_id' => $company->id, 'name' => 'Buyer GmbH'],
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
            'tax_total' => $taxRate > 0 ? 19 : 0,
            'total' => $taxRate > 0 ? 119 : 100,
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
            'line_total' => $taxRate > 0 ? 119 : 100,
        ]);

        return $doc->fresh(['company', 'contact', 'lines']);
    }

    #[Test]
    public function supports_only_german_companies(): void
    {
        $doc = $this->deDocument(['country' => 'DE']);
        $service = app(BusinessDocumentZugferdService::class);

        $this->assertTrue($service->supports($doc));
        $this->assertTrue($service->supportsEmbedInPdf($doc));

        $doc->company->forceFill(['jurisdiction' => CompanyJurisdiction::EuSk])->save();
        $this->assertFalse($service->supports($doc->fresh(['company'])));
    }

    #[Test]
    public function embed_toggle_can_switch_the_hybrid_off(): void
    {
        $doc = $this->deDocument(['country' => 'DE']);
        $doc->company->forceFill(['app_settings' => ['embed_zugferd_in_pdf' => false]])->save();

        $this->assertFalse(
            app(BusinessDocumentZugferdService::class)->supportsEmbedInPdf($doc->fresh(['company'])),
        );
    }

    #[Test]
    public function cii_xml_carries_the_en16931_profile_and_document_data(): void
    {
        $doc = $this->deDocument(['country' => 'DE']);
        $xml = app(BusinessDocumentZugferdService::class)->xml($doc);

        $this->assertStringContainsString('CrossIndustryInvoice', $xml);
        $this->assertStringContainsString('urn:cen.eu:en16931:2017', $xml);
        $this->assertStringContainsString('RE20260001', $xml);
        $this->assertStringContainsString('Muster GmbH', $xml);
        $this->assertStringContainsString('DE123456789', $xml);
        $this->assertStringContainsString('<ram:CategoryCode>S</ram:CategoryCode>', $xml);
    }

    #[Test]
    public function reverse_charge_invoice_exports_category_ae_in_cii(): void
    {
        $doc = $this->deDocument(['country' => 'FR', 'vat_id' => 'FR12345678901'], 0.0);
        $xml = app(BusinessDocumentZugferdService::class)->xml($doc);

        $this->assertStringContainsString('<ram:CategoryCode>AE</ram:CategoryCode>', $xml);
        $this->assertStringContainsString('Steuerschuldnerschaft des Leistungsempfängers', $xml);
    }

    #[Test]
    public function embeds_factur_x_into_a_visual_pdf(): void
    {
        $doc = $this->deDocument(['country' => 'DE']);

        // A real (dompdf-generated) carrier so FPDI parses an actual PDF.
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<h1>Rechnung RE20260001</h1>');
        $dompdf->render();
        $visual = tempnam(sys_get_temp_dir(), 'zug');
        $output = tempnam(sys_get_temp_dir(), 'zug');
        $this->assertNotFalse($visual);
        $this->assertNotFalse($output);
        file_put_contents($visual, $dompdf->output());

        try {
            app(BusinessDocumentZugferdService::class)->embedInPdf($visual, $doc, $output);

            $binary = (string) file_get_contents($output);
            $this->assertStringStartsWith('%PDF', $binary);
            $this->assertStringContainsString('factur-x.xml', $binary);
        } finally {
            @unlink($visual);
            @unlink($output);
        }
    }
}
