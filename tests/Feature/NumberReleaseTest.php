<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\CompanyDocumentSequence;
use App\Models\DocumentNumberReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gapless numbering (P3): deleting the last issued invoice releases its
 * reservation so the SAME number is handed out again - the sequence never
 * develops holes. Only the highest number of a series period is releasable.
 */
class NumberReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'enterprise']);
        $this->company = Company::create([
            'user_id' => $this->user->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]);

        CompanyDocumentSequence::create([
            'company_id' => $this->company->id,
            'document_type' => 'invoice',
            'name' => 'FV',
            'format' => 'FVYYYYNNNN',
            'reset_period' => 'yearly',
            'is_default' => true,
            'period_key' => now()->format('Y'),
            'last_number' => 0,
        ]);
    }

    /**
     * @return array{number: string, counter: int}
     */
    protected function reserveAndConfirm(string $issueRequestId): array
    {
        $reserve = $this->actingAs($this->user)
            ->postJson('/api/invoicing/companies/'.$this->company->id.'/number-allocator/reserve', [
                'document_type' => 'invoice',
                'issue_request_id' => $issueRequestId,
            ])->json('data');

        $this->actingAs($this->user)
            ->postJson('/api/invoicing/companies/'.$this->company->id.'/number-allocator/confirm', [
                'document_type' => 'invoice',
                'issue_request_id' => $issueRequestId,
            ]);

        return ['number' => $reserve['number'], 'counter' => $reserve['counter']];
    }

    protected function release(string $number): TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson('/api/invoicing/companies/'.$this->company->id.'/number-allocator/release', [
                'document_type' => 'invoice',
                'number' => $number,
            ]);
    }

    public function test_released_top_number_is_handed_out_again(): void
    {
        $first = $this->reserveAndConfirm('issue-req-0001-aa');
        $this->assertSame('FV'.now()->format('Y').'0001', $first['number']);

        $this->release($first['number'])
            ->assertOk()
            ->assertJsonPath('data.released', true);

        $this->assertSame(0, DocumentNumberReservation::query()->count());

        // The freed number is reissued to the next issue attempt - no gap.
        $second = $this->reserveAndConfirm('issue-req-0002-bb');
        $this->assertSame($first['number'], $second['number']);
    }

    public function test_only_the_highest_number_can_be_released(): void
    {
        $first = $this->reserveAndConfirm('issue-req-0001-aa');
        $second = $this->reserveAndConfirm('issue-req-0002-bb');

        $this->release($first['number'])->assertStatus(422);

        // Chained newest-first releases walk the counter down.
        $this->release($second['number'])->assertOk()->assertJsonPath('data.released', true);
        $this->release($first['number'])->assertOk()->assertJsonPath('data.released', true);

        $third = $this->reserveAndConfirm('issue-req-0003-cc');
        $this->assertSame($first['number'], $third['number']);
    }

    public function test_release_of_unknown_number_is_a_noop(): void
    {
        $this->release('FV20260099')
            ->assertOk()
            ->assertJsonPath('data.released', false)
            ->assertJsonPath('data.reason', 'not_found');
    }

    public function test_release_requires_company_ownership(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/api/invoicing/companies/'.$this->company->id.'/number-allocator/release', [
                'document_type' => 'invoice',
                'number' => 'FV20260001',
            ])->assertForbidden();
    }
}
