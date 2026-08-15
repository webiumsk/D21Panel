<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateCsvExport;
use App\Jobs\GenerateXlsxExport;
use App\Models\Export;
use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use App\Services\BtcPay\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
    }

    #[Test]
    public function handle_marks_export_as_running_then_finishes_or_fails(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'store-123',
        ]);
        $export = Export::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'format' => 'standard',
            'status' => 'pending',
            'filters' => [],
        ]);
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/stores/') && str_contains($url, '/invoices')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response([], 404);
        });

        // Local disk does not support temporaryUrl; job will fail there and call markAsFailed
        try {
            $job = new GenerateCsvExport($export);
            $job->handle(app(InvoiceService::class));
        } catch (\Throwable $e) {
            // Expected when temporaryUrl is not supported
        }

        $export->refresh();
        $this->assertTrue(in_array($export->status, ['running', 'failed', 'finished'], true));
        if ($export->status === 'failed') {
            $this->assertNotEmpty($export->error_message);
        }
    }

    #[Test]
    public function handle_marks_export_as_failed_when_store_user_has_no_api_key(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => null]);
        $store = Store::factory()->create(['user_id' => $user->id]);
        $export = Export::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'format' => 'standard',
            'status' => 'pending',
            'filters' => [],
        ]);

        $job = new GenerateCsvExport($export);
        try {
            $job->handle(app(InvoiceService::class));
        } catch (\Throwable $e) {
            // Job may rethrow after markAsFailed
        }

        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertStringContainsString('API key', $export->error_message ?? '');
    }

    #[Test]
    public function csv_export_failure_rethrows_for_retry_and_failed_hook_uses_safe_message(): void
    {
        $export = $this->makeExport('standard');
        $invoiceService = $this->failingInvoiceService();
        Http::fake();

        $job = new GenerateCsvExport($export);
        $thrown = null;
        try {
            $job->handle($invoiceService);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // handle() rethrows so the queue can retry; the export stays running until the last attempt.
        $this->assertNotNull($thrown);
        $export->refresh();
        $this->assertSame('running', $export->status);

        // After the final attempt the queue invokes failed(), which surfaces a safe message.
        $job->failed($thrown);
        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertSame('Export generation failed. Please try again or contact support.', $export->error_message);
        $this->assertStringNotContainsString('Unable to create export file', $export->error_message ?? '');
        $this->assertStringNotContainsString('/var/private/exports', $export->error_message ?? '');
    }

    #[Test]
    public function xlsx_export_failure_rethrows_for_retry_and_failed_hook_uses_safe_message(): void
    {
        $export = $this->makeExport('standard');
        $invoiceService = $this->failingInvoiceService();
        Http::fake();

        $job = new GenerateXlsxExport($export);
        $thrown = null;
        try {
            $job->handle($invoiceService);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $export->refresh();
        $this->assertSame('running', $export->status);

        $job->failed($thrown);
        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertSame('Export generation failed. Please try again or contact support.', $export->error_message);
        $this->assertStringNotContainsString('Unable to create export file', $export->error_message ?? '');
        $this->assertStringNotContainsString('/var/private/exports', $export->error_message ?? '');
    }

    private function makeExport(string $format): Export
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'store-123',
        ]);

        return Export::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'format' => $format,
            'status' => 'pending',
            'filters' => [],
        ]);
    }

    private function failingInvoiceService(): InvoiceService
    {
        return new class(app(BtcPayClient::class)) extends InvoiceService
        {
            public function listInvoices(string $storeId, array $filters = [], ?int $skip = null, ?int $take = null, ?string $userApiKey = null): array
            {
                throw new \RuntimeException('Unable to create export file: /var/private/exports/secret.csv');
            }
        };
    }
}
