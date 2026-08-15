<?php

namespace Tests\Feature;

use App\Models\PosOrder;
use App\Models\PosTerminal;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $proUser;

    protected User $freeUser;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
        Cache::flush();

        $proPlan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['advanced_statistics'],
            'is_active' => true,
        ]);

        $this->proUser = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        Subscription::create([
            'user_id' => $this->proUser->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $this->freeUser = User::factory()->create(['btcpay_api_key' => 'merchant-key-2']);

        $this->store = Store::factory()->create([
            'user_id' => $this->proUser->id,
            'btcpay_store_id' => 'btcpay-store-1',
            'name' => 'My Store',
        ]);
    }

    /**
     * Two settled invoices inside the window (day1 SATS, day2 EUR), one in
     * the PREVIOUS window (10 days ago), one far outside, one unpaid. The
     * fake honors the startDate/endDate filters the service now sends, so
     * each window only sees its own invoices - like real Greenfield.
     */
    protected function fakeInvoices(): void
    {
        $inWindowDay1 = now()->subDays(5)->setTime(10, 0);
        $inWindowDay2 = now()->subDays(3)->setTime(12, 0);
        $inPreviousWindow = now()->subDays(10)->setTime(9, 0);
        $outside = now()->subDays(40);

        $all = [
            ['id' => 'inv-sats', 'status' => 'Settled', 'amount' => 1500, 'currency' => 'SATS', 'createdTime' => $inWindowDay1->getTimestamp()],
            ['id' => 'inv-eur', 'status' => 'Complete', 'amount' => 50.5, 'currency' => 'EUR', 'createdTime' => $inWindowDay2->getTimestamp()],
            ['id' => 'inv-prev', 'status' => 'Settled', 'amount' => 700, 'currency' => 'SATS', 'createdTime' => $inPreviousWindow->getTimestamp()],
            ['id' => 'inv-old', 'status' => 'Settled', 'amount' => 999, 'currency' => 'SATS', 'createdTime' => $outside->getTimestamp()],
            ['id' => 'inv-new', 'status' => 'New', 'amount' => 7, 'currency' => 'EUR', 'createdTime' => $inWindowDay2->getTimestamp()],
        ];

        Http::fake(function ($request) use ($all) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/v1/stores/btcpay-store-1/invoices/inv-eur/payment-methods')) {
                return Http::response([
                    ['paymentMethodId' => 'BTC-LN', 'payments' => [['value' => '0.00002']]],
                ], 200);
            }
            if (str_contains($url, '/api/v1/stores/btcpay-store-1/invoices')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $from = isset($query['startDate']) ? (int) $query['startDate'] : null;
                $to = isset($query['endDate']) ? (int) $query['endDate'] : null;
                $filtered = array_values(array_filter($all, function ($inv) use ($from, $to) {
                    return ($from === null || $inv['createdTime'] >= $from)
                        && ($to === null || $inv['createdTime'] <= $to);
                }));

                return Http::response($filtered, 200);
            }

            return Http::response([], 200);
        });
    }

    protected function createPaidPosOrder(float $amount, string $currency, Carbon $paidAt): PosOrder
    {
        $terminal = PosTerminal::create([
            'store_id' => $this->store->id,
            'name' => 'Test terminal',
        ]);

        return PosOrder::create([
            'store_id' => $this->store->id,
            'pos_terminal_id' => $terminal->id,
            'status' => PosOrder::STATUS_PAID,
            'paid_method' => PosOrder::PAID_METHOD_LIGHTNING,
            'paid_at' => $paidAt,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    protected function range(): array
    {
        return [
            'from' => now()->subDays(6)->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
        ];
    }

    #[Test]
    public function pro_user_gets_series_totals_breakdowns_and_previous_window(): void
    {
        $this->fakeInvoices();
        $this->createPaidPosOrder(2000, 'SATS', now()->subDays(2));

        $response = $this->actingAs($this->proUser)
            ->getJson('/api/dashboard/analytics?'.http_build_query($this->range()))
            ->assertOk();

        // 2 settled invoices in window + 1 PoS order; the out-of-window and
        // unpaid invoices are excluded.
        $response->assertJsonPath('totals.paid_count', 3);
        // 1500 (SATS invoice) + 2000 (received sats of the EUR invoice) + 2000 (PoS SATS)
        $response->assertJsonPath('totals.amount_sats', 5500);
        $this->assertEqualsWithDelta(50.5, $response->json('totals.amount_by_currency.eur'), 0.001);

        $this->assertCount(7, $response->json('series'));
        $this->assertSame(3, array_sum(array_column($response->json('series'), 'paid_count')));

        $bySource = $response->json('by_source');
        $this->assertSame(1, $bySource['pos']['paid_count']);

        $byStore = $response->json('by_store');
        $this->assertCount(1, $byStore);
        $this->assertSame(3, $byStore[0]['paid_count']);

        // Previous window: equally long, ends the day before `from`; the
        // 10-days-ago invoice lands there (and ONLY there).
        $this->assertSame(1, $response->json('previous.paid_count'));
        $this->assertSame(700, $response->json('previous.amount_sats'));
        $this->assertSame(
            now()->subDays(7)->format('Y-m-d'),
            $response->json('previous.to'),
        );

        $this->assertNotNull($response->json('totals.best_day'));
        $this->assertTrue($response->json('can_view_stats'));
    }

    #[Test]
    public function source_filter_narrows_to_the_bucket(): void
    {
        $this->fakeInvoices();
        $this->createPaidPosOrder(2000, 'SATS', now()->subDays(2));

        $response = $this->actingAs($this->proUser)
            ->getJson('/api/dashboard/analytics?'.http_build_query($this->range() + ['source' => 'pos']))
            ->assertOk();

        // Only the PoS order lands in the pos bucket (fake invoices carry no
        // pos metadata, so they detect as other sources).
        $response->assertJsonPath('totals.paid_count', 1);
        $response->assertJsonPath('totals.amount_sats', 2000);
    }

    #[Test]
    public function free_user_gets_totals_but_no_series_or_breakdowns(): void
    {
        Store::factory()->create([
            'user_id' => $this->freeUser->id,
            'btcpay_store_id' => 'btcpay-store-2',
            'name' => 'Free Store',
        ]);
        Http::fake(['*' => Http::response([], 200)]);

        $response = $this->actingAs($this->freeUser)
            ->getJson('/api/dashboard/analytics?'.http_build_query($this->range()))
            ->assertOk();

        $response->assertJsonPath('can_view_stats', false);
        $this->assertNull($response->json('series'));
        $this->assertNull($response->json('by_source'));
        $this->assertNull($response->json('by_store'));
        $this->assertNull($response->json('previous'));
        $this->assertIsInt($response->json('totals.paid_count'));
    }

    #[Test]
    public function range_is_validated(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($this->proUser)
            ->getJson('/api/dashboard/analytics?from=2026-07-10&to=2026-07-01')
            ->assertStatus(422);

        $this->actingAs($this->proUser)
            ->getJson('/api/dashboard/analytics?from=2020-01-01&to=2026-07-01')
            ->assertStatus(422);

        $this->actingAs($this->proUser)
            ->getJson('/api/dashboard/analytics?'.http_build_query($this->range() + ['source' => 'bogus']))
            ->assertStatus(422);
    }

    #[Test]
    public function a_foreign_store_id_is_rejected(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $foreign = Store::factory()->create([
            'user_id' => $this->freeUser->id,
            'btcpay_store_id' => 'btcpay-store-3',
        ]);

        $this->actingAs($this->proUser)
            ->getJson('/api/dashboard/analytics?'.http_build_query($this->range() + ['store_id' => $foreign->id]))
            ->assertStatus(403);
    }

    #[Test]
    public function analytics_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/analytics?'.http_build_query($this->range()))
            ->assertUnauthorized();
    }
}
