<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsEmailCodes;
use Tests\TestCase;

class CashuPaymentsTest extends TestCase
{
    use ReadsEmailCodes, RefreshDatabase;

    public function test_list_payments_normalizes_pending_to_settled_when_settled_at_present(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'store-cashu-payments';

        Http::fake(function (Request $request) use ($baseUrl, $btcpaySid) {
            if (! str_contains($request->url(), "{$baseUrl}/api/v1/stores/{$btcpaySid}/plugins/cashumelt/payments")) {
                return Http::response(['error' => 'unexpected URL'], 500);
            }

            return Http::response([
                'total' => 1,
                'offset' => 0,
                'limit' => 50,
                'items' => [[
                    'quoteId' => 'q-normalize',
                    'invoiceId' => 'inv-1',
                    'amountSats' => 16771,
                    'state' => 'PAID',
                    'settlementState' => 'PENDING',
                    'settlementError' => null,
                    'createdAt' => '2026-03-26T22:54:00Z',
                    'paidAt' => '2026-03-26T22:55:00Z',
                    'settledAt' => '2026-03-26T22:56:00Z',
                ]],
            ], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/cashu/payments");

        $response->assertOk();
        $response->assertJsonPath('data.items.0.settlement_state', 'SETTLED');
        $response->assertJsonPath('data.items.0.settled_at', '2026-03-26T22:56:00Z');
    }

    public function test_list_payments_keeps_pending_when_settled_at_missing(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'store-cashu-payments-2';

        Http::fake(function (Request $request) use ($baseUrl, $btcpaySid) {
            if (! str_contains($request->url(), "{$baseUrl}/api/v1/stores/{$btcpaySid}/plugins/cashumelt/payments")) {
                return Http::response(['error' => 'unexpected URL'], 500);
            }

            return Http::response([
                'total' => 1,
                'offset' => 0,
                'limit' => 50,
                'items' => [[
                    'quoteId' => 'q-pending',
                    'invoiceId' => 'inv-2',
                    'amountSats' => 100,
                    'state' => 'PENDING',
                    'settlementState' => 'PENDING',
                    'settlementError' => null,
                    'createdAt' => '2026-03-26T10:00:00Z',
                    'paidAt' => null,
                    'settledAt' => null,
                ]],
            ], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/cashu/payments");

        $response->assertOk();
        $response->assertJsonPath('data.items.0.settlement_state', 'PENDING');
    }

    public function test_list_payments_passes_through_risk_control_fields(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'store-cashu-risk';

        Http::fake(function (Request $request) use ($baseUrl, $btcpaySid) {
            if (! str_contains($request->url(), "{$baseUrl}/api/v1/stores/{$btcpaySid}/plugins/cashumelt/payments")) {
                return Http::response(['error' => 'unexpected URL'], 500);
            }

            return Http::response([
                'total' => 2,
                'offset' => 0,
                'limit' => 50,
                'items' => [
                    [
                        'quoteId' => 'q-failed',
                        'invoiceId' => 'inv-3',
                        'amountSats' => 18058,
                        'state' => 'PAID',
                        'settlementState' => 'FAILED',
                        'settlementError' => 'Lightning routing fee reserve (180 sat) is too high',
                        'createdAt' => '2026-08-05T14:00:00Z',
                        'settledAt' => null,
                        'retryCount' => 7,
                        'needsManualReview' => true,
                        'failureReasonCode' => 'fee_too_high',
                    ],
                    [
                        // Older plugin without risk-control fields - defaults must apply.
                        'quoteId' => 'q-legacy',
                        'invoiceId' => 'inv-4',
                        'amountSats' => 500,
                        'state' => 'PAID',
                        'settlementState' => 'SETTLED',
                        'createdAt' => '2026-08-05T13:00:00Z',
                        'settledAt' => '2026-08-05T13:01:00Z',
                    ],
                ],
            ], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/cashu/payments");

        $response->assertOk();
        $response->assertJsonPath('data.items.0.retry_count', 7);
        $response->assertJsonPath('data.items.0.needs_manual_review', true);
        $response->assertJsonPath('data.items.0.failure_reason_code', 'fee_too_high');
        $response->assertJsonPath('data.items.1.retry_count', 0);
        $response->assertJsonPath('data.items.1.needs_manual_review', false);
        $response->assertJsonPath('data.items.1.failure_reason_code', null);
    }

    public function test_list_payments_allowed_for_cashu_fallback_store(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'store-fallback-payments';

        Http::fake(function (Request $request) use ($baseUrl, $btcpaySid) {
            if (! str_contains($request->url(), "{$baseUrl}/api/v1/stores/{$btcpaySid}/plugins/cashumelt/payments")) {
                return Http::response(['error' => 'unexpected URL'], 500);
            }

            return Http::response(['total' => 0, 'offset' => 0, 'limit' => 50, 'items' => []], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        // Lightning-primary store with CashuMelt running as the parallel fallback.
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
            'btcpay_store_id' => $btcpaySid,
            'cashu_fallback_enabled' => true,
            'cashu_fallback_address' => 'fallback@example.com',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/cashu/payments")->assertOk();
    }

    public function test_list_payments_still_404_without_cashu_or_fallback(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
            'btcpay_store_id' => 'store-no-cashu',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/cashu/payments")->assertNotFound();
    }

    public function test_switching_to_pure_cashu_resets_fallback_flags(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'store-fallback-to-cashu';

        Http::fake(function (Request $request) use ($baseUrl, $btcpaySid) {
            $url = $request->url();
            if (str_contains($url, "{$baseUrl}/api/v1/stores/{$btcpaySid}/plugins/cashumelt/settings")) {
                return Http::response(array_merge($request->data() ?? [], ['enabled' => true]), 200);
            }
            if (str_contains($url, '/payment-methods/') || str_contains($url, '/lightning/')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
            'btcpay_store_id' => $btcpaySid,
            'cashu_fallback_enabled' => true,
            'cashu_fallback_address' => 'fallback@example.com',
        ]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString('type=blink;ln-address=x@blink.sv;'),
            'status' => 'connected',
            'submitted_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        // Lightning -> Cashu drops the connected wallet: needs the email-code grant.
        $this->grantWalletChange($user, $store);

        $this->putJson("/api/stores/{$store->id}/cashu/settings", [
            'mint_url' => 'https://mint.example/x',
            'lightning_address' => 'merchant@example.com',
            'enabled' => true,
        ])->assertOk();

        $store->refresh();
        $this->assertSame('cashu', $store->wallet_type);
        // Pure Cashu store: CashuMelt is primary, not a fallback.
        $this->assertFalse((bool) $store->cashu_fallback_enabled);
        $this->assertNull($store->cashu_fallback_address);
    }

    public function test_retry_payment_passes_through_retry_after_seconds(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'store-cashu-retry';

        Http::fake(function (Request $request) use ($baseUrl, $btcpaySid) {
            if (! str_contains($request->url(), "{$baseUrl}/api/v1/stores/{$btcpaySid}/plugins/cashumelt/payments/q-retry/retry")) {
                return Http::response(['error' => 'unexpected URL'], 500);
            }

            return Http::response([
                'settled' => false,
                'error' => null,
                'retryAfterSeconds' => 3,
            ], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/cashu/payments/q-retry/retry");

        $response->assertOk();
        $response->assertJsonPath('data.settled', false);
        $response->assertJsonPath('data.retry_after_seconds', 3);
    }

    public function test_cashu_confirm_edit_accepts_account_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/stores/{$store->id}/cashu/confirm-edit", [
            'password' => 'wrong',
        ])->assertUnprocessable();

        $this->postJson("/api/stores/{$store->id}/cashu/confirm-edit", [
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('data.ok', true);
    }

    public function test_cashu_confirm_edit_accepts_recovery_phrase_session_without_password(): void
    {
        $user = User::factory()->create([
            'guest_recovery_public_key' => str_repeat('c', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/stores/{$store->id}/cashu/confirm-edit", [])
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_cashu_confirm_edit_rejects_when_wallet_type_is_not_cashu(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret'),
        ]);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/stores/{$store->id}/cashu/confirm-edit", [
            'password' => 'secret',
        ])->assertUnprocessable();
    }

    public function test_cashu_get_settings_returns_defaults_for_blink_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/cashu/settings")
            ->assertOk()
            ->assertJsonPath('data.mint_url', null)
            ->assertJsonPath('data.lightning_address', null)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.unit', null)
            ->assertJsonPath('data.trusted_mint_urls', null)
            ->assertJsonPath('data.max_melt_fee_reserve_sats', null)
            ->assertJsonPath('data.max_melt_fee_reserve_percent_of_minted', null);
    }

    public function test_cashu_get_settings_returns_defaults_for_nwc_store(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'nwc',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/cashu/settings")
            ->assertOk()
            ->assertJsonPath('data.mint_url', null)
            ->assertJsonPath('data.lightning_address', null)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.unit', null)
            ->assertJsonPath('data.trusted_mint_urls', null)
            ->assertJsonPath('data.max_melt_fee_reserve_sats', null)
            ->assertJsonPath('data.max_melt_fee_reserve_percent_of_minted', null);
    }

    public function test_list_payments_includes_mint_quote_poll_url_from_plugin(): void
    {
        $baseUrl = rtrim(config('services.btcpay.base_url'), '/');
        $btcpaySid = 'store-cashu-poll-url';

        Http::fake(function (Request $request) use ($baseUrl, $btcpaySid) {
            if (! str_contains($request->url(), "{$baseUrl}/api/v1/stores/{$btcpaySid}/plugins/cashumelt/payments")) {
                return Http::response(['error' => 'unexpected URL'], 500);
            }

            return Http::response([
                'total' => 1,
                'offset' => 0,
                'limit' => 50,
                'items' => [[
                    'quoteId' => 'q-poll',
                    'invoiceId' => 'inv-poll',
                    'amountSats' => 500,
                    'state' => 'PAID',
                    'settlementState' => 'MELT_COMPLETE',
                    'settlementError' => null,
                    'mintQuotePollUrl' => 'https://mint.example/v1/quote/abc',
                    'createdAt' => '2026-04-01T12:00:00Z',
                    'paidAt' => null,
                    'settledAt' => null,
                ]],
            ], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/cashu/payments")
            ->assertOk()
            ->assertJsonPath('data.items.0.mint_quote_poll_url', 'https://mint.example/v1/quote/abc')
            ->assertJsonPath('data.items.0.settlement_state', 'MELT_COMPLETE');
    }

    public function test_cashu_settings_put_omits_unsent_optional_fields_for_btcpay_merge(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $btcpaySid = 'store-cashu-merge-put';
        $captured = null;

        Http::fake(function (Request $request) use (&$captured, $btcpaySid) {
            $url = $request->url();
            if (! str_contains($url, "/api/v1/stores/{$btcpaySid}/plugins/cashumelt/settings") || $request->method() !== 'PUT') {
                return Http::response(['error' => 'unexpected URL'], 500);
            }
            $captured = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return Http::response([
                'mintUrl' => $captured['mintUrl'],
                'lightningAddress' => $captured['lightningAddress'],
                'enabled' => true,
                'unit' => 'sat',
                'trustedMintUrls' => null,
                'maxMeltFeeReserveSats' => null,
                'maxMeltFeeReservePercentOfMinted' => null,
            ], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/stores/{$store->id}/cashu/settings", [
            'mint_url' => 'https://mint.example/m',
            'lightning_address' => 'z@example.com',
        ])->assertOk();

        $this->assertIsArray($captured);
        $this->assertSame(['mintUrl', 'lightningAddress'], array_keys($captured));
    }

    public function test_cashu_settings_put_sends_null_when_optional_fields_cleared(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $btcpaySid = 'store-cashu-null-put';
        $captured = null;

        Http::fake(function (Request $request) use (&$captured, $btcpaySid) {
            $url = $request->url();
            if (! str_contains($url, "/api/v1/stores/{$btcpaySid}/plugins/cashumelt/settings") || $request->method() !== 'PUT') {
                return Http::response(['error' => 'unexpected URL'], 500);
            }
            $captured = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return Http::response([
                'mintUrl' => $captured['mintUrl'],
                'lightningAddress' => $captured['lightningAddress'],
                'enabled' => true,
                'trustedMintUrls' => null,
                'maxMeltFeeReserveSats' => null,
            ], 200);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/stores/{$store->id}/cashu/settings", [
            'mint_url' => 'https://mint.example/m',
            'lightning_address' => 'z@example.com',
            'trusted_mint_urls' => null,
            'max_melt_fee_reserve_sats' => null,
        ])->assertOk();

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('trustedMintUrls', $captured);
        $this->assertNull($captured['trustedMintUrls']);
        $this->assertArrayHasKey('maxMeltFeeReserveSats', $captured);
        $this->assertNull($captured['maxMeltFeeReserveSats']);
    }

    public function test_saving_cashu_from_blink_deletes_wallet_connection_and_removes_ln_payment_methods_at_btcpay(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $deletedMethods = [];
        Http::fake(function (Request $request) use (&$deletedMethods) {
            $url = $request->url();

            if (str_contains($url, 'cashumelt/settings') && $request->method() === 'PUT') {
                return Http::response([
                    'mintUrl' => 'https://mint.example/m',
                    'lightningAddress' => 'z@example.com',
                    'enabled' => true,
                ], 200);
            }

            if ($request->method() === 'DELETE' && preg_match('#/stores/[^/]+/payment-methods/(BTC-LN|BTC-LNURL)$#', $url, $m)) {
                $deletedMethods[] = $m[1];

                return Http::response([], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 500);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'blink',
            'btcpay_store_id' => 'store-btcpay-ln-to-cashu',
        ]);
        WalletConnection::factory()->create([
            'store_id' => $store->id,
            'submitted_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/stores/{$store->id}/cashu/settings", [
            'mint_url' => 'https://mint.example/m',
            'lightning_address' => 'z@example.com',
        ])->assertOk();

        $store->refresh();
        $this->assertSame('cashu', $store->wallet_type);
        $this->assertNull($store->walletConnection);

        sort($deletedMethods);
        $this->assertSame(['BTC-LN', 'BTC-LNURL'], $deletedMethods);
    }

    public function test_saving_cashu_from_nwc_deletes_wallet_connection(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'cashumelt/settings') && $request->method() === 'PUT') {
                return Http::response([
                    'mintUrl' => 'https://mint.example/m',
                    'lightningAddress' => 'z@example.com',
                    'enabled' => true,
                ], 200);
            }

            if ($request->method() === 'DELETE' && preg_match('#/stores/[^/]+/payment-methods/(BTC-LN|BTC-LNURL)$#', $url)) {
                return Http::response([], 200);
            }

            return Http::response(['error' => 'unexpected URL: '.$url], 500);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'nwc',
            'btcpay_store_id' => 'store-btcpay-nwc-to-cashu',
        ]);
        WalletConnection::factory()->create([
            'store_id' => $store->id,
            'submitted_by_user_id' => $user->id,
            'type' => 'nwc',
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/stores/{$store->id}/cashu/settings", [
            'mint_url' => 'https://mint.example/m',
            'lightning_address' => 'z@example.com',
        ])->assertOk();

        $store->refresh();
        $this->assertSame('cashu', $store->wallet_type);
        $this->assertNull($store->walletConnection);
    }

    public function test_cashu_settings_put_maps_broken_plugin_json_binding_error(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $btcpaySid = 'store-cashu-plugin-bind-bug';

        Http::fake(function (Request $request) use ($btcpaySid) {
            $url = $request->url();
            if (! str_contains($url, "/api/v1/stores/{$btcpaySid}/plugins/cashumelt/settings") || $request->method() !== 'PUT') {
                return Http::response(['error' => 'unexpected URL'], 500);
            }

            return Http::response(['error' => 'Request body must be a JSON object'], 400);
        });

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
            'btcpay_store_id' => $btcpaySid,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/stores/{$store->id}/cashu/settings", [
            'mint_url' => 'https://mint.example/m',
            'lightning_address' => 'z@example.com',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cashu']);
    }
}
