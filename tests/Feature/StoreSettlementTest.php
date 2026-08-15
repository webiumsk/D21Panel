<?php

namespace Tests\Feature;

use App\Jobs\ProcessBtcPayWebhook;
use App\Jobs\SyncInvoiceSettlements;
use App\Models\Store;
use App\Models\StoreSettlement;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Boltz\SettlementLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreSettlementTest extends TestCase
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
     * @param  array<string, array>  $overrides  url-fragment => [body, status]
     */
    protected function fakeBtcPay(array $overrides = []): void
    {
        Http::fake(function (Request $request) use ($overrides) {
            $url = $request->url();

            foreach ($overrides as $fragment => $response) {
                if (str_contains($url, $fragment)) {
                    return Http::response(...$response);
                }
            }

            if (str_contains($url, 'boltz.test/v2/swap/reverse')) {
                return Http::response([
                    'BTC' => [
                        'L-BTC' => [
                            'hash' => 'abc',
                            'limits' => ['minimal' => 100, 'maximal' => 25000000],
                            'fees' => ['percentage' => 0.25, 'minerFees' => ['claim' => 20, 'lockup' => 27]],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/payment-methods')) {
                return Http::response([
                    [
                        'paymentMethodId' => 'BTC-LN',
                        'currency' => 'BTC',
                        'rate' => '60000',
                        'paymentMethodPaid' => '0.001',
                        'payments' => [
                            [
                                'id' => 'lnpayment-hash-1',
                                'receivedDate' => '2026-07-11T10:00:00Z',
                                'value' => '0.001',
                                'fee' => '0',
                                'status' => 'Settled',
                                'destination' => 'lnbc1...',
                            ],
                        ],
                    ],
                ], 200);
            }

            if (preg_match('#/invoices/[^/]+$#', $url)) {
                return Http::response([
                    'id' => 'inv-1',
                    'currency' => 'EUR',
                    'amount' => '55.00',
                    'status' => 'Settled',
                ], 200);
            }

            return Http::response(['message' => 'unexpected: '.$url], 500);
        });
    }

    protected function makeStore(string $walletType = 'aqua_boltz'): array
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'wallet_type' => $walletType,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        return [$user, $store];
    }

    #[Test]
    public function sync_creates_estimated_boltz_settlement_rows(): void
    {
        $this->fakeBtcPay();
        [, $store] = $this->makeStore();

        $rows = app(SettlementLedgerService::class)->syncInvoice($store, 'inv-1');

        $this->assertSame(1, $rows);
        $row = StoreSettlement::sole();
        $this->assertSame('lightning_boltz', $row->category);
        $this->assertSame(100000, $row->gross_sats);
        // 0.25% of 100000 = 250; claim fee 20 -> net 99730
        $this->assertSame(250, $row->estimated_service_fee_sats);
        $this->assertSame(20, $row->estimated_network_fee_sats);
        $this->assertSame(99730, $row->estimated_net_settlement_sats);
        $this->assertSame('LBTC', $row->settlement_asset);
        $this->assertSame(StoreSettlement::NET_QUALITY_ESTIMATED, $row->net_quality);
        $this->assertSame('boltz_public_api', $row->estimate_basis['source'] ?? null);
        $this->assertSame('EUR', $row->invoice_currency);
    }

    #[Test]
    public function sync_is_idempotent(): void
    {
        $this->fakeBtcPay();
        [, $store] = $this->makeStore();
        $ledger = app(SettlementLedgerService::class);

        $ledger->syncInvoice($store, 'inv-1');
        $ledger->syncInvoice($store, 'inv-1');

        $this->assertSame(1, StoreSettlement::count());
    }

    #[Test]
    public function unavailable_boltz_backend_leaves_net_unknown(): void
    {
        $this->fakeBtcPay([
            'boltz.test/v2/swap/reverse' => [['message' => 'down'], 500],
        ]);
        [, $store] = $this->makeStore();

        app(SettlementLedgerService::class)->syncInvoice($store, 'inv-1');

        $row = StoreSettlement::sole();
        $this->assertSame(StoreSettlement::NET_QUALITY_UNKNOWN, $row->net_quality);
        $this->assertNull($row->estimated_net_settlement_sats);
        $this->assertNull($row->estimated_service_fee_sats);
        $this->assertSame(100000, $row->gross_sats);
    }

    #[Test]
    public function onchain_payment_net_is_derived_from_gross(): void
    {
        $this->fakeBtcPay([
            '/payment-methods' => [[
                [
                    'paymentMethodId' => 'BTC-CHAIN',
                    'currency' => 'BTC',
                    'payments' => [
                        [
                            'id' => 'txid:0',
                            'receivedDate' => '2026-07-11T10:00:00Z',
                            'value' => '0.002',
                            'status' => 'Settled',
                            'destination' => 'bc1q...',
                        ],
                    ],
                ],
            ], 200],
        ]);
        [, $store] = $this->makeStore();

        app(SettlementLedgerService::class)->syncInvoice($store, 'inv-1');

        $row = StoreSettlement::sole();
        $this->assertSame('onchain', $row->category);
        $this->assertSame(StoreSettlement::NET_QUALITY_DERIVED, $row->net_quality);
        $this->assertSame(200000, $row->gross_sats);
        $this->assertSame(200000, $row->estimated_net_settlement_sats);
        $this->assertSame('BTC', $row->settlement_asset);
    }

    #[Test]
    public function settlement_webhook_dispatches_sync_job(): void
    {
        Queue::fake([SyncInvoiceSettlements::class]);
        [, $store] = $this->makeStore();

        $event = WebhookEvent::create([
            'event_type' => 'InvoiceSettled',
            'payload' => ['storeId' => 'btcpay-store-1', 'invoiceId' => 'inv-1', 'type' => 'InvoiceSettled'],
        ]);

        (new ProcessBtcPayWebhook($event))->handle();

        Queue::assertPushed(SyncInvoiceSettlements::class, function (SyncInvoiceSettlements $job) use ($store) {
            return $job->storeId === $store->id && $job->invoiceId === 'inv-1';
        });
    }

    #[Test]
    public function non_settlement_webhook_does_not_dispatch_sync(): void
    {
        Queue::fake([SyncInvoiceSettlements::class]);
        $this->makeStore();

        $event = WebhookEvent::create([
            'event_type' => 'InvoiceCreated',
            'payload' => ['storeId' => 'btcpay-store-1', 'invoiceId' => 'inv-1', 'type' => 'InvoiceCreated'],
        ]);

        (new ProcessBtcPayWebhook($event))->handle();

        Queue::assertNotPushed(SyncInvoiceSettlements::class);
    }

    #[Test]
    public function settlements_endpoint_lists_and_filters_by_invoice(): void
    {
        $this->fakeBtcPay();
        [$user, $store] = $this->makeStore();
        app(SettlementLedgerService::class)->syncInvoice($store, 'inv-1');
        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->id}/settlements?invoice_id=inv-1")
            ->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.btcpay_invoice_id', 'inv-1')
            ->assertJsonPath('data.0.net_quality', 'estimated');

        $this->getJson("/api/stores/{$store->id}/settlements?invoice_id=other")
            ->assertStatus(200)
            ->assertJsonPath('total', 0);
    }

    #[Test]
    public function settlements_endpoint_is_owner_scoped(): void
    {
        Http::fake();
        [, $store] = $this->makeStore();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/stores/{$store->id}/settlements")->assertStatus(403);
    }

    #[Test]
    public function manual_sync_endpoint_syncs_single_invoice(): void
    {
        $this->fakeBtcPay();
        [$user, $store] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->postJson("/api/stores/{$store->id}/settlements/sync", ['invoice_id' => 'inv-1'])
            ->assertStatus(200)
            ->assertJsonPath('data.rows', 1);

        $this->assertSame(1, StoreSettlement::count());
    }

    #[Test]
    public function reconcile_flags_stuck_payments_and_clears_resolved(): void
    {
        $this->fakeBtcPay([
            '/invoices?' => [['data' => []], 200],
            '/invoices' => [['data' => []], 200],
        ]);
        [, $store] = $this->makeStore();

        $row = StoreSettlement::create([
            'store_id' => $store->id,
            'btcpay_invoice_id' => 'inv-stuck',
            'payment_method_id' => 'BTC-LN',
            'payment_id' => 'hash-stuck',
            'category' => 'lightning_boltz',
            'payment_status' => 'Processing',
            'paid_at' => now()->subDays(3),
            'gross_sats' => 5000,
            'net_quality' => StoreSettlement::NET_QUALITY_UNKNOWN,
            'synced_at' => now(),
        ]);

        $this->artisan('boltz:reconcile-settlements', ['--store' => $store->id])
            ->assertExitCode(0);

        $row->refresh();
        $this->assertArrayHasKey('stuck', $row->flags ?? []);

        // Resolving the payment clears the flag on the next run.
        $row->update(['payment_status' => 'Settled']);
        $this->artisan('boltz:reconcile-settlements', ['--store' => $store->id])
            ->assertExitCode(0);
        $this->assertNull($row->refresh()->flags);
    }
}
