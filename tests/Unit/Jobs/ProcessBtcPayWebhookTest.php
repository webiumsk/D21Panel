<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessBtcPayWebhook;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\BtcPay\BtcPayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessBtcPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_marks_event_as_processed_when_store_is_not_subscription_store(): void
    {
        config(['services.btcpay.subscription_store_id' => 'subscription-store-123']);
        $event = WebhookEvent::create([
            'event_type' => 'InvoiceSettled',
            'payload' => [
                'storeId' => 'other-store-456',
                'invoiceData' => ['id' => 'inv-1'],
            ],
            'verified' => true,
        ]);

        $job = new ProcessBtcPayWebhook($event);
        $job->handle();

        $event->refresh();
        $this->assertNotNull($event->processed_at);
    }

    public function test_handle_processes_subscription_invoice_paid_event_and_marks_complete(): void
    {
        Config::set('services.btcpay.base_url', 'https://btcpay.example.test');
        Config::set('services.btcpay.subscription_store_id', 'sub-store-123');
        Config::set('services.btcpay.subscription_plans.pro', 'plan-pro-123');
        $this->app->forgetInstance(BtcPayClient::class);

        SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'role' => 'free',
        ]);

        Http::fake([
            'https://btcpay.example.test/api/v1/stores/sub-store-123/invoices/inv-1' => Http::response([
                'id' => 'inv-1',
                'currency' => 'EUR',
                'amount' => 99,
            ]),
        ]);

        $event = WebhookEvent::create([
            'event_type' => 'InvoiceSettled',
            'payload' => [
                'storeId' => 'sub-store-123',
                'invoiceData' => [
                    'id' => 'inv-1',
                    'metadata' => [
                        'customerEmail' => 'customer@example.com',
                        'planId' => 'plan-pro-123',
                    ],
                ],
            ],
            'verified' => true,
        ]);

        $job = new ProcessBtcPayWebhook($event);
        $job->handle();

        $event->refresh();
        $this->assertNotNull($event->processed_at);

        $user->refresh();
        $this->assertSame('pro', $user->role);

        $subscription = Subscription::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->expires_at->isFuture());
    }

    public function test_handle_does_not_update_user_when_customer_email_not_found(): void
    {
        config(['services.btcpay.subscription_store_id' => 'sub-store-123']);
        config(['services.btcpay.subscription_plans.pro' => 'plan-pro-123']);
        $event = WebhookEvent::create([
            'event_type' => 'InvoiceSettled',
            'payload' => [
                'storeId' => 'sub-store-123',
                'invoiceData' => [
                    'id' => 'inv-1',
                    'metadata' => [
                        'customerEmail' => 'unknown@example.com',
                        'planId' => 'plan-pro-123',
                    ],
                ],
            ],
            'verified' => true,
        ]);

        $job = new ProcessBtcPayWebhook($event);
        $job->handle();

        $event->refresh();
        $this->assertNotNull($event->processed_at);
        $this->assertDatabaseMissing('users', ['email' => 'unknown@example.com']);
    }
}
