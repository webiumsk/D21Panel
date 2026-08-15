<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoltzReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.btcpay.base_url' => 'https://btcpay.test',
            'services.boltz.api_url' => 'https://boltz.test',
        ]);
    }

    /**
     * @param  array<string, callable|array>  $overrides  url-fragment => response factory
     */
    protected function fakeHappyPath(array $overrides = []): void
    {
        Http::fake(function (Request $request) use ($overrides) {
            $url = $request->url();

            foreach ($overrides as $fragment => $response) {
                if (str_contains($url, $fragment)) {
                    return is_callable($response) ? $response($request) : Http::response(...$response);
                }
            }

            if (str_contains($url, 'boltz.test/v2/swap/reverse')) {
                return Http::response([
                    'BTC' => [
                        'L-BTC' => [
                            'hash' => 'abc',
                            'rate' => 1,
                            'limits' => ['minimal' => 100, 'maximal' => 25000000],
                            'fees' => ['percentage' => 0.25, 'minerFees' => ['claim' => 20, 'lockup' => 27]],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/boltz/setup')) {
                return Http::response([
                    'enabled' => true,
                    'wallet' => ['id' => 1, 'name' => 'boltz-test', 'currency' => 'LBTC', 'readonly' => true],
                ], 200);
            }

            if (str_contains($url, '/lightning/BTC/info')) {
                return Http::response(['nodeURIs' => [], 'blockHeight' => 900000], 200);
            }

            if (str_contains($url, '/payment-methods')) {
                return Http::response([
                    ['paymentMethodId' => 'BTC-CHAIN', 'enabled' => true],
                    ['paymentMethodId' => 'BTC-LN', 'enabled' => true],
                ], 200);
            }

            return Http::response(['message' => 'unexpected call: '.$url], 500);
        });
    }

    protected function makeStore(string $walletType = 'aqua_boltz', ?string $apiKey = 'merchant-key'): array
    {
        $user = User::factory()->create(['btcpay_api_key' => $apiKey]);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => $walletType,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        return [$user, $store];
    }

    #[Test]
    public function ready_store_reports_limits_and_fees(): void
    {
        $this->fakeHappyPath();
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/boltz/readiness");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.limits.min', 100)
            ->assertJsonPath('data.limits.max', 25000000)
            ->assertJsonPath('data.limits.is_stale', false)
            ->assertJsonPath('data.limits.source', 'boltz_public_api')
            ->assertJsonPath('data.fees.percentage', 0.25)
            ->assertJsonPath('data.plugin.enabled', true)
            ->assertJsonPath('data.plugin.wallet_readonly', true)
            ->assertJsonPath('data.lightning_active', true)
            ->assertJsonPath('data.onchain_fallback', true)
            ->assertJsonPath('data.reasons', []);

        // Merchant API key must never leak into the response.
        $this->assertStringNotContainsString('merchant-key', $response->getContent());
    }

    #[Test]
    public function unsupported_wallet_type_skips_btcpay_probes(): void
    {
        Http::fake();
        [$user, $store] = $this->makeStore('blink');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/boltz/readiness");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'unsupported')
            ->assertJsonPath('data.reasons.0', 'wallet_type_not_boltz');

        Http::assertNothingSent();
    }

    #[Test]
    public function disabled_setup_is_misconfigured(): void
    {
        $this->fakeHappyPath([
            '/boltz/setup' => [['enabled' => false], 200],
        ]);
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'misconfigured')
            ->assertJsonPath('data.plugin.enabled', false)
            ->assertJsonFragment(['reasons' => ['setup_disabled']]);
    }

    #[Test]
    public function unreachable_plugin_is_unavailable(): void
    {
        $this->fakeHappyPath([
            '/boltz/setup' => [['code' => 'boltz-unavailable', 'message' => 'daemon down'], 503],
        ]);
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.plugin.reachable', false)
            ->assertJsonFragment(['reasons' => ['plugin_unreachable']]);
    }

    #[Test]
    public function missing_lightning_method_is_misconfigured(): void
    {
        $this->fakeHappyPath([
            '/lightning/BTC/info' => [['message' => 'not configured'], 404],
        ]);
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'misconfigured')
            ->assertJsonPath('data.lightning_active', false)
            ->assertJsonFragment(['reasons' => ['lightning_not_configured']]);
    }

    #[Test]
    public function boltz_backend_down_degrades_but_does_not_disable(): void
    {
        $this->fakeHappyPath([
            'boltz.test/v2/swap/reverse' => [['message' => 'down'], 500],
        ]);
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.limits', null)
            ->assertJsonPath('data.lightning_active', true)
            ->assertJsonFragment(['reasons' => ['limits_unavailable']]);
    }

    #[Test]
    public function stale_limits_snapshot_reports_stale_status(): void
    {
        $this->fakeHappyPath();
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        // Pre-seed the fresh cache with an old snapshot so no refetch happens.
        Cache::put('boltz:pair:reverse:BTC-L-BTC:fresh', [
            'min' => 1000,
            'max' => 20000000,
            'fee_percentage' => 0.25,
            'miner_fees' => ['claim' => 20, 'lockup' => 27],
            'hash' => 'old',
            'fetched_at' => now()->subSeconds((int) config('services.boltz.pairs_stale_after') + 60)->toIso8601String(),
        ], 300);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'stale')
            ->assertJsonPath('data.limits.is_stale', true)
            ->assertJsonPath('data.limits.min', 1000)
            ->assertJsonFragment(['reasons' => ['limits_stale']]);
    }

    #[Test]
    public function missing_onchain_fallback_is_flagged_but_stays_ready(): void
    {
        $this->fakeHappyPath([
            '/payment-methods' => [[['paymentMethodId' => 'BTC-LN', 'enabled' => true]], 200],
        ]);
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.onchain_fallback', false)
            ->assertJsonFragment(['reasons' => ['onchain_fallback_missing']]);
    }

    #[Test]
    public function readiness_is_cached_and_refresh_bypasses_cache(): void
    {
        $this->fakeHappyPath();
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")->assertStatus(200);
        $first = count(Http::recorded());

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")->assertStatus(200);
        $this->assertSame($first, count(Http::recorded()), 'cached readiness must not re-probe BTCPay');

        $this->getJson("/api/stores/{$store->id}/boltz/readiness?refresh=1")->assertStatus(200);
        $this->assertGreaterThan($first, count(Http::recorded()), 'refresh=1 must re-probe');
    }

    #[Test]
    public function other_users_store_is_forbidden(): void
    {
        Http::fake();
        [, $store] = $this->makeStore();
        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")->assertStatus(403);
    }

    #[Test]
    public function missing_merchant_api_key_is_misconfigured(): void
    {
        $this->fakeHappyPath();
        [$user, $store] = $this->makeStore(apiKey: null);
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/boltz/readiness")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'misconfigured')
            ->assertJsonFragment(['reasons' => ['missing_merchant_api_key']]);
    }
}
