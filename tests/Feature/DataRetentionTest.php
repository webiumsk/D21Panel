<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessExpenseStatus;
use App\Enums\CompanyJurisdiction;
use App\Enums\IntegrationDocumentInboxStatus;
use App\Models\BusinessDocument;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseAttachment;
use App\Models\Company;
use App\Models\IntegrationDocumentInbox;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\User;
use App\Services\DataRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retention_purges_old_processed_webhooks(): void
    {
        DB::table('webhook_events')->insert([
            'store_id' => null,
            'event_type' => 'InvoiceSettled',
            'payload' => json_encode(['test' => true]),
            'verified' => true,
            'processed_at' => now()->subDays(100),
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        config([
            'data_retention.webhook_events_days' => 90,
            'data_retention.audit_logs_days' => 9999,
            'data_retention.draft_documents_days' => 9999,
            'data_retention.soft_deleted_companies_days' => 9999,
            'data_retention.export_files_days' => 9999,
        ]);

        $stats = app(DataRetentionService::class)->run(dryRun: false);

        $this->assertGreaterThanOrEqual(1, $stats['webhook_events_deleted']);
        $this->assertSame(0, DB::table('webhook_events')->count());
    }

    #[Test]
    public function soft_deleted_company_is_force_deleted_after_grace(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Gone s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
        ]);
        $company->delete();
        Company::withTrashed()->where('id', $company->id)->update([
            'deleted_at' => now()->subDays(40),
        ]);

        config(['data_retention.soft_deleted_companies_days' => 30]);

        $stats = app(DataRetentionService::class)->run(dryRun: false);

        $this->assertSame(1, $stats['companies_force_deleted']);
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    #[Test]
    public function stale_draft_documents_are_deleted(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Acme',
            'jurisdiction' => CompanyJurisdiction::EuSk,
        ]);
        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'status' => BusinessDocumentStatus::Draft,
            'currency' => 'EUR',
        ]);
        DB::table('business_documents')->where('id', $doc->id)->update([
            'updated_at' => now()->subDays(400),
        ]);

        config(['data_retention.draft_documents_days' => 30]);

        $stats = app(DataRetentionService::class)->run(dryRun: false);

        $this->assertSame(1, $stats['draft_documents_deleted']);
        $this->assertDatabaseMissing('business_documents', ['id' => $doc->id]);
    }

    #[Test]
    public function retention_purges_old_cancelled_expenses_and_attachment_files(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Acme',
            'jurisdiction' => CompanyJurisdiction::EuSk,
        ]);

        $expense = BusinessExpense::create([
            'company_id' => $company->id,
            'status' => BusinessExpenseStatus::Cancelled,
            'internal_number' => 'N20260099',
            'issue_date' => now(),
            'total' => 10,
            'currency' => 'EUR',
        ]);
        $expense->cancelled_at = now()->subDays(100);
        $expense->save();

        $path = "companies/{$company->id}/expenses/{$expense->id}/invoice.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 fake');
        BusinessExpenseAttachment::create([
            'business_expense_id' => $expense->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => 'invoice.pdf',
            'mime' => 'application/pdf',
        ]);

        config([
            'data_retention.cancelled_expenses_days' => 90,
            'data_retention.webhook_events_days' => 9999,
            'data_retention.audit_logs_days' => 9999,
            'data_retention.draft_documents_days' => 9999,
            'data_retention.soft_deleted_companies_days' => 9999,
            'data_retention.export_files_days' => 9999,
        ]);

        $stats = app(DataRetentionService::class)->run(dryRun: false);

        $this->assertSame(1, $stats['cancelled_expenses_deleted']);
        $this->assertDatabaseMissing('business_expenses', ['id' => $expense->id]);
        $this->assertDatabaseMissing('business_expense_attachments', ['business_expense_id' => $expense->id]);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    #[Test]
    public function retention_keeps_recently_cancelled_expenses(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Acme',
            'jurisdiction' => CompanyJurisdiction::EuSk,
        ]);

        $expense = BusinessExpense::create([
            'company_id' => $company->id,
            'status' => BusinessExpenseStatus::Cancelled,
            'internal_number' => 'N20260100',
            'issue_date' => now(),
            'total' => 10,
            'currency' => 'EUR',
        ]);
        $expense->cancelled_at = now()->subDays(10);
        $expense->save();

        config(['data_retention.cancelled_expenses_days' => 90]);

        $stats = app(DataRetentionService::class)->run(dryRun: false);

        $this->assertSame(0, $stats['cancelled_expenses_deleted']);
        $this->assertDatabaseHas('business_expenses', ['id' => $expense->id]);
    }

    #[Test]
    public function retention_purges_closed_integration_inbox_rows(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Inbox Co',
            'country' => 'SK',
            'default_currency' => 'EUR',
            'jurisdiction' => CompanyJurisdiction::EuSk,
        ]);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
        $integration = StoreIntegration::createForStore($store)['integration'];

        IntegrationDocumentInbox::create([
            'store_integration_id' => $integration->id,
            'woocommerce_order_id' => 9001,
            'evolu_document_id' => '00000000-0000-4000-8000-000000000099',
            'payload_json' => ['buyer' => ['email' => 'buyer@example.com']],
            'status' => IntegrationDocumentInboxStatus::Dismissed,
        ]);

        config(['data_retention.integration_inbox_closed_days' => 0]);

        $stats = app(DataRetentionService::class)->run(dryRun: false);

        $this->assertGreaterThanOrEqual(1, $stats['integration_inbox_closed_deleted']);
        $this->assertSame(0, IntegrationDocumentInbox::query()->count());
    }

    #[Test]
    public function data_retention_command_requires_enable_or_force(): void
    {
        config(['data_retention.enabled' => false]);

        $this->artisan('data:retention-run')->assertFailed();
        $this->artisan('data:retention-run', ['--force' => true])->assertSuccessful();
    }
}
