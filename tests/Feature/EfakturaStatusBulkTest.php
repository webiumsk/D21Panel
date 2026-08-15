<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentCompliance;
use App\Models\Company;
use App\Models\EphemeralEfakturaSubmission;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EfakturaStatusBulkTest extends TestCase
{
    use RefreshDatabase;

    private function proUserWithCompany(): array
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
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'payer',
        ]);

        return [$user, $company];
    }

    private function document(Company $company, string $number): BusinessDocument
    {
        return BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => 'issued',
            'number' => $number,
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'currency' => 'EUR',
            'issue_date' => now(),
        ]);
    }

    #[Test]
    public function compliance_bulk_returns_the_latest_row_per_own_document(): void
    {
        [$user, $company] = $this->proUserWithCompany();
        $docA = $this->document($company, 'A1');
        $docB = $this->document($company, 'B1');

        // docA carries two rows (unique is per provider) - the endpoint must
        // return the NEWER one, so an oldest-row implementation fails here.
        $older = BusinessDocumentCompliance::create([
            'business_document_id' => $docA->id,
            'provider' => 'ctc',
            'status' => 'failed',
        ]);
        BusinessDocumentCompliance::query()
            ->whereKey($older->id)
            ->update(['updated_at' => now()->subDay()]);
        BusinessDocumentCompliance::create([
            'business_document_id' => $docA->id,
            'provider' => 'peppol',
            'status' => 'submitted',
        ]);
        BusinessDocumentCompliance::create([
            'business_document_id' => $docB->id,
            'provider' => 'peppol',
            'status' => 'approved',
        ]);

        // Another user's document must never leak through the bulk lookup.
        [, $otherCompany] = $this->proUserWithCompany();
        $foreignDoc = $this->document($otherCompany, 'X1');
        BusinessDocumentCompliance::create([
            'business_document_id' => $foreignDoc->id,
            'provider' => 'peppol',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/compliance-bulk", [
                'document_ids' => [$docA->id, $docB->id, $foreignDoc->id],
            ])
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame('submitted', $data[$docA->id]['status']);
        $this->assertSame('approved', $data[$docB->id]['status']);
        $this->assertArrayNotHasKey($foreignDoc->id, $data);
    }

    #[Test]
    public function ephemeral_status_bulk_maps_latest_rows_per_evolu_id_for_the_user_only(): void
    {
        [$user, $company] = $this->proUserWithCompany();
        [$otherUser, $otherCompany] = $this->proUserWithCompany();

        // Two rows for doc-1 (unique is per provider) with controlled ages -
        // latest-per-document selection must pick the newer peppol row.
        $older = EphemeralEfakturaSubmission::create([
            'user_id' => $user->id,
            'bridge_company_id' => $company->id,
            'evolu_document_id' => 'doc-1',
            'provider' => 'ctc',
            'status' => 'failed',
        ]);
        EphemeralEfakturaSubmission::query()
            ->whereKey($older->id)
            ->update(['updated_at' => now()->subDay()]);
        EphemeralEfakturaSubmission::create([
            'user_id' => $user->id,
            'bridge_company_id' => $company->id,
            'evolu_document_id' => 'doc-1',
            'provider' => 'peppol',
            'status' => 'submitted',
        ]);
        EphemeralEfakturaSubmission::create([
            'user_id' => $otherUser->id,
            'bridge_company_id' => $otherCompany->id,
            'evolu_document_id' => 'doc-2',
            'provider' => 'peppol',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/efaktura/status-bulk', [
                'evolu_document_ids' => ['doc-1', 'doc-2', 'doc-3'],
            ])
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame('submitted', $data['doc-1']['status']);
        $this->assertArrayNotHasKey('doc-2', $data);
        $this->assertArrayNotHasKey('doc-3', $data);
    }

    #[Test]
    public function bulk_endpoints_validate_their_id_lists(): void
    {
        [$user, $company] = $this->proUserWithCompany();

        $this->actingAs($user)
            ->postJson("/api/invoicing/companies/{$company->id}/efaktura/compliance-bulk", [
                'document_ids' => ['not-a-uuid'],
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/efaktura/status-bulk', [
                'evolu_document_ids' => [],
            ])
            ->assertStatus(422);
    }
}
