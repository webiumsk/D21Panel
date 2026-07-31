<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SEPA Instant QR management proxy. The routes are deliberately open to
 * every role including guest accounts (no guest.restrict, no plan gate) -
 * accepting SEPA payments is a core free feature for the cashless mandate.
 */
class SepaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user);
    }

    private function pluginBase(): string
    {
        return "https://btcpay.test/api/v1/stores/{$this->store->btcpay_store_id}/plugins/sepa-instant-qr";
    }

    private function settingsBody(array $overrides = []): array
    {
        return array_merge([
            'configured' => true,
            'enabled' => true,
            'countryProfile' => 'SK',
            'iban' => 'SK6807200002891987426353',
            'beneficiary' => 'My Company s.r.o.',
            'bic' => null,
            'message' => null,
            'confirmationBackend' => 'manual',
            'skQrVariant' => 'payme',
            'amountTolerance' => 0,
            'nopEnvironment' => 'INT',
            'nopCertSet' => false,
            'nopVatsk' => null,
            'nopPokladnica' => null,
        ], $overrides);
    }

    public function test_get_settings_proxies_the_plugin_response(): void
    {
        Http::fake([
            $this->pluginBase().'/settings' => Http::response($this->settingsBody()),
        ]);

        $response = $this->getJson("/api/stores/{$this->store->id}/sepa/settings");

        $response->assertOk();
        $response->assertJsonPath('data.iban', 'SK6807200002891987426353');
        $response->assertJsonPath('data.skQrVariant', 'payme');
    }

    public function test_update_settings_maps_snake_case_to_plugin_payload(): void
    {
        Http::fake([
            $this->pluginBase().'/settings' => Http::response($this->settingsBody(['skQrVariant' => 'bysquare'])),
        ]);

        $response = $this->putJson("/api/stores/{$this->store->id}/sepa/settings", [
            'enabled' => true,
            'country_profile' => 'SK',
            'iban' => 'SK68 0720 0002 8919 8742 6353',
            'beneficiary' => 'My Company s.r.o.',
            'bic' => null,
            'message' => 'Kiosk 1',
            'confirmation_backend' => 'manual',
            'sk_qr_variant' => 'bysquare',
            'amount_tolerance' => 0.05,
            'nop_environment' => 'PROD',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.skQrVariant', 'bysquare');
        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && str_ends_with((string) $request->url(), '/plugins/sepa-instant-qr/settings')
                && $request->data()['countryProfile'] === 'SK'
                && $request->data()['skQrVariant'] === 'bysquare'
                && $request->data()['amountTolerance'] === 0.05
                && $request->data()['nopEnvironment'] === 'PROD'
                && $request->data()['message'] === 'Kiosk 1';
        });
    }

    public function test_update_settings_validates_locally_before_calling_btcpay(): void
    {
        Http::fake();

        $response = $this->putJson("/api/stores/{$this->store->id}/sepa/settings", [
            'enabled' => true,
            'country_profile' => 'XX',
            'iban' => 'SK6807200002891987426353',
            'beneficiary' => 'My Company s.r.o.',
            'confirmation_backend' => 'nope',
            'sk_qr_variant' => 'payme',
            'amount_tolerance' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['country_profile', 'confirmation_backend']);
        Http::assertNothingSent();
    }

    public function test_certificate_upload_relays_pfx_as_base64(): void
    {
        Http::fake([
            $this->pluginBase().'/certificate' => Http::response($this->settingsBody([
                'nopCertSet' => true,
                'nopVatsk' => 'VATSK-1234567890',
                'nopPokladnica' => '88812345678900001',
            ])),
        ]);

        $pfx = UploadedFile::fake()->createWithContent('cert.p12', 'binary-pfx-bytes');

        $response = $this->post("/api/stores/{$this->store->id}/sepa/certificate", [
            'pfx_file' => $pfx,
            'pfx_password' => 'secret',
            'nop_environment' => 'INT',
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJsonPath('data.nopVatsk', 'VATSK-1234567890');
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_ends_with((string) $request->url(), '/plugins/sepa-instant-qr/certificate')
                && $request->data()['pfxBase64'] === base64_encode('binary-pfx-bytes')
                && $request->data()['pfxPassword'] === 'secret'
                && $request->data()['nopEnvironment'] === 'INT';
        });
    }

    public function test_certificate_upload_requires_files(): void
    {
        Http::fake();

        $response = $this->postJson("/api/stores/{$this->store->id}/sepa/certificate", [
            'nop_environment' => 'INT',
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_certificate_delete_proxies(): void
    {
        Http::fake([
            $this->pluginBase().'/certificate' => Http::response($this->settingsBody()),
        ]);

        $response = $this->deleteJson("/api/stores/{$this->store->id}/sepa/certificate");

        $response->assertOk();
        $response->assertJsonPath('data.nopCertSet', false);
    }

    public function test_backend_test_returns_result(): void
    {
        Http::fake([
            $this->pluginBase().'/test' => Http::response(['ok' => true, 'message' => 'INT status OK']),
        ]);

        $response = $this->postJson("/api/stores/{$this->store->id}/sepa/test");

        $response->assertOk();
        $response->assertJsonPath('data.ok', true);
        $response->assertJsonPath('data.message', 'INT status OK');
    }

    public function test_payment_requests_pass_state_filter(): void
    {
        Http::fake([
            $this->pluginBase().'/payment-requests*' => Http::response([[
                'reference' => 'QR-ab29e346f1d841c8a95a63d857490818',
                'invoiceId' => 'inv1',
                'state' => 'PENDING',
                'amountDue' => 12.5,
                'currency' => 'EUR',
                'createdAt' => '2026-07-31T10:00:00+00:00',
                'reviewReason' => null,
            ]]),
        ]);

        $response = $this->getJson("/api/stores/{$this->store->id}/sepa/payment-requests?state=pending");

        $response->assertOk();
        $response->assertJsonPath('data.0.state', 'PENDING');
        Http::assertSent(fn (Request $request) => str_contains((string) $request->url(), 'state=pending'));
    }

    public function test_confirm_payment_request_proxies(): void
    {
        $reference = 'QR-ab29e346f1d841c8a95a63d857490818';
        Http::fake([
            $this->pluginBase().'/payment-requests/'.$reference.'/confirm' => Http::response(['outcome' => 'settled']),
        ]);

        $response = $this->postJson("/api/stores/{$this->store->id}/sepa/payment-requests/{$reference}/confirm");

        $response->assertOk();
        $response->assertJsonPath('data.outcome', 'settled');
    }

    public function test_status_reports_missing_plugin_as_unavailable(): void
    {
        Http::fake([
            $this->pluginBase().'/settings' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->getJson("/api/stores/{$this->store->id}/sepa/status?refresh=1");

        $response->assertOk();
        $response->assertJsonPath('data.available', false);
    }

    public function test_guest_accounts_have_full_access(): void
    {
        $guest = User::factory()->guest()->create();
        $guestStore = Store::factory()->create(['user_id' => $guest->id]);
        Sanctum::actingAs($guest);

        Http::fake([
            "https://btcpay.test/api/v1/stores/{$guestStore->btcpay_store_id}/plugins/sepa-instant-qr/settings" => Http::response($this->settingsBody()),
        ]);

        $this->getJson("/api/stores/{$guestStore->id}/sepa/settings")->assertOk();
        $this->putJson("/api/stores/{$guestStore->id}/sepa/settings", [
            'enabled' => false,
            'country_profile' => 'SK',
            'iban' => 'SK6807200002891987426353',
            'beneficiary' => 'Guest s.r.o.',
            'confirmation_backend' => 'manual',
            'sk_qr_variant' => 'payme',
            'amount_tolerance' => 0,
        ])->assertOk();
    }

    public function test_foreign_store_is_rejected(): void
    {
        $other = User::factory()->create();
        $foreignStore = Store::factory()->create(['user_id' => $other->id]);

        Http::fake();

        $response = $this->getJson("/api/stores/{$foreignStore->id}/sepa/settings");

        $response->assertForbidden();
        Http::assertNothingSent();
    }
}
