<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BtcPay\BtcpayEmailSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BtcpayEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.btcpay.base_url' => 'https://btcpay.test',
            'services.btcpay.api_key' => 'server-key',
            // Most tests exercise the opt-in rename path; the default
            // (label-only) has its own test below.
            'services.btcpay.email_rename' => true,
        ]);
    }

    #[Test]
    public function default_sync_only_labels_the_machine_user(): void
    {
        config(['services.btcpay.email_rename' => false]);
        $user = $this->makeUser();
        $user->forceFill(['btcpay_password' => 'stored-btcpay-pass'])->save();

        Http::fake([
            'https://btcpay.test/api/v1/users/me' => Http::response([], 200),
        ]);

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        // Only the name label goes out - never the email (a rename would reset
        // emailConfirmed with no API way back).
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with((string) $request->url(), '/api/v1/users/me')
                && ($body['name'] ?? null) === 'Satflux: merchant@example.com'
                && ! isset($body['email'])
                && ! isset($body['currentPassword']);
        });
        Http::assertSentCount(1);
    }

    protected function makeUser(): User
    {
        return User::factory()->create([
            'email' => 'merchant@example.com',
            'btcpay_user_id' => 'btcpay-user-1',
            'btcpay_api_key' => 'old-merchant-key',
        ]);
    }

    #[Test]
    public function forbidden_key_is_reminted_and_the_sync_retried_once(): void
    {
        $user = $this->makeUser();

        $usersMeCalls = 0;
        Http::fake(function ($request) use (&$usersMeCalls) {
            $url = (string) $request->url();
            if (str_ends_with($url, '/api/v1/users/me')) {
                $usersMeCalls++;

                // Old key lacks btcpay.user.canmodifyprofile; the re-minted key works.
                return $usersMeCalls === 1
                    ? Http::response(['message' => 'Forbidden'], 403)
                    : Http::response(['email' => 'merchant@example.com'], 200);
            }
            if (str_contains($url, '/api/v1/users/btcpay-user-1/api-keys')) {
                return Http::response(['apiKey' => 'new-merchant-key'], 200);
            }
            if (str_contains($url, '/api-keys/')) {
                return Http::response([], 200);
            }
            if (str_contains($url, 'confirm-email')) {
                return Http::response([], 200);
            }

            return Http::response([], 404);
        });

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        $this->assertSame(2, $usersMeCalls);
        $this->assertSame('new-merchant-key', $user->fresh()->btcpay_api_key);
    }

    #[Test]
    public function stored_btcpay_password_is_sent_as_current_password(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['btcpay_password' => 'stored-btcpay-pass'])->save();

        Http::fake([
            'https://btcpay.test/api/v1/users/me' => Http::response(['email' => 'merchant@example.com'], 200),
            'https://btcpay.test/api/v1/users/btcpay-user-1/confirm-email' => Http::response([], 200),
        ]);

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with((string) $request->url(), '/api/v1/users/me')
                && ($body['email'] ?? null) === 'merchant@example.com'
                && ($body['currentPassword'] ?? null) === 'stored-btcpay-pass';
        });

        // Changing the email resets emailConfirmed on BTCPay - the sync must
        // re-confirm via the server key or the merchant API key stops working.
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/users/btcpay-user-1/confirm-email'));
    }

    #[Test]
    public function current_password_rejection_is_logged_and_not_retried(): void
    {
        // Legacy guest: BTCPay password was generated and discarded, and the
        // server now demands it for email changes - a retry cannot help.
        $user = $this->makeUser();

        $usersMeCalls = 0;
        Http::fake(function ($request) use (&$usersMeCalls) {
            $url = (string) $request->url();
            if (str_ends_with($url, '/api/v1/users/me')) {
                $usersMeCalls++;

                return Http::response([
                    ['path' => 'CurrentPassword', 'message' => 'The current password is not correct.'],
                ], 422);
            }

            return Http::response([], 404);
        });

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        $this->assertSame(1, $usersMeCalls);
    }

    #[Test]
    public function sync_without_btcpay_user_id_is_skipped_entirely(): void
    {
        // The email change resets emailConfirmed and without a BTCPay user id
        // there is no way to re-confirm - the sync must not touch the account.
        $user = User::factory()->create([
            'email' => 'merchant@example.com',
            'btcpay_user_id' => null,
            'btcpay_api_key' => 'old-merchant-key',
        ]);

        Http::fake();

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        Http::assertNothingSent();
    }

    #[Test]
    public function legacy_account_without_merchant_key_reconfirms_after_update(): void
    {
        $user = User::factory()->create([
            'email' => 'merchant@example.com',
            'btcpay_user_id' => 'btcpay-user-1',
            'btcpay_api_key' => null,
        ]);

        Http::fake([
            'https://btcpay.test/api/v1/users/btcpay-user-1' => Http::response([], 200),
            'https://btcpay.test/api/v1/users/btcpay-user-1/confirm-email' => Http::response([], 200),
        ]);

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/users/btcpay-user-1/confirm-email'));
    }

    #[Test]
    public function client_rejects_public_http_base_url_outright(): void
    {
        config(['services.btcpay.base_url' => 'http://btcpay.test']);

        $this->expectException(\RuntimeException::class);

        app(BtcpayEmailSyncService::class)->syncUserEmail($this->makeUser());
    }

    #[Test]
    public function sync_refuses_to_send_credentials_over_plain_http(): void
    {
        // Dot-less Docker hostname passes the client-level guard; the sync's
        // stricter HTTPS-only rule still keeps credentials off the wire.
        config(['services.btcpay.base_url' => 'http://btcpay-internal']);
        $user = $this->makeUser();
        $user->forceFill(['btcpay_password' => 'stored-btcpay-pass'])->save();

        Http::fake();

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        Http::assertNothingSent();
    }

    #[Test]
    public function taken_email_is_logged_and_not_retried(): void
    {
        $user = $this->makeUser();

        $emailChangeAttempts = 0;
        $nameLabelCalls = 0;
        Http::fake(function ($request) use (&$emailChangeAttempts, &$nameLabelCalls) {
            $url = (string) $request->url();
            if (str_ends_with($url, '/api/v1/users/me')) {
                $body = $request->data();
                if (isset($body['email'])) {
                    $emailChangeAttempts++;

                    return Http::response([
                        ['path' => 'email', 'message' => "Username 'merchant@example.com' is already taken."],
                    ], 422);
                }

                // Monetization-style servers keep the email forever taken - the
                // machine user gets labeled via its profile name instead.
                if (($body['name'] ?? null) === 'Satflux: merchant@example.com') {
                    $nameLabelCalls++;

                    return Http::response([], 200);
                }
            }

            return Http::response([], 404);
        });

        // Must not throw (best-effort) and must not hammer the email change.
        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        $this->assertSame(1, $emailChangeAttempts);
        $this->assertSame(1, $nameLabelCalls);
        $this->assertSame('old-merchant-key', $user->fresh()->btcpay_api_key);
    }
}
