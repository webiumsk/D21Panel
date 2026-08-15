<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LandingPayButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.btcpay.base_url' => 'https://btcpay.test']);
        $this->app->forgetInstance(BtcPayClient::class);
    }

    #[Test]
    public function landing_pay_button_returns_503_when_not_configured(): void
    {
        config(['services.btcpay.landing_pay_demo_store_id' => null]);

        $response = $this->post('/landing/pay-button', [
            'price' => 5,
            'currency' => 'EUR',
        ]);

        $response->assertStatus(503);
    }

    #[Test]
    public function landing_pay_button_redirects_to_btcpay_checkout(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-api-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-abc',
        ]);

        config(['services.btcpay.landing_pay_demo_store_id' => (string) $store->id]);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if ($request->method() === 'POST'
                && str_contains($url, '/api/v1/stores/btcpay-store-abc/invoices')) {
                return Http::response([
                    'checkoutLink' => 'https://btcpay.test/i/landing-demo',
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->post('/landing/pay-button', [
            'price' => 10,
            'currency' => 'EUR',
        ]);

        $response->assertRedirect('https://btcpay.test/i/landing-demo');
    }
}
