<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlinkMigrationAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);

        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains((string) $request->url(), '/api/v1/stores/')) {
                return Http::response([
                    'id' => 'btcpay-store-1',
                    'storeId' => 'btcpay-store-1',
                    'name' => 'Blink Store',
                    'defaultCurrency' => 'EUR',
                    'timeZone' => 'Europe/Vienna',
                    'preferredExchange' => 'kraken',
                ], 200);
            }

            return Http::response([], 404);
        });
    }

    #[Test]
    public function blink_store_exposes_active_migration_alert(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withBlink()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', true)
            ->assertJsonPath('data.blink_migration_alert.snoozed_until', null)
            ->assertJsonPath('data.blink_migration_alert.dismissed_at', null);
    }

    #[Test]
    public function alert_is_inactive_for_store_on_ln_address_format(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withBlink()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString('type=blink;ln-address=satoshi@blink.sv;'),
            'status' => 'connected',
            'submitted_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', false);
    }

    #[Test]
    public function alert_stays_active_for_store_on_legacy_api_key_format(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withBlink()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);
        WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString('type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456'),
            'status' => 'connected',
            'submitted_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', true);
    }

    #[Test]
    public function snooze_hides_alert_for_24_hours(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withBlink()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/blink-migration-alert/snooze")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', false)
            ->assertJsonPath('data.blink_migration_alert.snoozed_until', fn ($v) => is_string($v) && $v !== '');

        $this->assertNotNull($store->fresh()->blink_alert_snoozed_until);

        $this->travel(25)->hours();

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', true);
    }

    #[Test]
    public function dismiss_hides_alert_permanently_for_store(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withBlink()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/blink-migration-alert/dismiss")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', false)
            ->assertJsonPath('data.blink_migration_alert.dismissed_at', fn ($v) => is_string($v) && $v !== '');

        $this->travel(48)->hours();

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', false);
    }

    #[Test]
    public function alert_becomes_inactive_after_wallet_type_migration(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withBlink()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', true);

        $store->update(['wallet_type' => 'aqua_boltz']);

        $this->actingAs($user)
            ->getJson("/api/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.blink_migration_alert.active', false);
    }

    #[Test]
    public function non_owner_cannot_snooze_or_dismiss(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $store = Store::factory()->withBlink()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)
            ->postJson("/api/stores/{$store->id}/blink-migration-alert/snooze")
            ->assertForbidden();

        $this->actingAs($intruder)
            ->postJson("/api/stores/{$store->id}/blink-migration-alert/dismiss")
            ->assertForbidden();
    }

    #[Test]
    public function non_blink_store_returns_422_on_snooze_and_dismiss(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->withAquaBoltz()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/blink-migration-alert/snooze")
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson("/api/stores/{$store->id}/blink-migration-alert/dismiss")
            ->assertStatus(422);
    }
}
