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
use App\Services\Invoicing\Accounting\AccountingCsvWriter;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use App\Support\Invoicing\Accounting\ReceivedExpenseAttachment;
use App\Support\Invoicing\Accounting\ReceivedExpenseItem;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountingCsvWriterTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'user_id' => User::factory()->create()->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'registration_number' => '47615681',
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'vat_rate_default' => 23,
        ]);
    }

    private function document(Company $company, string $type, string $number, string $buyerName = 'Klient'): BusinessDocument
    {
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => $buyerName,
            'registration_number' => '12345678',
            'country' => 'SK',
        ]);
        $document = BusinessDocument::create([
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'type' => $type,
            'status' => BusinessDocumentStatus::Issued,
            'number' => $number,
            'variable_symbol' => $number,
            'currency' => 'EUR',
            'issue_date' => '2026-03-05',
            'due_date' => '2026-03-19',
            'total' => 0,
        ]);
        BusinessDocumentLine::create([
            'business_document_id' => $document->id,
            'sort_order' => 0,
            'name' => 'Služba',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 23,
            'line_total' => 0,
        ]);

        return $document->fresh(['company', 'contact', 'lines']);
    }

    /**
     * @return list<list<string>>
     */
    private function parse(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'CSV must carry a UTF-8 BOM for Excel');
        $body = substr($csv, 3);
        $this->assertStringContainsString("\r\n", $body);

        return array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            array_values(array_filter(explode("\r\n", $body), static fn (string $line): bool => $line !== '')),
        );
    }

    #[Test]
    public function issued_csv_lists_documents_with_buyer_and_totals(): void
    {
        $company = $this->company();
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument($this->document($company, 'invoice', '20260001'));

        $rows = $this->parse((new AccountingCsvWriter)->issued([$canonical]));

        $this->assertSame('number', $rows[0][0]);
        $header = array_flip($rows[0]);
        $this->assertSame('20260001', $rows[1][$header['number']]);
        $this->assertSame('invoice', $rows[1][$header['type']]);
        $this->assertSame('Klient', $rows[1][$header['buyer_name']]);
        $this->assertSame('12345678', $rows[1][$header['buyer_registration_number']]);
        $this->assertSame('2026-03-05', $rows[1][$header['issue_date']]);
        $this->assertSame('100.00', $rows[1][$header['subtotal']]);
        $this->assertSame('23.00', $rows[1][$header['tax_total']]);
        $this->assertSame('123.00', $rows[1][$header['total']]);
    }

    #[Test]
    public function formula_injection_is_neutralized_and_quotes_are_escaped(): void
    {
        $company = $this->company();
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument(
            $this->document($company, 'invoice', '20260002', '=HYPERLINK("evil") "Corp"'),
        );

        $csv = (new AccountingCsvWriter)->issued([$canonical]);

        $this->assertStringContainsString('"\'=HYPERLINK(""evil"") ""Corp"""', $csv);
        $rows = $this->parse($csv);
        $this->assertSame('\'=HYPERLINK("evil") "Corp"', $rows[1][array_flip($rows[0])['buyer_name']]);
    }

    #[Test]
    public function received_csv_counts_attachments(): void
    {
        $expense = new ReceivedExpenseItem(
            internalNumber: 'EXP1',
            externalNumber: 'IN-1',
            supplierName: 'Supplier',
            variableSymbol: '1',
            constantSymbol: null,
            issueDate: new DateTimeImmutable('2026-03-02'),
            deliveryDate: null,
            dueDate: null,
            paidAt: new DateTimeImmutable('2026-03-10 12:00:00'),
            total: '88.50',
            currency: 'EUR',
            status: BusinessExpenseStatus::Paid,
            attachments: [
                new ReceivedExpenseAttachment('a.pdf', 'application/pdf', 'x'),
                new ReceivedExpenseAttachment('b.xml', 'application/xml', 'y'),
            ],
        );

        $rows = $this->parse((new AccountingCsvWriter)->received([$expense]));
        $header = array_flip($rows[0]);

        $this->assertSame('EXP1', $rows[1][$header['internal_number']]);
        $this->assertSame('paid', $rows[1][$header['status']]);
        $this->assertSame('2026-03-10', $rows[1][$header['paid_at']]);
        $this->assertSame('88.50', $rows[1][$header['total']]);
        $this->assertSame('2', $rows[1][$header['attachments']]);
    }

    #[Test]
    public function vat_summary_aggregates_per_rate_and_subtracts_credit_notes(): void
    {
        $company = $this->company();
        $builder = app(CanonicalInvoiceBuilder::class);
        $issued = [
            $builder->fromDocument($this->document($company, 'invoice', 'F1')),
            $builder->fromDocument($this->document($company, 'invoice', 'F2')),
            $builder->fromDocument($this->document($company, 'credit_note', 'D1')),
        ];

        $rows = $this->parse((new AccountingCsvWriter)->vatSummary($issued));

        $this->assertCount(2, $rows);
        $this->assertSame(['EUR', '23.00', '100.00', '23.00', '123.00', '3'], $rows[1]);
    }

    #[Test]
    public function negative_vat_totals_stay_numeric_without_the_injection_guard(): void
    {
        $company = $this->company();
        $builder = app(CanonicalInvoiceBuilder::class);
        $issued = [
            $builder->fromDocument($this->document($company, 'invoice', 'F1')),
            $builder->fromDocument($this->document($company, 'credit_note', 'D1')),
            $builder->fromDocument($this->document($company, 'credit_note', 'D2')),
        ];

        $csv = (new AccountingCsvWriter)->vatSummary($issued);
        $rows = $this->parse($csv);

        // Credit notes exceed invoices: amounts go negative and must not be
        // prefixed with the formula-guard apostrophe.
        $this->assertSame(['EUR', '23.00', '-100.00', '-23.00', '-123.00', '3'], $rows[1]);
        $this->assertStringNotContainsString("'-", $csv);
    }
}
