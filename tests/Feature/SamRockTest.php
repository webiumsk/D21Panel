<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamRockTest extends TestCase
{
    use RefreshDatabase;

    public function test_samrock_create_otp_returns_404_for_cashu_store(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => 'cashu',
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/samrock/otps", [
            'btc' => true,
            'btcln' => true,
        ]);

        $response->assertStatus(404);
    }

    public function test_samrock_create_otp_allowed_when_store_wallet_type_is_blink(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $user = User::factory()->create();
        $store = Store::factory()->withBlink()->create(['user_id' => $user->id]);

        Http::fake([
            'https://btcpay.test/api/v1/stores/'.$store->btcpay_store_id.'/samrock/otps' => Http::response([
                'otp' => 'otp-from-blink-store',
                'expiresAt' => '2026-12-01T12:00:00Z',
                'setupUrl' => 'https://btcpay.test/samrock',
            ], 201),
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/samrock/otps", [
            'btc' => true,
            'btcln' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.otp', 'otp-from-blink-store');
    }

    public function test_samrock_create_otp_proxies_to_btcpay(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $user = User::factory()->create();
        $store = Store::factory()->withAquaBoltz()->create(['user_id' => $user->id]);

        Http::fake([
            'https://btcpay.test/api/v1/stores/'.$store->btcpay_store_id.'/samrock/otps' => Http::response([
                'otp' => 'otp-abc',
                'expiresAt' => '2026-12-01T12:00:00Z',
                'setupUrl' => 'https://btcpay.test/plugins/x/samrock/protocol?otp=otp-abc',
            ], 201),
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/samrock/otps", [
            'btc' => true,
            'btcln' => true,
            'lbtc' => false,
            'expires_in_seconds' => 300,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.otp', 'otp-abc')
            ->assertJsonPath('data.expires_at', '2026-12-01T12:00:00Z');

        Http::assertSent(function ($request) use ($store) {
            return $request->url() === 'https://btcpay.test/api/v1/stores/'.$store->btcpay_store_id.'/samrock/otps'
                && $request->method() === 'POST'
                && $request->data()['btc'] === true
                && $request->data()['btcln'] === true
                && $request->data()['lbtc'] === false
                && $request->data()['expiresInSeconds'] === 300;
        });
    }

    public function test_samrock_complete_creates_wallet_connection_when_btcpay_reports_success(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $user = User::factory()->create();
        $store = Store::factory()->withAquaBoltz()->create(['user_id' => $user->id]);

        Http::fake([
            'https://btcpay.test/api/v1/stores/'.$store->btcpay_store_id.'/plugins/cashumelt/*' => Http::response([], 200),
            'https://btcpay.test/api/v1/stores/'.$store->btcpay_store_id.'/samrock/otps/otp-xyz' => Http::sequence()
                ->push([
                    'otp' => 'otp-xyz',
                    'expiresAt' => '2026-12-01T12:00:00Z',
                    'status' => 'success',
                ], 200)
                ->push([], 200),
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/samrock/complete", [
            'otp' => 'otp-xyz',
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.configuration_source', 'samrock');

        // The pairing also configures the CashuMelt parallel fallback.
        $store->refresh();
        $this->assertTrue((bool) $store->cashu_fallback_enabled);
        $this->assertSame('fallback@example.com', $store->cashu_fallback_address);

        $this->assertDatabaseHas('wallet_connections', [
            'store_id' => $store->id,
            'type' => 'aqua_descriptor',
            'configuration_source' => 'samrock',
            'status' => 'connected',
        ]);

        $this->assertDatabaseCount('wallet_connections', 1);
    }

    public function test_samrock_complete_returns_422_when_not_success(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $user = User::factory()->create();
        $store = Store::factory()->withAquaBoltz()->create(['user_id' => $user->id]);

        Http::fake([
            'https://btcpay.test/api/v1/stores/'.$store->btcpay_store_id.'/samrock/otps/otp-pending' => Http::response([
                'otp' => 'otp-pending',
                'status' => 'pending',
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson("/api/stores/{$store->id}/samrock/complete", [
            'otp' => 'otp-pending',
            'fallback_lightning_address' => 'fallback@example.com',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('wallet_connections', 0);
    }

    public function test_samrock_qr_returns_png_bytes(): void
    {
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $user = User::factory()->create();
        $store = Store::factory()->withAquaBoltz()->create(['user_id' => $user->id]);

        Http::fake([
            'https://btcpay.test/api/v1/stores/'.$store->btcpay_store_id.'/samrock/otps/otp-qr/qr' => Http::response("\x89PNG\r\n\x1a\n", 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $response = $this->actingAs($user)->get("/api/stores/{$store->id}/samrock/otps/otp-qr/qr?format=png");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $response->getContent());
    }
}
