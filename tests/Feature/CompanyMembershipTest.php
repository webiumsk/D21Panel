<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Enums\CompanyMemberRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $accountant;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = SubscriptionPlan::create([
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

        $this->owner = User::factory()->create();
        Subscription::create([
            'user_id' => $this->owner->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        // The accountant deliberately has NO plan of their own - members work
        // under the owner's entitlement.
        $this->accountant = User::factory()->create();

        $this->company = Company::create([
            'user_id' => $this->owner->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
    }

    private function addMember(User $user, CompanyMemberRole $role = CompanyMemberRole::Accountant, bool $accepted = true): CompanyMember
    {
        return CompanyMember::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role' => $role,
            'invited_by' => $this->owner->id,
            'accepted_at' => $accepted ? now() : null,
        ]);
    }

    #[Test]
    public function outsiders_and_pending_or_revoked_members_are_rejected(): void
    {
        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertForbidden();

        $member = $this->addMember($this->accountant, accepted: false);
        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertForbidden();

        $member->update(['accepted_at' => now(), 'revoked_at' => now()]);
        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertForbidden();

        $this->assertNull($this->company->fresh()->roleFor($this->accountant));
    }

    #[Test]
    public function an_active_member_reads_the_company_under_the_owners_plan(): void
    {
        $this->addMember($this->accountant);

        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->company->id)
            ->assertJsonPath('data.role', 'accountant');

        $this->actingAs($this->owner)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');
    }

    #[Test]
    public function the_company_index_lists_shared_companies_with_their_role(): void
    {
        $this->addMember($this->accountant);
        $ownCompany = Company::create([
            'user_id' => $this->accountant->id,
            'legal_name' => 'Uctovnik s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);

        $rows = $this->actingAs($this->accountant)
            ->getJson('/api/invoicing/companies')
            ->assertOk()
            ->json('data');

        $byId = collect($rows)->keyBy('id');
        $this->assertSame('accountant', $byId[$this->company->id]['role'] ?? null);
        $this->assertSame('owner', $byId[$ownCompany->id]['role'] ?? null);

        // The owner's own index does not gain the accountant's company.
        $ownerRows = $this->actingAs($this->owner)->getJson('/api/invoicing/companies')->json('data');
        $this->assertSame([$this->company->id], array_column($ownerRows, 'id'));
    }

    #[Test]
    public function members_reserve_numbers_in_the_same_sequence_as_the_owner(): void
    {
        $this->addMember($this->accountant);
        $url = "/api/invoicing/companies/{$this->company->id}/number-allocator/reserve";

        $first = $this->actingAs($this->owner)
            ->postJson($url, ['document_type' => 'invoice', 'issue_request_id' => 'draft-owner-0001'])
            ->assertOk()
            ->json('data');
        $second = $this->actingAs($this->accountant)
            ->postJson($url, ['document_type' => 'invoice', 'issue_request_id' => 'draft-acct-0001'])
            ->assertOk()
            ->json('data');
        $third = $this->actingAs($this->owner)
            ->postJson($url, ['document_type' => 'invoice', 'issue_request_id' => 'draft-owner-0002'])
            ->assertOk()
            ->json('data');

        $this->assertSame((int) $first['counter'] + 1, (int) $second['counter']);
        $this->assertSame((int) $second['counter'] + 1, (int) $third['counter']);

        // Idempotent retry by the member returns their own number.
        $retry = $this->actingAs($this->accountant)
            ->postJson($url, ['document_type' => 'invoice', 'issue_request_id' => 'draft-acct-0001'])
            ->assertOk()
            ->json('data');
        $this->assertSame($second['counter'], $retry['counter']);
    }

    #[Test]
    public function destructive_and_credential_routes_stay_with_the_owner(): void
    {
        $this->addMember($this->accountant);
        $id = $this->company->id;

        $this->actingAs($this->accountant)->deleteJson("/api/invoicing/companies/{$id}")->assertForbidden();
        $this->actingAs($this->accountant)->postJson("/api/invoicing/companies/{$id}/reset-data", ['confirm' => 'RESET'])->assertForbidden();
        $this->actingAs($this->accountant)->patchJson("/api/invoicing/companies/{$id}/stores", ['store_ids' => []])->assertForbidden();
        $this->actingAs($this->accountant)->patchJson("/api/invoicing/companies/{$id}/email-settings", [])->assertForbidden();
        $this->actingAs($this->accountant)->postJson("/api/invoicing/companies/{$id}/email-settings/test-smtp", [])->assertForbidden();

        // app-settings carry write-only secrets (Stripe Tax key, SAPI-SK secret).
        $this->actingAs($this->accountant)
            ->patchJson("/api/invoicing/companies/{$id}/app-settings", ['default_constant_symbol' => '0308'])
            ->assertForbidden();

        $this->assertDatabaseHas('companies', ['id' => $id]);

        // Non-destructive writes stay open to the member.
        $this->actingAs($this->accountant)
            ->patchJson("/api/invoicing/companies/{$id}", ['trade_name' => 'Acme'])
            ->assertOk();
    }

    #[Test]
    public function support_keeps_its_bypass_on_owner_only_routes(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/stores", ['store_ids' => []])
            ->assertOk();
    }

    #[Test]
    public function a_member_without_a_plan_is_still_blocked_on_companies_they_do_not_belong_to(): void
    {
        $this->addMember($this->accountant);
        $other = Company::create([
            'user_id' => $this->owner->id,
            'legal_name' => 'Other s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);

        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$other->id}")
            ->assertForbidden();
    }
}
