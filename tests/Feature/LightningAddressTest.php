<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LightningAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);

        SubscriptionPlan::create([
            'code' => 'free',
            'name' => 'free',
            'display_name' => 'Free',
            'price_eur' => 0,
            'billing_period' => 'year',
            'max_stores' => 5,
            'max_api_keys' => 10,
            'max_ln_addresses' => 1,
            'features' => [],
            'is_active' => true,
        ]);
    }

    protected function fakeLightningAddressApi(string $storeId, array $list = [], ?array $get = null, ?array $post = null, bool $deleteOk = true): void
    {
        $baseUrl = 'https://btcpay.test';
        Http::fake(function ($request) use ($baseUrl, $storeId, $list, $get, $post, $deleteOk) {
            $url = (string) $request->url();
            $method = $request->method();
            $path = "/api/v1/stores/{$storeId}/lightning-addresses";
            if ($method === 'GET' && $url === $baseUrl.$path) {
                return Http::response($list, 200);
            }
            if ($method === 'GET' && str_contains($url, $path.'/')) {
                $username = basename(parse_url($url, PHP_URL_PATH));
                if ($get !== null && isset($get[$username])) {
                    return Http::response($get[$username], 200);
                }

                return Http::response(['message' => 'Not found'], 404);
            }
            if ($method === 'POST' && str_contains($url, $path.'/')) {
                if ($post !== null) {
                    return Http::response($post, 200);
                }

                return Http::response(['username' => basename(parse_url($url, PHP_URL_PATH)), 'currencyCode' => null], 200);
            }
            if ($method === 'DELETE' && str_contains($url, $path.'/')) {
                return $deleteOk ? Http::response(null, 200) : Http::response(['message' => 'Not found'], 404);
            }

            return Http::response([], 404);
        });
    }

    #[Test]
    public function user_can_list_lightning_addresses_for_own_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [
            ['username' => 'alice', 'currencyCode' => 'SATS'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/lightning-addresses");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.username', 'alice')
            ->assertJsonPath('limit.current', 1)
            ->assertJsonPath('limit.max', 1)
            ->assertJsonPath('limit.unlimited', false);
    }

    #[Test]
    public function user_cannot_list_lightning_addresses_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', []);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/lightning-addresses");

        $response->assertStatus(403);
    }

    #[Test]
    public function listing_lightning_addresses_returns_500_when_user_has_no_btcpay_api_key(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => null]);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/lightning-addresses");

        $response->assertStatus(500)
            ->assertJsonPath('message', 'BTCPay API key not configured. Please contact support.');
    }

    #[Test]
    public function user_can_show_lightning_address_for_own_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], ['alice' => ['username' => 'alice', 'currencyCode' => 'SATS', 'min' => '1', 'max' => '1000000']]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/lightning-addresses/alice");

        $response->assertStatus(200)
            ->assertJsonPath('data.username', 'alice')
            ->assertJsonPath('data.currencyCode', 'SATS');
    }

    #[Test]
    public function show_lightning_address_returns_404_when_not_found(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], []);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/lightning-addresses/nonexistent");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Lightning address not found');
    }

    #[Test]
    public function user_cannot_show_lightning_address_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], ['alice' => ['username' => 'alice']]);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/lightning-addresses/alice");

        $response->assertStatus(403);
    }

    #[Test]
    public function user_can_create_lightning_address(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], ['bob' => null], ['username' => 'bob', 'currencyCode' => 'SATS']);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/lightning-addresses/bob", [
            'username' => 'bob',
            'currencyCode' => 'SATS',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Lightning address saved successfully')
            ->assertJsonPath('data.username', 'bob');
    }

    #[Test]
    public function create_lightning_address_validates_username_matches_url(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', []);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/lightning-addresses/bob", [
            'username' => 'alice',
            'currencyCode' => 'SATS',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Username in request body must match URL parameter');
    }

    #[Test]
    public function user_at_lightning_address_limit_receives_403_when_creating_new(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        // Free plan: max 1. List returns 1 address; getAddress for newuser returns 404 (new address).
        $this->fakeLightningAddressApi('store-1', [['username' => 'existing']], [], null);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/lightning-addresses/newuser", [
            'username' => 'newuser',
        ]);

        $response->assertStatus(403);
        $message = $response->json('message');
        $this->assertStringContainsString('maximum number of Lightning Addresses (1)', $message);
        $this->assertStringContainsString('Please upgrade', $message);
    }

    #[Test]
    public function user_can_update_lightning_address(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], ['alice' => ['username' => 'alice']], ['username' => 'alice', 'currencyCode' => 'EUR']);

        $response = $this->actingAs($user)->putJson("/api/stores/{$store->id}/lightning-addresses/alice", [
            'username' => 'alice',
            'currencyCode' => 'EUR',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Lightning address saved successfully');
    }

    #[Test]
    public function user_can_delete_lightning_address(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], [], null, true);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/lightning-addresses/alice");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Lightning address deleted successfully');
    }

    #[Test]
    public function delete_lightning_address_returns_404_when_not_found(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], [], null, false);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/lightning-addresses/nonexistent");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Lightning address not found');
    }

    #[Test]
    public function user_cannot_delete_lightning_address_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeLightningAddressApi('store-1', [], [], null, true);

        $response = $this->actingAs($other)->deleteJson("/api/stores/{$store->id}/lightning-addresses/alice");

        $response->assertStatus(403);
    }
}
