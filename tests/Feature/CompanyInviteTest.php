<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Enums\CompanyMemberRole;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\CompanyMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyInviteTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $invitee;

    protected Company $company;

    private const INVITEE_PK = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

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

        // Invitee has a recovery key but no plan of their own.
        $this->invitee = User::factory()->create(['guest_recovery_public_key' => self::INVITEE_PK]);

        $this->company = Company::create([
            'user_id' => $this->owner->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
    }

    private function sealedBody(array $override = []): array
    {
        return array_merge([
            'role' => 'accountant',
            'mode' => 'sealed',
            'invited_email' => $this->invitee->email,
            'invitee_public_key' => self::INVITEE_PK,
            'sealed_secret' => ['v' => 1, 'epkB64' => 'ZXBr', 'ivB64' => 'aXY=', 'ctB64' => 'Y3Q='],
        ], $override);
    }

    #[Test]
    public function owner_creates_a_sealed_invite_and_gets_the_token_once(): void
    {
        $res = $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody())
            ->assertCreated();

        $token = $res->json('token');
        $this->assertStringStartsWith('cinv_', $token);
        $this->assertDatabaseHas('company_invites', [
            'company_id' => $this->company->id,
            'role' => 'accountant',
            'mode' => 'sealed',
            'invitee_public_key' => self::INVITEE_PK,
            'token_hash' => hash('sha256', $token),
        ]);
    }

    #[Test]
    public function sealed_invite_refuses_a_key_that_does_not_match_the_recipients_current_key(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody([
                'invitee_public_key' => str_repeat('f', 64),
            ]))
            ->assertStatus(422)
            ->assertJson(['message' => 'recovery_key_mismatch']);
    }

    #[Test]
    public function sealed_invite_refuses_a_recipient_without_a_recovery_key(): void
    {
        $noKey = User::factory()->create(['guest_recovery_public_key' => null]);
        $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody([
                'invited_email' => $noKey->email,
            ]))
            ->assertStatus(422)
            ->assertJson(['message' => 'recipient_no_recovery_key']);
    }

    #[Test]
    public function a_non_owner_member_cannot_create_invites(): void
    {
        CompanyMember::create([
            'company_id' => $this->company->id, 'user_id' => $this->invitee->id,
            'role' => CompanyMemberRole::Accountant, 'invited_by' => $this->owner->id, 'accepted_at' => now(),
        ]);

        $this->actingAs($this->invitee)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody())
            ->assertStatus(403);
    }

    #[Test]
    public function the_targeted_recipient_previews_the_sealed_blob_but_others_cannot(): void
    {
        $token = $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody())
            ->json('token');

        $this->actingAs($this->invitee)
            ->getJson("/api/invoicing/invites/{$token}")
            ->assertOk()
            ->assertJsonPath('sealed_secret.epkB64', 'ZXBr')
            ->assertJsonPath('role', 'accountant')
            ->assertJsonPath('company_id', $this->company->id);

        $stranger = User::factory()->create(['guest_recovery_public_key' => str_repeat('b', 64)]);
        $this->actingAs($stranger)
            ->getJson("/api/invoicing/invites/{$token}")
            ->assertStatus(403);
    }

    #[Test]
    public function accepting_creates_membership_and_consumes_the_invite(): void
    {
        $token = $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody())
            ->json('token');

        $this->actingAs($this->invitee)
            ->postJson("/api/invoicing/invites/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('company_id', $this->company->id)
            ->assertJsonPath('role', 'accountant');

        $this->assertDatabaseHas('company_members', [
            'company_id' => $this->company->id,
            'user_id' => $this->invitee->id,
            'role' => 'accountant',
        ]);
        $this->assertTrue($this->company->fresh()->isAccessibleBy($this->invitee->fresh()));

        // One-time: a second accept no longer finds a pending invite.
        $this->actingAs($this->invitee)
            ->postJson("/api/invoicing/invites/{$token}/accept")
            ->assertStatus(404);
    }

    #[Test]
    public function a_link_invite_carries_no_server_secret_and_any_user_can_accept(): void
    {
        $token = $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", [
                'role' => 'member', 'mode' => 'link',
            ])
            ->assertCreated()
            ->json('token');

        $this->assertDatabaseHas('company_invites', ['mode' => 'link', 'sealed_secret_json' => null]);

        $anyUser = User::factory()->create();
        $this->actingAs($anyUser)
            ->getJson("/api/invoicing/invites/{$token}")
            ->assertOk()
            ->assertJsonPath('sealed_secret', null);

        $this->actingAs($anyUser)
            ->postJson("/api/invoicing/invites/{$token}/accept")
            ->assertOk();
        $this->assertDatabaseHas('company_members', ['company_id' => $this->company->id, 'user_id' => $anyUser->id, 'role' => 'member']);
    }

    #[Test]
    public function revoked_and_expired_invites_cannot_be_accepted(): void
    {
        $token = $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody())
            ->json('token');
        $invite = CompanyInvite::where('token_hash', hash('sha256', $token))->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/invites/{$invite->id}")
            ->assertOk();
        $this->actingAs($this->invitee)
            ->postJson("/api/invoicing/invites/{$token}/accept")
            ->assertStatus(404);

        // Expiry also closes the window.
        $token2 = $this->actingAs($this->owner)
            ->postJson("/api/invoicing/companies/{$this->company->id}/invites", $this->sealedBody())
            ->json('token');
        CompanyInvite::where('token_hash', hash('sha256', $token2))->update(['expires_at' => now()->subDay()]);
        $this->actingAs($this->invitee)
            ->getJson("/api/invoicing/invites/{$token2}")
            ->assertStatus(404);
    }

    #[Test]
    public function recipient_lookup_reports_the_key_and_membership_state(): void
    {
        $this->actingAs($this->owner)
            ->getJson("/api/invoicing/companies/{$this->company->id}/invite-recipient?email={$this->invitee->email}")
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('public_key', self::INVITEE_PK)
            ->assertJsonPath('has_recovery', true)
            ->assertJsonPath('already_member', false);

        $this->actingAs($this->owner)
            ->getJson("/api/invoicing/companies/{$this->company->id}/invite-recipient?email=nobody@example.com")
            ->assertOk()
            ->assertJsonPath('found', false);
    }
}
