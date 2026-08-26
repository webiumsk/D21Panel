<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\BusinessDocumentUblService;
use App\Services\Invoicing\Efaktura\UblExpenseDraftParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * D1 (generate self-billed UBL) <-> D3.2 (parse it back) contract: what the
 * supplier's client will book (D3.3) must match what the customer issued.
 */
class SelfBilledUblRoundTripTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_self_billed_ubl_parses_back_to_the_same_customer_and_lines(): void
    {
        $plan = SubscriptionPlan::create([
            'code' => 'pro', 'name' => 'pro', 'display_name' => 'Pro', 'price_eur' => 99,
            'billing_period' => 'year', 'max_stores' => 3, 'max_api_keys' => 3, 'max_ln_addresses' => null,
            'features' => ['business_invoicing'], 'is_active' => true,
        ]);
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now(), 'expires_at' => now()->addYear(),
        ]);

        // The issuing company is the CUSTOMER under self-billing; the parser must
        // recover exactly its identity from AccountingCustomerParty.
        $customer = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Odberateľ (tvorca) s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'registration_number' => '36012345',
            'vat_number' => 'SK2020304050',
            'street' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
            'country' => 'SK',
            'vat_payer' => true,
        ]);
        $supplier = CompanyContact::create([
            'company_id' => $customer->id,
            'name' => 'Dodávateľ s.r.o.',
            'country' => 'SK',
        ]);

        $doc = BusinessDocument::create([
            'company_id' => $customer->id,
            'company_contact_id' => $supplier->id,
            'type' => 'invoice',
            'self_billed' => true,
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260909',
            'subtotal' => 250, 'tax_total' => 57.5, 'total' => 307.5, 'currency' => 'EUR',
            'issue_date' => '2026-06-01', 'variable_symbol' => '20260909',
        ]);
        BusinessDocumentLine::create([
            'business_document_id' => $doc->id, 'sort_order' => 0, 'name' => 'Widget',
            'quantity' => 2, 'unit' => 'ks', 'unit_price' => 100, 'tax_rate' => 23, 'line_total' => 246,
        ]);
        BusinessDocumentLine::create([
            'business_document_id' => $doc->id, 'sort_order' => 1, 'name' => 'Práca',
            'quantity' => 1, 'unit' => 'hod', 'unit_price' => 50, 'tax_rate' => 23, 'line_total' => 61.5,
        ]);

        $ubl = app(BusinessDocumentUblService::class)->xml($doc->fresh(['company', 'contact', 'lines']));
        $draft = (new UblExpenseDraftParser)->parse($ubl);

        $this->assertTrue($draft['self_billed']);
        $this->assertSame('389', $draft['document_type_code']);

        // The parser's "customer" is the AccountingCustomerParty = the issuing company.
        $this->assertSame('Odberateľ (tvorca) s.r.o.', $draft['customer']['name']);
        $this->assertSame('36012345', $draft['customer']['registration_number']);
        $this->assertSame('SK2020304050', $draft['customer']['vat_number']);
        $this->assertSame('Bratislava', $draft['customer']['city']);
        $this->assertSame('SK', $draft['customer']['country']);

        $this->assertCount(2, $draft['lines']);
        $this->assertSame('Widget', $draft['lines'][0]['name']);
        $this->assertSame('2.00', $draft['lines'][0]['quantity']);
        $this->assertSame('100.00', $draft['lines'][0]['unit_price']);
        $this->assertSame('23.00', $draft['lines'][0]['tax_rate']);
        // LineExtensionAmount is the NET line amount (qty x unit price).
        $this->assertSame('200.00', $draft['lines'][0]['line_total']);
        $this->assertSame('50.00', $draft['lines'][1]['line_total']);
        $this->assertSame('Práca', $draft['lines'][1]['name']);
        $this->assertSame('50.00', $draft['lines'][1]['unit_price']);
        $this->assertSame('20260909', $draft['external_number']);
    }
}
