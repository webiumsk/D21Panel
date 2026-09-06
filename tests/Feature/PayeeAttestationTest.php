<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Models\UserMessage;
use App\Models\WalletConnection;
use App\Notifications\WalletPayeeMismatchNotification;
use App\Services\Boltz\SettlementLedgerService;
use App\Services\WalletSecurity\PayeeAttestationService;
use App\Services\WalletSecurity\WalletConfigIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Bolt11Test;

class PayeeAttestationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456';

    /** BOLT11 the fake BTCPay hands out for new (canary) invoices; null = no Lightning destination. */
    private ?string $canaryInvoice = Bolt11Test::SPEC_COFFEE;

    /** @var list<string> BOLT11s reported as settled payments on invoice "paid-1". */
    private array $paidInvoices = [Bolt11Test::SPEC_DONATION];

    private bool $faked = false;

    /** @var list<string> */
    private array $archived = [];

    private function fakeBtcPay(): void
    {
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'POST' && preg_match('#/stores/[^/]+/invoices$#', $url)) {
                return Http::response(['id' => 'canary-1', 'status' => 'New'], 200);
            }
            if ($request->method() === 'DELETE' && preg_match('#/invoices/([^/]+)$#', $url, $m)) {
                $this->archived[] = $m[1];

                return Http::response([], 200);
            }
            if (preg_match('#/invoices/canary-1/payment-methods#', $url)) {
                return Http::response($this->canaryInvoice === null ? [] : [
                    ['paymentMethodId' => 'BTC-LN', 'destination' => $this->canaryInvoice, 'payments' => []],
                ], 200);
            }
            if (preg_match('#/invoices/paid-1/payment-methods#', $url)) {
                return Http::response([
                    ['paymentMethodId' => 'BTC-CHAIN', 'destination' => 'bc1qxyz', 'payments' => [], 'rate' => '60000'],
                    [
                        'paymentMethodId' => 'BTC-LN',
                        'destination' => $this->paidInvoices[0],
                        'rate' => '60000',
                        'payments' => array_map(fn ($b) => ['id' => 'p'.md5($b), 'destination' => $b, 'value' => '0.00001', 'status' => 'Settled', 'receivedDate' => now()->toIso8601String()], $this->paidInvoices),
                    ],
                ], 200);
            }
            if (preg_match('#/invoices/paid-1$#', $url)) {
                return Http::response(['id' => 'paid-1', 'status' => 'Settled', 'currency' => 'EUR', 'amount' => '1'], 200);
            }
            if (str_contains($url, '/payment-methods')) {
                return Http::response([
                    ['paymentMethodId' => 'BTC-LN', 'enabled' => true, 'config' => ['connectionString' => self::SECRET]],
                ], 200);
            }

            return Http::response([], 200);
        });
    }

    /** @return array{0: User, 1: Store, 2: WalletConnection} */
    private function connectedStore(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id, 'wallet_type' => 'blink']);
        $connection = WalletConnection::create([
            'store_id' => $store->id,
            'type' => 'blink',
            'encrypted_secret' => Crypt::encryptString(self::SECRET),
            'status' => 'connected',
            'submitted_by_user_id' => $user->id,
        ]);

        return [$user, $store, $connection];
    }

    #[Test]
    public function baseline_learns_the_payee_from_a_canary_invoice_and_archives_it(): void
    {
        $this->fakeBtcPay();
        [$user, , $connection] = $this->connectedStore();

        $this->assertTrue(app(WalletConfigIntegrityService::class)->baseline($connection, $user));

        $fresh = $connection->fresh();
        $this->assertSame([Bolt11Test::SPEC_PAYEE], $fresh->payee_pubkeys);
        $this->assertSame('canary', $fresh->payee_learn_source);
        $this->assertNotNull($fresh->payee_learned_at);
        $this->assertSame(['canary-1'], $this->archived, 'the canary invoice is archived right after reading');
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet_connection.payee_learned', 'target_id' => $connection->id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/invoices')
            && ($r['metadata']['satflux_canary'] ?? false) === true && $r['currency'] === 'BTC');
    }

    #[Test]
    public function without_a_canary_the_first_settled_payment_is_trusted(): void
    {
        $this->fakeBtcPay();
        $this->canaryInvoice = null;
        [$user, $store, $connection] = $this->connectedStore();
        app(WalletConfigIntegrityService::class)->baseline($connection, $user);
        $this->assertNull($connection->fresh()->payee_pubkeys);

        app(SettlementLedgerService::class)->syncInvoice($store, 'paid-1');

        $fresh = $connection->fresh();
        $this->assertSame([Bolt11Test::SPEC_PAYEE], $fresh->payee_pubkeys);
        $this->assertSame('first_payment', $fresh->payee_learn_source);
    }

    #[Test]
    public function a_payment_signed_by_another_node_raises_a_security_incident_once(): void
    {
        Notification::fake();
        $this->fakeBtcPay();
        [$user, $store, $connection] = $this->connectedStore();
        $admin = User::factory()->admin()->create();
        app(WalletConfigIntegrityService::class)->baseline($connection, $user);

        // Attacker's invoice gets paid.
        $this->paidInvoices = [Bolt11Test::OTHER_INVOICE];
        app(SettlementLedgerService::class)->syncInvoice($store, 'paid-1');

        $fresh = $connection->fresh();
        $this->assertNotNull($fresh->payee_mismatch_at);
        $this->assertSame(Bolt11Test::OTHER_PAYEE, $fresh->payee_mismatch_details['pubkey']);
        $this->assertSame('paid-1', $fresh->payee_mismatch_details['invoice_id']);
        $this->assertSame([Bolt11Test::SPEC_PAYEE], $fresh->payee_mismatch_details['expected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet_connection.payee_mismatch', 'target_id' => $connection->id]);

        $merchantMessage = UserMessage::where('user_id', $user->id)->where('type', 'security')->first();
        $this->assertNotNull($merchantMessage);
        $this->assertStringContainsString('received by node 029fc62178…883826', $merchantMessage->body);
        $this->assertStringContainsString('Check your wallet balance', $merchantMessage->body);
        $this->assertDatabaseHas('user_messages', ['user_id' => $admin->id, 'type' => 'security']);
        Notification::assertSentTo($user, WalletPayeeMismatchNotification::class);

        // Same invoice synced again (webhook retry): no second incident.
        app(SettlementLedgerService::class)->syncInvoice($store, 'paid-1');
        $this->assertSame(1, AuditLog::where('action', 'wallet_connection.payee_mismatch')->count());
        $this->assertSame(1, UserMessage::where('user_id', $user->id)->where('type', 'security')->count());

        // Payments to the known node keep passing while the incident is open.
        $this->paidInvoices = [Bolt11Test::SPEC_DONATION];
        $result = app(PayeeAttestationService::class)->attestInvoice($store, 'paid-1', [
            ['paymentMethodId' => 'BTC-LN', 'destination' => Bolt11Test::SPEC_DONATION, 'payments' => [['destination' => Bolt11Test::SPEC_DONATION]]],
        ]);
        $this->assertSame(['ok'], array_values($result));
        $this->assertNotNull($connection->fresh()->payee_mismatch_at);
    }

    #[Test]
    public function reconnecting_the_wallet_relearns_the_payee_and_closes_the_incident(): void
    {
        Notification::fake();
        $this->fakeBtcPay();
        [$user, $store, $connection] = $this->connectedStore();
        app(WalletConfigIntegrityService::class)->baseline($connection, $user);
        $this->paidInvoices = [Bolt11Test::OTHER_INVOICE];
        app(SettlementLedgerService::class)->syncInvoice($store, 'paid-1');
        $this->assertNotNull($connection->fresh()->payee_mismatch_at);

        // Merchant moved to the other provider and reconnected: the canary now comes from that node.
        $this->canaryInvoice = Bolt11Test::OTHER_INVOICE;
        app(WalletConfigIntegrityService::class)->baseline($connection->fresh(), $user, 'connected');

        $fresh = $connection->fresh();
        $this->assertNull($fresh->payee_mismatch_at);
        $this->assertSame([Bolt11Test::OTHER_PAYEE], $fresh->payee_pubkeys);
    }

    #[Test]
    public function admin_can_accept_a_node_relearn_and_sees_payee_incidents(): void
    {
        Notification::fake();
        $this->fakeBtcPay();
        [$user, $store, $connection] = $this->connectedStore();
        app(WalletConfigIntegrityService::class)->baseline($connection, $user);
        $this->paidInvoices = [Bolt11Test::OTHER_INVOICE];
        app(SettlementLedgerService::class)->syncInvoice($store, 'paid-1');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/admin/wallet-changes/drifts')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payee_mismatch_details.pubkey', Bolt11Test::OTHER_PAYEE)
            ->assertJsonPath('data.0.payee_pubkeys.0', Bolt11Test::SPEC_PAYEE);

        $this->postJson("/api/admin/wallet-connections/{$connection->id}/accept-payee", ['pubkey' => 'nope'])
            ->assertStatus(422);
        $this->postJson("/api/admin/wallet-connections/{$connection->id}/accept-payee", ['pubkey' => Bolt11Test::OTHER_PAYEE])
            ->assertStatus(200)
            ->assertJsonPath('data.payee_pubkeys.1', Bolt11Test::OTHER_PAYEE);

        $fresh = $connection->fresh();
        $this->assertNull($fresh->payee_mismatch_at);
        $this->assertSame([Bolt11Test::SPEC_PAYEE, Bolt11Test::OTHER_PAYEE], $fresh->payee_pubkeys);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet_connection.payee_accepted', 'user_id' => $admin->id]);
        $this->getJson('/api/admin/wallet-changes/drifts')->assertJsonCount(0, 'data');

        $this->postJson("/api/admin/wallet-connections/{$connection->id}/learn-payee")
            ->assertStatus(200)
            ->assertJsonPath('data.payee_pubkeys', [Bolt11Test::SPEC_PAYEE]);

        $actions = array_column($this->getJson('/api/admin/wallet-changes?store_id='.$store->id)->json('data'), 'action');
        $this->assertContains('wallet_connection.payee_mismatch', $actions);
        $this->assertContains('wallet_connection.payee_accepted', $actions);
        $this->assertContains('wallet_connection.payee_learned', $actions);

        // Support cannot.
        $this->actingAs(User::factory()->support()->create())
            ->postJson("/api/admin/wallet-connections/{$connection->id}/accept-payee", ['pubkey' => Bolt11Test::OTHER_PAYEE])
            ->assertStatus(403);
    }

    #[Test]
    public function learn_payees_command_fills_missing_allow_lists_only(): void
    {
        $this->fakeBtcPay();
        [, , $withList] = $this->connectedStore();
        $withList->forceFill(['payee_pubkeys' => [Bolt11Test::OTHER_PAYEE], 'payee_learn_source' => 'canary', 'payee_learned_at' => now()])->save();
        [, , $without] = $this->connectedStore();

        $this->artisan('wallet-connections:learn-payees')
            ->expectsOutputToContain('learned: 1, skipped: 0')
            ->assertExitCode(0);

        $this->assertSame([Bolt11Test::OTHER_PAYEE], $withList->fresh()->payee_pubkeys, 'existing lists are left alone');
        $this->assertSame([Bolt11Test::SPEC_PAYEE], $without->fresh()->payee_pubkeys);
    }

    #[Test]
    public function merchant_endpoint_exposes_the_payee_incident(): void
    {
        Notification::fake();
        $this->fakeBtcPay();
        [$user, $store, $connection] = $this->connectedStore();
        app(WalletConfigIntegrityService::class)->baseline($connection, $user);
        $this->paidInvoices = [Bolt11Test::OTHER_INVOICE];
        app(SettlementLedgerService::class)->syncInvoice($store, 'paid-1');

        $this->actingAs($user)->getJson("/api/stores/{$store->id}/wallet-connection")
            ->assertStatus(200)
            ->assertJsonPath('data.payee_mismatch_details.pubkey', Bolt11Test::OTHER_PAYEE);
    }
}
