<?php

namespace Tests\Feature;

use App\Mail\StoreInvoiceEmail;
use App\Models\Store;
use App\Models\StoreEmailRule;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\StoreEmailRuleDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoreEmailRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_email_rules(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/stores/{$store->id}/email-rules")->assertUnauthorized();
    }

    public function test_owner_can_crud_email_rules(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->getJson("/api/stores/{$store->id}/email-rules/triggers")->assertOk();

        $create = $this->actingAs($user)->postJson("/api/stores/{$store->id}/email-rules", [
            'trigger' => 'InvoiceSettled',
            'condition' => null,
            'to_addresses' => 'merchant@example.com',
            'cc_addresses' => '',
            'bcc_addresses' => '',
            'send_to_buyer' => false,
            'subject' => 'Paid {Invoice.Id}',
            'body' => '<p>Done {Invoice.Id}</p>',
            'sort_order' => 0,
        ]);
        $create->assertCreated();
        $ruleId = $create->json('data.id');
        $this->assertNotEmpty($ruleId);

        $this->actingAs($user)->getJson("/api/stores/{$store->id}/email-rules")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)->putJson("/api/stores/{$store->id}/email-rules/{$ruleId}", [
            'trigger' => 'InvoiceSettled',
            'to_addresses' => 'a@example.com,b@example.com',
            'subject' => 'S',
            'body' => '<p>B</p>',
        ])->assertOk();

        $this->actingAs($user)->deleteJson("/api/stores/{$store->id}/email-rules/{$ruleId}")
            ->assertOk();

        $this->actingAs($user)->getJson("/api/stores/{$store->id}/email-rules")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_trigger_must_be_invoice_event(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/stores/{$store->id}/email-rules", [
            'trigger' => 'PayoutCreated',
            'to_addresses' => 'x@example.com',
            'subject' => 'S',
            'body' => 'B',
        ])->assertStatus(422);
    }

    public function test_dispatcher_sends_mailable_on_matching_webhook(): void
    {
        Mail::fake();
        Cache::flush();

        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-x',
        ]);

        StoreEmailRule::query()->create([
            'store_id' => $store->id,
            'trigger' => 'InvoiceSettled',
            'condition' => null,
            'to_addresses' => 'notify@example.com',
            'cc_addresses' => null,
            'bcc_addresses' => null,
            'send_to_buyer' => true,
            'subject' => 'Invoice {Invoice.Id} settled',
            'body' => '<p>Order {Invoice.OrderId}</p>',
            'sort_order' => 0,
        ]);

        Http::fake([
            'https://btcpay.test/api/v1/stores/btcpay-store-x/invoices/inv-abc' => Http::response([
                'id' => 'inv-abc',
                'orderId' => 'ord-1',
                'status' => 'Settled',
                'amount' => '10',
                'currency' => 'EUR',
                'checkoutLink' => 'https://checkout.example/i/inv-abc',
                'metadata' => [
                    'buyerEmail' => 'buyer@example.com',
                ],
            ], 200),
        ]);

        $webhookEvent = WebhookEvent::create([
            'store_id' => $store->id,
            'event_type' => 'InvoiceSettled',
            'payload' => [
                'type' => 'InvoiceSettled',
                'storeId' => 'btcpay-store-x',
                'invoiceId' => 'inv-abc',
                'deliveryId' => 'del-1',
            ],
            'verified' => true,
        ]);

        app(StoreEmailRuleDispatcher::class)->dispatchForWebhook($webhookEvent, $store);

        Mail::assertSent(StoreInvoiceEmail::class, function (StoreInvoiceEmail $mail) {
            return str_contains($mail->subjectLine, 'inv-abc')
                && str_contains($mail->htmlBody, 'ord-1');
        });
    }

    public function test_idempotent_dispatch_key_prevents_duplicate_mail(): void
    {
        Mail::fake();
        Cache::flush();

        config(['services.btcpay.base_url' => 'https://btcpay.test']);

        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-x',
        ]);

        StoreEmailRule::query()->create([
            'store_id' => $store->id,
            'trigger' => 'InvoiceSettled',
            'condition' => null,
            'to_addresses' => 'notify@example.com',
            'cc_addresses' => null,
            'bcc_addresses' => null,
            'send_to_buyer' => false,
            'subject' => 'Invoice {Invoice.Id} settled',
            'body' => '<p>Hi</p>',
            'sort_order' => 0,
        ]);

        Http::fake([
            'https://btcpay.test/api/v1/stores/btcpay-store-x/invoices/inv-abc' => Http::response([
                'id' => 'inv-abc',
                'status' => 'Settled',
                'checkoutLink' => 'https://checkout.example/i/inv-abc',
                'metadata' => [],
            ], 200),
        ]);

        $payload = [
            'storeId' => 'btcpay-store-x',
            'invoiceId' => 'inv-abc',
            'deliveryId' => 'del-same',
        ];

        $e1 = WebhookEvent::create([
            'store_id' => $store->id,
            'event_type' => 'InvoiceSettled',
            'payload' => $payload,
            'verified' => true,
        ]);
        $e2 = WebhookEvent::create([
            'store_id' => $store->id,
            'event_type' => 'InvoiceSettled',
            'payload' => $payload,
            'verified' => true,
        ]);

        app(StoreEmailRuleDispatcher::class)->dispatchForWebhook($e1, $store);
        app(StoreEmailRuleDispatcher::class)->dispatchForWebhook($e2, $store);

        Mail::assertSent(StoreInvoiceEmail::class, 1);
    }
}
