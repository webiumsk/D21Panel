<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\EfakturaInboundReceipt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\Efaktura\EfakturaInboundInboxService;
use App\Services\Invoicing\Efaktura\EfakturaInboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EfakturaInboundInboxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Company}
     */
    private function proUserWithBridgeCompany(): array
    {
        $plan = SubscriptionPlan::firstOrCreate(['code' => 'pro'], [
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
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Bridge s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'registration_number' => '47615681',
            'tax_id' => '2023980035',
            'country' => 'SK',
            'vat_payer' => false,
            'vat_status' => 'none',
            'app_settings' => [
                'efaktura_enabled' => true,
                'efaktura_inbound_enabled' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
            ],
        ]);

        return [$user, $company];
    }

    private function sampleUbl(string $number = 'IN-7788'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>{$number}</cbc:ID>
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

    private function fakeCpds(string $ubl, string $externalId = 'inbound-42'): void
    {
        Http::fake(function ($request) use ($ubl, $externalId) {
            $url = $request->url();
            if (str_contains($url, '/sapi/v1/auth/token')) {
                return Http::response(['access_token' => 'token-abc', 'expires_in' => 900]);
            }
            if ($request->method() === 'GET' && str_contains($url, "/sapi/v1/document/receive/{$externalId}") && ! str_contains($url, '/acknowledge')) {
                return Http::response(['providerDocumentId' => $externalId, 'payload' => $ubl]);
            }
            if ($request->method() === 'GET' && (str_ends_with($url, '/sapi/v1/document/receive') || str_contains($url, '/document/receive?'))) {
                return Http::response(['documents' => [['providerDocumentId' => $externalId]]]);
            }
            if (str_contains($url, '/acknowledge')) {
                return Http::response(['status' => 'ACKNOWLEDGED']);
            }

            return Http::response([], 404);
        });
    }

    private function localFirst(): void
    {
        config([
            'efaktura.enabled' => true,
            'efaktura.allowed_sapi_hosts' => ['sapi.test'],
            'invoicing.local_first' => true,
        ]);
    }

    #[Test]
    public function poll_parks_the_document_as_a_pending_inbox_item_for_local_first_companies(): void
    {
        $this->localFirst();
        $this->fakeCpds($this->sampleUbl());
        [, $company] = $this->proUserWithBridgeCompany();

        $stats = app(EfakturaInboundService::class)->pollCompany($company);

        $this->assertSame(1, $stats['imported']);
        $this->assertSame(1, $stats['acknowledged']);
        $this->assertDatabaseCount('business_expenses', 0);

        $receipt = EfakturaInboundReceipt::query()->firstOrFail();
        $this->assertSame('pending', $receipt->inbox_status);
        $this->assertSame('acknowledged', $receipt->status);
        $this->assertNotNull($receipt->acknowledged_at);
        $this->assertNotNull($receipt->evolu_expense_id);
        $this->assertNull($receipt->business_expense_id);
        $this->assertNull($receipt->attachment_path);
        $this->assertSame('IN-7788', $receipt->external_number);
        $this->assertSame('Supplier s.r.o.', $receipt->supplier_name);
        $this->assertSame('88.50', $receipt->total);
        $this->assertSame('IN-7788', $receipt->draft_json['external_number'] ?? null);
        // UBL is encrypted at rest and never stored in plain text.
        $this->assertStringNotContainsString('<Invoice', (string) $receipt->getRawOriginal('ubl_encrypted'));
        $this->assertStringContainsString('<Invoice', Crypt::decryptString($receipt->ubl_encrypted));
        // The provider detail echoes the UBL - it must not land in the plain
        // response_payload column either.
        $this->assertStringNotContainsString('<Invoice', (string) $receipt->getRawOriginal('response_payload'));
        $this->assertSame('inbound-42', $receipt->response_payload['providerDocumentId'] ?? null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.efaktura_inbound_inboxed', 'target_id' => $company->id]);

        // A second poll must not re-fetch or duplicate the item.
        $again = app(EfakturaInboundService::class)->pollCompany($company->fresh());
        $this->assertSame(1, $again['skipped']);
        $this->assertDatabaseCount('efaktura_inbound_receipts', 1);
    }

    #[Test]
    public function server_mode_companies_keep_creating_expenses(): void
    {
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => ['sapi.test'], 'invoicing.local_first' => false]);
        $this->fakeCpds($this->sampleUbl());
        [, $company] = $this->proUserWithBridgeCompany();

        app(EfakturaInboundService::class)->pollCompany($company);

        $this->assertDatabaseCount('business_expenses', 1);
        $receipt = EfakturaInboundReceipt::query()->firstOrFail();
        $this->assertSame('imported', $receipt->inbox_status);
        $this->assertNull($receipt->ubl_encrypted);
    }

    #[Test]
    public function inbox_endpoints_list_show_import_and_dismiss(): void
    {
        $this->localFirst();
        $this->fakeCpds($this->sampleUbl());
        [$user, $company] = $this->proUserWithBridgeCompany();
        app(EfakturaInboundService::class)->pollCompany($company);
        $receipt = EfakturaInboundReceipt::query()->firstOrFail();

        $list = $this->actingAs($user)
            ->getJson("/api/invoicing/companies/{$company->id}/efaktura/inbox")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inbox_id', $receipt->id)
            ->assertJsonPath('data.0.evolu_expense_id', $receipt->evolu_expense_id)
            ->assertJsonPath('data.0.summary.supplier_name', 'Supplier s.r.o.')
            ->assertJsonPath('data.0.summary.total', '88.50')
            ->assertJsonPath('data.0.draft.external_number', 'IN-7788')
            ->assertJsonMissingPath('data.0.ubl')
            ->assertJsonMissingPath('data.0.ubl_encrypted');

        $this->assertStringNotContainsString('<Invoice', $list->getContent());

        $this->actingAs($user)
            ->getJson("/api/invoicing/companies/{$company->id}/efaktura/inbox/{$receipt->id}")
            ->assertOk()
            ->assertJsonPath('data.inbox_id', $receipt->id)
            ->assertJsonPath('data.summary.external_number', 'IN-7788');
        $detail = $this->actingAs($user)->getJson("/api/invoicing/companies/{$company->id}/efaktura/inbox/{$receipt->id}")->json('data');
        $this->assertStringContainsString('<Invoice', (string) $detail['ubl']);

        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/inbox/{$receipt->id}/imported")
            ->assertNoContent();

        $receipt->refresh();
        $this->assertSame('imported', $receipt->inbox_status);
        $this->assertNull($receipt->draft_json);
        $this->assertNull($receipt->ubl_encrypted);
        $this->assertNotNull($receipt->inbox_resolved_at);
        $this->assertNotNull($receipt->evolu_expense_id, 'stable id survives so a second device can dedupe');

        $this->actingAs($user)
            ->getJson("/api/invoicing/companies/{$company->id}/efaktura/inbox")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Not pending any more - imported / dismiss are rejected, also when the
        // caller holds a stale (still pending) copy of the row: the update is
        // conditional on the database state, not on the loaded model.
        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/inbox/{$receipt->id}/dismiss")
            ->assertStatus(422);
        $stale = EfakturaInboundReceipt::query()->findOrFail($receipt->id);
        $stale->inbox_status = 'pending';
        try {
            app(EfakturaInboundInboxService::class)->markImported($stale, $company);
            $this->fail('stale pending copy must not win');
        } catch (ValidationException) {
            // expected
        }

        // Poll retry after import: the CPDS still lists the id; nothing is re-fetched.
        $again = app(EfakturaInboundService::class)->pollCompany($company->fresh());
        $this->assertSame(1, $again['skipped']);
        $this->assertSame('imported', $receipt->fresh()->inbox_status);
    }

    #[Test]
    public function dismiss_clears_the_payload(): void
    {
        $this->localFirst();
        $this->fakeCpds($this->sampleUbl());
        [$user, $company] = $this->proUserWithBridgeCompany();
        app(EfakturaInboundService::class)->pollCompany($company);
        $receipt = EfakturaInboundReceipt::query()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/inbox/{$receipt->id}/dismiss")
            ->assertNoContent();

        $receipt->refresh();
        $this->assertSame('dismissed', $receipt->inbox_status);
        $this->assertNull($receipt->ubl_encrypted);
    }

    #[Test]
    public function other_users_and_other_companies_cannot_touch_the_inbox(): void
    {
        $this->localFirst();
        $this->fakeCpds($this->sampleUbl());
        [$user, $company] = $this->proUserWithBridgeCompany();
        [$intruder, $otherCompany] = $this->proUserWithBridgeCompany();
        app(EfakturaInboundService::class)->pollCompany($company);
        $receipt = EfakturaInboundReceipt::query()->firstOrFail();

        $this->actingAs($intruder)
            ->getJson("/api/invoicing/companies/{$company->id}/efaktura/inbox")
            ->assertStatus(403);

        // Own company, foreign receipt - must not leak across companies.
        $this->actingAs($intruder)
            ->getJson("/api/invoicing/companies/{$otherCompany->id}/efaktura/inbox/{$receipt->id}")
            ->assertStatus(404);
        $this->actingAs($intruder)
            ->postJson("/api/invoicing/companies/{$otherCompany->id}/efaktura/inbox/{$receipt->id}/imported")
            ->assertStatus(404);

        $this->assertSame('pending', $receipt->fresh()->inbox_status);
        $this->assertTrue($user->is($company->user));
    }

    #[Test]
    public function stale_pending_items_are_purged_by_the_command(): void
    {
        $this->localFirst();
        $this->fakeCpds($this->sampleUbl());
        [, $company] = $this->proUserWithBridgeCompany();
        app(EfakturaInboundService::class)->pollCompany($company);
        $receipt = EfakturaInboundReceipt::query()->firstOrFail();

        $this->artisan('efaktura:purge-inbound-inbox')->expectsOutputToContain('Purged 0');
        $this->assertSame('pending', $receipt->fresh()->inbox_status);

        EfakturaInboundReceipt::query()->whereKey($receipt->id)->update(['created_at' => now()->subDays(61)]);

        $this->artisan('efaktura:purge-inbound-inbox')->expectsOutputToContain('Purged 1');
        $receipt->refresh();
        $this->assertSame('dismissed', $receipt->inbox_status);
        $this->assertNull($receipt->ubl_encrypted);
        $this->assertNull($receipt->draft_json);
    }
}
