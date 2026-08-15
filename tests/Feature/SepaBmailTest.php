<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\Invoicing\BankInboundAddressService;
use App\Services\Invoicing\BankInboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Store-scoped b-mail channel: bank credit-notification e-mails confirm
 * pending SEPA payment requests through the plugin's amount-verified
 * report endpoint.
 */
class SepaBmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Store $store;

    private BankInboundAddressService $addressService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bank_inbound.enabled' => true,
            'bank_inbound.webhook_secret' => 'test-inbound-secret',
            'bank_inbound.domain' => 'payments.satflux.io',
            'bank_inbound.address_prefix' => 'pay',
            'bank_inbound.store_address_prefix' => 'ps',
            'bank_inbound.max_address_length' => 50,
            'bank_inbound.reject_forwarded' => true,
            'services.btcpay.base_url' => 'https://btcpay.test',
        ]);

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);
        $this->addressService = app(BankInboundAddressService::class);
    }

    private function reportUrl(): string
    {
        return "https://btcpay.test/api/v1/stores/{$this->store->btcpay_store_id}/plugins/sepa-instant-qr/payment-requests/report";
    }

    #[Test]
    public function store_address_reports_the_e2e_reference_from_the_body(): void
    {
        Http::fake([$this->reportUrl() => Http::response(['outcome' => 'settled'])]);

        $address = $this->addressService->buildStoreAddress($this->store);
        $this->assertLessThanOrEqual(50, strlen($address));
        $this->assertStringStartsWith('ps', $address);

        $response = $this->postJson('/api/webhooks/bank-inbound', [
            'to' => $address,
            'from' => 'notify@tatrabanka.sk',
            'subject' => 'Obrat na ucte',
            'body' => 'Suma: 12,50 EUR. Referencia platitela: QR-ab29e346f1d841c8a95a63d857490818. 01.06.2026',
        ], [
            'X-Bank-Inbound-Secret' => 'test-inbound-secret',
        ]);

        $response->assertOk();
        $response->assertJsonPath('accepted', true);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_ends_with((string) $request->url(), '/payment-requests/report')
                && $request->data()['reference'] === 'QR-ab29e346f1d841c8a95a63d857490818'
                && abs($request->data()['amount'] - 12.50) < 0.001
                && $request->data()['currency'] === 'EUR'
                && is_string($request->data()['dedupKey'] ?? null);
        });
    }

    #[Test]
    public function falls_back_to_the_variable_symbol_reference(): void
    {
        Http::fake([$this->reportUrl() => Http::response(['outcome' => 'settled'])]);

        $address = $this->addressService->buildStoreAddress($this->store);
        $this->postJson('/api/webhooks/bank-inbound', [
            'to' => $address,
            'from' => 'notify@tatrabanka.sk',
            'subject' => 'Obrat na ucte',
            'body' => 'Suma: 5,00 EUR. VS: 1234567890. 01.06.2026',
        ], [
            'X-Bank-Inbound-Secret' => 'test-inbound-secret',
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request->data()['reference'] === '1234567890');
    }

    #[Test]
    public function debit_notifications_are_ignored(): void
    {
        Http::fake();

        $address = $this->addressService->buildStoreAddress($this->store);
        $this->postJson('/api/webhooks/bank-inbound', [
            'to' => $address,
            'from' => 'notify@tatrabanka.sk',
            'subject' => 'Obrat na ucte - debet',
            'body' => 'Debet. Suma: -12,50 EUR. Referencia platitela: QR-ab29e346f1d841c8a95a63d857490818.',
        ], [
            'X-Bank-Inbound-Secret' => 'test-inbound-secret',
        ])->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function unknown_store_token_still_rejects(): void
    {
        Http::fake();
        $service = app(BankInboundEmailService::class);

        $this->expectException(ValidationException::class);

        $service->handle([
            'to' => 'psunknown00000@payments.satflux.io',
            'from' => 'notify@tatrabanka.sk',
            'subject' => 'Obrat',
            'body' => 'Suma: 1,00 EUR. VS: 1',
        ]);
    }

    #[Test]
    public function store_tokens_are_stable_and_unique(): void
    {
        $first = $this->addressService->buildStoreAddress($this->store);
        $second = $this->addressService->buildStoreAddress($this->store->fresh());
        $this->assertSame($first, $second);

        $other = Store::factory()->create(['user_id' => $this->user->id]);
        $this->assertNotSame($first, $this->addressService->buildStoreAddress($other));
    }

    #[Test]
    public function inbound_email_endpoint_returns_the_address(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/stores/{$this->store->id}/sepa/inbound-email");

        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
        $this->assertStringStartsWith('ps', $response->json('data.address'));
    }

    #[Test]
    public function disabled_channel_generates_no_token_on_get(): void
    {
        config(['bank_inbound.enabled' => false]);
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/stores/{$this->store->id}/sepa/inbound-email");

        $response->assertOk();
        $response->assertJsonPath('data.enabled', false);
        $response->assertJsonPath('data.address', null);
        $this->assertNull($this->store->fresh()->bank_inbound_token);
    }

    #[Test]
    public function issued_addresses_survive_a_config_length_change(): void
    {
        $address = $this->addressService->buildStoreAddress($this->store);

        // shortening the max length changes the COMPUTED token length -
        // already issued addresses must still resolve
        config(['bank_inbound.max_address_length' => 40]);

        $owner = $this->addressService->resolveOwner($address);
        $this->assertTrue($owner->is($this->store));
    }

    #[Test]
    public function token_creation_is_audited(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson("/api/stores/{$this->store->id}/sepa/inbound-email")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sepa.bmail_address_created',
            'target_id' => $this->store->id,
        ]);
    }
}
