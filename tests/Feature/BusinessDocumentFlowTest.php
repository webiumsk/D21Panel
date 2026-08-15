<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessDocumentFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pro_user_can_create_and_issue_invoice(): void
    {
        $proPlan = SubscriptionPlan::create([
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
        $user = User::factory()->create(['btcpay_api_key' => 'test-key']);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'iban' => 'SK3112000000198747547509',
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);

        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Client Ltd',
            'email' => 'client@example.com',
        ]);

        $store = Store::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $create = $this->actingAs($user)->postJson("/api/invoicing/companies/{$company->id}/documents", [
            'type' => 'invoice',
            'company_contact_id' => $contact->id,
            'store_id' => $store->id,
            'issue_date' => now()->toDateString(),
            'payment_btc_enabled' => true,
            'payment_bank_enabled' => true,
            'lines' => [
                ['name' => 'Service', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        Http::fake([
            '*' => Http::response([
                'id' => 'btcpay-inv-issue',
                'checkoutLink' => 'https://btcpay.example/i/issue-checkout',
            ], 200),
        ]);

        $create->assertCreated();
        $docId = $create->json('data.id');

        $issue = $this->actingAs($user)->postJson(
            "/api/invoicing/companies/{$company->id}/documents/{$docId}/issue"
        );

        $issue->assertOk();
        $issue->assertJsonPath('data.status', BusinessDocumentStatus::Issued->value);
        $issue->assertJsonPath('data.number', fn ($n) => ! empty($n));
        $issue->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-issue');
        $issue->assertJsonPath('data.btcpay_checkout_link', 'https://btcpay.example/i/issue-checkout');
        $issue->assertJsonPath('data.payment_token', fn ($t) => is_string($t) && strlen($t) === 64);
    }

    #[Test]
    public function pro_user_can_update_issued_invoice_and_refresh_btcpay_on_total_change(): void
    {
        $proPlan = SubscriptionPlan::create([
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
        $user = User::factory()->create(['btcpay_api_key' => 'test-key']);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]);

        $store = Store::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $doc = BusinessDocument::create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260099',
            'total' => 100,
            'currency' => 'EUR',
            'payment_btc_enabled' => true,
            'payment_bank_enabled' => true,
            'btcpay_invoice_id' => 'old-btcpay',
            'btcpay_checkout_link' => 'https://btcpay.example/i/old',
            'issue_date' => now(),
        ]);

        Http::fake([
            '*' => Http::response([
                'id' => 'btcpay-inv-refreshed',
                'checkoutLink' => 'https://btcpay.example/i/refreshed',
            ], 200),
        ]);

        $this->actingAs($user)->patchJson(
            "/api/invoicing/companies/{$company->id}/documents/{$doc->id}",
            [
                'type' => 'invoice',
                'store_id' => $store->id,
                'payment_btc_enabled' => true,
                'payment_bank_enabled' => true,
                'lines' => [
                    ['name' => 'Service', 'quantity' => 1, 'unit_price' => 150],
                ],
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.total', '150.00')
            ->assertJsonPath('data.btcpay_invoice_id', 'btcpay-inv-refreshed')
            ->assertJsonPath('data.btcpay_checkout_link', 'https://btcpay.example/i/refreshed');
    }
}
