<?php

namespace Tests\Feature;

use App\Enums\RegWatchChangeStatus;
use App\Enums\RegWatchSourceType;
use App\Models\RegWatchChange;
use App\Models\RegWatchJurisdiction;
use App\Models\RegWatchSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegWatchAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeChange(array $attributes = []): RegWatchChange
    {
        $jurisdiction = RegWatchJurisdiction::firstOrCreate(
            ['code' => 'SK'],
            ['name' => 'Slovakia', 'active' => true],
        );
        $source = RegWatchSource::firstOrCreate(
            ['slug' => 'sk-test'],
            [
                'jurisdiction_id' => $jurisdiction->id,
                'name' => 'Test source',
                'url' => 'https://source.test/',
                'type' => RegWatchSourceType::TaxAuthority,
                'active' => true,
            ],
        );

        return RegWatchChange::create(array_merge([
            'source_id' => $source->id,
            'status' => RegWatchChangeStatus::New,
            'diff' => "- old\n+ new",
            'detected_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function non_admins_are_forbidden(): void
    {
        $change = $this->makeChange();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/admin/regwatch/changes')->assertForbidden();
        $this->actingAs($user)->getJson("/api/admin/regwatch/changes/{$change->id}")->assertForbidden();
        $this->actingAs($user)
            ->putJson("/api/admin/regwatch/changes/{$change->id}/status", ['status' => 'reviewed'])
            ->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/regwatch/sources')->assertForbidden();
    }

    #[Test]
    public function changes_index_lists_with_status_filter_and_meta(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeChange();
        $this->makeChange(['status' => RegWatchChangeStatus::Dismissed]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/regwatch/changes?status=new')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('new', $response->json('data.0.status'));
        $this->assertSame('sk-test', $response->json('data.0.source.slug'));
        // The list stays lightweight - the diff is detail-only.
        $this->assertArrayNotHasKey('diff', $response->json('data.0'));
    }

    #[Test]
    public function change_detail_carries_diff_and_allowed_transitions(): void
    {
        $admin = User::factory()->admin()->create();
        $change = $this->makeChange();

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/regwatch/changes/{$change->id}")
            ->assertOk();

        $this->assertSame("- old\n+ new", $response->json('data.diff'));
        $this->assertEqualsCanonicalizing(['reviewed', 'dismissed'], $response->json('data.allowed_transitions'));
    }

    #[Test]
    public function reviewing_stamps_reviewer_and_time(): void
    {
        $admin = User::factory()->admin()->create();
        $change = $this->makeChange();

        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/changes/{$change->id}/status", ['status' => 'reviewed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewed');

        $change->refresh();
        $this->assertSame(RegWatchChangeStatus::Reviewed, $change->status);
        $this->assertNotNull($change->reviewed_at);
        $this->assertSame($admin->id, $change->reviewed_by);
    }

    #[Test]
    public function reviewed_change_can_be_applied_and_keeps_first_reviewer(): void
    {
        $admin = User::factory()->admin()->create();
        $firstReviewer = User::factory()->admin()->create();
        // DB timestamp columns drop sub-second precision - compare at seconds.
        $reviewedAt = now()->subHour()->startOfSecond();
        $change = $this->makeChange([
            'status' => RegWatchChangeStatus::Reviewed,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $firstReviewer->id,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/changes/{$change->id}/status", ['status' => 'applied'])
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $change->refresh();
        $this->assertSame($firstReviewer->id, $change->reviewed_by);
        $this->assertTrue($change->reviewed_at->equalTo($reviewedAt));
    }

    #[Test]
    public function invalid_transitions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        // new -> applied skips review.
        $change = $this->makeChange();
        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/changes/{$change->id}/status", ['status' => 'applied'])
            ->assertStatus(422);
        $this->assertSame(RegWatchChangeStatus::New, $change->refresh()->status);

        // applied is terminal.
        $applied = $this->makeChange(['status' => RegWatchChangeStatus::Applied]);
        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/changes/{$applied->id}/status", ['status' => 'dismissed'])
            ->assertStatus(422);

        // Unknown status fails validation.
        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/changes/{$change->id}/status", ['status' => 'nonsense'])
            ->assertStatus(422);
    }

    #[Test]
    public function noise_can_be_dismissed_straight_from_new(): void
    {
        $admin = User::factory()->admin()->create();
        $change = $this->makeChange();

        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/changes/{$change->id}/status", ['status' => 'dismissed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed');
    }

    #[Test]
    public function sources_endpoint_reports_new_change_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeChange();
        $this->makeChange(['status' => RegWatchChangeStatus::Dismissed]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/regwatch/sources')
            ->assertOk();

        $this->assertSame('sk-test', $response->json('data.0.slug'));
        $this->assertSame('SK', $response->json('data.0.jurisdiction_code'));
        $this->assertSame(1, $response->json('data.0.new_changes_count'));
    }
}
