<?php

namespace Tests\Feature;

use App\Models\EfakturaCpdsProvider;
use App\Models\User;
use App\Services\Invoicing\Efaktura\SapiSkClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminEfakturaCpdsProviderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function non_admins_are_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/admin/efaktura/cpds-providers')->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/admin/efaktura/cpds-providers', ['name' => 'X', 'base_url' => 'https://x.test'])
            ->assertForbidden();
    }

    #[Test]
    public function admin_manages_presets_end_to_end(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->actingAs($admin)
            ->postJson('/api/admin/efaktura/cpds-providers', [
                'name' => 'Postman One',
                'base_url' => 'https://postman-one.test/',
                'send_detail_path' => '/sapi/v1/document/send/{id}',
                'sort_order' => 1,
            ])
            ->assertCreated();

        $id = $created->json('data.id');
        // Trailing slash is normalized away.
        $this->assertSame('https://postman-one.test', $created->json('data.base_url'));

        $this->actingAs($admin)
            ->putJson("/api/admin/efaktura/cpds-providers/{$id}", [
                'name' => 'Postman One (renamed)',
                'base_url' => 'https://postman-one.test',
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->actingAs($admin)->getJson('/api/admin/efaktura/cpds-providers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Postman One (renamed)');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/efaktura/cpds-providers/{$id}")
            ->assertOk();
        $this->assertDatabaseCount('efaktura_cpds_providers', 0);
    }

    #[Test]
    public function validation_rejects_http_urls_and_detail_paths_without_the_placeholder(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/efaktura/cpds-providers', [
                'name' => 'Insecure',
                'base_url' => 'http://insecure.test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);

        $this->actingAs($admin)
            ->postJson('/api/admin/efaktura/cpds-providers', [
                'name' => 'No placeholder',
                'base_url' => 'https://ok.test',
                'send_detail_path' => '/sapi/v1/document/send/detail',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['send_detail_path']);
    }

    #[Test]
    public function a_host_can_belong_to_only_one_active_preset(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/efaktura/cpds-providers', [
                'name' => 'First',
                'base_url' => 'https://shared.test',
            ])
            ->assertCreated();

        // Same host again (even with a different path/casing) is rejected.
        $this->actingAs($admin)
            ->postJson('/api/admin/efaktura/cpds-providers', [
                'name' => 'Second',
                'base_url' => 'https://SHARED.test/api',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);

        // An inactive duplicate is fine - only ACTIVE hosts must be unique.
        $inactive = $this->actingAs($admin)
            ->postJson('/api/admin/efaktura/cpds-providers', [
                'name' => 'Second (inactive)',
                'base_url' => 'https://shared.test',
                'active' => false,
            ])
            ->assertCreated();

        // Re-activating it collides with the first preset again.
        $this->actingAs($admin)
            ->putJson('/api/admin/efaktura/cpds-providers/'.$inactive->json('data.id'), [
                'name' => 'Second (inactive)',
                'base_url' => 'https://shared.test',
                'active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);
    }

    #[Test]
    public function detail_path_resolution_is_deterministic_for_legacy_duplicate_hosts(): void
    {
        // Rows created directly (bypassing the controller guard) simulate
        // pre-guard data: resolution must follow sort_order, not insertion.
        EfakturaCpdsProvider::create([
            'name' => 'Later',
            'base_url' => 'https://dup.test',
            'send_detail_path' => '/sapi/v1/later/{id}',
            'sort_order' => 5,
        ]);
        EfakturaCpdsProvider::create([
            'name' => 'Winner',
            'base_url' => 'https://dup.test',
            'send_detail_path' => '/sapi/v1/winner/{id}',
            'sort_order' => 1,
        ]);

        $this->assertSame(
            '/sapi/v1/winner/{id}',
            EfakturaCpdsProvider::detailPathForBaseUrl('https://dup.test'),
        );
    }

    #[Test]
    public function active_presets_appear_in_public_config_only_when_enabled(): void
    {
        EfakturaCpdsProvider::create(['name' => 'B postman', 'base_url' => 'https://b.test', 'sort_order' => 2]);
        EfakturaCpdsProvider::create(['name' => 'A postman', 'base_url' => 'https://a.test', 'sort_order' => 1]);
        EfakturaCpdsProvider::create(['name' => 'Hidden', 'base_url' => 'https://hidden.test', 'active' => false]);

        config(['efaktura.enabled' => false]);
        $this->getJson('/api/config')->assertOk()->assertJsonPath('efaktura_cpds_presets', []);

        config(['efaktura.enabled' => true]);
        $presets = $this->getJson('/api/config')->assertOk()->json('efaktura_cpds_presets');

        $this->assertSame(['A postman', 'B postman'], array_column($presets, 'name'));
        $this->assertSame(['https://a.test', 'https://b.test'], array_column($presets, 'base_url'));
    }

    #[Test]
    public function preset_hosts_are_trusted_by_the_sapi_ssrf_guard(): void
    {
        // preset.test resolves nowhere - without the preset the DNS guard
        // would reject it; an active preset marks it operator-verified.
        config(['efaktura.enabled' => true, 'efaktura.allowed_sapi_hosts' => []]);
        Http::fake([
            'https://preset.test/sapi/v1/auth/token' => Http::response(['access_token' => 'tok']),
        ]);

        $client = app(SapiSkClient::class);

        try {
            $client->authenticate('id', 'secret', 'https://preset.test');
            $this->fail('Expected the unresolvable host to be rejected without a preset.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be resolved', $e->getMessage());
        }

        EfakturaCpdsProvider::create(['name' => 'Preset', 'base_url' => 'https://preset.test']);

        $payload = $client->authenticate('id', 'secret', 'https://preset.test');
        $this->assertSame('tok', $payload['access_token']);
    }
}
