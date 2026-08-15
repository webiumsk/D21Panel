<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Enums\IntegrationDocumentInboxStatus;
use App\Models\Company;
use App\Models\IntegrationDocumentInbox;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Integrations\IntegrationDocumentInboxService;
use App\Services\Invoicing\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationDocumentInboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Store $store;

    protected StoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $proPlan = SubscriptionPlan::create([
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

        $this->user = User::factory()->create();
        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->company = Company::create([
            'user_id' => $this->user->id,
            'legal_name' => 'Inbox Co',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]);

        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $credentials = StoreIntegration::createForStore($this->store);
        $this->integration = $credentials['integration'];
        $this->integrationToken = $credentials['token'];
    }

    protected string $integrationToken;

    protected function samplePayload(int $orderId = 1001): array
    {
        return [
            'type' => 'invoice',
            'woocommerce_order_id' => $orderId,
            'currency' => 'EUR',
            'buyer' => [
                'name' => 'Jane Buyer',
                'email' => 'jane@example.com',
            ],
            'lines' => [
                [
                    'name' => 'Widget',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 20,
                ],
            ],
        ];
    }

    #[Test]
    public function woo_enqueue_creates_inbox_entry_when_inbox_mode_enabled(): void
    {
        config(['invoicing.woocommerce_inbox_mode' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->integrationToken)
            ->postJson('/api/integrations/woocommerce/documents', $this->samplePayload());

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['inbox_id', 'evolu_document_id', 'status', 'summary'],
            ])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.summary.buyer_name', 'Jane Buyer');

        $this->assertDatabaseCount('business_documents', 0);
        $this->assertDatabaseHas('integration_document_inbox', [
            'store_integration_id' => $this->integration->id,
            'woocommerce_order_id' => 1001,
            'status' => IntegrationDocumentInboxStatus::Pending->value,
        ]);
    }

    #[Test]
    public function woo_enqueue_preserves_payment_metadata(): void
    {
        config(['invoicing.woocommerce_inbox_mode' => true]);

        $payload = array_merge($this->samplePayload(3003), [
            'payment_method' => 'satflux_bitcoin',
            'is_paid' => true,
            'paid_at' => '2026-06-24T18:01:00+00:00',
            'order_total' => 0.18,
            'discount_percent' => 10.0,
            'btcpay_invoice_id' => 'EP3HaqUX43q2Fv3yvhq1po',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->integrationToken)
            ->postJson('/api/integrations/woocommerce/documents', $payload);

        $response->assertCreated();

        $entry = IntegrationDocumentInbox::query()->where('woocommerce_order_id', 3003)->firstOrFail();
        $stored = is_array($entry->payload_json) ? $entry->payload_json : [];

        $this->assertSame('satflux_bitcoin', $stored['payment_method'] ?? null);
        $this->assertTrue($stored['is_paid'] ?? false);
        $this->assertSame(0.18, $stored['order_total'] ?? null);
        $this->assertSame(10.0, (float) ($stored['discount_percent'] ?? 0));
        $this->assertSame('EP3HaqUX43q2Fv3yvhq1po', $stored['btcpay_invoice_id'] ?? null);
    }

    #[Test]
    public function user_can_list_pending_inbox_items_for_store(): void
    {
        $service = app(IntegrationDocumentInboxService::class);
        $service->enqueueFromWoo($this->integration, $this->samplePayload(2002));

        $this->actingAs($this->user)
            ->getJson("/api/invoicing/stores/{$this->store->id}/integration-inbox")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.woocommerce_order_id', 2002)
            ->assertJsonPath('data.0.status', 'pending');
    }

    #[Test]
    public function user_can_list_pending_inbox_items_for_company(): void
    {
        $service = app(IntegrationDocumentInboxService::class);
        $service->enqueueFromWoo($this->integration, $this->samplePayload(2002));

        $this->actingAs($this->user)
            ->getJson("/api/invoicing/companies/{$this->company->id}/integration-inbox")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.woocommerce_order_id', 2002)
            ->assertJsonPath('data.0.status', 'pending');
    }

    #[Test]
    public function user_can_dismiss_pending_inbox_item(): void
    {
        $service = app(IntegrationDocumentInboxService::class);
        $entry = $service->enqueueFromWoo($this->integration, $this->samplePayload(3003));

        $this->actingAs($this->user)
            ->postJson("/api/invoicing/companies/{$this->company->id}/integration-inbox/{$entry['inbox_id']}/dismiss")
            ->assertNoContent();

        $this->assertDatabaseMissing('integration_document_inbox', [
            'id' => $entry['inbox_id'],
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/invoicing/companies/{$this->company->id}/integration-inbox")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function user_can_issue_inbox_entry_and_reserve_invoice_number(): void
    {
        config(['invoicing.woocommerce_inbox_mode' => true]);

        $create = $this->withHeader('Authorization', 'Bearer '.$this->integrationToken)
            ->postJson('/api/integrations/woocommerce/documents', $this->samplePayload(5005));

        $create->assertCreated();
        $inboxId = $create->json('data.inbox_id');

        $this->withHeader('Authorization', 'Bearer '.$this->integrationToken)
            ->postJson("/api/integrations/woocommerce/documents/{$inboxId}/issue")
            ->assertOk()
            ->assertJsonPath('data.number', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('data.status', 'issued');

        $this->assertDatabaseHas('integration_document_inbox', [
            'id' => $inboxId,
        ]);

        $entry = IntegrationDocumentInbox::query()->findOrFail($inboxId);
        $payload = is_array($entry->payload_json) ? $entry->payload_json : [];
        $this->assertNotEmpty($payload['number']);
    }

    #[Test]
    public function user_can_reserve_number_via_store_bridge(): void
    {
        app(DocumentSequenceService::class)->seedDefaultsForCompany($this->company);

        $this->actingAs($this->user)
            ->postJson("/api/invoicing/stores/{$this->store->id}/number-series/reserve", [
                'document_type' => 'invoice',
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['number', 'document_type']]);

        $this->actingAs($this->user)
            ->getJson("/api/invoicing/stores/{$this->store->id}/number-series/preview?type=invoice")
            ->assertOk()
            ->assertJsonStructure(['data' => ['next_number', 'document_type']]);
    }

    #[Test]
    public function store_reserve_honors_local_high_counter_from_evolu_clients(): void
    {
        $sequenceService = app(DocumentSequenceService::class);
        $sequenceService->seedDefaultsForCompany($this->company);

        $this->actingAs($this->user)
            ->postJson("/api/invoicing/stores/{$this->store->id}/number-series/reserve", [
                'document_type' => 'invoice',
                'local_high_counter' => 65,
            ])
            ->assertOk()
            ->assertJsonPath('data.number', 'INV20260066')
            ->assertJsonPath('data.counter', 66);

        $this->actingAs($this->user)
            ->getJson("/api/invoicing/stores/{$this->store->id}/number-series/preview?type=invoice&local_high_counter=66")
            ->assertOk()
            ->assertJsonPath('data.next_number', 'INV20260067')
            ->assertJsonPath('data.next_counter', 67);
    }

    #[Test]
    public function store_number_series_returns_store_not_linked_when_company_missing(): void
    {
        $unlinkedStore = Store::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => null,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/invoicing/stores/{$unlinkedStore->id}/number-series/preview?type=invoice")
            ->assertStatus(422)
            ->assertJsonPath('error', 'store_not_linked')
            ->assertJsonPath('data', null);

        $this->actingAs($this->user)
            ->postJson("/api/invoicing/stores/{$unlinkedStore->id}/number-series/reserve", [
                'document_type' => 'invoice',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'store_not_linked');
    }

    #[Test]
    public function mark_imported_deletes_entry(): void
    {
        $entry = IntegrationDocumentInbox::create([
            'store_integration_id' => $this->integration->id,
            'woocommerce_order_id' => 4004,
            'evolu_document_id' => '00000000-0000-4000-8000-000000000001',
            'payload_json' => $this->samplePayload(4004),
            'status' => IntegrationDocumentInboxStatus::Pending,
        ]);

        app(IntegrationDocumentInboxService::class)->markImported($entry);

        $this->assertDatabaseMissing('integration_document_inbox', [
            'id' => $entry->id,
        ]);
    }

    #[Test]
    public function deeplink_resolves_satflux_store_uuid(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/invoicing/integration-inbox/deeplink?store='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('data.store_id', $this->store->id)
            ->assertJsonPath('data.company_id', $this->company->id)
            ->assertJsonPath('data.integration_inbox_path', '/invoicing/stores/'.$this->store->id.'/integration-inbox')
            ->assertJsonPath('data.invoices_path', '/invoicing/companies/'.$this->company->id.'/invoices');
    }

    #[Test]
    public function deeplink_resolves_btcpay_store_id(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/invoicing/integration-inbox/deeplink?store='.$this->store->btcpay_store_id)
            ->assertOk()
            ->assertJsonPath('data.store_id', $this->store->id)
            ->assertJsonPath('data.btcpay_store_id', $this->store->btcpay_store_id);
    }

    #[Test]
    public function deeplink_resolves_company_uuid(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/invoicing/integration-inbox/deeplink?company='.$this->company->id)
            ->assertOk()
            ->assertJsonPath('data.store_id', $this->store->id)
            ->assertJsonPath('data.company_id', $this->company->id);
    }
}
