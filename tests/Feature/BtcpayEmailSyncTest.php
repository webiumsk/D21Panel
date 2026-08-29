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
        ]);
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

            return Http::response([], 404);
        });

        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        $this->assertSame(2, $usersMeCalls);
        $this->assertSame('new-merchant-key', $user->fresh()->btcpay_api_key);
    }

    #[Test]
    public function taken_email_is_logged_and_not_retried(): void
    {
        $user = $this->makeUser();

        $usersMeCalls = 0;
        Http::fake(function ($request) use (&$usersMeCalls) {
            $url = (string) $request->url();
            if (str_ends_with($url, '/api/v1/users/me')) {
                $usersMeCalls++;

                return Http::response([
                    ['path' => 'email', 'message' => "Username 'merchant@example.com' is already taken."],
                ], 422);
            }

            return Http::response([], 404);
        });

        // Must not throw (best-effort) and must not hammer the endpoint.
        app(BtcpayEmailSyncService::class)->syncUserEmail($user);

        $this->assertSame(1, $usersMeCalls);
        $this->assertSame('old-merchant-key', $user->fresh()->btcpay_api_key);
    }
}
