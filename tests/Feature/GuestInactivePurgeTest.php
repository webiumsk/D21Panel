<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\GuestBtcPayDecommissioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuestInactivePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('guest.purge_enabled', true);
        Config::set('guest.idle_days', 90);
        Config::set('guest.batch_size', 50);
        Config::set('guest.max_stores_check', 10);
        Config::set('services.btcpay.base_url', 'http://guest-purge-btcpay.test');
        Config::set('services.btcpay.api_key', 'server-test-key');
    }

    protected function tearDown(): void
    {
        Http::fake();
        parent::tearDown();
    }

    /**
     * BTCPay list-invoices HTTP (exercises real InvoiceService + BtcPayClient parsing).
     */
    protected function fakeBtcpayListInvoicesHttp(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (preg_match('#/api/v1/stores/([^/]+)/invoices(\?|$)#', $url, $m)) {
                $storeId = $m[1];
                if ($storeId === 'btcpay_store_test_2') {
                    return Http::response(['data' => [['id' => 'inv_1', 'status' => 'New']]], 200);
                }

                return Http::response(['data' => []], 200);
            }

            return Http::response(['message' => 'unfaked: '.$url], 404);
        });
    }

    public function test_command_skips_when_purge_disabled_and_no_force(): void
    {
        Config::set('guest.purge_enabled', false);

        $user = User::factory()->guest()->create([
            'last_login_at' => now()->subDays(120),
        ]);
        Store::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->mock(GuestBtcPayDecommissioner::class, function ($mock) {
            $mock->shouldNotReceive('decommissionAllForLocalGuestUser');
        });

        $exitCode = Artisan::call('guests:purge-inactive');

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_purge_deletes_guest_when_login_stale_and_no_invoices(): void
    {
        $this->fakeBtcpayListInvoicesHttp();

        $user = User::factory()->guest()->create([
            'last_login_at' => now()->subDays(120),
        ]);
        Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay_store_test_1',
        ]);

        $this->mock(GuestBtcPayDecommissioner::class, function ($mock) {
            $mock->shouldReceive('decommissionAllForLocalGuestUser')
                ->once()
                ->andReturn(true);
        });

        $this->assertSame(0, Artisan::call('guests:purge-inactive', ['--force' => true]));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_purge_deletes_guest_when_enabled_and_no_force(): void
    {
        $this->fakeBtcpayListInvoicesHttp();

        $user = User::factory()->guest()->create([
            'last_login_at' => now()->subDays(120),
        ]);
        Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay_store_test_1',
        ]);

        $this->mock(GuestBtcPayDecommissioner::class, function ($mock) {
            $mock->shouldReceive('decommissionAllForLocalGuestUser')
                ->once()
                ->andReturn(true);
        });

        $this->assertSame(0, Artisan::call('guests:purge-inactive'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_purge_keeps_guest_when_recent_invoice_exists(): void
    {
        $this->fakeBtcpayListInvoicesHttp();

        $user = User::factory()->guest()->create([
            'last_login_at' => now()->subDays(120),
        ]);
        Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay_store_test_2',
        ]);

        $this->mock(GuestBtcPayDecommissioner::class, function ($mock) {
            $mock->shouldNotReceive('decommissionAllForLocalGuestUser');
        });

        Artisan::call('guests:purge-inactive', ['--force' => true]);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_purge_keeps_guest_when_last_login_recent(): void
    {
        $user = User::factory()->guest()->create([
            'last_login_at' => now()->subDays(1),
        ]);
        Store::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->mock(GuestBtcPayDecommissioner::class, function ($mock) {
            $mock->shouldNotReceive('decommissionAllForLocalGuestUser');
        });

        Artisan::call('guests:purge-inactive', ['--force' => true]);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_dry_run_does_not_delete_or_decommission(): void
    {
        $this->fakeBtcpayListInvoicesHttp();

        $user = User::factory()->guest()->create([
            'last_login_at' => now()->subDays(120),
        ]);
        Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay_store_test_1',
        ]);

        $this->mock(GuestBtcPayDecommissioner::class, function ($mock) {
            $mock->shouldNotReceive('decommissionAllForLocalGuestUser');
        });

        Artisan::call('guests:purge-inactive', [
            '--force' => true,
            '--dry-run' => true,
        ]);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
