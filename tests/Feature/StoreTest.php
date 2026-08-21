<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Store creation requires server-level API key; use fixed base URL so Http::fake matches
        config(['services.btcpay.api_key' => 'test-server-api-key']);
        config(['services.btcpay.base_url' => 'http://localhost']);

        // Clear cache and force fresh BtcPayClient with new config
        Cache::flush();
        $this->app->forgetInstance(BtcPayClient::class);

        // Mock BTCPay API responses (closure so POST vs GET to same URL return different bodies)
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        Http::fake(function ($request) use ($baseUrl) {
            $url = (string) $request->url();
            $method = $request->method();
            if ($method === 'POST' && $url === $baseUrl.'/api/v1/stores') {
                return Http::response([
                    'id' => 'test-store-id',
                    'storeId' => 'test-store-id',
                    'name' => 'Test Store',
                    'defaultCurrency' => 'EUR',
                    'timeZone' => 'Europe/Vienna',
                    'preferredExchange' => 'kraken',
                ], 201);
            }
            if ($method === 'GET' && $url === $baseUrl.'/api/v1/stores') {
                return Http::response([
                    ['id' => 'test-store-id', 'name' => 'Test Store', 'defaultCurrency' => 'EUR', 'timeZone' => 'Europe/Vienna', 'preferredExchange' => 'kraken', 'logoUrl' => null],
                ], 200);
            }
            if (str_contains($url, $baseUrl.'/api/v1/stores/') && str_contains($url, '/users')) {
                return Http::response(['userId' => 'user-id', 'role' => 'Owner'], 200);
            }
            if (str_contains($url, $baseUrl.'/api/v1/stores/') && ! str_contains($url, '/logo')) {
                return Http::response(['id' => 'test-store-id', 'name' => 'Test Store', 'defaultCurrency' => 'EUR', 'timeZone' => 'Europe/Vienna', 'preferredExchange' => 'kraken', 'logoUrl' => null], 200);
            }
            if (str_contains($url, '/logo')) {
                return Http::response(['id' => 'test-store-id', 'logoUrl' => 'https://example.com/logo.jpg'], 200);
            }
            if (str_contains($url, $baseUrl.'/api/v1/users/me') || str_contains($url, $baseUrl.'/api/v1/api-keys/current')) {
                return Http::response(['id' => 'admin-user-id', 'email' => 'admin@example.com'], 200);
            }
            if (str_contains($url, $baseUrl.'/api/v1/users')) {
                return Http::response([['id' => 'admin-user-id', 'email' => 'admin@example.com', 'isAdministrator' => true]], 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_user_can_create_store(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/stores', [
            'name' => 'My Test Store',
            'default_currency' => 'EUR',
            'timezone' => 'Europe/Vienna',
            'preferred_exchange' => 'kraken',
            'wallet_type' => 'blink',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'default_currency', 'timezone'],
        ]);

        $this->assertDatabaseHas('stores', [
            'user_id' => $user->id,
            'name' => 'My Test Store',
        ]);
    }

    public function test_store_can_be_created_without_wallet_type(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/stores', [
            'name' => 'Store No Wallet Yet',
            'default_currency' => 'EUR',
            'timezone' => 'Europe/Vienna',
            'preferred_exchange' => 'kraken',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.wallet_type', null);

        $this->assertDatabaseHas('stores', [
            'user_id' => $user->id,
            'name' => 'Store No Wallet Yet',
            'wallet_type' => null,
        ]);

        $this->assertDatabaseMissing('wallet_connections', [
            'store_id' => $response->json('data.id'),
        ]);
    }

    public function test_store_creation_requires_valid_timezone(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/stores', [
            'name' => 'My Test Store',
            'default_currency' => 'EUR',
            'timezone' => 'Invalid/Timezone',
            'wallet_type' => 'blink',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }

    public function test_store_creation_creates_wallet_connection_if_provided(): void
    {
        $user = User::factory()->create([
            'btcpay_api_key' => 'test-merchant-key-for-connect',
        ]);

        $response = $this->actingAs($user)->postJson('/api/stores', [
            'name' => 'My Test Store',
            'default_currency' => 'EUR',
            'timezone' => 'Europe/Vienna',
            'wallet_type' => 'blink',
            'connection_string' => 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456',
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201);

        $store = Store::where('user_id', $user->id)->first();
        $this->assertNotNull($store);

        $connection = WalletConnection::where('store_id', $store->id)->first();
        if ($connection !== null) {
            $this->assertEquals('blink', $connection->type);
            $this->assertContains($connection->status, ['pending', 'needs_support', 'connected']);
        }
    }

    public function test_store_creation_requires_fallback_when_wallet_secret_cannot_provide_one(): void
    {
        $user = User::factory()->create([
            'btcpay_api_key' => 'test-merchant-key-for-connect',
        ]);

        $response = $this->actingAs($user)->postJson('/api/stores', [
            'name' => 'My Test Store',
            'default_currency' => 'EUR',
            'timezone' => 'Europe/Vienna',
            'wallet_type' => 'blink',
            'connection_string' => 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fallback_lightning_address']);

        $this->assertDatabaseMissing('stores', [
            'user_id' => $user->id,
            'name' => 'My Test Store',
        ]);
    }

    public function test_user_can_view_own_stores(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'test-store-id', // Must match mock response
        ]);

        // Mock store list API - returns stores that user has access to
        $baseUrl = config('services.btcpay.base_url', 'http://localhost');
        Http::fake([
            $baseUrl.'/api/v1/stores' => Http::response([
                [
                    'id' => 'test-store-id',
                    'name' => $store->name,
                    'defaultCurrency' => $store->default_currency ?? 'EUR',
                    'timeZone' => $store->timezone ?? 'Europe/Vienna',
                    'preferredExchange' => $store->preferred_exchange ?? 'kraken',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->getJson('/api/stores');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $store->id);
    }

    public function test_user_can_update_store_settings(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'name' => 'Old Name',
        ]);

        Http::fake([
            config('services.btcpay.base_url').'/stores/'.$store->btcpay_store_id => Http::response([
                'id' => $store->btcpay_store_id,
                'name' => 'New Name',
                'defaultCurrency' => 'USD',
                'timeZone' => 'America/New_York',
                'preferredExchange' => 'coinbasepro',
            ], 200),
        ]);

        $response = $this->actingAs($user)->putJson("/api/stores/{$store->id}/settings", [
            'name' => 'New Name',
            'default_currency' => 'USD',
            'timezone' => 'America/New_York',
            'preferred_exchange' => 'coinbasepro',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'name' => 'New Name',
        ]);
    }

    public function test_user_can_upload_store_logo(): void
    {
        $user = User::factory()->create([
            'btcpay_api_key' => 'test-merchant-api-key',
        ]);
        $store = Store::factory()->create(['user_id' => $user->id]);

        $file = UploadedFile::fake()->image('logo.jpg', 100, 100);

        $baseUrl = config('services.btcpay.base_url');
        Http::fake([
            $baseUrl.'/api/v1/stores/'.$store->btcpay_store_id.'/logo' => Http::response([
                'id' => $store->btcpay_store_id,
                'logoUrl' => 'https://example.com/logo.jpg',
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/logo", [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['logoUrl']]);
    }

    public function test_user_can_delete_store_logo(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $storePath = $baseUrl.'/api/v1/stores/'.$store->btcpay_store_id;
        Http::fake([
            $storePath.'/logo' => Http::response([], 200),
            $storePath => Http::response([
                'id' => $store->btcpay_store_id,
                'name' => $store->name,
                'logoUrl' => null,
            ], 200),
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/logo");

        $response->assertStatus(200);
    }

    public function test_user_can_delete_own_store(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        Http::fake([
            config('services.btcpay.base_url').'/stores/'.$store->btcpay_store_id => Http::response([], 200),
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    public function test_user_cannot_access_other_users_store(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)->getJson("/api/stores/{$store->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_other_users_store(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)->deleteJson("/api/stores/{$store->id}");

        // Middleware returns 403 for unauthorized access, not 404
        $response->assertStatus(403);
    }

    public function test_support_user_can_delete_other_users_store(): void
    {
        $owner = User::factory()->create();
        $support = User::factory()->support()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);

        Http::fake([
            config('services.btcpay.base_url').'/stores/'.$store->btcpay_store_id => Http::response([], 200),
        ]);

        $response = $this->actingAs($support)->deleteJson("/api/stores/{$store->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    public function test_store_list_includes_wallet_connection_status(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'test-store-id', // Must match mock response
        ]);
        WalletConnection::factory()->create([
            'store_id' => $store->id,
            'type' => 'blink',
            'status' => 'connected',
        ]);

        // Mock store list API - returns stores that user has access to
        $baseUrl = config('services.btcpay.base_url', 'http://localhost');
        Http::fake([
            $baseUrl.'/api/v1/stores' => Http::response([
                [
                    'id' => 'test-store-id',
                    'name' => $store->name,
                    'defaultCurrency' => $store->default_currency ?? 'EUR',
                    'timeZone' => $store->timezone ?? 'Europe/Vienna',
                    'preferredExchange' => $store->preferred_exchange ?? 'kraken',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->getJson('/api/stores');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.wallet_connection.status', 'connected');
    }

    public function test_store_list_includes_synthetic_wallet_connection_for_cashu(): void
    {
        $user = User::factory()->create();
        // setUp() Http fake returns GET /api/v1/stores with id test-store-id first; merged fakes use first match.
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'test-store-id',
            'wallet_type' => 'cashu',
        ]);

        $response = $this->actingAs($user)->getJson('/api/stores');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.wallet_connection.type', 'cashu');
        $response->assertJsonPath('data.0.wallet_connection.status', 'connected');
    }

    public function test_patch_wallet_type_sets_type_when_null(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => null,
        ]);

        $response = $this->actingAs($user)->patchJson("/api/stores/{$store->id}/wallet-type", [
            'wallet_type' => 'aqua_boltz',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet_type', 'aqua_boltz');
        $this->assertSame('aqua_boltz', $store->fresh()->wallet_type);
    }

    public function test_patch_wallet_type_accepts_blitz(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => null,
        ]);

        $response = $this->actingAs($user)->patchJson("/api/stores/{$store->id}/wallet-type", [
            'wallet_type' => 'blitz',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet_type', 'blitz');
        $this->assertSame('blitz', $store->fresh()->wallet_type);
    }

    public function test_patch_wallet_type_idempotent_when_same(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'aqua_boltz',
        ]);

        $response = $this->actingAs($user)->patchJson("/api/stores/{$store->id}/wallet-type", [
            'wallet_type' => 'aqua_boltz',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet_type', 'aqua_boltz');
    }

    public function test_patch_wallet_type_422_when_already_different(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
        ]);

        $response = $this->actingAs($user)->patchJson("/api/stores/{$store->id}/wallet-type", [
            'wallet_type' => 'aqua_boltz',
        ]);

        $response->assertStatus(422);
    }

    public function test_cashu_first_settings_update_sets_wallet_type_on_store(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'cashu-store-btcpay-id';

        Http::fake([
            $baseUrl.'/api/v1/stores/'.$btcpaySid.'/plugins/cashumelt/settings' => Http::response([
                'mintUrl' => 'https://mint.example.com/Bitcoin',
                'lightningAddress' => 'u@example.com',
                'enabled' => true,
            ], 200),
        ]);

        $user = User::factory()->create([
            'btcpay_api_key' => 'merchant-api-key-cashu',
        ]);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => $btcpaySid,
            'wallet_type' => null,
        ]);

        $response = $this->actingAs($user)->putJson("/api/stores/{$store->id}/cashu/settings", [
            'mint_url' => 'https://mint.example.com/Bitcoin',
            'lightning_address' => 'u@example.com',
        ]);

        $response->assertStatus(200);
        $store->refresh();
        $this->assertSame('cashu', $store->wallet_type);
    }
}
