<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
        Cache::flush();
    }

    protected function fakeStoreGet(string $storeId, array $btcpayStore): void
    {
        $baseUrl = 'https://btcpay.test';
        Http::fake(function ($request) use ($baseUrl, $storeId, $btcpayStore) {
            $url = (string) $request->url();
            $method = $request->method();
            if ($method === 'GET' && $url === $baseUrl."/api/v1/stores/{$storeId}") {
                return Http::response($btcpayStore, 200);
            }
            if ($method === 'PUT' && $url === $baseUrl."/api/v1/stores/{$storeId}") {
                return Http::response($btcpayStore, 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_unauthenticated_user_cannot_access_store_settings(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        $response = $this->getJson("/api/stores/{$store->id}/settings");

        $response->assertStatus(401);
    }

    public function test_user_can_get_store_settings_for_own_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'store-1',
            'name' => 'My Store',
        ]);
        $btcpayStore = [
            'id' => 'store-1',
            'name' => 'My Store',
            'defaultCurrency' => 'EUR',
            'timeZone' => 'Europe/Vienna',
            'preferredExchange' => 'kraken',
            'logoUrl' => null,
        ];
        $this->fakeStoreGet('store-1', $btcpayStore);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/settings");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'My Store')
            ->assertJsonPath('data.default_currency', 'EUR')
            ->assertJsonPath('data.timezone', 'Europe/Vienna')
            ->assertJsonPath('data.preferred_exchange', 'kraken')
            ->assertJsonPath('data.store_url', rtrim(config('app.url'), '/').'/stores/'.$store->id);
    }

    public function test_user_cannot_get_store_settings_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeStoreGet('store-1', ['id' => 'store-1', 'defaultCurrency' => 'EUR', 'timeZone' => 'UTC']);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/settings");

        $response->assertStatus(403);
    }

    public function test_store_settings_returns_500_when_user_has_no_btcpay_api_key(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => null]);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/settings");

        $response->assertStatus(500)
            ->assertJsonPath('message', 'BTCPay API key not configured. Please contact support.');
    }

    public function test_user_can_update_store_settings_for_own_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'store-1',
            'name' => 'Old Name',
        ]);
        $this->fakeStoreGet('store-1', [
            'id' => 'store-1',
            'name' => 'Old Name',
            'defaultCurrency' => 'EUR',
            'timeZone' => 'Europe/Vienna',
            'preferredExchange' => 'kraken',
        ]);

        $response = $this->actingAs($user)->putJson("/api/stores/{$store->id}/settings", [
            'name' => 'Updated Store Name',
            'default_currency' => 'USD',
            'timezone' => 'America/New_York',
            'preferred_exchange' => 'binance',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Store Name')
            ->assertJsonPath('data.default_currency', 'USD')
            ->assertJsonPath('data.timezone', 'America/New_York')
            ->assertJsonPath('data.preferred_exchange', 'binance')
            ->assertJsonPath('message', 'Store settings updated successfully');
        $store->refresh();
        $this->assertSame('Updated Store Name', $store->name);
    }

    public function test_store_settings_update_validates_required_fields(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create(['user_id' => $user->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeStoreGet('store-1', ['id' => 'store-1', 'defaultCurrency' => 'EUR', 'timeZone' => 'UTC']);

        $response = $this->actingAs($user)->putJson("/api/stores/{$store->id}/settings", [
            'name' => '',
            'default_currency' => '',
            'timezone' => 'Invalid/Timezone',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_cannot_update_store_settings_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_api_key' => 'key']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'btcpay_store_id' => 'store-1']);
        $this->fakeStoreGet('store-1', ['id' => 'store-1', 'defaultCurrency' => 'EUR', 'timeZone' => 'UTC']);

        $response = $this->actingAs($other)->putJson("/api/stores/{$store->id}/settings", [
            'name' => 'Hacked Name',
            'default_currency' => 'EUR',
            'timezone' => 'Europe/Vienna',
        ]);

        $response->assertStatus(403);
    }
}
