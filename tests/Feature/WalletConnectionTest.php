<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\BtcPay\BoltzService;
use App\Services\LnAddressLud21Prober;
use App\Services\WalletConnectionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ReadsEmailCodes;
use Tests\TestCase;

class WalletConnectionTest extends TestCase
{
    use ReadsEmailCodes, RefreshDatabase;

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
    public function flash_bare_address_creates_flash_connection_and_sends_canonical_string_to_btcpay(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/stores/flash-store/lightning/BTC/connect')) {
                return Http::response(['success' => true], 200);
            }
            if (str_contains($url, '/lightning/BTC') || str_contains($url, 'cashumelt/settings')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-flash-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'flash-store',
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'flash',
            'secret' => 'satoshi@flashapp.me',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'flash');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/stores/flash-store/lightning/BTC/connect')
                && ($request->data()['ConnectionString'] ?? null) === 'type=flash;ln-address=satoshi@flashapp.me;';
        });

        $store->refresh();
        $this->assertSame('flash', $store->wallet_type);
        // The address doubles as the CashuMelt fallback (derived, no explicit field).
        $this->assertTrue((bool) $store->cashu_fallback_enabled);
        $this->assertSame('satoshi@flashapp.me', $store->cashu_fallback_address);
    }

    #[Test]
    public function lnaddress_bare_curated_address_creates_lnaddress_connection_with_brand(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/stores/lnaddr-store/lightning/BTC/connect')) {
                return Http::response(['success' => true], 200);
            }
            if (str_contains($url, '/lightning/BTC') || str_contains($url, 'cashumelt/settings')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-lnaddr-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'lnaddr-store',
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'lnaddress',
            'secret' => 'type=lnaddress;ln-address=merchant@coinos.io;',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'lnaddress');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/stores/lnaddr-store/lightning/BTC/connect')
                && ($request->data()['ConnectionString'] ?? null) === 'type=lnaddress;ln-address=merchant@coinos.io;';
        });

        $store->refresh();
        $this->assertSame('lnaddress', $store->wallet_type);
        // The address doubles as the CashuMelt fallback (derived, no explicit field).
        $this->assertTrue((bool) $store->cashu_fallback_enabled);
        $this->assertSame('merchant@coinos.io', $store->cashu_fallback_address);

        // The masked show endpoint derives the curated brand from the secret.
        $this->actingAs($user)->getJson("/api/stores/{$store->id}/wallet-connection")
            ->assertOk()
            ->assertJsonPath('data.type', 'lnaddress')
            ->assertJsonPath('data.brand', 'coinos');
    }

    #[Test]
    public function lnaddress_rejects_bare_username_without_domain(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-lnaddr-key2']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'lnaddr-store-2',
        ]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'lnaddress',
            'secret' => 'type=lnaddress;ln-address=satoshi;',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['secret']);
    }

    /**
     * Test domains do not resolve in DNS - stub the prober's resolver. A host
     * mapped to null "resolves privately" (refused); unmapped hosts get a
     * public address so the HTTP fakes drive the outcome.
     *
     * @param  array<string, list<string>|null>  $map
     */
    private function fakeProberDns(array $map = []): void
    {
        $this->app->bind(LnAddressLud21Prober::class, function () use ($map) {
            return new class($map) extends LnAddressLud21Prober
            {
                /** @param array<string, list<string>|null> $map */
                public function __construct(private array $map) {}

                protected function resolvePublicIps(string $host): ?array
                {
                    return array_key_exists($host, $this->map) ? $this->map[$host] : ['198.51.100.7'];
                }
            };
        });
    }

    #[Test]
    public function lnaddress_probe_reports_lud21_support_from_the_wallets_lnurl_server(): void
    {
        $this->fakeProberDns();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($url === 'https://lud21wallet.example/.well-known/lnurlp/alice') {
                return Http::response([
                    'tag' => 'payRequest',
                    'callback' => 'https://lud21wallet.example/pay/lnurl/alice',
                    'minSendable' => 1000,
                    'maxSendable' => 10000000000,
                ], 200);
            }
            if (str_starts_with($url, 'https://lud21wallet.example/pay/lnurl/alice')) {
                // The probe must request an invoice with a clamped msat amount.
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $amount = $query['amount'] ?? null;
                if (! is_numeric($amount) || (int) $amount < 1000 || (int) $amount > 10000000000) {
                    return Http::response(['status' => 'ERROR', 'reason' => 'bad amount'], 400);
                }

                return Http::response([
                    'pr' => 'lnbc10n1invoice',
                    'verify' => 'https://lud21wallet.example/verify/abc',
                ], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'alice@lud21wallet.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', true)
            ->assertJsonPath('data.reason', 'ok');
    }

    #[Test]
    public function lnaddress_probe_cache_is_scoped_to_the_full_address(): void
    {
        $this->fakeProberDns();
        Cache::flush();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($url === 'https://sharedwallet.example/.well-known/lnurlp/alice') {
                return Http::response([
                    'tag' => 'payRequest',
                    'callback' => 'https://sharedwallet.example/pay/lnurl/alice',
                    'minSendable' => 1000,
                    'maxSendable' => 10000000000,
                ], 200);
            }
            if (str_starts_with($url, 'https://sharedwallet.example/pay/lnurl/alice')) {
                return Http::response([
                    'pr' => 'lnbc10n1invoice',
                    'verify' => 'https://sharedwallet.example/verify/alice',
                ], 200);
            }
            if ($url === 'https://sharedwallet.example/.well-known/lnurlp/bob') {
                return Http::response(['message' => 'not found'], 404);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'alice@sharedwallet.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', true)
            ->assertJsonPath('data.reason', 'ok');

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'bob@sharedwallet.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', false)
            ->assertJsonPath('data.reason', 'unreachable');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://sharedwallet.example/.well-known/lnurlp/bob');
    }

    #[Test]
    public function lnaddress_probe_reports_missing_lud21_when_callback_has_no_verify(): void
    {
        $this->fakeProberDns();

        Http::fake([
            'https://plainwallet.example/.well-known/lnurlp/bob' => Http::response([
                'tag' => 'payRequest',
                'callback' => 'https://plainwallet.example/pay/lnurl/bob',
                'minSendable' => 1000,
                'maxSendable' => 10000000000,
            ], 200),
            'https://plainwallet.example/pay/lnurl/bob*' => Http::response([
                'pr' => 'lnbc10n1invoice',
            ], 200),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'bob@plainwallet.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', false)
            ->assertJsonPath('data.reason', 'no_verify');
    }

    #[Test]
    public function lnaddress_probe_reports_unreachable_domains(): void
    {
        $this->fakeProberDns();

        Http::fake([
            '*' => Http::response(['message' => 'not found'], 404),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'ghost@deadwallet.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', false)
            ->assertJsonPath('data.reason', 'unreachable');
    }

    #[Test]
    public function lnaddress_probe_does_not_follow_lnurl_redirects(): void
    {
        $this->fakeProberDns();

        Http::fake([
            'https://redirecting.example/.well-known/lnurlp/eve' => Http::response(
                null,
                302,
                ['Location' => 'https://internal.example/steal']
            ),
            '*' => Http::response(['message' => 'not found'], 404),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'eve@redirecting.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', false)
            ->assertJsonPath('data.reason', 'unreachable');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'internal.example'));
    }

    #[Test]
    public function lnaddress_probe_refuses_callbacks_on_private_hosts(): void
    {
        // Callback host "resolves" to a private address -> the probe must not call it.
        $this->fakeProberDns(['internal.example' => null]);

        Http::fake([
            'https://evilwallet.example/.well-known/lnurlp/mallory' => Http::response([
                'tag' => 'payRequest',
                'callback' => 'https://internal.example/pay/lnurl/mallory',
                'minSendable' => 1000,
                'maxSendable' => 10000000000,
            ], 200),
            '*' => Http::response(['message' => 'not found'], 404),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'mallory@evilwallet.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', false)
            ->assertJsonPath('data.reason', 'invalid_lnurlp');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'internal.example'));
    }

    #[Test]
    public function lnaddress_probe_refuses_ip_literal_callbacks(): void
    {
        $this->fakeProberDns();

        Http::fake([
            'https://looper.example/.well-known/lnurlp/carol' => Http::response([
                'tag' => 'payRequest',
                'callback' => 'https://192.168.1.1/pay/lnurl/carol',
                'minSendable' => 1000,
                'maxSendable' => 10000000000,
            ], 200),
            '*' => Http::response(['message' => 'not found'], 404),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'carol@looper.example',
        ])->assertOk()
            ->assertJsonPath('data.lud21', false)
            ->assertJsonPath('data.reason', 'invalid_lnurlp');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '192.168.1.1'));
    }

    #[Test]
    public function lnaddress_probe_requires_store_ownership(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);
        $other = User::factory()->create();

        $this->actingAs($other)->postJson("/api/stores/{$store->id}/wallet-connection/lnaddress-probe", [
            'address' => 'alice@lud21wallet.example',
        ])->assertStatus(403);

        Http::assertNothingSent();
    }

    #[Test]
    public function lnaddress_accepts_a_short_but_valid_lightning_address(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/lightning/BTC') || str_contains($url, 'cashumelt/settings')) {
                return Http::response(['success' => true], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-short-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'short-store',
        ]);

        // 7 chars - valid user@domain, but below the generic min:10 of other types.
        $this->actingAs($user)->postJson("/api/stores/{$store->id}/wallet-connection", [
            'type' => 'lnaddress',
            'secret' => 'a@bc.io',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'lnaddress');
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

        // Replacing a connected wallet needs the email-code grant (see WalletChangeConfirmationGuard).
        $this->grantWalletChange($user, $store);

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

    // ---- Replacing a CONNECTED wallet: email-code grant (WalletChangeConfirmationGuard)

    private function connectedBlinkStore(User $user): Store
    {
        $store = Store::factory()->create(['user_id' => $user->id, 'wallet_type' => 'blink']);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::VALID_BLINK_SECRET),
            'status' => 'connected',
            'submitted_by_user_id' => $user->id,
        ]);

        return $store;
    }

    /** @return array<string, mixed> */
    private function blinkReplacementPayload(): array
    {
        return [
            'type' => 'blink',
            'secret' => 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_rotated;wallet-id=wallet789',
            'fallback_lightning_address' => 'merchant@blink.sv',
        ];
    }

    #[Test]
    public function first_wallet_connection_needs_no_email_code(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection/change/request", [])
            ->assertStatus(200)
            ->assertJsonPath('data.required', false);

        $this->postJson("/api/stores/{$store->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(201);
    }

    #[Test]
    public function pending_wallet_connection_can_be_replaced_without_email_code(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::VALID_BLINK_SECRET),
            'status' => 'pending',
            'submitted_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(201);
    }

    #[Test]
    public function replacing_connected_wallet_without_grant_is_rejected(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $store = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'wallet_change_confirmation_required');

        $this->assertSame(
            self::VALID_BLINK_SECRET,
            Crypt::decryptString(WalletConnection::where('store_id', $store->id)->firstOrFail()->encrypted_secret)
        );
        $this->getJson("/api/stores/{$store->id}/wallet-connection")
            ->assertJsonPath('data.change_confirmation.required', true)
            ->assertJsonPath('data.change_confirmation.guest_upgrade_required', false)
            ->assertJsonPath('data.change_confirmation.pending', null)
            ->assertJsonPath('data.change_confirmation.granted_until', null);
    }

    #[Test]
    public function guest_owner_must_upgrade_before_replacing_connected_wallet(): void
    {
        Http::fake();
        $guest = User::factory()->guest()->create([
            'email' => 'guest+abc@guest.satflux.io',
            'guest_recovery_public_key' => str_repeat('a', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        $store = $this->connectedBlinkStore($guest);

        $this->actingAs($guest)
            ->postJson("/api/stores/{$store->id}/wallet-connection/change/request", [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'guest_upgrade_required');

        $this->postJson("/api/stores/{$store->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'guest_upgrade_required');

        $this->getJson("/api/stores/{$store->id}/wallet-connection")
            ->assertJsonPath('data.change_confirmation.guest_upgrade_required', true);

        // Guests can still look at their own secret (session-only reveal) - nothing to confirm with.
        $this->postJson("/api/stores/{$store->id}/wallet-connection/reveal", [])
            ->assertStatus(200)
            ->assertJsonPath('data.secret', self::VALID_BLINK_SECRET);
    }

    #[Test]
    public function change_request_requires_password_for_password_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => bcrypt('correct-horse')]);
        $store = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection/change/request", ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->postJson("/api/stores/{$store->id}/wallet-connection/change/request", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->postJson("/api/stores/{$store->id}/wallet-connection/change/request", ['password' => 'correct-horse'])
            ->assertStatus(200)
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.challenge.purpose', 'wallet_connection_change')
            ->assertJsonPath('data.challenge.email', $user->email);
    }

    #[Test]
    public function change_request_reports_not_required_when_nothing_is_connected(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection/change/request", [])
            ->assertStatus(200)
            ->assertJsonPath('data.required', false);
    }

    #[Test]
    public function confirmed_code_grants_reveal_and_one_replacement(): void
    {
        Http::fake();
        Notification::fake();
        $user = User::factory()->create([
            'guest_recovery_public_key' => str_repeat('a', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        $store = $this->connectedBlinkStore($user);

        // Recovery-phrase account: no password needed for the request.
        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection/change/request", [])
            ->assertStatus(200)
            ->assertJsonPath('data.required', true);

        $this->getJson("/api/stores/{$store->id}/wallet-connection")
            ->assertJsonPath('data.change_confirmation.pending.email', $user->email);

        // Plain reveal is gated by the grant on a connected store.
        $this->postJson("/api/stores/{$store->id}/wallet-connection/reveal", [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'wallet_change_confirmation_required');

        $code = $this->lastEmailCode($user->email);
        $this->postJson("/api/stores/{$store->id}/wallet-connection/change/confirm", ['code' => $this->wrongEmailCode($code)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_mismatch');

        $confirm = $this->postJson("/api/stores/{$store->id}/wallet-connection/change/confirm", ['code' => $code]);
        $confirm->assertStatus(200)
            ->assertJsonPath('data.secret', self::VALID_BLINK_SECRET)
            ->assertJsonPath('data.type', 'blink');
        $this->assertNotNull($confirm->json('data.granted_until'));

        $this->getJson("/api/stores/{$store->id}/wallet-connection")
            ->assertJsonPath('data.change_confirmation.pending', null);
        $this->assertNotNull($this->getJson("/api/stores/{$store->id}/wallet-connection")->json('data.change_confirmation.granted_until'));

        $this->postJson("/api/stores/{$store->id}/wallet-connection/reveal", [])->assertStatus(200);

        $this->postJson("/api/stores/{$store->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(201);
        $this->assertStringContainsString(
            'blink_rotated',
            Crypt::decryptString(WalletConnection::where('store_id', $store->id)->firstOrFail()->encrypted_secret)
        );

        // The grant is single-use: the replacement flipped the row to pending
        // (bot/sync runs), and once connected again a new code is required.
        WalletConnection::where('store_id', $store->id)->update(['status' => 'connected']);
        $this->postJson("/api/stores/{$store->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(409);
    }

    #[Test]
    public function grant_is_scoped_to_the_store_it_was_issued_for(): void
    {
        Http::fake();
        Notification::fake();
        $user = User::factory()->create([
            'guest_recovery_public_key' => str_repeat('a', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        $storeA = $this->connectedBlinkStore($user);
        $storeB = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->postJson("/api/stores/{$storeA->id}/wallet-connection/change/request", [])
            ->assertStatus(200);
        $code = $this->lastEmailCode();

        $this->postJson("/api/stores/{$storeB->id}/wallet-connection/change/confirm", ['code' => $code])
            ->assertStatus(410)
            ->assertJsonPath('code', 'challenge_missing');

        $this->postJson("/api/stores/{$storeB->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(409);
    }

    #[Test]
    public function expired_grant_requires_a_new_code(): void
    {
        Http::fake();
        Notification::fake();
        $user = User::factory()->create([
            'guest_recovery_public_key' => str_repeat('a', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        $store = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection/change/request", [])
            ->assertStatus(200);
        $this->postJson("/api/stores/{$store->id}/wallet-connection/change/confirm", ['code' => $this->lastEmailCode()])
            ->assertStatus(200);

        $this->travel(16)->minutes();

        $this->postJson("/api/stores/{$store->id}/wallet-connection", $this->blinkReplacementPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'wallet_change_confirmation_required');
    }

    #[Test]
    public function change_resend_rotates_code_after_cooldown(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'guest_recovery_public_key' => str_repeat('a', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        $store = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection/change/request", [])
            ->assertStatus(200);

        $this->postJson("/api/stores/{$store->id}/wallet-connection/change/resend")
            ->assertStatus(429)
            ->assertJsonPath('code', 'resend_cooldown');

        $this->travel(61)->seconds();
        $this->postJson("/api/stores/{$store->id}/wallet-connection/change/resend")
            ->assertStatus(200)
            ->assertJsonPath('data.challenge.sends_left', 3);

        $this->postJson("/api/stores/{$store->id}/wallet-connection/change/confirm", ['code' => $this->lastEmailCode()])
            ->assertStatus(200);
    }

    #[Test]
    public function configure_endpoint_on_connected_store_requires_grant(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $store = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/wallet-connection/configure", [
                'connection_string' => self::VALID_BLINK_SECRET,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'wallet_change_confirmation_required');
        Http::assertNothingSent();
    }

    #[Test]
    public function samrock_complete_on_connected_store_requires_grant(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $store = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/samrock/complete", [
                'otp' => '123456',
                'fallback_lightning_address' => 'merchant@blink.sv',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'wallet_change_confirmation_required');
        Http::assertNothingSent();
    }

    #[Test]
    public function switching_connected_lightning_store_to_cashu_requires_grant(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $store = $this->connectedBlinkStore($user);

        $this->actingAs($user)
            ->putJson("/api/stores/{$store->id}/cashu/settings", [
                'mint_url' => 'https://mint.example.com',
                'lightning_address' => 'merchant@blink.sv',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'wallet_change_confirmation_required');
        Http::assertNothingSent();
        $this->assertSame('blink', $store->fresh()->wallet_type);
    }
}
