<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
        Cache::flush();
    }

    #[Test]
    public function unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(401);
    }

    #[Test]
    public function authenticated_user_without_btcpay_api_key_gets_empty_dashboard(): void
    {
        Http::fake([
            'https://btcpay.test/api/v1/users/me' => Http::response(['code' => 'unauthenticated'], 401),
        ]);

        $user = User::factory()->create(['btcpay_api_key' => null]);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('stores', [])
            ->assertJsonPath('store_count', 0)
            ->assertJsonPath('total_revenue', 0)
            ->assertJsonPath('btcpay_ping.state', 'online')
            ->assertJsonPath('btcpay_ping.http_status', 401);
    }

    #[Test]
    public function authenticated_user_with_btcpay_api_key_gets_stores_and_revenue(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
            'name' => 'My Store',
            'wallet_type' => 'blink',
        ]);

        $baseUrl = 'https://btcpay.test';
        Http::fake(function ($request) use ($baseUrl) {
            $url = (string) $request->url();
            $method = $request->method();
            if ($method === 'GET' && $url === $baseUrl.'/api/v1/users/me') {
                return Http::response(['code' => 'unauthenticated'], 401);
            }
            if ($method === 'GET' && $url === $baseUrl.'/api/v1/stores') {
                return Http::response([
                    ['id' => 'btcpay-store-1', 'name' => 'My Store', 'defaultCurrency' => 'EUR', 'timeZone' => 'Europe/Vienna', 'created' => 1704067200],
                ], 200);
            }
            if ($method === 'GET' && str_contains($url, '/api/v1/stores/btcpay-store-1/invoices')) {
                // Dashboard expects listInvoices to return array of invoices (BTCPay may return wrapped; we return direct for controller foreach)
                return Http::response([
                    ['id' => 'inv-1', 'status' => 'Settled', 'amount' => 0.00005, 'currency' => 'BTC'],
                    ['id' => 'inv-2', 'status' => 'Paid', 'amount' => 1000, 'currency' => 'SATS'],
                    ['id' => 'inv-3', 'status' => 'Complete', 'amount' => 50.5, 'currency' => 'EUR'],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('store_count', 1)
            ->assertJsonPath('stores.0.name', 'My Store')
            ->assertJsonPath('stores.0.wallet_type', 'blink')
            ->assertJsonPath('btcpay_ping.state', 'online');
        // Revenue: settled BTC + fiat from mocked invoices (see StoreInvoiceStatsService)
        $data = $response->json();
        $by = $data['total_revenue_by_currency'] ?? [];
        $this->assertArrayHasKey('sats', $by);
        $this->assertArrayHasKey('eur', $by);
        $this->assertSame((int) $data['total_revenue'], (int) $by['sats']);
        $this->assertSame(50.5, (float) $by['eur']);
    }

    #[Test]
    public function dashboard_only_includes_stores_present_in_btcpay_api(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'orphan-store',
            'name' => 'Orphan Store',
        ]);

        $baseUrl = 'https://btcpay.test';
        Http::fake(function ($request) use ($baseUrl) {
            $url = (string) $request->url();
            if ($request->method() === 'GET' && $url === $baseUrl.'/api/v1/users/me') {
                return Http::response(['code' => 'unauthenticated'], 401);
            }
            if ($request->method() === 'GET' && $url === $baseUrl.'/api/v1/stores') {
                // BTCPay returns only one store - orphan-store is not in list
                return Http::response([
                    ['id' => 'other-store', 'name' => 'Other', 'defaultCurrency' => 'EUR', 'timeZone' => 'UTC'],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('store_count', 0)
            ->assertJsonPath('stores', []);
    }

    #[Test]
    public function dashboard_returns_200_when_btcpay_api_fails_uses_empty_stores(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        $baseUrl = 'https://btcpay.test';
        Http::fake(function ($request) use ($baseUrl) {
            $url = (string) $request->url();
            if ($request->method() === 'GET' && $url === $baseUrl.'/api/v1/users/me') {
                return Http::response(['code' => 'unauthenticated'], 401);
            }
            if ($request->method() === 'GET' && $url === $baseUrl.'/api/v1/stores') {
                return Http::response(['error' => 'Unauthorized'], 401);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('store_count', 0)
            ->assertJsonPath('stores', [])
            ->assertJsonPath('total_revenue', 0);
    }
}
