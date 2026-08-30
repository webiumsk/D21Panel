<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealUnconfirmedBtcpayUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.btcpay.base_url' => 'https://btcpay.test',
            'services.btcpay.api_key' => 'server-key',
        ]);

        User::factory()->create(['email' => 'ok@example.com', 'btcpay_user_id' => 'bp-ok']);
        User::factory()->create(['email' => 'broken@example.com', 'btcpay_user_id' => 'bp-broken']);
        User::factory()->create(['email' => 'nobtc@example.com', 'btcpay_user_id' => null]);
    }

    protected function fakeBtcpay(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_ends_with($url, '/api/v1/users/bp-ok')) {
                return Http::response(['id' => 'bp-ok', 'emailConfirmed' => true], 200);
            }
            if (str_ends_with($url, '/api/v1/users/bp-broken')) {
                return Http::response(['id' => 'bp-broken', 'emailConfirmed' => false], 200);
            }
            if (str_contains($url, '/plugins/email-confirm/users/bp-broken/confirm-email')) {
                return Http::response(['emailConfirmed' => true, 'changed' => true], 200);
            }

            return Http::response([], 404);
        });
    }

    #[Test]
    public function confirms_exactly_the_unconfirmed_users(): void
    {
        $this->fakeBtcpay();

        $this->artisan('btcpay:heal-unconfirmed-users')
            ->expectsOutputToContain('confirmed 1')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/plugins/email-confirm/users/bp-broken/confirm-email'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'bp-ok/confirm-email'));
    }

    #[Test]
    public function dry_run_reports_without_confirming(): void
    {
        $this->fakeBtcpay();

        $this->artisan('btcpay:heal-unconfirmed-users', ['--dry-run' => true])
            ->expectsOutputToContain('would confirm 1')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'confirm-email'));
    }
}
