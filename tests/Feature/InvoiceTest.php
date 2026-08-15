<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
    }

    /** Fake BTCPay invoices API (GET /api/v1/stores/{storeId}/invoices). */
    protected function fakeBtcPayInvoices(array $invoices = []): void
    {
        Http::fake(function ($request) use ($invoices) {
            $url = (string) $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/api/v1/stores/') && str_contains($url, '/invoices')) {
                return Http::response([
                    'data' => $invoices,
                ], 200);
            }

            return Http::response([], 404);
        });
    }

    #[Test]
    public function user_can_list_invoices_for_own_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-api-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'store-123',
        ]);
        $this->fakeBtcPayInvoices([
            [
                'id' => 'inv-1',
                'status' => 'Settled',
                'amount' => 100,
                'currency' => 'EUR',
                'createdTime' => 1704067200,
                'paidTime' => 1704070800,
            ],
        ]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/invoices");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', 'inv-1')
            ->assertJsonPath('data.0.status', 'Settled')
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function user_cannot_list_invoices_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-123']);
        $this->fakeBtcPayInvoices([]);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/invoices");

        $response->assertStatus(403);
    }

    #[Test]
    public function user_can_export_invoices_csv_when_count_small(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-api-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'store-123',
        ]);
        // estimateInvoiceCount fetches 0..1000; return 2 so sync stream is used
        $this->fakeBtcPayInvoices([
            ['id' => 'inv-1', 'status' => 'Settled', 'amount' => 100, 'currency' => 'EUR', 'createdTime' => 1704067200],
            ['id' => 'inv-2', 'status' => 'Settled', 'amount' => 200, 'currency' => 'EUR', 'createdTime' => 1704070800],
        ]);

        $response = $this->actingAs($user)->get("/api/stores/{$store->id}/invoices/export");

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function user_cannot_export_invoices_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-123']);
        $this->fakeBtcPayInvoices([]);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/invoices/export");

        $response->assertStatus(403);
    }
}
