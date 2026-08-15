<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\DocumentNumberReservation;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    protected User $proUser;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->proUser = User::factory()->create();
        Subscription::create([
            'user_id' => $this->proUser->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->company = Company::create([
            'user_id' => $this->proUser->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
    }

    protected function reserve(array $payload): TestResponse
    {
        return $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/reserve",
            $payload,
        );
    }

    #[Test]
    public function it_reserves_sequential_numbers_for_distinct_requests(): void
    {
        $first = $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-aaaa-0001',
        ])->assertOk()->json('data');

        $second = $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-aaaa-0002',
        ])->assertOk()->json('data');

        $this->assertSame('reserved', $first['status']);
        $this->assertSame($first['counter'] + 1, $second['counter']);
        $this->assertNotSame($first['number'], $second['number']);
    }

    #[Test]
    public function it_returns_the_same_number_for_a_retried_issue_request(): void
    {
        $payload = [
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-bbbb-0001',
        ];

        $first = $this->reserve($payload)->assertOk()->json('data');
        $retry = $this->reserve($payload)->assertOk()->json('data');

        $this->assertSame($first['number'], $retry['number']);
        $this->assertSame($first['counter'], $retry['counter']);
        $this->assertSame(1, DocumentNumberReservation::query()->count());
    }

    #[Test]
    public function it_does_not_reissue_counters_below_existing_reservations(): void
    {
        // First reservation advances the series; the series counter re-sync
        // from (nonexistent) server documents must not let the second
        // reservation reuse the same counter.
        $first = $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-cccc-0001',
        ])->assertOk()->json('data');

        $second = $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-cccc-0002',
        ])->assertOk()->json('data');

        $this->assertGreaterThan($first['counter'], $second['counter']);
    }

    #[Test]
    public function it_confirms_a_reservation_with_an_opaque_snapshot_hash(): void
    {
        $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-dddd-0001',
        ])->assertOk();

        $confirmed = $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/confirm",
            [
                'document_type' => 'invoice',
                'issue_request_id' => 'draft-dddd-0001',
                'snapshot_hash' => str_repeat('ab', 32),
                'snapshot_format_version' => '1',
            ],
        )->assertOk()->json('data');

        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame(
            str_repeat('ab', 32),
            DocumentNumberReservation::query()->sole()->confirmed_hash,
        );
    }

    #[Test]
    public function voiding_leaves_a_gap_and_never_recycles_the_number(): void
    {
        $first = $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-eeee-0001',
        ])->assertOk()->json('data');

        $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/void",
            ['document_type' => 'invoice', 'issue_request_id' => 'draft-eeee-0001'],
        )->assertOk()->assertJsonPath('data.status', 'voided');

        $next = $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-eeee-0002',
        ])->assertOk()->json('data');

        $this->assertSame($first['counter'] + 1, $next['counter']);
    }

    #[Test]
    public function a_confirmed_reservation_cannot_be_voided_and_vice_versa(): void
    {
        $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-ffff-0001',
        ])->assertOk();

        $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/confirm",
            ['document_type' => 'invoice', 'issue_request_id' => 'draft-ffff-0001'],
        )->assertOk();

        $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/void",
            ['document_type' => 'invoice', 'issue_request_id' => 'draft-ffff-0001'],
        )->assertStatus(422);

        $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-ffff-0002',
        ])->assertOk();

        $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/void",
            ['document_type' => 'invoice', 'issue_request_id' => 'draft-ffff-0002'],
        )->assertOk();

        $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/confirm",
            ['document_type' => 'invoice', 'issue_request_id' => 'draft-ffff-0002'],
        )->assertStatus(422);
    }

    #[Test]
    public function status_reports_the_reservation_for_recovery(): void
    {
        $this->actingAs($this->proUser)->getJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/status"
            .'?document_type=invoice&issue_request_id=draft-gggg-0001',
        )->assertOk()->assertJsonPath('data', null);

        $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-gggg-0001',
        ])->assertOk();

        $this->actingAs($this->proUser)->getJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/status"
            .'?document_type=invoice&issue_request_id=draft-gggg-0001',
        )->assertOk()->assertJsonPath('data.status', 'reserved');
    }

    #[Test]
    public function it_rejects_access_to_a_foreign_company(): void
    {
        $intruder = User::factory()->create();
        Subscription::create([
            'user_id' => $intruder->id,
            'plan_id' => SubscriptionPlan::query()->sole()->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($intruder)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-allocator/reserve",
            ['document_type' => 'invoice', 'issue_request_id' => 'draft-hhhh-0001'],
        )->assertForbidden();
    }

    #[Test]
    public function it_respects_the_local_high_counter_floor(): void
    {
        $reserved = $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-iiii-0001',
            'local_high_counter' => 70,
        ])->assertOk()->json('data');

        $this->assertSame(71, $reserved['counter']);
    }

    #[Test]
    public function a_sequence_with_reservations_cannot_be_deleted(): void
    {
        $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'draft-jjjj-0001',
        ])->assertOk();

        $sequenceId = DocumentNumberReservation::query()->sole()->company_document_sequence_id;

        $this->actingAs($this->proUser)->postJson(
            "/api/invoicing/companies/{$this->company->id}/number-series",
            ['document_type' => 'invoice', 'name' => 'Second', 'format' => 'YYYYNNNN', 'reset_period' => 'yearly'],
        )->assertSuccessful();

        $this->actingAs($this->proUser)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/number-series/{$sequenceId}")
            ->assertStatus(422);

        $this->assertSame(1, DocumentNumberReservation::query()->count());
    }

    #[Test]
    public function it_validates_the_issue_request_id_shape(): void
    {
        $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'short',
        ])->assertStatus(422);

        $this->reserve([
            'document_type' => 'invoice',
            'issue_request_id' => 'bad id with spaces',
        ])->assertStatus(422);
    }
}
