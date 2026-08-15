<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreApiKey;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreAndApiKeyLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Free plan: 1 store, 1 API key (code must be `free` for User::currentSubscriptionPlan())
        SubscriptionPlan::query()->updateOrCreate(
            ['code' => 'free'],
            [
                'name' => 'free',
                'display_name' => 'Free',
                'price_eur' => 0,
                'billing_period' => 'year',
                'max_stores' => 1,
                'max_api_keys' => 1,
                'max_ln_addresses' => 2,
                'max_events' => 1,
                'features' => [],
                'is_active' => true,
            ]
        );
    }

    public function test_user_at_store_limit_receives_403_when_creating_store(): void
    {
        $user = User::factory()->create();
        Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'existing-store-id']);

        $baseUrl = config('services.btcpay.base_url', 'http://localhost');
        Http::fake([
            $baseUrl.'/api/v1/stores' => Http::response(['id' => 'new-store-id', 'name' => 'New'], 201),
            $baseUrl.'/api/v1/stores' => Http::response([], 200),
            $baseUrl.'/api/v1/users' => Http::response([[]], 200),
            $baseUrl.'/api/v1/users/me' => Http::response([], 200),
            $baseUrl.'/api/v1/api-keys/current' => Http::response([], 200),
            $baseUrl.'/api/v1/stores/*/users' => Http::response([], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/stores', [
            'name' => 'Second Store',
            'default_currency' => 'EUR',
            'timezone' => 'Europe/Vienna',
            'wallet_type' => 'blink',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'You have reached the maximum number of stores (1) for your plan.',
            'current_count' => 1,
            'max_allowed' => 1,
        ]);
    }

    public function test_user_at_api_key_limit_receives_403_when_creating_api_key(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Existing Key',
            'btcpay_api_key' => 'dummy-key-for-test',
        ]);

        $baseUrl = config('services.btcpay.base_url', 'http://localhost');
        Http::fake([
            $baseUrl.'/api/v1/stores/*/api-keys' => Http::response([
                'id' => 'new-key-id',
                'label' => 'Test Key',
                'apiKey' => 'secret-token',
            ], 201),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/api-keys", [
            'label' => 'Second API Key',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'This store has reached the maximum number of active API keys (1) for the plan.',
            'current_count' => 1,
            'max_allowed' => 1,
        ]);
    }

    public function test_revoked_api_keys_do_not_count_toward_limit(): void
    {
        $user = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $store = Store::factory()->create(['user_id' => $user->id]);
        StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Revoked Key',
            'btcpay_api_key' => 'dummy-key',
            'is_active' => false,
        ]);

        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/api/v1/users/') && str_contains($url, '/api-keys')) {
                return Http::response([
                    'id' => 'btcpay-key-'.uniqid(),
                    'label' => 'Test Key',
                    'apiKey' => 'secret-api-key-'.bin2hex(random_bytes(8)),
                ], 201);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/api-keys", [
            'label' => 'New Active Key',
        ]);

        // With only 0 active keys (1 revoked), we are under limit 1
        $response->assertStatus(201);
    }
}
