<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\BtcPay\BoltzService;
use App\Services\WalletConnectionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WalletConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected const VALID_BLINK_SECRET = 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456';

    protected const VALID_AQUA_DESCRIPTOR = 'ct(slip77(xpub6D4BDPcP2GT577Vvch3Reb8P8CH),elsh(wpkh(xpub6E8...)))';

    #[Test]
    public function user_can_get_wallet_connection_for_own_store(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $connection = WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::VALID_BLINK_SECRET),
            'status' => 'needs_support',
            'submitted_by_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/wallet-connection");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $connection->id)
            ->assertJsonPath('data.type', 'blink')
            ->assertJsonPath('data.status', 'needs_support');
        $response->assertJsonMissingPath('data.secret');
    }

    #[Test]
    public function user_gets_null_when_store_has_no_wallet_connection(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/stores/{$store->id}/wallet-connection");

        $response->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function user_cannot_get_wallet_connection_for_other_users_store(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->getJson("/api/stores/{$store->id}/wallet-connection");

        $response->assertStatus(403);
    }

    #[Test]
    public function user_can_create_wallet_connection_with_valid_blink_secret(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => self::VALID_BLINK_SECRET,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'blink')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('message', 'Wallet connection saved successfully');
        $response->assertJsonStructure(['data' => ['id', 'type', 'status', 'masked_secret']]);
        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'blink',
            'status' => 'pending',
            'reconfig' => false,
        ]);
        $connection = WalletConnection::where('store_id', $store->id)->first();
        $this->assertSame(self::VALID_BLINK_SECRET, Crypt::decryptString($connection->encrypted_secret));
    }

    #[Test]
    public function user_can_create_wallet_connection_with_blink_ln_address_secret(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => 'type=blink;ln-address=satoshi@blink.sv;',
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'blink')
            ->assertJsonPath('data.status', 'pending');
        $store->refresh();
        $this->assertSame('blink', $store->wallet_type);
        $connection = WalletConnection::where('store_id', $store->id)->first();
        $this->assertSame('type=blink;ln-address=satoshi@blink.sv;', Crypt::decryptString($connection->encrypted_secret));
    }

    #[Test]
    public function blink_ln_address_shorthand_sends_canonical_connection_string_to_btcpay(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/stores/blink-ln-store/lightning/BTC/connect')) {
                return Http::response(['success' => true], 200);
            }
            if (str_contains($url, '/lightning/BTC')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-blink-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'blink-ln-store',
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => 'satoshi@blink.sv',
            'fallback_lightning_address' => 'fallback@example.com',
        ])->assertStatus(201);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/stores/blink-ln-store/lightning/BTC/connect')
                && ($request->data()['ConnectionString'] ?? null) === 'type=blink;ln-address=satoshi@blink.sv;';
        });
        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'blink',
            'status' => 'connected',
        ]);
    }

    #[Test]
    public function blitz_bare_address_creates_blitz_connection_and_sends_canonical_string_to_btcpay(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/stores/blitz-store/lightning/BTC/connect')) {
                return Http::response(['success' => true], 200);
            }
            if (str_contains($url, '/lightning/BTC')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-blitz-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'blitz-store',
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blitz',
            'secret' => 'satoshi@blitzwalletapp.com',
            'fallback_lightning_address' => 'fallback@example.com',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'blitz');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/stores/blitz-store/lightning/BTC/connect')
                && ($request->data()['ConnectionString'] ?? null) === 'type=blitz;ln-address=satoshi@blitzwalletapp.com;';
        });

        $store->refresh();
        $this->assertSame('blitz', $store->wallet_type);
        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'blitz',
            'status' => 'connected',
        ]);
    }

    #[Test]
    public function cashu_store_saving_blitz_sets_pending_reconfig_for_config_bot(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/payment-methods/') && $request->method() === 'DELETE') {
                return Http::response([], 204);
            }

            if (
                $request->method() === 'DELETE'
                && str_contains($url, '/lightning/BTC')
                && ! str_contains($url, '/payment-methods/')
            ) {
                return Http::response([], 204);
            }

            if (str_contains($url, '/lightning/')) {
                return Http::response(['message' => 'test: no lightning API in fake'], 422);
            }

            if (! str_contains($url, 'cashumelt/settings')) {
                return Http::response(['message' => 'not found'], 404);
            }
            if ($request->method() === 'GET') {
                return Http::response([
                    'mintUrl' => 'https://mint.example/x',
                    'lightningAddress' => 'merchant@example.com',
                    'enabled' => true,
                ], 200);
            }
            if ($request->method() === 'PUT') {
                return Http::response(array_merge($request->data() ?? [], ['enabled' => false]), 200);
            }

            return Http::response('not found', 404);
        });

        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => 'store-cashu-blitz-reconfig',
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blitz',
            'secret' => 'satoshi@blitzwalletapp.com',
            'fallback_lightning_address' => 'fallback@example.com',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'blitz')
            ->assertJsonPath('data.status', 'pending');

        $store->refresh();
        $this->assertSame('blitz', $store->wallet_type);

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'blitz',
            'status' => 'pending',
            'reconfig' => true,
        ]);
    }

    #[Test]
    public function blitz_secret_without_ln_address_is_rejected(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blitz',
            'secret' => 'type=blitz;something=else;',
            'fallback_lightning_address' => 'fallback@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['secret']);
    }

    #[Test]
    public function nwc_connection_requires_fallback_lightning_address(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'nwc',
            'secret' => 'nostr+walletconnect://abc1234567890123456789012345678901234567890123456789012345678901234?relay=wss%3A%2F%2Frelay.example.com&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['fallback_lightning_address']);
    }

    #[Test]
    public function blink_ln_address_secret_derives_cashu_fallback_automatically(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $putPayload = null;
        Http::fake(function (Request $request) use (&$putPayload) {
            $url = $request->url();
            if (str_contains($url, 'cashumelt/settings')) {
                if ($request->method() === 'PUT') {
                    $putPayload = $request->data();
                }

                return Http::response($request->method() === 'PUT' ? ($putPayload ?? []) : [], 200);
            }
            if (str_contains($url, '/lightning/')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'blink-derive-store',
        ]);

        // No explicit fallback - the ln-address inside the secret is used.
        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => 'satoshi@blink.sv',
        ])->assertStatus(201);

        $this->assertNotNull($putPayload);
        $this->assertTrue((bool) ($putPayload['enabled'] ?? false));
        $this->assertSame('satoshi@blink.sv', $putPayload['lightningAddress'] ?? null);
        // No existing settings at the mint - the configured default mint applies.
        $this->assertSame(config('services.cashu.default_mint_url'), $putPayload['mintUrl'] ?? null);

        $store->refresh();
        $this->assertTrue((bool) $store->cashu_fallback_enabled);
        $this->assertSame('satoshi@blink.sv', $store->cashu_fallback_address);
    }

    #[Test]
    public function blink_ln_address_secret_with_empty_address_is_rejected(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => 'type=blink;ln-address=@blink.sv;',
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['secret']);
    }

    #[Test]
    public function cashu_store_saving_blink_sets_pending_reconfig_for_config_bot(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/payment-methods/') && $request->method() === 'DELETE') {
                return Http::response([], 204);
            }

            if (
                $request->method() === 'DELETE'
                && str_contains($url, '/lightning/BTC')
                && ! str_contains($url, '/payment-methods/')
            ) {
                return Http::response([], 204);
            }

            if (str_contains($url, '/lightning/')) {
                return Http::response(['message' => 'test: no lightning API in fake'], 422);
            }

            if (! str_contains($url, 'cashumelt/settings')) {
                return Http::response(['message' => 'not found'], 404);
            }
            if ($request->method() === 'GET') {
                return Http::response([
                    'mintUrl' => 'https://mint.example/x',
                    'lightningAddress' => 'merchant@example.com',
                    'enabled' => true,
                ], 200);
            }
            if ($request->method() === 'PUT') {
                return Http::response(array_merge($request->data() ?? [], ['enabled' => false]), 200);
            }

            return Http::response('not found', 404);
        });

        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => 'store-cashu-reconfig-bot',
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => self::VALID_BLINK_SECRET,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'blink')
            ->assertJsonPath('data.status', 'pending');

        $store->refresh();
        $this->assertSame('blink', $store->wallet_type);

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'blink',
            'status' => 'pending',
            'reconfig' => true,
        ]);
        $connection = WalletConnection::where('store_id', $store->id)->first();
        $this->assertSame(self::VALID_BLINK_SECRET, Crypt::decryptString($connection->encrypted_secret));
    }

    #[Test]
    public function user_can_create_wallet_connection_with_valid_aqua_descriptor(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'aqua_descriptor',
            'secret' => self::VALID_AQUA_DESCRIPTOR,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'aqua_descriptor');
        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'aqua_descriptor',
        ]);
    }

    #[Test]
    public function aqua_descriptor_marks_connected_when_boltz_greenfield_import_succeeds(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $btcpayStoreId = 'store-boltz-greenfield';

        Http::fake(function (Request $request) use ($btcpayStoreId) {
            $url = $request->url();

            if (str_contains($url, "/api/v1/stores/{$btcpayStoreId}/boltz/wallets") && $request->method() === 'POST') {
                return Http::response(['id' => 'wallet-1', 'name' => 'boltz-test'], 200);
            }

            if (str_contains($url, "/api/v1/stores/{$btcpayStoreId}/boltz/setup") && $request->method() === 'POST') {
                return Http::response(['enabled' => true], 200);
            }

            if (str_contains($url, '/plugins/cashumelt/settings') && $request->method() === 'GET') {
                return Http::response(['message' => 'not configured'], 404);
            }

            if (str_contains($url, '/payment-methods/') && $request->method() === 'DELETE') {
                return Http::response([], 404);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-boltz-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'name' => 'Acme Store',
            'wallet_type' => 'aqua_boltz',
            'btcpay_store_id' => $btcpayStoreId,
        ]);
        $walletName = app(BoltzService::class)->buildWalletName($store);
        $expectedDescriptor = app(WalletConnectionValidator::class)
            ->stripDescriptorChecksum(trim(self::VALID_AQUA_DESCRIPTOR));

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'aqua_descriptor',
            'secret' => self::VALID_AQUA_DESCRIPTOR,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'aqua_descriptor')
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'aqua_descriptor',
            'status' => 'connected',
        ]);

        Http::assertSent(function (Request $request) use ($btcpayStoreId, $walletName, $expectedDescriptor) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), "/api/v1/stores/{$btcpayStoreId}/boltz/wallets")) {
                return false;
            }
            $body = $request->data();

            return ($body['name'] ?? null) === $walletName
                && ($body['currency'] ?? null) === 'LBTC'
                && ($body['coreDescriptor'] ?? null) === $expectedDescriptor;
        });
        Http::assertSent(function (Request $request) use ($btcpayStoreId, $walletName) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), "/api/v1/stores/{$btcpayStoreId}/boltz/setup")) {
                return false;
            }
            $body = $request->data();

            return ($body['walletName'] ?? null) === $walletName;
        });
    }

    #[Test]
    public function nwc_connection_syncs_to_btcpay_with_formatted_string(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $btcpayStoreId = 'store-nwc-greenfield';
        $nwcUri = 'nostr+walletconnect://abc1234567890123456789012345678901234567890123456789012345678901234?relay=wss%3A%2F%2Frelay.example.com&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

        Http::fake(function (Request $request) use ($btcpayStoreId, $nwcUri) {
            $url = $request->url();

            if (str_contains($url, "/api/v1/stores/{$btcpayStoreId}/lightning/BTC/connect") && $request->method() === 'POST') {
                $body = $request->data();
                $connectionString = $body['ConnectionString'] ?? $body['connectionString'] ?? $body['connection_string'] ?? null;
                if ($connectionString === 'type=nwc;key='.$nwcUri) {
                    return Http::response(['success' => true], 200);
                }
            }

            if (str_contains($url, "/api/v1/stores/{$btcpayStoreId}/lightning/BTC") && $request->method() === 'GET') {
                return Http::response(['implementation' => 'nwc'], 200);
            }

            if (str_contains($url, '/payment-methods/') && $request->method() === 'DELETE') {
                return Http::response([], 404);
            }

            if (str_contains($url, '/plugins/cashumelt/settings') && $request->method() === 'GET') {
                return Http::response(['message' => 'not configured'], 404);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-nwc-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
            'btcpay_store_id' => $btcpayStoreId,
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'nwc',
            'secret' => $nwcUri,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'nwc');

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'nwc',
        ]);

        $this->assertSame('nwc', $store->fresh()->wallet_type);
    }

    #[Test]
    public function aqua_descriptor_marks_connected_when_boltz_fails_but_btcpay_lightning_is_active(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $btcpayStoreId = 'store-boltz-probe';

        Http::fake(function (Request $request) use ($btcpayStoreId) {
            $url = $request->url();

            if (str_contains($url, "/api/v1/stores/{$btcpayStoreId}/boltz/wallets") && $request->method() === 'POST') {
                return Http::response(['message' => 'boltz import failed'], 500);
            }

            if (str_contains($url, "/api/v1/stores/{$btcpayStoreId}/lightning/BTC/info") && $request->method() === 'GET') {
                return Http::response(['implementation' => 'boltz'], 200);
            }

            if (str_contains($url, '/plugins/cashumelt/settings') && $request->method() === 'GET') {
                return Http::response(['message' => 'not configured'], 404);
            }

            if (str_contains($url, '/payment-methods/') && $request->method() === 'DELETE') {
                return Http::response([], 404);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-probe-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'aqua_boltz',
            'btcpay_store_id' => $btcpayStoreId,
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'aqua_descriptor',
            'secret' => self::VALID_AQUA_DESCRIPTOR,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'aqua_descriptor',
            'status' => 'connected',
        ]);
    }

    #[Test]
    public function wallet_connection_store_validates_type_and_secret(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        // Valid type but invalid secret (too short, wrong format for blink)
        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => 'short',
            'fallback_lightning_address' => 'fallback@example.com',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['secret']);
    }

    #[Test]
    public function user_cannot_create_wallet_connection_for_other_users_store(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => self::VALID_BLINK_SECRET,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function check_duplicate_returns_false_when_descriptor_not_used(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/check-duplicate", [
            'descriptor' => self::VALID_AQUA_DESCRIPTOR,
            'type' => 'aqua_descriptor',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('message', null);
    }

    #[Test]
    public function check_duplicate_returns_true_when_descriptor_used_by_another_store(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $store1 = Store::factory()->create(['user_id' => $user1->id]);
        $store2 = Store::factory()->create(['user_id' => $user2->id]);
        WalletConnection::create([
            'store_id' => $store1->id,
            'type' => 'aqua_descriptor',
            'encrypted_secret' => Crypt::encryptString(self::VALID_AQUA_DESCRIPTOR),
            'status' => 'needs_support',
            'submitted_by_user_id' => $user1->id,
        ]);

        $response = $this->actingAs($user2)->postJson("/api/stores/{$store2->id}/wallet-connection/check-duplicate", [
            'descriptor' => self::VALID_AQUA_DESCRIPTOR,
            'type' => 'aqua_descriptor',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('existing_store_id', $store1->id)
            ->assertJsonPath('existing_store_name', $store1->name);
    }

    #[Test]
    public function check_duplicate_validates_descriptor_and_type(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/check-duplicate", [
            'descriptor' => '',
            'type' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['descriptor', 'type']);
    }

    #[Test]
    public function check_duplicate_new_returns_duplicate_when_descriptor_used(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'aqua_descriptor',
            'encrypted_secret' => Crypt::encryptString(self::VALID_AQUA_DESCRIPTOR),
            'status' => 'needs_support',
            'submitted_by_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/wallet-connection/check-duplicate', [
            'descriptor' => self::VALID_AQUA_DESCRIPTOR,
            'type' => 'aqua_descriptor',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('duplicate', true);
    }

    #[Test]
    public function user_can_delete_wallet_connection_when_status_is_pending(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $connection = WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::VALID_BLINK_SECRET),
            'status' => 'pending',
            'submitted_by_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/wallet-connection");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Wallet connection deleted successfully']);
        $this->assertDatabaseMissing('wallet_connections', ['id' => $connection->id]);
    }

    #[Test]
    public function user_cannot_delete_wallet_connection_when_status_is_not_pending(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::VALID_BLINK_SECRET),
            'status' => 'needs_support',
            'submitted_by_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/wallet-connection");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot delete wallet connection. Only pending connections can be deleted.',
            ]);
    }

    #[Test]
    public function delete_returns_404_when_no_wallet_connection(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/wallet-connection");

        $response->assertStatus(404)
            ->assertJson(['message' => 'Wallet connection not found']);
    }

    #[Test]
    public function user_cannot_delete_other_stores_wallet_connection(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::VALID_BLINK_SECRET),
            'status' => 'pending',
            'submitted_by_user_id' => $owner->id,
        ]);

        $response = $this->actingAs($other)->deleteJson("/api/stores/{$store->id}/wallet-connection");

        $response->assertStatus(403);
    }

    #[Test]
    public function store_returns_422_when_aqua_descriptor_duplicate(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $store1 = Store::factory()->create(['user_id' => $user1->id]);
        $store2 = Store::factory()->create(['user_id' => $user2->id]);
        WalletConnection::create([
            'store_id' => $store1->id,
            'type' => 'aqua_descriptor',
            'encrypted_secret' => Crypt::encryptString(self::VALID_AQUA_DESCRIPTOR),
            'status' => 'needs_support',
            'submitted_by_user_id' => $user1->id,
        ]);

        $response = $this->actingAs($user2)->postJson("/api/stores/{$store2->id}/wallet-connection", [
            'type' => 'aqua_descriptor',
            'secret' => self::VALID_AQUA_DESCRIPTOR,
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['secret']);
    }

    #[Test]
    public function recovery_phrase_user_can_reveal_wallet_connection_without_password(): void
    {
        $user = User::factory()->create([
            'guest_recovery_public_key' => str_repeat('a', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        $store = Store::factory()->create(['user_id' => $user->id]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::VALID_BLINK_SECRET),
            'status' => 'needs_support',
            'submitted_by_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/reveal", []);

        $response->assertStatus(200)
            ->assertJsonPath('data.secret', self::VALID_BLINK_SECRET)
            ->assertJsonPath('data.type', 'blink');
    }

    #[Test]
    public function saving_blink_wallet_configures_cashumelt_fallback_at_btcpay(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $putPayload = null;
        Http::fake(function (Request $request) use (&$putPayload) {
            $url = $request->url();

            if (str_contains($url, '/payment-methods/') && $request->method() === 'DELETE') {
                return Http::response([], 204);
            }

            if (
                $request->method() === 'DELETE'
                && str_contains($url, '/lightning/BTC')
                && ! str_contains($url, '/payment-methods/')
            ) {
                return Http::response([], 204);
            }

            if (str_contains($url, '/lightning/')) {
                return Http::response(['message' => 'test: no lightning API in fake'], 422);
            }

            if (! str_contains($url, 'cashumelt/settings')) {
                return Http::response(['message' => 'not found'], 404);
            }
            if ($request->method() === 'GET') {
                return Http::response([
                    'mintUrl' => 'https://mint.example/x',
                    'lightningAddress' => 'merchant@example.com',
                    'enabled' => true,
                ], 200);
            }
            if ($request->method() === 'PUT') {
                $putPayload = $request->data();

                return Http::response($putPayload ?? [], 200);
            }

            return Http::response('not found', 404);
        });

        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => 'store-btcpay-cashu-switch',
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => self::VALID_BLINK_SECRET,
            'fallback_lightning_address' => 'fallback@example.com',
        ])->assertStatus(201);

        // CashuMelt stays enabled in parallel with the new payout address; the existing mint is kept.
        $this->assertNotNull($putPayload);
        $this->assertTrue((bool) ($putPayload['enabled'] ?? false));
        $this->assertSame('fallback@example.com', $putPayload['lightningAddress'] ?? null);
        $this->assertSame('https://mint.example/x', $putPayload['mintUrl'] ?? null);
        $store->refresh();
        $this->assertSame('blink', $store->wallet_type);
        $this->assertTrue((bool) $store->cashu_fallback_enabled);
        $this->assertSame('fallback@example.com', $store->cashu_fallback_address);

        // The CASHU checkout method is no longer removed - it is the fallback.
        Http::assertNotSent(function (Request $request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/payment-methods/CASHU');
        });

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function switching_from_aqua_descriptor_to_blink_sets_reconfig_for_bot(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (
                $request->method() === 'DELETE'
                && str_contains($url, '/stores/aqua-to-blink-store/lightning/BTC')
                && ! str_contains($url, '/payment-methods/')
            ) {
                return Http::response([], 204);
            }
            if (str_contains($url, '/lightning/')) {
                return Http::response(['message' => 'fake: use bot'], 422);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'aqua_boltz',
            'btcpay_store_id' => 'aqua-to-blink-store',
        ]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'aqua_descriptor',
            'encrypted_secret' => Crypt::encryptString(self::VALID_AQUA_DESCRIPTOR),
            'status' => 'connected',
            'submitted_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'blink',
            'secret' => self::VALID_BLINK_SECRET,
            'fallback_lightning_address' => 'fallback@example.com',
        ])->assertStatus(201);

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'blink',
            'reconfig' => true,
            'status' => 'pending',
        ]);
        $store->refresh();
        $this->assertSame('blink', $store->wallet_type);
    }
}
