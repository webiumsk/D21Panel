<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RaffleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_returns_available_when_plugin_responds(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/raffles/status?refresh=1");

        $response->assertOk();
        $response->assertJsonPath('data.available', true);
    }

    public function test_status_returns_unavailable_when_plugin_missing(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response('Not found', 404);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/raffles/status?refresh=1");

        $response->assertOk();
        $response->assertJsonPath('data.available', false);
    }

    public function test_list_returns_plugin_unavailable_on_404(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response('Not found', 404);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/raffles");

        $response->assertNotFound();
        $response->assertJsonPath('code', 'raffle_plugin_unavailable');
    }

    public function test_create_and_open_raffle(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $raffleId = 'raffle-uuid-1';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([
                    'id' => $raffleId,
                    'name' => 'Spring Raffle',
                    'description' => null,
                    'storeId' => $btcpayStoreId,
                    'ticketPriceSats' => 21000,
                    'maxTickets' => 100,
                    'status' => 'Draft',
                    'ticketsSold' => 0,
                    'createdAt' => '2026-05-18T12:00:00Z',
                ], 201);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}/open")) {
                return Http::response([
                    'id' => $raffleId,
                    'name' => 'Spring Raffle',
                    'status' => 'Open',
                    'ticketsSold' => 0,
                    'ticketPriceSats' => 21000,
                    'maxTickets' => 100,
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $create = $this->postJson("/api/stores/{$store->id}/raffles", [
            'name' => 'Spring Raffle',
            'ticketPriceSats' => 21000,
            'maxTickets' => 100,
        ]);

        $create->assertCreated();
        $create->assertJsonPath('data.status', 'Draft');
        $create->assertJsonPath('data.allowedActions', ['open']);

        $open = $this->postJson("/api/stores/{$store->id}/raffles/{$raffleId}/open");

        $open->assertOk();
        $open->assertJsonPath('data.status', 'Open');
        $open->assertJsonPath('data.allowedActions', ['close']);
        $open->assertJsonPath('data.showsPublicLink', true);
    }

    public function test_create_raffle_with_fiat_currency_proxies_to_btcpay(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $raffleId = 'raffle-eur-1';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                $body = $request->data();
                $this->assertSame('EUR', $body['ticketCurrency'] ?? null);
                $this->assertEquals(5, $body['ticketPrice'] ?? null);
                $this->assertArrayNotHasKey('ticketPriceSats', $body);

                return Http::response([
                    'id' => $raffleId,
                    'name' => 'Euro Raffle',
                    'ticketCurrency' => 'EUR',
                    'ticketPrice' => 5.0,
                    'ticketPriceSats' => null,
                    'status' => 'Draft',
                    'ticketsSold' => 0,
                    'maxTickets' => null,
                ], 201);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/raffles", [
            'name' => 'Euro Raffle',
            'ticketCurrency' => 'eur',
            'ticketPrice' => 5,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.ticketCurrency', 'EUR');
    }

    public function test_update_draft_raffle_proxies_put(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $raffleId = '11111111-1111-1111-1111-111111111111';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'PUT' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}")) {
                $body = $request->data();
                $this->assertSame('Updated name', $body['name'] ?? null);
                $this->assertSame(25000, $body['ticketPriceSats'] ?? null);

                return Http::response([
                    'id' => $raffleId,
                    'name' => 'Updated name',
                    'status' => 'Draft',
                    'ticketPriceSats' => 25000,
                    'maxTickets' => 50,
                    'ticketsSold' => 0,
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/stores/{$store->id}/raffles/{$raffleId}", [
            'name' => 'Updated name',
            'ticketPriceSats' => 25000,
            'maxTickets' => 50,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Updated name');
        $response->assertJsonPath('data.status', 'Draft');
    }

    public function test_delete_draft_raffle_proxies_delete(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $raffleId = '44444444-4444-4444-4444-444444444444';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}")) {
                return Http::response([
                    'id' => $raffleId,
                    'name' => 'Draft raffle',
                    'status' => 'Draft',
                    'ticketsSold' => 0,
                    'ticketPriceSats' => 1000,
                ], 200);
            }

            if ($request->method() === 'DELETE' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}")) {
                return Http::response(['deleted' => true], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/stores/{$store->id}/raffles/{$raffleId}");

        $response->assertOk();
        $response->assertJsonPath('data.deleted', true);
    }

    public function test_delete_open_raffle_returns_forbidden_without_btcpay_delete(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $raffleId = '77777777-7777-7777-7777-777777777777';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}")) {
                return Http::response([
                    'id' => $raffleId,
                    'name' => 'Open raffle',
                    'status' => 'Open',
                    'ticketsSold' => 0,
                    'ticketPriceSats' => 1000,
                ], 200);
            }

            if ($request->method() === 'DELETE' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}")) {
                return Http::response(['deleted' => true], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/stores/{$store->id}/raffles/{$raffleId}");

        $response->assertForbidden();
        $response->assertJsonFragment(['message' => __('messages.raffles_cannot_delete')]);

        Http::assertNotSent(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();

            return $request->method() === 'DELETE'
                && str_contains($url, $baseUrl)
                && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}");
        });
    }

    public function test_tickets_list_masks_buyer_email(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $raffleId = '55555555-5555-5555-5555-555555555555';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}/tickets")) {
                return Http::response([
                    [
                        'ticketNumber' => 1,
                        'buyerName' => 'Alice',
                        'buyerEmail' => 'alice@secret.com',
                        'allocatedAt' => '2026-05-18T12:00:00Z',
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/raffles/{$raffleId}/tickets");

        $response->assertOk();
        $response->assertJsonPath('data.0.buyerEmail', 'a***@secret.com');
        $response->assertJsonPath('data.0.buyerName', 'Alice');
    }

    public function test_presenter_token_proxies_post(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        config([
            'services.btcpay.base_url' => 'http://btcpay-internal:49392',
            'services.btcpay.public_url' => 'https://btcpay.example.com',
        ]);
        $apiBase = rtrim(config('services.btcpay.base_url'), '/');
        $publicBase = rtrim(config('services.btcpay.public_url'), '/');
        $raffleId = '22222222-2222-2222-2222-222222222222';
        $internalPresenterUrl = 'http://btcpay:49392/raffle/'.$raffleId.'/present?token=abc';

        Http::fake(function (Request $request) use ($apiBase, $btcpayStoreId, $raffleId, $internalPresenterUrl) {
            $url = (string) $request->url();
            if (! str_contains($url, $apiBase)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}/presenter-token")) {
                return Http::response([
                    'token' => 'abc',
                    'expiresAt' => '2026-05-18T18:00:00Z',
                    'presenterUrl' => $internalPresenterUrl,
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/raffles/{$raffleId}/presenter-token");

        $expectedPublicUrl = $publicBase.'/raffle/'.$raffleId.'/present?token=abc';

        $response->assertOk();
        $response->assertJsonPath('data.presenterUrl', $expectedPublicUrl);
        $response->assertJsonPath('data.token', 'abc');
    }

    public function test_free_plan_limits_one_raffle_per_store(): void
    {
        $user = User::factory()->create(['role' => 'free']);
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $existingId = '11111111-1111-1111-1111-111111111111';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $existingId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([
                    [
                        'id' => $existingId,
                        'name' => 'Existing',
                        'status' => 'Draft',
                        'ticketsSold' => 0,
                        'ticketPriceSats' => 1000,
                        'createdAt' => '2026-05-18T12:00:00Z',
                    ],
                ], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response(['id' => 'new'], 201);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/raffles", [
            'name' => 'Second Raffle',
            'ticketPriceSats' => 5000,
        ]);

        $response->assertForbidden();
        $response->assertJsonFragment(['message' => __('messages.raffles_limit_free', ['max' => 1])]);

        Http::assertNotSent(function (Request $request) use ($baseUrl, $btcpayStoreId) {
            $url = (string) $request->url();

            return $request->method() === 'POST'
                && str_contains($url, $baseUrl)
                && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle");
        });
    }

    public function test_free_plan_create_returns_503_when_quota_list_fails(): void
    {
        $user = User::factory()->create(['role' => 'free']);
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response(['message' => 'upstream down'], 502);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/raffles", [
            'name' => 'Should not create',
            'ticketPriceSats' => 5000,
        ]);

        $response->assertStatus(503);
        $response->assertJsonFragment(['message' => __('messages.raffles_quota_verification_failed')]);

        Http::assertNotSent(function (Request $request) use ($baseUrl, $btcpayStoreId) {
            $url = (string) $request->url();

            return $request->method() === 'POST'
                && str_contains($url, $baseUrl)
                && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle");
        });
    }

    public function test_pro_plan_allows_multiple_raffles(): void
    {
        $proPlan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => [],
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => 'free']);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([
                    ['id' => 'a', 'name' => 'One', 'status' => 'Draft', 'ticketsSold' => 0, 'ticketPriceSats' => 1000, 'createdAt' => '2026-05-18T12:00:00Z'],
                ], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([
                    'id' => 'b',
                    'name' => 'Two',
                    'status' => 'Draft',
                    'ticketsSold' => 0,
                    'ticketPriceSats' => 2000,
                    'createdAt' => '2026-05-18T12:01:00Z',
                ], 201);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/raffles", [
            'name' => 'Two',
            'ticketPriceSats' => 2000,
        ]);

        $response->assertCreated();
    }

    public function test_add_manual_tickets_proxies_post(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $raffleId = '66666666-6666-6666-6666-666666666666';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}/tickets/manual")) {
                $body = $request->data();
                if (($body['count'] ?? null) !== 2 || ($body['buyerEmail'] ?? null) !== 'buyer@example.com') {
                    return Http::response(['message' => 'bad payload'], 400);
                }

                return Http::response([
                    [
                        'ticketNumber' => 10,
                        'buyerName' => 'Nick',
                        'buyerEmail' => 'buyer@example.com',
                        'allocatedAt' => '2026-05-18T12:00:00Z',
                        'isManual' => true,
                    ],
                    [
                        'ticketNumber' => 11,
                        'buyerName' => 'Nick',
                        'buyerEmail' => 'buyer@example.com',
                        'allocatedAt' => '2026-05-18T12:00:01Z',
                        'isManual' => true,
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/raffles/{$raffleId}/tickets/manual", [
            'count' => 2,
            'buyerEmail' => 'buyer@example.com',
            'buyerName' => 'Nick',
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.buyerEmail', 'b***@example.com');
        $response->assertJsonPath('data.0.isManual', true);
    }

    public function test_presenter_token_rebuilds_relative_url(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        config(['services.btcpay.public_url' => 'https://pay.example.com']);
        $apiBase = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $publicBase = rtrim(config('services.btcpay.public_url'), '/');
        $raffleId = '33333333-3333-3333-3333-333333333333';

        Http::fake(function (Request $request) use ($apiBase, $btcpayStoreId, $raffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $apiBase)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'POST' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle/{$raffleId}/presenter-token")) {
                return Http::response([
                    'token' => 'xyz',
                    'expiresAt' => '2026-05-18T18:00:00Z',
                    'presenterUrl' => '/raffle/'.$raffleId.'/present?token=xyz',
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/raffles/{$raffleId}/presenter-token");

        $response->assertOk();
        $response->assertJsonPath('data.presenterUrl', $publicBase.'/raffle/'.$raffleId.'/present?token=xyz');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function guestBlockedRaffleRoutesProvider(): array
    {
        return [
            'status' => ['GET', 'status'],
            'list' => ['GET', ''],
            'create' => ['POST', ''],
            'show' => ['GET', '{raffleId}'],
            'open' => ['POST', '{raffleId}/open'],
            'delete' => ['DELETE', '{raffleId}'],
            'manualTicket' => ['POST', '{raffleId}/tickets/manual'],
            'presenterToken' => ['POST', '{raffleId}/presenter-token'],
        ];
    }

    /**
     * @dataProvider guestBlockedRaffleRoutesProvider
     */
    public function test_guest_cannot_access_raffle_routes(string $method, string $pathSuffix): void
    {
        $user = User::factory()->create(['is_guest' => true]);
        $store = Store::factory()->create(['user_id' => $user->id]);
        $raffleId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        $path = "/api/stores/{$store->id}/raffles";
        if ($pathSuffix !== '') {
            $path .= '/'.str_replace('{raffleId}', $raffleId, $pathSuffix);
        }

        Sanctum::actingAs($user);

        $response = $this->json($method, $path, $method === 'POST' && $pathSuffix === '' ? [
            'name' => 'Guest Raffle',
            'ticketPriceSats' => 1000,
        ] : []);

        $response->assertForbidden();
        $response->assertJsonPath('code', 'guest_feature_locked');
        $response->assertJsonPath('message', __('auth.guest_feature_requires_account'));
    }

    public function test_guest_limits_show_raffles_not_allowed(): void
    {
        $user = User::factory()->create(['is_guest' => true]);
        $store = Store::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/user/limits?store_id={$store->id}");

        $response->assertOk();
        $response->assertJsonPath('raffles.max', 0);
        $response->assertJsonPath('raffles.unlimited', false);
        $response->assertJsonPath('raffles.allowed', false);
    }
}
