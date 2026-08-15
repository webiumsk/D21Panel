<?php

namespace Tests\Feature;

use App\Enums\BankImportSource;
use App\Enums\BankTransactionDirection;
use App\Enums\BankTransactionMatchStatus;
use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BankTransaction;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\BankImport\CsvBankParser;
use App\Services\Invoicing\BankStatementImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BankPaymentMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

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
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);
        $this->user = User::factory()->create();
        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $this->company = Company::create([
            'user_id' => $this->user->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]);
    }

    #[Test]
    public function csv_import_auto_matches_invoice_by_variable_symbol(): void
    {
        BusinessDocument::create([
            'company_id' => $this->company->id,
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260042',
            'variable_symbol' => '20260042',
            'currency' => 'EUR',
            'total' => 150.50,
            'issue_date' => now(),
        ]);

        $csv = implode("\n", [
            'datum;suma;mena;vs',
            '01.06.2026;150,50;EUR;20260042',
        ]);

        $file = UploadedFile::fake()->createWithContent('export.csv', $csv);

        $response = $this->actingAs($this->user)->postJson(
            "/api/invoicing/companies/{$this->company->id}/bank-transactions/import",
            ['file' => $file],
        );

        $response->assertOk();
        $response->assertJsonPath('data.imported', 1);
        $response->assertJsonPath('data.auto_matched', 1);

        $doc = BusinessDocument::first();
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);

        $list = $this->actingAs($this->user)->getJson(
            "/api/invoicing/companies/{$this->company->id}/documents",
        );
        $list->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $doc->id);
        $this->assertNotNull($row['bank_match'] ?? null);
        $this->assertSame($doc->id, $row['bank_match']['business_document_id'] ?? null);
    }

    #[Test]
    public function manual_match_marks_invoice_paid(): void
    {
        $doc = BusinessDocument::create([
            'company_id' => $this->company->id,
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260099',
            'variable_symbol' => '20260099',
            'currency' => 'EUR',
            'total' => 200,
            'issue_date' => now(),
        ]);

        $import = $this->actingAs($this->user)->postJson(
            "/api/invoicing/companies/{$this->company->id}/bank-transactions/import",
            [
                'file' => UploadedFile::fake()->createWithContent('x.csv', "datum;suma;mena;vs\n01.06.2026;200,00;EUR;88888888"),
            ],
        );
        $import->assertOk();
        $txId = BankTransaction::first()->id;

        $match = $this->actingAs($this->user)->postJson(
            "/api/invoicing/companies/{$this->company->id}/bank-transactions/{$txId}/match",
            ['business_document_id' => $doc->id],
        );
        $match->assertOk();

        $doc->refresh();
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);
    }

    #[Test]
    public function bank_transaction_index_includes_summary_totals(): void
    {
        $this->company->update([
            'iban' => 'SK31 1200 0000 1987 4269 76',
            'bank_name' => 'Tatra banka',
        ]);

        $csv = implode("\n", [
            'datum;suma;mena;vs;popis',
            '01.06.2026;100,00;EUR;111;Kredit na ucte',
            '02.06.2026;40,00;EUR;222;Debet na ucte',
        ]);

        $this->actingAs($this->user)->postJson(
            "/api/invoicing/companies/{$this->company->id}/bank-transactions/import",
            ['file' => UploadedFile::fake()->createWithContent('export.csv', $csv)],
        )->assertOk();

        $response = $this->actingAs($this->user)->getJson(
            "/api/invoicing/companies/{$this->company->id}/bank-transactions",
        );

        $response->assertOk();
        $response->assertJsonPath('meta.summary.credit_count', 1);
        $response->assertJsonPath('meta.summary.debit_count', 1);
        $response->assertJsonPath('meta.summary.credit_total', '100.00');
        $response->assertJsonPath('meta.summary.debit_total', '40.00');
        $response->assertJsonPath('meta.summary.balance', '60.00');
        $response->assertJsonPath('meta.summary.currency', 'EUR');
    }

    #[Test]
    public function company_summary_includes_masked_bank_account_label(): void
    {
        $this->company->update([
            'iban' => 'SK31 1200 0000 1987 4269 76',
            'bank_name' => 'Tatra banka',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/invoicing/companies/{$this->company->id}/summary",
        );

        $response->assertOk();
        $response->assertJsonPath('data.has_bank_account', true);
        $response->assertJsonPath('data.bank_account_label', 'Tatra banka ****6976');
        $response->assertJsonPath('data.default_currency', 'EUR');
    }

    #[Test]
    public function balance_snapshot_is_excluded_from_list_but_in_summary(): void
    {
        BankTransaction::create([
            'company_id' => $this->company->id,
            'booked_at' => now(),
            'amount' => 18.80,
            'currency' => 'EUR',
            'direction' => BankTransactionDirection::Credit,
            'match_status' => BankTransactionMatchStatus::Unmatched,
            'counterparty_name' => 'Platba 1100/000000-2629709868',
            'reference' => 'COD - DOBIERKA:0610182023',
            'variable_symbol' => '0610182023',
            'source' => 'email',
            'dedupe_hash' => 'movement-1',
        ]);

        BankTransaction::create([
            'company_id' => $this->company->id,
            'booked_at' => now()->subMinute(),
            'amount' => 107.13,
            'currency' => 'EUR',
            'direction' => BankTransactionDirection::Credit,
            'match_status' => BankTransactionMatchStatus::Unmatched,
            'counterparty_name' => 'Stav na účte',
            'reference' => 'Stav na ucte (ID=100626/103565-3)',
            'source' => 'email',
            'dedupe_hash' => 'balance-1',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/invoicing/companies/{$this->company->id}/bank-transactions",
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.summary.credit_count', 1);
        $response->assertJsonPath('meta.summary.credit_total', '18.80');
        $response->assertJsonPath('meta.summary.account_balance.amount', '107.13');
        $response->assertJsonPath('meta.summary.account_balance.currency', 'EUR');
    }

    #[Test]
    public function wise_csv_import_auto_matches_us_invoice_by_payment_reference(): void
    {
        $this->company->update([
            'jurisdiction' => CompanyJurisdiction::Us,
            'default_currency' => 'USD',
            'legal_name' => 'Webium LLC',
        ]);

        BusinessDocument::create([
            'company_id' => $this->company->id,
            'status' => BusinessDocumentStatus::Issued,
            'number' => 'INV-0042',
            'variable_symbol' => '0042',
            'currency' => 'USD',
            'total' => 250.00,
            'issue_date' => now(),
        ]);

        $csv = implode("\n", [
            'ID,Status,Direction,Created on,Finished on,Source name,Source amount (after fees),Source currency,Target name,Target amount (after fees),Target currency,Reference',
            'tx-1,COMPLETED,IN,01-06-2026,02-06-2026,Acme Client,250.00,USD,Webium LLC,250.00,USD,Invoice INV-0042',
        ]);

        $rows = (new CsvBankParser)->parse($csv);
        $result = app(BankStatementImportService::class)->persistRows(
            $this->company,
            $this->user,
            $rows,
            BankImportSource::Csv,
            'wise-usd.csv',
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['auto_matched']);

        $doc = BusinessDocument::first();
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);
    }

    #[Test]
    public function csv_import_auto_matches_by_bank_vs_when_reference_differs(): void
    {
        BusinessDocument::create([
            'company_id' => $this->company->id,
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260042',
            'variable_symbol' => '20260042',
            'currency' => 'EUR',
            'total' => 120.00,
            'issue_date' => now(),
        ]);

        $csv = implode("\n", [
            'datum;suma;mena;vs;referencia',
            '01.06.2026;120,00;EUR;20260042;Internal wire note',
        ]);

        $this->actingAs($this->user)
            ->post("/api/invoicing/companies/{$this->company->id}/bank-transactions/import", [
                'file' => UploadedFile::fake()->createWithContent('tb.csv', $csv),
            ])
            ->assertOk();

        $doc = BusinessDocument::first();
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);
    }

    #[Test]
    public function csv_import_auto_matches_by_reference_when_bank_vs_differs(): void
    {
        BusinessDocument::create([
            'company_id' => $this->company->id,
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260042',
            'variable_symbol' => '20260042',
            'currency' => 'EUR',
            'total' => 120.00,
            'issue_date' => now(),
        ]);

        $csv = implode("\n", [
            'datum;suma;mena;vs;referencia',
            '01.06.2026;120,00;EUR;99999999;Uhrada faktury 20260042',
        ]);

        $this->actingAs($this->user)
            ->post("/api/invoicing/companies/{$this->company->id}/bank-transactions/import", [
                'file' => UploadedFile::fake()->createWithContent('tb.csv', $csv),
            ])
            ->assertOk();

        $doc = BusinessDocument::first();
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);
    }
}
