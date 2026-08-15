<?php

namespace Tests\Feature;

use App\Enums\RegWatchSourceType;
use App\Models\RegWatchJurisdiction;
use App\Models\RegWatchRule;
use App\Models\RegWatchSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegWatchRulesAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeJurisdiction(string $code = 'SK', string $name = 'Slovakia'): RegWatchJurisdiction
    {
        return RegWatchJurisdiction::firstOrCreate(['code' => $code], ['name' => $name, 'active' => true]);
    }

    private function makeSource(RegWatchJurisdiction $jurisdiction, string $slug = 'sk-test'): RegWatchSource
    {
        return RegWatchSource::firstOrCreate(['slug' => $slug], [
            'jurisdiction_id' => $jurisdiction->id,
            'name' => 'Test source '.$slug,
            'url' => "https://{$slug}.test/",
            'type' => RegWatchSourceType::TaxAuthority,
            'active' => true,
        ]);
    }

    private function makeRule(array $attributes = []): RegWatchRule
    {
        $jurisdiction = $this->makeJurisdiction();
        $source = $this->makeSource($jurisdiction);

        return RegWatchRule::create(array_merge([
            'jurisdiction_id' => $jurisdiction->id,
            'source_id' => $source->id,
            'slug' => 'sk-vat-registration',
            'topic' => 'vat_registration',
            'title' => 'DPH - registračná povinnosť a prahy',
            'rule_text' => 'TODO: overiť z oficiálneho zdroja',
            'source_url' => $source->url,
        ], $attributes));
    }

    #[Test]
    public function non_admins_are_forbidden(): void
    {
        $rule = $this->makeRule();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/admin/regwatch/rules')->assertForbidden();
        $this->actingAs($user)->getJson("/api/admin/regwatch/rules/{$rule->id}")->assertForbidden();
        $this->actingAs($user)
            ->putJson("/api/admin/regwatch/rules/{$rule->id}", [])
            ->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/regwatch/jurisdictions')->assertForbidden();
    }

    #[Test]
    public function rules_index_filters_by_verified_state(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeRule();
        $this->makeRule([
            'slug' => 'sk-oss',
            'topic' => 'oss',
            'verified_on' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/regwatch/rules?verified=0')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('sk-vat-registration', $response->json('data.0.slug'));
        $this->assertNull($response->json('data.0.verified_on'));
        // rule_text is detail-only.
        $this->assertArrayNotHasKey('rule_text', $response->json('data.0'));

        $verified = $this->actingAs($admin)->getJson('/api/admin/regwatch/rules?verified=1')->assertOk();
        $this->assertCount(1, $verified->json('data'));
        $this->assertSame('sk-oss', $verified->json('data.0.slug'));
    }

    #[Test]
    public function rule_detail_carries_text_jurisdiction_and_source(): void
    {
        $admin = User::factory()->admin()->create();
        $rule = $this->makeRule();

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/regwatch/rules/{$rule->id}")
            ->assertOk();

        $this->assertSame('TODO: overiť z oficiálneho zdroja', $response->json('data.rule_text'));
        $this->assertSame('SK', $response->json('data.jurisdiction.code'));
        $this->assertSame('sk-test', $response->json('data.source.slug'));
    }

    #[Test]
    public function admin_can_update_a_rule_and_stamp_verification(): void
    {
        $admin = User::factory()->admin()->create();
        $rule = $this->makeRule();

        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/rules/{$rule->id}", [
                'title' => 'DPH - registračná povinnosť a prahy',
                'rule_text' => 'Overené znenie pravidla doplnené človekom.',
                'source_url' => 'https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-pridanej-hodnoty/registracna-povinnost-pre-dph',
                'source_id' => $rule->source_id,
                'verified_on' => now()->toDateString(),
                'effective_from' => '2026-01-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.verified_on', now()->toDateString());

        $rule->refresh();
        $this->assertSame('Overené znenie pravidla doplnené človekom.', $rule->rule_text);
        $this->assertNotNull($rule->verified_on);
        $this->assertSame('2026-01-01', $rule->effective_from->toDateString());

        // The write route is audit-logged.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.regwatch.rule_updated',
            'user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function update_validation_rejects_bad_input(): void
    {
        $admin = User::factory()->admin()->create();
        $rule = $this->makeRule();
        $base = [
            'title' => 'Title',
            'rule_text' => 'Text',
            'source_url' => 'https://example.test/',
        ];

        // Future verification date is nonsense.
        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/rules/{$rule->id}", $base + ['verified_on' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['verified_on']);

        // Invalid URL.
        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/rules/{$rule->id}", array_merge($base, ['source_url' => 'not-a-url']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source_url']);

        // A rule whose text is still the seeded placeholder must not accept
        // a verification stamp (LEGAL.md: verified_on = human-verified text).
        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/rules/{$rule->id}", array_merge($base, [
                'rule_text' => RegWatchRule::PLACEHOLDER_RULE_TEXT,
                'verified_on' => now()->toDateString(),
            ]))
            ->assertStatus(422);
        $this->assertNull($rule->refresh()->verified_on);

        $this->assertSame('TODO: overiť z oficiálneho zdroja', $rule->refresh()->rule_text);
    }

    #[Test]
    public function update_rejects_a_source_from_another_jurisdiction(): void
    {
        $admin = User::factory()->admin()->create();
        $rule = $this->makeRule();
        $cz = $this->makeJurisdiction('CZ', 'Czechia');
        $czSource = $this->makeSource($cz, 'cz-test');

        $this->actingAs($admin)
            ->putJson("/api/admin/regwatch/rules/{$rule->id}", [
                'title' => 'Title',
                'rule_text' => 'Text',
                'source_url' => 'https://example.test/',
                'source_id' => $czSource->id,
            ])
            ->assertStatus(422);

        $this->assertNotSame($czSource->id, $rule->refresh()->source_id);
    }

    #[Test]
    public function jurisdictions_endpoint_lists_codes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeJurisdiction();
        $this->makeJurisdiction('CZ', 'Czechia');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/regwatch/jurisdictions')
            ->assertOk();

        $this->assertSame(['CZ', 'SK'], array_column($response->json('data'), 'code'));
    }
}
