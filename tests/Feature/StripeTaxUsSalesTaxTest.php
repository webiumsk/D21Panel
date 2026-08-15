<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StripeTaxUsSalesTaxTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stripe_tax_calculation_updates_line_taxes_from_api(): void
    {
        config(['services.stripe.tax_secret_key' => 'sk_test_fake']);

        Http::fake([
            'api.stripe.com/v1/tax/calculations' => Http::response([
                'id' => 'taxcalc_123',
                'tax_breakdown' => [
                    [
                        'taxable_amount' => 10000,
                        'tax_amount' => 825,
                        'tax_rate_details' => [
                            'percentage_decimal' => '8.25',
                            'tax_type' => 'sales_tax',
                            'state' => 'CA',
                        ],
                    ],
                ],
                'line_items' => [
                    'data' => [
                        [
                            'reference' => 'line-0',
                            'amount' => 10000,
                            'amount_tax' => 825,
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'US LLC',
            'jurisdiction' => CompanyJurisdiction::Us,
            'default_currency' => 'USD',
            'state_region' => 'CA',
            'postal_code' => '90001',
            'street' => '1 Main St',
            'city' => 'Los Angeles',
            'app_settings' => ['us_sales_tax_provider' => 'stripe_tax'],
        ]);

        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Client Inc',
            'street' => '2 Oak Ave',
            'city' => 'Los Angeles',
            'state_region' => 'CA',
            'postal_code' => '90002',
            'country' => 'US',
        ]);

        $canonical = app(CanonicalInvoiceBuilder::class)->fromLinePayloads(
            $company,
            [['name' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
            0,
            null,
            $contact,
        );

        $this->assertSame('8.25', $canonical->taxTotal);
        $this->assertSame('108.25', $canonical->total);
        $this->assertStringContainsString('CA', (string) $canonical->taxBreakdown[0]->label);
    }

    #[Test]
    public function pro_user_can_preview_us_sales_tax_via_api(): void
    {
        config(['services.stripe.tax_secret_key' => 'sk_test_fake']);

        Http::fake([
            'api.stripe.com/v1/tax/calculations' => Http::response([
                'tax_breakdown' => [],
                'line_items' => [
                    'data' => [
                        ['reference' => 'line-0', 'amount' => 5000, 'amount_tax' => 400],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        SubscriptionPlan::create([
            'code' => 'pro', 'name' => 'pro', 'display_name' => 'Pro', 'price_eur' => 99,
            'billing_period' => 'year', 'max_stores' => 3, 'max_api_keys' => 3,
            'max_ln_addresses' => null, 'features' => ['business_invoicing'], 'is_active' => true,
        ]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => SubscriptionPlan::first()->id,
            'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addYear(),
        ]);

        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'US Co',
            'jurisdiction' => CompanyJurisdiction::Us,
            'default_currency' => 'USD',
            'state_region' => 'NY',
            'postal_code' => '10001',
            'street' => 'Broadway',
            'city' => 'New York',
            'app_settings' => ['us_sales_tax_provider' => 'stripe_tax'],
        ]);

        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/us-sales-tax/preview", [
                'currency' => 'USD',
                'lines' => [
                    ['name' => 'Item', 'quantity' => 1, 'unit_price' => 50],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.tax_total', '4.00')
            ->assertJsonPath('data.total', '54.00');
    }

    #[Test]
    public function issued_us_document_totals_match_canonical_after_save(): void
    {
        config(['services.stripe.tax_secret_key' => 'sk_test_fake']);

        Http::fake([
            'api.stripe.com/v1/tax/calculations' => Http::response([
                'tax_breakdown' => [
                    [
                        'taxable_amount' => 20000,
                        'tax_amount' => 1700,
                        'tax_rate_details' => [
                            'percentage_decimal' => '8.5',
                            'tax_type' => 'sales_tax',
                            'state' => 'TX',
                        ],
                    ],
                ],
                'line_items' => [
                    'data' => [
                        ['reference' => 'line-0', 'amount' => 20000, 'amount_tax' => 1700],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        SubscriptionPlan::create([
            'code' => 'pro', 'name' => 'pro', 'display_name' => 'Pro', 'price_eur' => 99,
            'billing_period' => 'year', 'max_stores' => 3, 'max_api_keys' => 3,
            'max_ln_addresses' => null, 'features' => ['business_invoicing'], 'is_active' => true,
        ]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => SubscriptionPlan::first()->id,
            'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addYear(),
        ]);

        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'US Stripe Co',
            'jurisdiction' => CompanyJurisdiction::Us,
            'default_currency' => 'USD',
            'state_region' => 'TX',
            'postal_code' => '73301',
            'street' => 'Main',
            'city' => 'Austin',
            'app_settings' => ['us_sales_tax_provider' => 'stripe_tax'],
        ]);

        $contact = CompanyContact::create([
            'company_id' => $company->id,
            'name' => 'Client',
            'state_region' => 'TX',
            'postal_code' => '73301',
            'street' => 'Oak',
            'city' => 'Austin',
            'country' => 'US',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/documents", [
                'type' => 'invoice',
                'company_contact_id' => $contact->id,
                'issue_date' => '2026-06-11',
                'currency' => 'USD',
                'lines' => [
                    ['name' => 'Goods', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 0],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.subtotal', '200.00');
        $response->assertJsonPath('data.tax_total', '17.00');
        $response->assertJsonPath('data.total', '217.00');

        $doc = BusinessDocument::find($response->json('data.id'));
        $canonical = app(CanonicalInvoiceBuilder::class)->fromDocument(
            $doc->fresh(['company', 'contact', 'lines'])
        );

        $this->assertSame($canonical->subtotal, $doc->subtotal);
        $this->assertSame($canonical->taxTotal, $doc->tax_total);
        $this->assertSame($canonical->total, $doc->total);
    }
}
