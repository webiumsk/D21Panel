<?php

namespace Tests\Unit\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessExpenseStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\User;
use App\Services\Invoicing\Accounting\PohodaXmlWriter;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use App\Support\Invoicing\Accounting\PohodaVatRateMapper;
use App\Support\Invoicing\Accounting\ReceivedExpenseItem;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PohodaXmlWriterTest extends TestCase
{
    use RefreshDatabase;

    private function skCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'registration_number' => '47615681',
            'tax_id' => '2023980035',
            'vat_number' => 'SK2023980035',
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'vat_rate_default' => 23,
        ], $overrides));
    }

    private function invoice(Company $company, array $overrides = [], array $lines = []): BusinessDocument
    {
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Klient s.r.o.',
            'registration_number' => '12345678',
            'tax_id' => '2020202020',
            'vat_id' => 'SK2020202020',
            'street' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
            'country' => 'SK',
        ]);

        $document = BusinessDocument::create(array_merge([
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260001',
            'variable_symbol' => '20260001',
            'constant_symbol' => '0308',
            'title' => 'Konzultácie',
            'currency' => 'EUR',
            'issue_date' => '2026-03-05',
            'delivery_date' => '2026-03-04',
            'due_date' => '2026-03-19',
            'total' => 0,
            'payment_bank_enabled' => true,
        ], $overrides));

        $lines = $lines ?: [
            ['name' => 'Služba', 'quantity' => 2, 'unit' => 'hod', 'unit_price' => 100, 'tax_rate' => 23],
            ['name' => 'Kniha', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 50, 'tax_rate' => 5],
        ];
        foreach ($lines as $index => $line) {
            BusinessDocumentLine::create(array_merge([
                'business_document_id' => $document->id,
                'sort_order' => $index,
                'line_total' => 0,
            ], $line));
        }

        return $document->fresh(['company', 'contact', 'lines']);
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Pohoda XML must be well-formed');
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('dat', 'http://www.stormware.cz/schema/version_2/data.xsd');
        $xpath->registerNamespace('inv', 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $xpath->registerNamespace('typ', 'http://www.stormware.cz/schema/version_2/type.xsd');

        return $xpath;
    }

    private function value(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        return $nodes !== false && $nodes->length > 0 ? $nodes->item(0)?->textContent : null;
    }

    #[Test]
    public function issued_invoice_maps_header_lines_and_vat_buckets(): void
    {
        $company = $this->skCompany();
        $document = $this->invoice($company);
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument($document);

        $writer = new PohodaXmlWriter;
        $xml = $writer->write($company, [$canonical], [], 'satflux-test');
        $xpath = $this->xpath($xml);

        $this->assertSame('47615681', $this->value($xpath, '/dat:dataPack/@ico'));
        $this->assertSame('2.0', $this->value($xpath, '/dat:dataPack/@version'));
        $this->assertSame('issuedInvoice', $this->value($xpath, '//inv:invoiceHeader/inv:invoiceType'));
        $this->assertSame('20260001', $this->value($xpath, '//inv:invoiceHeader/inv:number/typ:numberRequested'));
        $this->assertSame('20260001', $this->value($xpath, '//inv:invoiceHeader/inv:symVar'));
        $this->assertSame('0308', $this->value($xpath, '//inv:invoiceHeader/inv:symConst'));
        $this->assertSame('2026-03-05', $this->value($xpath, '//inv:invoiceHeader/inv:date'));
        $this->assertSame('2026-03-04', $this->value($xpath, '//inv:invoiceHeader/inv:dateTax'));
        $this->assertSame('2026-03-19', $this->value($xpath, '//inv:invoiceHeader/inv:dateDue'));
        $this->assertSame('Klient s.r.o.', $this->value($xpath, '//inv:partnerIdentity/typ:address/typ:company'));
        $this->assertSame('12345678', $this->value($xpath, '//inv:partnerIdentity/typ:address/typ:ico'));
        $this->assertSame('SK2020202020', $this->value($xpath, '//inv:partnerIdentity/typ:address/typ:icDph'));

        // Lines: 23 % -> high, 5 % -> third (SK 2026 rate list 23/19/5).
        $this->assertSame('high', $this->value($xpath, '//inv:invoiceItem[1]/inv:rateVAT'));
        $this->assertSame('200.00', $this->value($xpath, '//inv:invoiceItem[1]/inv:homeCurrency/typ:price'));
        $this->assertSame('46.00', $this->value($xpath, '//inv:invoiceItem[1]/inv:homeCurrency/typ:priceVAT'));
        $this->assertSame('third', $this->value($xpath, '//inv:invoiceItem[2]/inv:rateVAT'));
        $this->assertSame('hod', $this->value($xpath, '//inv:invoiceItem[1]/inv:unit'));

        // Summary buckets add up to the canonical totals.
        $this->assertSame('200.00', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:priceHigh'));
        $this->assertSame('46.00', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:priceHighVAT'));
        $this->assertSame('50.00', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:price3'));
        $this->assertSame('2.50', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:price3VAT'));
        $this->assertSame('0.00', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:priceLow'));
        $this->assertNull($this->value($xpath, '//inv:invoiceSummary/inv:foreignCurrency'));
        $this->assertSame([], $writer->warnings());
    }

    #[Test]
    public function credit_note_and_proforma_use_pohoda_document_types(): void
    {
        $company = $this->skCompany();
        $builder = app(CanonicalInvoiceBuilder::class);
        $credit = $builder->fromDocument($this->invoice($company, ['type' => 'credit_note', 'number' => 'D1']));
        $proforma = $builder->fromDocument($this->invoice($company, ['type' => 'proforma', 'number' => 'Z1']));

        $xpath = $this->xpath((new PohodaXmlWriter)->write($company, [$credit, $proforma], []));

        $this->assertSame('issuedCreditNotice', $this->value($xpath, '//dat:dataPackItem[1]//inv:invoiceType'));
        $this->assertSame('issuedAdvanceInvoice', $this->value($xpath, '//dat:dataPackItem[2]//inv:invoiceType'));
    }

    #[Test]
    public function non_vat_payer_puts_everything_into_the_none_bucket(): void
    {
        $company = $this->skCompany(['vat_payer' => false, 'vat_status' => 'none']);
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument(
            $this->invoice($company, [], [['name' => 'Služba', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]]),
        );

        $xpath = $this->xpath((new PohodaXmlWriter)->write($company, [$canonical], []));

        $this->assertSame('none', $this->value($xpath, '//inv:invoiceItem[1]/inv:rateVAT'));
        $this->assertSame('100.00', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:priceNone'));
        $this->assertSame('0.00', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:priceHigh'));
    }

    #[Test]
    public function foreign_currency_document_goes_to_foreign_block_with_a_warning(): void
    {
        $company = $this->skCompany();
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument(
            $this->invoice($company, ['currency' => 'USD', 'number' => 'USD1']),
        );

        $writer = new PohodaXmlWriter;
        $xpath = $this->xpath($writer->write($company, [$canonical], []));

        $this->assertSame('USD', $this->value($xpath, '//inv:invoiceSummary/inv:foreignCurrency/typ:currency/typ:ids'));
        $this->assertSame('1', $this->value($xpath, '//inv:invoiceSummary/inv:foreignCurrency/typ:rate'));
        $this->assertSame($canonical->total, $this->value($xpath, '//inv:invoiceSummary/inv:foreignCurrency/typ:priceSum'));
        $this->assertNotNull($this->value($xpath, '//inv:invoiceItem[1]/inv:foreignCurrency/typ:price'));
        $this->assertStringContainsString('USD1', $writer->warnings()[0]);
    }

    #[Test]
    public function received_expense_is_exported_as_received_invoice_header_only(): void
    {
        $company = $this->skCompany();
        $expense = new ReceivedExpenseItem(
            internalNumber: 'EXP20260007',
            externalNumber: 'IN-7788',
            supplierName: 'Supplier s.r.o.',
            variableSymbol: '7788',
            constantSymbol: null,
            issueDate: new DateTimeImmutable('2026-03-02'),
            deliveryDate: null,
            dueDate: new DateTimeImmutable('2026-03-16'),
            paidAt: null,
            total: '88.50',
            currency: 'EUR',
            status: BusinessExpenseStatus::Recorded,
            supplierRegistrationNumber: '11223344',
        );

        $xpath = $this->xpath((new PohodaXmlWriter)->write($company, [], [$expense]));

        $this->assertSame('receivedInvoice', $this->value($xpath, '//inv:invoiceType'));
        $this->assertSame('EXP20260007', $this->value($xpath, '//inv:number/typ:numberRequested'));
        $this->assertSame('IN-7788', $this->value($xpath, '//inv:originalDocument'));
        $this->assertSame('7788', $this->value($xpath, '//inv:symVar'));
        $this->assertSame('2026-03-02', $this->value($xpath, '//inv:dateTax'));
        $this->assertSame('Supplier s.r.o.', $this->value($xpath, '//inv:partnerIdentity/typ:address/typ:company'));
        $this->assertSame('11223344', $this->value($xpath, '//inv:partnerIdentity/typ:address/typ:ico'));
        $this->assertSame('88.50', $this->value($xpath, '//inv:invoiceSummary/inv:homeCurrency/typ:priceNone'));
        $this->assertNull($this->value($xpath, '//inv:invoiceDetail'));
    }

    #[Test]
    public function vat_rate_mapper_follows_the_jurisdiction_rate_list(): void
    {
        $sk = new PohodaVatRateMapper(CompanyJurisdiction::EuSk);
        $this->assertSame('high', $sk->bucket(23));
        $this->assertSame('low', $sk->bucket(19));
        $this->assertSame('third', $sk->bucket(5));
        $this->assertSame('none', $sk->bucket(0));
        $this->assertSame('none', $sk->bucket(20)); // pre-2025 rate - no bucket
        $this->assertSame(['20.00'], $sk->unmapped());

        $cz = new PohodaVatRateMapper(CompanyJurisdiction::EuCz);
        $this->assertSame('high', $cz->bucket(21));
        $this->assertSame('low', $cz->bucket(12));
        $this->assertSame('none', $cz->bucket(15));
    }

    #[Test]
    public function unknown_vat_rate_is_reported_as_a_warning(): void
    {
        $company = $this->skCompany();
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument(
            $this->invoice($company, [], [['name' => 'Stará sadzba', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 20]]),
        );

        $writer = new PohodaXmlWriter;
        $xpath = $this->xpath($writer->write($company, [$canonical], []));

        $this->assertSame('none', $this->value($xpath, '//inv:invoiceItem[1]/inv:rateVAT'));
        $this->assertCount(1, $writer->warnings());
        $this->assertStringContainsString('20.00%', $writer->warnings()[0]);
    }
}
