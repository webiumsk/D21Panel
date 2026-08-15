<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Jobs\ProcessBtcPayWebhook;
use App\Mail\BusinessDocumentEmail;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\BtcPay\BtcPayClient;
use App\Services\Invoicing\DocumentSequenceService;
use App\Services\Invoicing\SubscriptionBillingInvoiceService;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionBillingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        Cache::flush();
        config([
            'services.btcpay.base_url' => 'https://btcpay.example.test',
            'services.btcpay.api_key' => 'test-server-key',
        ]);
        $this->app->forgetInstance(BtcPayClient::class);
    }

    protected function seedProPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'max_companies' => 2,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);
    }

    protected function fakeBtcPaySubscriptionInvoice(string $invoiceId = 'inv-sub-1'): void
    {
        Http::fake(function ($request) use ($invoiceId) {
            $url = (string) $request->url();

            if (str_contains($url, "/invoices/{$invoiceId}/payment-methods")) {
                return Http::response([
                    [
                        'paymentMethodId' => 'BTC-LN',
                        'rate' => '60000',
                        'paymentMethodPaid' => '0.0024',
                        'totalPaid' => '0.0024',
                    ],
                ]);
            }

            if (str_contains($url, "/invoices/{$invoiceId}")) {
                return Http::response([
                    'id' => $invoiceId,
                    'currency' => 'SATS',
                    'amount' => 240_000,
                ]);
            }

            return Http::response([], 404);
        });
    }

    protected function runSubscriptionWebhook(User $subscriber, string $invoiceId = 'inv-sub-1'): void
    {
        $event = WebhookEvent::create([
            'event_type' => 'InvoiceSettled',
            'payload' => [
                'storeId' => 'sub-store-123',
                'invoiceData' => [
                    'id' => $invoiceId,
                    'currency' => 'SATS',
                    'amount' => 240_000,
                    'metadata' => [
                        'customerEmail' => $subscriber->email,
                        'planId' => 'plan-pro-123',
                        'subscriptionId' => 'btcpay-sub-99',
                    ],
                ],
            ],
            'verified' => true,
        ]);

        (new ProcessBtcPayWebhook($event))->handle();
    }

    #[Test]
    public function service_creates_paid_invoice_directly(): void
    {
        $operator = User::factory()->create();
        $billingCompany = Company::create([
            'user_id' => $operator->id,
            'legal_name' => 'Satflux s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
        app(DocumentSequenceService::class)->seedDefaultsForCompany($billingCompany);

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-123',
            'invoicing.subscription_billing.company_id' => $billingCompany->id,
        ]);

        $subscriber = User::factory()->create([
            'email' => 'customer@example.com',
            'name' => 'Customer Co',
        ]);

        $this->fakeBtcPaySubscriptionInvoice();

        $doc = app(SubscriptionBillingInvoiceService::class)->fulfillPaidInvoice(
            $subscriber,
            'pro',
            'inv-sub-1',
            ['currency' => 'SATS', 'amount' => 240_000, 'metadata' => []],
        );

        $this->assertNotNull($doc);
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);
    }

    #[Test]
    public function subscription_activation_then_fulfill_creates_invoice(): void
    {
        $this->seedProPlan();

        $operator = User::factory()->create();
        $billingCompany = Company::create([
            'user_id' => $operator->id,
            'legal_name' => 'Satflux s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
        app(DocumentSequenceService::class)->seedDefaultsForCompany($billingCompany);

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-123',
            'invoicing.subscription_billing.company_id' => $billingCompany->id,
        ]);

        $subscriber = User::factory()->create(['email' => 'customer@example.com', 'role' => 'free']);
        $this->fakeBtcPaySubscriptionInvoice();

        app(SubscriptionEntitlementService::class)->activateSubscription($subscriber, 'pro', 'btcpay-sub-99');

        $invoiceData = [
            'id' => 'inv-sub-1',
            'currency' => 'SATS',
            'amount' => 240_000,
            'metadata' => ['planId' => 'plan-pro-123', 'subscriptionId' => 'btcpay-sub-99'],
        ];

        $doc = app(SubscriptionBillingInvoiceService::class)->fulfillPaidInvoice(
            $subscriber,
            'pro',
            'inv-sub-1',
            $invoiceData,
        );

        $this->assertNotNull($doc);
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);
    }

    #[Test]
    public function webhook_creates_paid_subscription_billing_invoice_with_eur_from_sats_rate(): void
    {
        $this->seedProPlan();

        $operator = User::factory()->create(['email' => 'operator@satflux.io']);
        $billingCompany = Company::create([
            'user_id' => $operator->id,
            'legal_name' => 'Satflux s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
        app(DocumentSequenceService::class)->seedDefaultsForCompany($billingCompany);

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-123',
            'services.btcpay.subscription_plans.pro' => 'plan-pro-123',
            'invoicing.subscription_billing.company_id' => $billingCompany->id,
        ]);

        $subscriber = User::factory()->create([
            'email' => 'customer@example.com',
            'name' => 'Customer Co',
            'role' => 'free',
        ]);

        $this->fakeBtcPaySubscriptionInvoice();
        $this->runSubscriptionWebhook($subscriber);

        $subscriber->refresh();
        $this->assertSame('pro', $subscriber->role);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $subscriber->id,
            'status' => 'active',
        ]);

        $doc = BusinessDocument::query()
            ->where('btcpay_invoice_id', 'inv-sub-1')
            ->first();

        $this->assertNotNull($doc);
        $this->assertSame(BusinessDocumentStatus::Paid, $doc->status);
        $this->assertSame($billingCompany->id, $doc->company_id);
        $this->assertFalse($doc->payment_btc_enabled);
        $this->assertFalse($doc->payment_bank_enabled);
        $this->assertStringContainsString('subscription_plan=pro', (string) $doc->internal_note);

        $contact = CompanyContact::query()
            ->where('company_id', $billingCompany->id)
            ->where('email', 'customer@example.com')
            ->first();
        $this->assertNotNull($contact);
        $this->assertSame($contact->id, $doc->company_contact_id);

        $this->assertCount(1, $doc->lines);
        $this->assertSame('144.00', $doc->lines->first()->line_total);
        $this->assertSame('144.00', $doc->total);

        Mail::assertSent(BusinessDocumentEmail::class);
    }

    #[Test]
    public function fulfill_prefers_received_sats_over_misleading_eur_invoice_amount(): void
    {
        $operator = User::factory()->create();
        $billingCompany = Company::create([
            'user_id' => $operator->id,
            'legal_name' => 'Satflux s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
        app(DocumentSequenceService::class)->seedDefaultsForCompany($billingCompany);

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-123',
            'invoicing.subscription_billing.company_id' => $billingCompany->id,
        ]);

        $subscriber = User::factory()->create(['email' => 'customer@example.com']);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/invoices/inv-eur-mismatch/payment-methods')) {
                return Http::response([
                    [
                        'paymentMethodId' => 'BTC-LN',
                        'currency' => 'BTC',
                        'rate' => '60000',
                        'paymentMethodPaid' => '0.00000240',
                        'totalPaid' => '0.00000240',
                    ],
                ]);
            }

            if (str_contains($url, '/invoices/inv-eur-mismatch')) {
                return Http::response([
                    'id' => 'inv-eur-mismatch',
                    'currency' => 'EUR',
                    'amount' => 240,
                ]);
            }

            return Http::response([], 404);
        });

        $doc = app(SubscriptionBillingInvoiceService::class)->fulfillPaidInvoice(
            $subscriber,
            'pro',
            'inv-eur-mismatch',
            ['id' => 'inv-eur-mismatch', 'currency' => 'EUR', 'amount' => 240],
        );

        $this->assertNotNull($doc);
        $this->assertStringContainsString('eur_source=sats_rate', (string) $doc->internal_note);
        $this->assertStringContainsString('paid_sats=240', (string) $doc->internal_note);
        $this->assertSame('0.14', $doc->total);
    }

    #[Test]
    public function us_billing_company_uses_default_sales_tax_rate_not_vat_payer_flag(): void
    {
        $operator = User::factory()->create();
        $billingCompany = Company::create([
            'user_id' => $operator->id,
            'legal_name' => 'WY Seller LLC',
            'jurisdiction' => CompanyJurisdiction::Us,
            'default_currency' => 'USD',
            'country' => 'US',
            'state_region' => 'WY',
            'vat_payer' => true,
            'vat_rate_default' => 8.25,
            'app_settings' => ['us_sales_tax_provider' => 'manual'],
        ]);
        app(DocumentSequenceService::class)->seedDefaultsForCompany($billingCompany);

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-123',
            'invoicing.subscription_billing.company_id' => $billingCompany->id,
        ]);

        $subscriber = User::factory()->create(['email' => 'customer@example.com']);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/invoices/inv-us-tax/payment-methods')) {
                return Http::response([
                    [
                        'paymentMethodId' => 'BTC-LN',
                        'rate' => '60000',
                        'paymentMethodPaid' => '0.0024',
                        'totalPaid' => '0.0024',
                    ],
                ]);
            }

            if (str_contains($url, '/invoices/inv-us-tax')) {
                return Http::response([
                    'id' => 'inv-us-tax',
                    'currency' => 'SATS',
                    'amount' => 240_000,
                ]);
            }

            return Http::response([], 404);
        });

        $doc = app(SubscriptionBillingInvoiceService::class)->fulfillPaidInvoice(
            $subscriber,
            'pro',
            'inv-us-tax',
            ['id' => 'inv-us-tax', 'currency' => 'SATS', 'amount' => 240_000],
        );

        $this->assertNotNull($doc);
        $this->assertSame('155.88', $doc->total);
    }

    #[Test]
    public function duplicate_webhook_does_not_create_second_invoice(): void
    {
        $this->seedProPlan();

        $operator = User::factory()->create();
        $billingCompany = Company::create([
            'user_id' => $operator->id,
            'legal_name' => 'Satflux s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
        app(DocumentSequenceService::class)->seedDefaultsForCompany($billingCompany);

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-123',
            'services.btcpay.subscription_plans.pro' => 'plan-pro-123',
            'invoicing.subscription_billing.company_id' => $billingCompany->id,
        ]);

        $subscriber = User::factory()->create([
            'email' => 'customer@example.com',
            'role' => 'free',
        ]);

        $this->fakeBtcPaySubscriptionInvoice();
        $this->runSubscriptionWebhook($subscriber);
        $this->runSubscriptionWebhook($subscriber);

        $this->assertSame(1, BusinessDocument::query()->where('btcpay_invoice_id', 'inv-sub-1')->count());
    }

    #[Test]
    public function webhook_without_billing_company_still_activates_subscription(): void
    {
        $this->seedProPlan();

        config([
            'services.btcpay.subscription_store_id' => 'sub-store-123',
            'services.btcpay.subscription_plans.pro' => 'plan-pro-123',
            'invoicing.subscription_billing.company_id' => null,
        ]);

        $subscriber = User::factory()->create([
            'email' => 'customer@example.com',
            'role' => 'free',
        ]);

        Http::fake();
        $this->runSubscriptionWebhook($subscriber);

        $subscriber->refresh();
        $this->assertSame('pro', $subscriber->role);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $subscriber->id]);
        $this->assertSame(0, BusinessDocument::count());
    }
}
