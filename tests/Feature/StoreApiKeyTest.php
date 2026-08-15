<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreApiKey;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);

        SubscriptionPlan::create([
            'name' => 'free',
            'display_name' => 'Free',
            'price_eur' => 0,
            'max_stores' => 5,
            'max_api_keys' => 10,
            'features' => [],
            'is_active' => true,
        ]);
    }

    /** Fake BTCPay user API key creation (POST /api/v1/users/{userId}/api-keys). */
    protected function fakeBtcPayApiKeyCreation(): void
    {
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
    }

    #[Test]
    public function user_can_list_api_keys_for_own_store(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'My Key',
            'btcpay_api_key' => 'encrypted-key',
            'permissions' => ['btcpay.store.cancreateinvoice'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/api-keys");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.label', 'My Key')
            ->assertJsonPath('data.0.has_api_key', true);
        $this->assertArrayNotHasKey('btcpay_api_key', $response->json('data.0'));
    }

    #[Test]
    public function user_cannot_list_api_keys_for_other_users_store(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/api-keys");

        $response->assertStatus(403);
    }

    #[Test]
    public function user_can_create_api_key_when_btcpay_user_linked(): void
    {
        $user = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->fakeBtcPayApiKeyCreation();

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/api-keys", [
            'label' => 'E-shop Key',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.label', 'E-shop Key')
            ->assertJsonPath('message', 'API key created successfully');
        $response->assertJsonStructure([
            'data' => [
                'id',
                'label',
                'api_key',
                'permissions',
                'is_active',
                'created_at',
                'store_id',
            ],
        ]);
        $this->assertDatabaseHas('store_api_keys', [
            'store_id' => $store->id,
            'label' => 'E-shop Key',
        ]);
    }

    #[Test]
    public function api_key_creation_validates_label_required(): void
    {
        $user = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/api-keys", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['label']);
    }

    #[Test]
    public function api_key_creation_validates_callback_url_format(): void
    {
        $user = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/api-keys", [
            'label' => 'Key',
            'callback_url' => 'not-a-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['callback_url']);
    }

    #[Test]
    public function user_cannot_create_api_key_for_other_users_store(): void
    {
        $owner = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);

        $this->fakeBtcPayApiKeyCreation();

        $response = $this->actingAs($other)->postJson("/api/stores/{$store->id}/api-keys", [
            'label' => 'Hacked Key',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function user_can_show_api_key_metadata_without_exposing_key(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $apiKey = StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Show Key',
            'btcpay_api_key' => 'never-expose-this',
            'permissions' => ['btcpay.store.canviewinvoices'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/api-keys/{$apiKey->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.label', 'Show Key')
            ->assertJsonPath('data.has_api_key', true);
        $response->assertJsonMissingPath('data.btcpay_api_key');
    }

    #[Test]
    public function user_cannot_show_other_stores_api_key(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);
        $apiKey = StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Owner Key',
            'btcpay_api_key' => 'secret',
        ]);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/api-keys/{$apiKey->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function show_returns_404_for_invalid_api_key_id(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/api-keys/00000000-0000-0000-0000-000000000000");

        $response->assertStatus(404);
    }

    #[Test]
    public function user_can_revoke_api_key(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $apiKey = StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Revoke Me',
            'btcpay_api_key' => 'key',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/api-keys/{$apiKey->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'API key revoked successfully']);
        $apiKey->refresh();
        $this->assertFalse($apiKey->is_active);
    }

    #[Test]
    public function user_cannot_revoke_other_stores_api_key(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);
        $apiKey = StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Owner Key',
            'btcpay_api_key' => 'secret',
        ]);

        $response = $this->actingAs($other)->deleteJson("/api/stores/{$store->id}/api-keys/{$apiKey->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function user_cannot_revoke_other_stores_api_key_through_own_store_route(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $victimStore = Store::factory()->create(['user_id' => $owner->id]);
        $attackerStore = Store::factory()->create(['user_id' => $attacker->id]);
        $victimApiKey = StoreApiKey::create([
            'store_id' => $victimStore->id,
            'label' => 'Victim Key',
            'btcpay_api_key' => 'victim-secret',
            'is_active' => true,
        ]);

        $response = $this->actingAs($attacker)
            ->deleteJson("/api/stores/{$attackerStore->id}/api-keys/{$victimApiKey->id}");

        $response->assertStatus(404);
        $this->assertTrue($victimApiKey->fresh()->is_active);
    }

    #[Test]
    public function user_can_regenerate_api_key(): void
    {
        $user = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $store = Store::factory()->create(['user_id' => $user->id]);
        $apiKey = StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Regenerate Me',
            'btcpay_api_key' => 'old-key',
            'permissions' => ['btcpay.store.cancreateinvoice'],
        ]);

        $this->fakeBtcPayApiKeyCreation();

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/api-keys/{$apiKey->id}/regenerate", [
            'label' => 'Regenerated Key',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'API key regenerated successfully')
            ->assertJsonPath('data.label', 'Regenerated Key')
            ->assertJsonStructure(['data' => ['id', 'label', 'api_key', 'permissions', 'created_at']]);
        $apiKey->refresh();
        $this->assertFalse($apiKey->is_active);
        $this->assertCount(1, StoreApiKey::where('store_id', $store->id)->where('is_active', true)->get());
    }

    #[Test]
    public function user_cannot_regenerate_other_stores_api_key(): void
    {
        $owner = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);
        $apiKey = StoreApiKey::create([
            'store_id' => $store->id,
            'label' => 'Owner Key',
            'btcpay_api_key' => 'secret',
        ]);

        $this->fakeBtcPayApiKeyCreation();

        $response = $this->actingAs($other)->postJson("/api/stores/{$store->id}/api-keys/{$apiKey->id}/regenerate");

        $response->assertStatus(403);
    }

    #[Test]
    public function user_cannot_regenerate_other_stores_api_key_through_own_store_route(): void
    {
        $owner = User::factory()->create(['btcpay_user_id' => 'btcpay-user-123']);
        $attacker = User::factory()->create(['btcpay_user_id' => 'attacker-btcpay-user']);
        $victimStore = Store::factory()->create(['user_id' => $owner->id]);
        $attackerStore = Store::factory()->create(['user_id' => $attacker->id]);
        $victimApiKey = StoreApiKey::create([
            'store_id' => $victimStore->id,
            'label' => 'Victim Key',
            'btcpay_api_key' => 'victim-secret',
            'permissions' => ['btcpay.store.cancreateinvoice'],
            'is_active' => true,
        ]);

        $this->fakeBtcPayApiKeyCreation();

        $response = $this->actingAs($attacker)
            ->postJson("/api/stores/{$attackerStore->id}/api-keys/{$victimApiKey->id}/regenerate", [
                'label' => 'Stolen Key',
            ]);

        $response->assertStatus(404);
        $this->assertTrue($victimApiKey->fresh()->is_active);
        $this->assertSame(1, StoreApiKey::where('store_id', $victimStore->id)->count());
        $this->assertSame(0, StoreApiKey::where('store_id', $attackerStore->id)->count());
    }

    #[Test]
    public function user_can_generate_eshop_token(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/api-keys/token", [
            'label' => 'E-shop Token',
            'expiration_minutes' => 60,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Token generated successfully')
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'panel_url',
                    'api_endpoint',
                    'expires_in_minutes',
                ],
            ]);
        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertStringContainsString($token, $response->json('data.api_endpoint'));
        $this->assertTrue(Cache::has("eshop_token:{$token}"));
    }

    #[Test]
    public function generate_token_validates_expiration_minutes(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/api-keys/token", [
            'expiration_minutes' => 2000,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expiration_minutes']);
    }

    #[Test]
    public function user_cannot_generate_token_for_other_users_store(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->postJson("/api/stores/{$store->id}/api-keys/token", [
            'expiration_minutes' => 60,
        ]);

        $response->assertStatus(403);
    }
}
