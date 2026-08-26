<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\EfakturaInboundReceipt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\BusinessExpenseService;
use App\Services\Invoicing\Efaktura\EfakturaInboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EfakturaInboundServiceTest extends TestCase
{
    use RefreshDatabase;

    private function inboundCompany(): Company
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
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        return Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'registration_number' => '47615681',
            'tax_id' => '2023980035',
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'payer',
            'app_settings' => [
                'efaktura_enabled' => true,
                'efaktura_inbound_enabled' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_peppol_participant_id' => '0245:2023980035',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
            ],
        ]);
    }

    private function sampleInboundUbl(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>IN-7788</cbc:ID>
  <cbc:IssueDate>2026-06-02</cbc:IssueDate>
  <cbc:DueDate>2026-06-16</cbc:DueDate>
  <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>
    <cac:Party><cac:PartyName><cbc:Name>Supplier s.r.o.</cbc:Name></cac:PartyName></cac:Party>
  </cac:AccountingSupplierParty>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">88.50</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;
    }

    private function selfBilledInboundUbl(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>IN-SB-1</cbc:ID>
  <cbc:IssueDate>2026-06-02</cbc:IssueDate>
  <cbc:InvoiceTypeCode>389</cbc:InvoiceTypeCode>
  <cac:AccountingSupplierParty>
    <cac:Party><cac:PartyName><cbc:Name>Supplier s.r.o.</cbc:Name></cac:PartyName></cac:Party>
  </cac:AccountingSupplierParty>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">88.50</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;
    }

    #[Test]
    public function poll_company_parks_a_self_billed_document_as_inbox_never_an_expense(): void
    {
        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
        ]);

        $ubl = $this->selfBilledInboundUbl();

        Http::fake(function ($request) use ($ubl) {
            $url = $request->url();
            if (str_contains($url, '/sapi/v1/auth/token')) {
                return Http::response(['access_token' => 'token-abc', 'expires_in' => 900]);
            }
            if ($request->method() === 'GET' && str_contains($url, '/sapi/v1/document/receive/inbound-42') && ! str_contains($url, '/acknowledge')) {
                return Http::response(['providerDocumentId' => 'inbound-42', 'payload' => $ubl]);
            }
            if ($request->method() === 'GET' && (str_ends_with($url, '/sapi/v1/document/receive') || str_contains($url, '/document/receive?'))) {
                return Http::response(['documents' => [['providerDocumentId' => 'inbound-42']]]);
            }
            if (str_contains($url, '/acknowledge')) {
                return Http::response(['status' => 'ACKNOWLEDGED']);
            }

            return Http::response([], 404);
        });

        $company = $this->inboundCompany();
        app(EfakturaInboundService::class)->pollCompany($company);

        // A self-billed sale must never be booked as an expense - it is parked
        // as a pending inbox item for review instead.
        $this->assertDatabaseCount('business_expenses', 0);
        $this->assertDatabaseHas('efaktura_inbound_receipts', [
            'company_id' => $company->id,
            'external_document_id' => 'inbound-42',
            'external_number' => 'IN-SB-1',
            'business_expense_id' => null,
            'inbox_status' => 'pending',
        ]);
    }

    #[Test]
    public function poll_company_imports_inbound_document_as_expense(): void
    {
        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
        ]);

        $ubl = $this->sampleInboundUbl();

        Http::fake(function ($request) use ($ubl) {
            $url = $request->url();

            if (str_contains($url, '/sapi/v1/auth/token')) {
                return Http::response(['access_token' => 'token-abc', 'expires_in' => 900]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/sapi/v1/document/receive/inbound-42') && ! str_contains($url, '/acknowledge')) {
                return Http::response([
                    'providerDocumentId' => 'inbound-42',
                    'payload' => $ubl,
                ]);
            }

            if ($request->method() === 'GET' && (str_ends_with($url, '/sapi/v1/document/receive') || str_contains($url, '/document/receive?'))) {
                return Http::response([
                    'documents' => [
                        ['providerDocumentId' => 'inbound-42'],
                    ],
                ]);
            }

            if (str_contains($url, '/acknowledge')) {
                return Http::response(['status' => 'ACKNOWLEDGED']);
            }

            return Http::response([], 404);
        });

        $company = $this->inboundCompany();
        $stats = app(EfakturaInboundService::class)->pollCompany($company);

        $this->assertSame(1, $stats['imported']);
        $this->assertSame(1, $stats['acknowledged']);

        $this->assertDatabaseHas('business_expenses', [
            'company_id' => $company->id,
            'external_number' => 'IN-7788',
            'title' => 'Supplier s.r.o.',
            'total' => 88.50,
        ]);

        $this->assertDatabaseHas('efaktura_inbound_receipts', [
            'company_id' => $company->id,
            'external_document_id' => 'inbound-42',
            'status' => 'acknowledged',
        ]);

        $receipt = EfakturaInboundReceipt::query()->first();
        $this->assertNotNull($receipt?->business_expense_id);

        $company->refresh();
        $this->assertNotNull($company->app_settings['efaktura_inbound_last_poll_at'] ?? null);
        $this->assertSame(1, $company->app_settings['efaktura_inbound_last_poll_stats']['imported'] ?? null);
    }

    #[Test]
    public function poll_company_imports_for_non_payer_sk_company(): void
    {
        // Receiving is mandatory for every SK taxable entity, not only full
        // VAT payers - a non-payer with inbound enabled gets its expenses too.
        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
        ]);

        $ubl = $this->sampleInboundUbl();

        Http::fake(function ($request) use ($ubl) {
            $url = $request->url();

            if (str_contains($url, '/sapi/v1/auth/token')) {
                return Http::response(['access_token' => 'token-abc', 'expires_in' => 900]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/sapi/v1/document/receive/inbound-77') && ! str_contains($url, '/acknowledge')) {
                return Http::response([
                    'providerDocumentId' => 'inbound-77',
                    'payload' => $ubl,
                ]);
            }

            if ($request->method() === 'GET' && (str_ends_with($url, '/sapi/v1/document/receive') || str_contains($url, '/document/receive?'))) {
                return Http::response(['documents' => [['providerDocumentId' => 'inbound-77']]]);
            }

            if (str_contains($url, '/acknowledge')) {
                return Http::response(['status' => 'ACKNOWLEDGED']);
            }

            return Http::response([], 404);
        });

        $company = $this->inboundCompany();
        $company->forceFill(['vat_status' => 'none', 'vat_payer' => false])->save();

        $stats = app(EfakturaInboundService::class)->pollCompany($company->fresh());

        $this->assertSame(1, $stats['imported']);
        $this->assertDatabaseHas('business_expenses', [
            'company_id' => $company->id,
            'external_number' => 'IN-7788',
        ]);

        // pollAll walks the same gate - the non-payer must not be skipped.
        $this->assertDatabaseCount('efaktura_inbound_receipts', 1);
    }

    #[Test]
    public function poll_all_skips_non_slovak_companies_but_not_non_payers(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/sapi/v1/auth/token')) {
                return Http::response(['access_token' => 'token-abc', 'expires_in' => 900]);
            }

            return Http::response(['documents' => []]);
        });

        $nonPayer = $this->inboundCompany();
        $nonPayer->forceFill(['vat_status' => 'none', 'vat_payer' => false])->save();

        app(EfakturaInboundService::class)->pollAll();

        $nonPayer->refresh();
        $this->assertNotNull($nonPayer->app_settings['efaktura_inbound_last_poll_at'] ?? null);
    }

    #[Test]
    public function poll_company_retries_acknowledge_after_transient_failure(): void
    {
        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
        ]);

        $ubl = $this->sampleInboundUbl();
        $ackCalls = 0;

        Http::fake(function ($request) use ($ubl, &$ackCalls) {
            $url = $request->url();

            if (str_contains($url, '/sapi/v1/auth/token')) {
                return Http::response(['access_token' => 'token-abc', 'expires_in' => 900]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/sapi/v1/document/receive/inbound-42') && ! str_contains($url, '/acknowledge')) {
                return Http::response([
                    'providerDocumentId' => 'inbound-42',
                    'payload' => $ubl,
                ]);
            }

            if ($request->method() === 'GET' && (str_ends_with($url, '/sapi/v1/document/receive') || str_contains($url, '/document/receive?'))) {
                return Http::response([
                    'documents' => [
                        ['providerDocumentId' => 'inbound-42'],
                    ],
                ]);
            }

            if (str_contains($url, '/acknowledge')) {
                $ackCalls++;

                return $ackCalls === 1
                    ? Http::response(['error' => 'temporary'], 500)
                    : Http::response(['status' => 'ACKNOWLEDGED']);
            }

            return Http::response([], 404);
        });

        $company = $this->inboundCompany();
        $service = app(EfakturaInboundService::class);

        $firstStats = $service->pollCompany($company);

        $this->assertSame(0, $firstStats['imported']);
        $this->assertSame(0, $firstStats['acknowledged']);
        $this->assertSame(1, $firstStats['failed']);

        $receipt = EfakturaInboundReceipt::query()->first();
        $this->assertNotNull($receipt);
        $this->assertSame('imported', $receipt->status);
        $this->assertNull($receipt->acknowledged_at);
        $expenseId = $receipt->business_expense_id;
        $this->assertNotNull($expenseId);

        $secondStats = $service->pollCompany($company);

        $this->assertSame(0, $secondStats['imported']);
        $this->assertSame(1, $secondStats['acknowledged']);

        $receipt->refresh();
        $this->assertSame('acknowledged', $receipt->status);
        $this->assertNotNull($receipt->acknowledged_at);
        $this->assertSame($expenseId, $receipt->business_expense_id);
    }

    #[Test]
    public function poll_company_reimports_orphaned_unacknowledged_receipt_before_acknowledge(): void
    {
        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
        ]);

        $ubl = $this->sampleInboundUbl();
        $ackCalls = 0;
        $detailCalls = 0;

        Http::fake(function ($request) use ($ubl, &$ackCalls, &$detailCalls) {
            $url = $request->url();

            if (str_contains($url, '/sapi/v1/auth/token')) {
                return Http::response(['access_token' => 'token-abc', 'expires_in' => 900]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/sapi/v1/document/receive/inbound-42') && ! str_contains($url, '/acknowledge')) {
                $detailCalls++;

                return Http::response([
                    'providerDocumentId' => 'inbound-42',
                    'payload' => $ubl,
                ]);
            }

            if ($request->method() === 'GET' && (str_ends_with($url, '/sapi/v1/document/receive') || str_contains($url, '/document/receive?'))) {
                return Http::response([
                    'documents' => [
                        ['providerDocumentId' => 'inbound-42'],
                    ],
                ]);
            }

            if (str_contains($url, '/acknowledge')) {
                $ackCalls++;

                return $ackCalls === 1
                    ? Http::response(['error' => 'temporary'], 500)
                    : Http::response(['status' => 'ACKNOWLEDGED']);
            }

            return Http::response([], 404);
        });

        $company = $this->inboundCompany();
        $service = app(EfakturaInboundService::class);

        $firstStats = $service->pollCompany($company);

        $this->assertSame(0, $firstStats['imported']);
        $this->assertSame(0, $firstStats['acknowledged']);
        $this->assertSame(1, $firstStats['failed']);

        $receipt = EfakturaInboundReceipt::query()->first();
        $this->assertNotNull($receipt);
        $expense = $receipt->expense;
        $this->assertNotNull($expense);
        $oldExpenseId = $expense->id;

        app(BusinessExpenseService::class)->permanentlyDelete($expense);
        $receipt->refresh();
        $this->assertNull($receipt->business_expense_id);

        $secondStats = $service->pollCompany($company);

        $this->assertSame(1, $secondStats['imported']);
        $this->assertSame(1, $secondStats['acknowledged']);
        $this->assertSame(2, $detailCalls);

        $receipt->refresh();
        $this->assertSame('acknowledged', $receipt->status);
        $this->assertNotNull($receipt->acknowledged_at);
        $this->assertNotNull($receipt->business_expense_id);
        $this->assertNotSame($oldExpenseId, $receipt->business_expense_id);

        $this->assertDatabaseHas('business_expenses', [
            'id' => $receipt->business_expense_id,
            'company_id' => $company->id,
            'external_number' => 'IN-7788',
            'title' => 'Supplier s.r.o.',
            'total' => 88.50,
        ]);
    }
}
