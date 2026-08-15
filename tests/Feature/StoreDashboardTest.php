<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
        Cache::flush();
    }

    protected function fakeStoreDashboardApi(string $storeId, array $invoices = [], array $apps = []): void
    {
        $baseUrl = 'https://btcpay.test';
        Http::fake(function ($request) use ($baseUrl, $storeId, $invoices, $apps) {
            $url = (string) $request->url();
            $method = $request->method();
            if ($method === 'GET' && str_contains($url, "/api/v1/stores/{$storeId}/invoices")) {
                return Http::response($invoices, 200);
            }
            if ($method === 'GET' && ($url === $baseUrl."/stores/{$storeId}/apps" || $url === $baseUrl."/api/v1/stores/{$storeId}/apps")) {
                return Http::response($apps, 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_unauthenticated_user_cannot_access_store_dashboard(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        $response = $this->getJson("/api/stores/{$store->id}/dashboard");

        $response->assertStatus(401);
    }

    public function test_user_can_get_store_dashboard_for_own_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $invoices = [
            ['id' => 'inv-1', 'status' => 'Settled', 'amount' => 10, 'currency' => 'EUR', 'createdTime' => time()],
        ];
        $this->fakeStoreDashboardApi('store-1', $invoices, [['id' => 'app-1', 'name' => 'POS', 'appType' => 'PointOfSale']]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/dashboard");

        $response->assertStatus(200)
            ->assertJsonPath('data.paid_invoices_last_7d', fn ($v) => is_int($v))
            ->assertJsonPath('data.total_invoices', 1)
            ->assertJsonPath('data.is_ready', false)
            ->assertJsonPath('data.has_wallet_connection', false)
            ->assertJsonStructure([
                'data' => [
                    'paid_invoices_last_7d',
                    'total_invoices',
                    'recent_invoices',
                    'apps' => ['crowdfund', 'point_of_sale', 'payment_button'],
                    'is_ready',
                    'has_wallet_connection',
                    'sales' => ['last_7_days', 'last_30_days', 'total_7d', 'total_30d'],
                    'top_items',
                ],
            ]);
    }

    public function test_cashu_store_dashboard_reports_wallet_connection_without_row(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'store-cashu',
            'wallet_type' => 'cashu',
        ]);
        $this->fakeStoreDashboardApi('store-cashu', [], []);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/dashboard");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_ready', true)
            ->assertJsonPath('data.has_wallet_connection', true);
    }

    public function test_user_cannot_get_store_dashboard_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeStoreDashboardApi('store-1', [], []);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/dashboard");

        $response->assertStatus(403);
    }

    public function test_store_dashboard_returns_500_when_user_has_no_btcpay_api_key(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => null]);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/dashboard");

        $response->assertStatus(500)
            ->assertJsonPath('message', 'BTCPay API key not configured. Please contact support.');
    }

    public function test_store_dashboard_returns_minimal_data_when_btcpay_invoices_api_fails(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $baseUrl = 'https://btcpay.test';
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/invoices')) {
                return Http::response(['error' => 'Unauthorized'], 401);
            }
            if (str_contains($url, '/apps')) {
                return Http::response([], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/dashboard");

        $response->assertStatus(200)
            ->assertJsonPath('data.error', 'Failed to load dashboard data')
            ->assertJsonPath('data.paid_invoices_last_7d', 0)
            ->assertJsonPath('data.total_invoices', 0)
            ->assertJsonPath('data.recent_invoices', []);
    }
}
