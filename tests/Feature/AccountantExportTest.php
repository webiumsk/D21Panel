<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessExpenseStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseAttachment;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class AccountantExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Company}
     */
    private function proUserWithCompany(): array
    {
        $plan = SubscriptionPlan::firstOrCreate(['code' => 'pro'], [
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
            'plan_id' => $plan->id,
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
            'street' => 'Bohunice 47',
            'city' => 'Bohunice',
            'postal_code' => '93505',
            'country' => 'SK',
            'iban' => 'SK3112000000198747547509',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'vat_rate_default' => 23,
        ]);

        return [$user, $company];
    }

    private function document(Company $company, string $number, string $issueDate, BusinessDocumentStatus $status = BusinessDocumentStatus::Issued): BusinessDocument
    {
        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Klient s.r.o.',
            'registration_number' => '12345678',
            'country' => 'SK',
        ]);
        $document = BusinessDocument::create([
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'type' => 'invoice',
            'status' => $status,
            'number' => $status === BusinessDocumentStatus::Draft ? null : $number,
            'currency' => 'EUR',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'total' => 0,
            'payment_bank_enabled' => true,
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

        return $document;
    }

    private function expense(Company $company, string $number, string $issueDate, BusinessExpenseStatus $status = BusinessExpenseStatus::Recorded, bool $withAttachment = true): BusinessExpense
    {
        $expense = BusinessExpense::create([
            'company_id' => $company->id,
            'status' => $status,
            'internal_number' => $number,
            'external_number' => 'IN-'.$number,
            'title' => 'Supplier s.r.o.',
            'issue_date' => $issueDate,
            'total' => 88.5,
            'currency' => 'EUR',
        ]);
        if ($withAttachment) {
            Storage::disk('local')->put("expenses/{$expense->id}/faktura.pdf", '%PDF-1.4 fake');
            BusinessExpenseAttachment::create([
                'business_expense_id' => $expense->id,
                'disk' => 'local',
                'path' => "expenses/{$expense->id}/faktura.pdf",
                'original_filename' => 'faktura.pdf',
                'mime' => 'application/pdf',
                'size_bytes' => 13,
            ]);
        }

        return $expense;
    }

    /**
     * @return list<string>
     */
    private function zipEntries(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'acc-');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($path);
        sort($names);

        return $names;
    }

    #[Test]
    public function owner_downloads_a_package_for_the_period_with_attachments(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->proUserWithCompany();
        $this->document($company, '20260001', '2026-03-05');
        $this->document($company, '20260002', '2026-04-05'); // outside the period
        $this->document($company, 'DRAFT', '2026-03-06', BusinessDocumentStatus::Draft);
        $this->expense($company, 'EXP1', '2026-03-02');
        $this->expense($company, 'EXP2', '2026-03-03', BusinessExpenseStatus::Cancelled, false);

        $response = $this->actingAs($user)->get(
            "/api/invoicing/companies/{$company->id}/accountant-export?from=2026-03-01&to=2026-03-31&include_ubl=1",
        );

        $response->assertOk();
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('accountant-webium-sro-2026-03-01_2026-03-31.zip', (string) $response->headers->get('content-disposition'));

        $this->assertSame([
            'csv/issued.csv',
            'csv/received.csv',
            'csv/vat_summary.csv',
            'issued/20260001.isdoc',
            'issued/20260001.pdf',
            'issued/20260001.ubl.xml',
            'manifest.txt',
            'pohoda/invoices.xml',
            'received/EXP1/faktura.pdf',
        ], $this->zipEntries($response->getContent()));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company.accountant_export',
            'target_id' => $company->id,
        ]);
    }

    #[Test]
    public function options_narrow_the_package_and_missing_attachment_files_are_skipped(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->proUserWithCompany();
        $this->document($company, '20260001', '2026-03-05');
        $expense = $this->expense($company, 'EXP1', '2026-03-02');
        Storage::disk('local')->delete("expenses/{$expense->id}/faktura.pdf");

        $response = $this->actingAs($user)->get(
            "/api/invoicing/companies/{$company->id}/accountant-export?from=2026-03-01&to=2026-03-31&formats[]=csv&include_pdf=0&include_isdoc=0",
        );

        $response->assertOk();
        $this->assertSame(
            ['csv/issued.csv', 'csv/received.csv', 'csv/vat_summary.csv', 'manifest.txt'],
            $this->zipEntries($response->getContent()),
        );
    }

    #[Test]
    public function empty_period_returns_a_validation_error(): void
    {
        [$user, $company] = $this->proUserWithCompany();

        $this->actingAs($user)
            ->getJson("/api/invoicing/companies/{$company->id}/accountant-export?from=2025-01-01&to=2025-01-31")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    #[Test]
    public function period_is_validated(): void
    {
        [$user, $company] = $this->proUserWithCompany();

        $this->actingAs($user)
            ->getJson("/api/invoicing/companies/{$company->id}/accountant-export?from=2026-03-31&to=2026-03-01")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);

        $this->actingAs($user)
            ->getJson("/api/invoicing/companies/{$company->id}/accountant-export?from=2026-03-01&to=2026-03-31&formats[]=kros")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['formats.0']);
    }

    #[Test]
    public function too_many_rows_in_the_period_return_413(): void
    {
        [$user, $company] = $this->proUserWithCompany();
        config(['invoicing.accountant_export_max_rows' => 1]);
        $this->document($company, '20260001', '2026-03-05');
        $this->document($company, '20260002', '2026-03-06');

        $this->actingAs($user)
            ->getJson("/api/invoicing/companies/{$company->id}/accountant-export?from=2026-03-01&to=2026-03-31")
            ->assertStatus(413);
    }

    #[Test]
    public function other_users_cannot_export_the_company(): void
    {
        [, $company] = $this->proUserWithCompany();
        [$intruder] = $this->proUserWithCompany();
        $this->document($company, '20260001', '2026-03-05');

        $this->actingAs($intruder)
            ->getJson("/api/invoicing/companies/{$company->id}/accountant-export?from=2026-03-01&to=2026-03-31")
            ->assertStatus(403);
    }
}
