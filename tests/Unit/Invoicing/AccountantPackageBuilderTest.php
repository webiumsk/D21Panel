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
use App\Services\Invoicing\Accounting\AccountantPackageBuilder;
use App\Services\Invoicing\Accounting\AccountantPackageOptions;
use App\Support\Invoicing\Accounting\ReceivedExpenseAttachment;
use App\Support\Invoicing\Accounting\ReceivedExpenseItem;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class AccountantPackageBuilderTest extends TestCase
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
            'tax_id' => '2023980035',
            'street' => 'Bohunice 47',
            'city' => 'Bohunice',
            'postal_code' => '93505',
            'country' => 'SK',
            'iban' => 'SK3112000000198747547509',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'vat_rate_default' => 23,
        ]);
    }

    private function document(Company $company, string $number, BusinessDocumentStatus $status = BusinessDocumentStatus::Issued): BusinessDocument
    {
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Klient s.r.o.',
            'registration_number' => '12345678',
            'street' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
            'country' => 'SK',
        ]);
        $document = BusinessDocument::create([
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'type' => 'invoice',
            'status' => $status,
            'number' => $status === BusinessDocumentStatus::Draft ? null : $number,
            'variable_symbol' => $number,
            'currency' => 'EUR',
            'issue_date' => '2026-03-05',
            'due_date' => '2026-03-19',
            'total' => 0,
            'payment_bank_enabled' => true,
        ]);
        BusinessDocumentLine::create([
            'business_document_id' => $document->id,
            'sort_order' => 0,
            'name' => 'Služba',
            'quantity' => 1,
            'unit' => 'ks',
            'unit_price' => 100,
            'tax_rate' => 23,
            'line_total' => 0,
        ]);

        return $document->fresh(['company', 'contact', 'lines']);
    }

    private function expense(string $number, array $attachments = []): ReceivedExpenseItem
    {
        return new ReceivedExpenseItem(
            internalNumber: $number,
            externalNumber: 'IN-'.$number,
            supplierName: 'Supplier s.r.o.',
            variableSymbol: '1',
            constantSymbol: null,
            issueDate: new DateTimeImmutable('2026-03-02'),
            deliveryDate: null,
            dueDate: null,
            paidAt: null,
            total: '88.50',
            currency: 'EUR',
            status: BusinessExpenseStatus::Recorded,
            attachments: $attachments,
        );
    }

    /**
     * @return array<string, string> entry name => content
     */
    private function entries(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pkg-');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $entries;
    }

    #[Test]
    public function full_package_contains_every_layer(): void
    {
        $company = $this->company();
        $documents = [$this->document($company, '20260001'), $this->document($company, '20260002')];
        $expenses = [
            $this->expense('EXP1', [
                new ReceivedExpenseAttachment('faktura.pdf', 'application/pdf', '%PDF-1.4 fake'),
                new ReceivedExpenseAttachment('../evil name.xml', 'application/xml', '<x/>'),
            ]),
            $this->expense('EXP2'),
        ];

        $binary = app(AccountantPackageBuilder::class)->build(
            $company,
            $documents,
            $expenses,
            new AccountantPackageOptions(
                includeUbl: true,
                from: new DateTimeImmutable('2026-03-01'),
                to: new DateTimeImmutable('2026-03-31'),
            ),
        );
        $entries = $this->entries($binary);

        $names = array_keys($entries);
        sort($names);
        $this->assertSame([
            'csv/issued.csv',
            'csv/received.csv',
            'csv/vat_summary.csv',
            'issued/20260001.isdoc',
            'issued/20260001.pdf',
            'issued/20260001.ubl.xml',
            'issued/20260002.isdoc',
            'issued/20260002.pdf',
            'issued/20260002.ubl.xml',
            'manifest.txt',
            'pohoda/invoices.xml',
            'received/EXP1/evil_name.xml',
            'received/EXP1/faktura.pdf',
        ], $names);

        $this->assertStringStartsWith('%PDF', $entries['issued/20260001.pdf']);
        $this->assertStringContainsString('<inv:invoiceType>issuedInvoice</inv:invoiceType>', $entries['pohoda/invoices.xml']);
        $this->assertStringContainsString('<inv:invoiceType>receivedInvoice</inv:invoiceType>', $entries['pohoda/invoices.xml']);
        $this->assertStringContainsString('Invoice', $entries['issued/20260001.isdoc']);
        $this->assertStringContainsString('Vystavene doklady: 2', $entries['manifest.txt']);
        $this->assertStringContainsString('Prijate doklady (naklady): 2', $entries['manifest.txt']);
        $this->assertStringContainsString('Prilohy nakladov: 2', $entries['manifest.txt']);
        $this->assertStringContainsString('2026-03-01 az 2026-03-31', $entries['manifest.txt']);
        $this->assertStringContainsString('%PDF-1.4 fake', $entries['received/EXP1/faktura.pdf']);
    }

    #[Test]
    public function options_control_which_layers_are_written_and_drafts_are_skipped(): void
    {
        $company = $this->company();
        $documents = [
            $this->document($company, '20260001'),
            $this->document($company, 'DRAFT', BusinessDocumentStatus::Draft),
        ];

        $binary = app(AccountantPackageBuilder::class)->build(
            $company,
            $documents,
            [$this->expense('EXP1', [new ReceivedExpenseAttachment('a.pdf', 'application/pdf', 'x')])],
            new AccountantPackageOptions(
                formats: [AccountantPackageOptions::FORMAT_CSV],
                includePdf: false,
                includeIsdoc: false,
                includeUbl: false,
                includeExpenseAttachments: false,
            ),
        );
        $names = array_keys($this->entries($binary));
        sort($names);

        $this->assertSame(['csv/issued.csv', 'csv/received.csv', 'csv/vat_summary.csv', 'manifest.txt'], $names);
        $issuedCsv = $this->entries($binary)['csv/issued.csv'];
        $this->assertStringContainsString('20260001', $issuedCsv);
        $this->assertStringNotContainsString('DRAFT', $issuedCsv);
    }

    #[Test]
    public function duplicate_numbers_get_unique_zip_entries(): void
    {
        $company = $this->company();
        // Distinct numbers that sanitize to the same ZIP segment.
        $documents = [$this->document($company, 'X/1'), $this->document($company, 'X_1')];

        $names = array_keys($this->entries(app(AccountantPackageBuilder::class)->build(
            $company,
            $documents,
            [],
            new AccountantPackageOptions(formats: [], includeIsdoc: false),
        )));
        sort($names);

        $this->assertSame(['issued/X_1-2.pdf', 'issued/X_1.pdf', 'manifest.txt'], $names);
    }

    #[Test]
    public function empty_selection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AccountantPackageBuilder::class)->build($this->company(), [], [], new AccountantPackageOptions);
    }

    #[Test]
    public function options_reject_unknown_formats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AccountantPackageOptions(formats: ['kros']);
    }

    #[Test]
    public function options_reject_a_package_with_nothing_to_write(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AccountantPackageOptions(
            formats: [],
            includePdf: false,
            includeIsdoc: false,
            includeUbl: false,
            includeExpenseAttachments: false,
        );
    }

    #[Test]
    public function options_reject_malformed_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountantPackageOptions::fromArray(['from' => 'not-a-date']);
    }

    #[Test]
    public function options_from_array_parses_dates_and_flags(): void
    {
        $options = AccountantPackageOptions::fromArray([
            'formats' => ['csv'],
            'include_pdf' => false,
            'include_ubl' => true,
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ]);

        $this->assertFalse($options->wantsPohoda());
        $this->assertTrue($options->wantsCsv());
        $this->assertFalse($options->includePdf);
        $this->assertTrue($options->includeIsdoc);
        $this->assertTrue($options->includeUbl);
        $this->assertSame('2026-01-01', $options->from?->format('Y-m-d'));
        $this->assertSame('2026-12-31', $options->to?->format('Y-m-d'));
    }
}
