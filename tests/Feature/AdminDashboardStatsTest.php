<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\PosOrder;
use App\Models\PosTerminal;
use App\Models\Store;
use App\Models\StoreSettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminDashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function settlement(Store $store, string $invoiceId, string $paymentId, float $amount = 12.5): StoreSettlement
    {
        return StoreSettlement::forceCreate([
            'store_id' => $store->id,
            'btcpay_invoice_id' => $invoiceId,
            'payment_method_id' => 'BTC-LN',
            'payment_id' => $paymentId,
            'category' => 'lightning',
            'payment_status' => 'Settled',
            'paid_at' => now()->subDay(),
            'gross_sats' => 21000,
            'invoice_currency' => 'EUR',
            'invoice_amount' => $amount,
            'synced_at' => now(),
        ]);
    }

    #[Test]
    public function pos_terminals_count_includes_point_of_sale_apps(): void
    {
        $this->actingAsAdmin();
        $store = Store::factory()->create();
        App::factory()->create(['store_id' => $store->id, 'app_type' => 'PointOfSale']);
        App::factory()->create(['store_id' => $store->id, 'app_type' => 'Crowdfund']);

        $response = $this->getJson('/api/admin/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('pos_terminals_total'));
    }

    #[Test]
    public function paid_orders_count_settled_invoices_deduped_per_invoice(): void
    {
        $this->actingAsAdmin();
        $store = Store::factory()->create();
        // two payment rows of the SAME invoice must count as one order
        $this->settlement($store, 'inv-1', 'pay-1');
        $this->settlement($store, 'inv-1', 'pay-2');
        $this->settlement($store, 'inv-2', 'pay-3', amount: 5.0);

        $response = $this->getJson('/api/admin/stats');

        $response->assertOk();
        $this->assertSame(2, $response->json('pos_orders_paid_total'));
        $this->assertSame(2, $response->json('pos_orders_paid_30d'));
        $this->assertEqualsWithDelta(17.5, $response->json('pos_orders_amount_30d_eur'), 0.001);

        $top = $response->json('top_stores_by_pos_orders');
        $this->assertCount(1, $top);
        $this->assertSame($store->id, $top[0]['store_id']);
        $this->assertSame(2, $top[0]['pos_orders_paid']);
    }

    #[Test]
    public function processing_settlements_are_not_counted_as_paid(): void
    {
        $this->actingAsAdmin();
        $store = Store::factory()->create();
        $this->settlement($store, 'inv-ok', 'pay-1');
        $processing = $this->settlement($store, 'inv-processing', 'pay-2', amount: 99.0);
        $processing->forceFill(['payment_status' => 'Processing'])->save();

        $response = $this->getJson('/api/admin/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('pos_orders_paid_total'));
        $this->assertEqualsWithDelta(12.5, $response->json('pos_orders_amount_30d_eur'), 0.001);
    }

    #[Test]
    public function invoice_shared_by_native_order_and_settlement_counts_once(): void
    {
        $this->actingAsAdmin();
        $store = Store::factory()->create();
        $terminal = PosTerminal::create(['store_id' => $store->id, 'name' => 'Counter']);
        PosOrder::create([
            'pos_terminal_id' => $terminal->id,
            'store_id' => $store->id,
            'amount' => 12.5,
            'currency' => 'EUR',
            'status' => PosOrder::STATUS_PAID,
            'paid_method' => 'lightning',
            'btcpay_invoice_id' => 'inv-shared',
            'paid_at' => now()->subDay(),
        ]);
        // the same invoice mirrored into the settlement ledger
        $this->settlement($store, 'inv-shared', 'pay-1');

        $response = $this->getJson('/api/admin/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('pos_orders_paid_total'));
        $top = $response->json('top_stores_by_pos_orders');
        $this->assertCount(1, $top);
        $this->assertSame(1, $top[0]['pos_orders_paid']);
    }

    #[Test]
    public function volume_counts_all_settled_payment_sats(): void
    {
        $this->actingAsAdmin();
        $store = Store::factory()->create();
        // two payments of one invoice both contribute to volume
        $this->settlement($store, 'inv-1', 'pay-1');
        $this->settlement($store, 'inv-1', 'pay-2');
        $processing = $this->settlement($store, 'inv-2', 'pay-3');
        $processing->forceFill(['payment_status' => 'Processing'])->save();

        $response = $this->getJson('/api/admin/stats');

        $response->assertOk();
        $this->assertSame(42000, $response->json('volume_sats_total'));
        $this->assertSame(42000, $response->json('volume_sats_30d'));
    }
}
