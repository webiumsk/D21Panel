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

class CompanyMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $accountant;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = SubscriptionPlan::create([
            'code' => 'pro', 'name' => 'pro', 'display_name' => 'Pro', 'price_eur' => 99,
            'billing_period' => 'year', 'max_stores' => 3, 'max_api_keys' => 3, 'max_ln_addresses' => null,
            'features' => ['business_invoicing'], 'is_active' => true,
        ]);

        $this->owner = User::factory()->create();
        Subscription::create([
            'user_id' => $this->owner->id, 'plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now(), 'expires_at' => now()->addYear(),
        ]);

        $this->accountant = User::factory()->create();

        $this->company = Company::create([
            'user_id' => $this->owner->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
    }

    private function addMember(User $user, CompanyMemberRole $role = CompanyMemberRole::Accountant, bool $revoked = false): CompanyMember
    {
        return CompanyMember::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role' => $role,
            'invited_by' => $this->owner->id,
            'accepted_at' => now(),
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    #[Test]
    public function owner_lists_active_members_excluding_revoked(): void
    {
        $this->addMember($this->accountant);
        $revoked = User::factory()->create();
        $this->addMember($revoked, CompanyMemberRole::Member, revoked: true);

        $this->actingAs($this->owner)
            ->getJson("/api/invoicing/companies/{$this->company->id}/members")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $this->accountant->email)
            ->assertJsonPath('data.0.role', 'accountant');
    }

    #[Test]
    public function owner_revokes_a_member_who_then_loses_access(): void
    {
        $member = $this->addMember($this->accountant);

        // Before: the member can reach a company route.
        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertOk();

        $this->actingAs($this->owner)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/members/{$member->id}")
            ->assertOk()
            ->assertJson(['revoked' => true]);

        $this->assertNotNull($member->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.member_revoked']);

        // After: the former member is locked out server-side.
        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$this->company->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function a_non_owner_member_cannot_list_or_revoke(): void
    {
        $member = $this->addMember($this->accountant);

        $this->actingAs($this->accountant)
            ->getJson("/api/invoicing/companies/{$this->company->id}/members")
            ->assertStatus(403);

        $this->actingAs($this->accountant)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/members/{$member->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function a_member_of_another_company_cannot_be_revoked_here(): void
    {
        $otherCompany = Company::create([
            'user_id' => $this->owner->id, 'legal_name' => 'Beta s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk, 'default_currency' => 'EUR', 'vat_payer' => false,
        ]);
        $foreign = CompanyMember::create([
            'company_id' => $otherCompany->id, 'user_id' => $this->accountant->id,
            'role' => CompanyMemberRole::Accountant, 'invited_by' => $this->owner->id, 'accepted_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/members/{$foreign->id}")
            ->assertStatus(404);
        $this->assertNull($foreign->fresh()->revoked_at);
    }

    #[Test]
    public function revoking_is_idempotent(): void
    {
        $member = $this->addMember($this->accountant, revoked: true);

        $this->actingAs($this->owner)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/members/{$member->id}")
            ->assertOk()
            ->assertJson(['revoked' => true]);
    }

    #[Test]
    public function a_first_time_revocation_emits_exactly_one_audit_event(): void
    {
        $member = $this->addMember($this->accountant);

        // Two first-time revokes (a duplicate/replayed DELETE): the locked
        // re-check must keep it to a single membership write and audit row.
        $this->actingAs($this->owner)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/members/{$member->id}")
            ->assertOk();
        $this->actingAs($this->owner)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/members/{$member->id}")
            ->assertOk();

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.member_revoked', 'target_id' => $this->company->id]);
    }

    #[Test]
    public function the_owner_role_row_is_rejected_at_the_model_and_the_endpoint(): void
    {
        // The model guard blocks an active Owner membership on any write path.
        $this->expectException(\LogicException::class);
        CompanyMember::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'role' => CompanyMemberRole::Owner,
            'invited_by' => $this->owner->id,
            'accepted_at' => now(),
        ]);
    }

    #[Test]
    public function destroy_refuses_an_owner_role_row(): void
    {
        // A revoked Owner row is the only way one can persist (guard allows it);
        // destroy must still refuse to act on an Owner-role row.
        $owner = $this->addMember($this->accountant);
        // Force the role to Owner + revoked directly, bypassing the active guard.
        CompanyMember::withoutEvents(fn () => $owner->forceFill([
            'role' => CompanyMemberRole::Owner->value,
            'revoked_at' => now(),
        ])->save());

        $this->actingAs($this->owner)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/members/{$owner->id}")
            ->assertStatus(403);
    }
}
