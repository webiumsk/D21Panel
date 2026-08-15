<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EphemeralBtcpayCheckout;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EphemeralBusinessDocumentBtcpayTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_create_ephemeral_btcpay_checkout_without_persisting_document(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'btcpay-inv-ephemeral',
                'checkoutLink' => 'https://btcpay.example/i/ephemeral',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        $response = $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload);

        $response->assertOk()
            ->assertJsonPath('data.checkout_link', 'https://btcpay.example/i/ephemeral')
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-ephemeral');
        $this->assertDatabaseCount('business_documents', 0);
        $this->assertDatabaseHas('ephemeral_btcpay_checkouts', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'evolu_document_id' => 'evolu-doc-123',
            'btcpay_invoice_id' => 'btcpay-inv-ephemeral',
            'status' => 'pending',
        ]);

        $auditLog = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'business_document.ephemeral_btcpay_checkout')
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertSame('company', $auditLog->target_type);
        $this->assertTrue(Str::isUuid((string) $auditLog->target_id));
    }

    #[Test]
    public function repeated_checkout_request_reuses_the_pending_btcpay_invoice(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-ephemeral' => Http::response([
                'id' => 'btcpay-inv-ephemeral',
                'status' => 'New',
                'checkoutLink' => 'https://btcpay.example/i/ephemeral',
            ], 200),
            '*' => Http::response([
                'id' => 'btcpay-inv-ephemeral',
                'checkoutLink' => 'https://btcpay.example/i/ephemeral',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-ephemeral');

        // Second view of the same unpaid invoice: the still-New BTCPay
        // invoice is reused - no second invoice is minted (production
        // 2026-07-14: every open created another "New" BTCPay invoice).
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-ephemeral')
            ->assertJsonPath('data.checkout_link', 'https://btcpay.example/i/ephemeral');

        $this->assertDatabaseCount('ephemeral_btcpay_checkouts', 1);
        Http::assertSentCount(2); // 1x create + 1x status fetch, NOT 2x create
    }

    #[Test]
    public function expired_pending_checkout_is_replaced_by_a_fresh_invoice(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-old' => Http::response([
                'id' => 'btcpay-inv-old',
                'status' => 'Expired',
                'checkoutLink' => 'https://btcpay.example/i/old',
            ], 200),
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-old',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-new');
    }

    #[Test]
    public function changed_amount_gets_a_fresh_checkout_instead_of_the_stale_one(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        // Pending checkout exists but for a DIFFERENT amount (document was
        // edited/re-frozen) - it must not be reused.
        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-old',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'EUR',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-new');
    }

    #[Test]
    public function paid_ephemeral_checkout_is_not_replaced_by_a_fresh_invoice(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-paid',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-paid')
            ->assertJsonPath('data.checkout_link', null)
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseCount('ephemeral_btcpay_checkouts', 1);
        Http::assertSentCount(0);
    }

    #[Test]
    public function settled_pending_ephemeral_checkout_is_marked_paid_instead_of_replaced(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-pending' => Http::response([
                'id' => 'btcpay-inv-pending',
                'status' => 'Settled',
                'checkoutLink' => 'https://btcpay.example/i/pending',
            ], 200),
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-pending')
            ->assertJsonPath('data.checkout_link', null)
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('ephemeral_btcpay_checkouts', [
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'status' => 'paid',
        ]);
        $this->assertDatabaseMissing('ephemeral_btcpay_checkouts', [
            'btcpay_invoice_id' => 'btcpay-inv-new',
        ]);
        Http::assertSentCount(1);
    }

    #[Test]
    public function processing_checkout_is_reused_as_payable_not_reported_paid(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-pending' => Http::response([
                'id' => 'btcpay-inv-pending',
                'status' => 'Processing',
                'checkoutLink' => 'https://btcpay.example/i/pending',
            ], 200),
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        // Processing = broadcast but unconfirmed: the link is reused, the
        // document is NOT reported paid (settlement lands via webhook).
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-pending')
            ->assertJsonPath('data.checkout_link', 'https://btcpay.example/i/pending')
            ->assertJsonMissingPath('data.status');

        $this->assertDatabaseHas('ephemeral_btcpay_checkouts', [
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function unknown_btcpay_state_fails_closed_instead_of_minting(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-pending' => Http::response(null, 500),
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        // The pending invoice's state could not be determined - it might be
        // paid. Creating a replacement blindly is exactly the duplicate bug,
        // so the create flow fails closed.
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)
            ->assertStatus(422);
        $this->assertDatabaseMissing('ephemeral_btcpay_checkouts', [
            'btcpay_invoice_id' => 'btcpay-inv-new',
        ]);

        // The read-only view lookup degrades to "no link" instead of erroring.
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout/existing', $payload)
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function view_lookup_surfaces_a_settled_checkout_as_paid(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-pending' => Http::response([
                'id' => 'btcpay-inv-pending',
                'status' => 'Settled',
                'checkoutLink' => 'https://btcpay.example/i/pending',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        // The read-only view lookup also heals: the browser learns the
        // checkout settled and can mark the local document paid.
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout/existing', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-pending')
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('ephemeral_btcpay_checkouts', [
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'status' => 'paid',
        ]);
    }

    #[Test]
    public function existing_checkout_lookup_never_mints_a_btcpay_invoice(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-ephemeral' => Http::response([
                'id' => 'btcpay-inv-ephemeral',
                'status' => 'New',
                'checkoutLink' => 'https://btcpay.example/i/ephemeral',
            ], 200),
            '*' => Http::response([
                'id' => 'btcpay-inv-ephemeral',
                'checkoutLink' => 'https://btcpay.example/i/ephemeral',
            ], 200),
        ]);

        $user = $this->createProUser();
        $user->update(['btcpay_api_key' => 'test-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $payload = $this->ephemeralPayload();
        $payload['store_id'] = $store->id;
        $payload['evolu_document_id'] = 'evolu-doc-123';
        $payload['document']['payment_btc_enabled'] = true;

        // Viewing an invoice without a checkout: nothing exists, nothing is
        // created - no BTCPay call at all.
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout/existing', $payload)
            ->assertOk()
            ->assertJsonPath('data', null);
        Http::assertSentCount(0);

        // Explicit create, then the view lookup returns the SAME invoice.
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout', $payload)->assertOk();
        $this->actingAs($user)->postJson('/api/invoicing/ephemeral/btcpay-checkout/existing', $payload)
            ->assertOk()
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-ephemeral');
        $this->assertDatabaseCount('ephemeral_btcpay_checkouts', 1);
    }

    #[Test]
    public function authenticated_user_can_poll_ephemeral_btcpay_status(): void
    {
        $user = $this->createProUser();
        $store = Store::factory()->create(['user_id' => $user->id]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'btcpay-inv-paid',
            'evolu_document_id' => 'evolu-doc-abc',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/invoicing/ephemeral/btcpay-status?'.http_build_query([
            'evolu_document_id' => 'evolu-doc-abc',
            'btcpay_invoice_id' => 'btcpay-inv-paid',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.evolu_document_id', 'evolu-doc-abc');
    }

    protected function createProUser(): User
    {
        $plan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function ephemeralPayload(): array
    {
        return [
            'company' => [
                'legal_name' => 'Local Studio s.r.o.',
                'street' => 'Main 1',
                'city' => 'Bratislava',
                'postal_code' => '81101',
                'country' => 'SK',
                'default_currency' => 'EUR',
                'jurisdiction' => 'eu_sk',
            ],
            'contact' => [
                'name' => 'Client Ltd',
                'email' => 'client@example.com',
            ],
            'document' => [
                'type' => 'invoice',
                'status' => 'issued',
                'number' => 'LOCAL-2026-001',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'currency' => 'EUR',
                'discount_percent' => 0,
                'pdf_locale' => 'sk',
            ],
            'lines' => [
                [
                    'name' => 'Consulting',
                    'quantity' => 1,
                    'unit' => 'h',
                    'unit_price' => 100,
                    'tax_rate' => 0,
                ],
            ],
        ];
    }
}
