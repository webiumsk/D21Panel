<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_own_profile(): void
    {
        $user = User::factory()->create(['name' => 'Test User', 'email' => 'user@example.com']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => 'user@example.com', 'name' => 'Test User']);
        $response->assertJsonStructure(['id', 'name', 'email']);
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => __('messages.profile_updated')]);
        $response->assertJsonPath('user.name', 'New Name');
        $response->assertJsonPath('user.email', 'old@example.com');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('old@example.com', $user->email);
    }

    public function test_profile_update_cannot_change_email(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'name' => 'Me',
            'email' => 'other@example.com',
        ]);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertSame('me@example.com', $user->email);
    }

    public function test_user_can_set_evolu_relay_url(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'evolu_relay_url' => 'wss://evolu.satflux.io',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.evolu_relay_url', 'wss://evolu.satflux.io');
        $user->refresh();
        $this->assertSame('wss://evolu.satflux.io', $user->evolu_relay_url);
    }

    public function test_evolu_relay_url_must_be_wss(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'evolu_relay_url' => 'https://evolu.satflux.io',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_clear_evolu_relay_url(): void
    {
        $user = User::factory()->create(['evolu_relay_url' => 'wss://evolu.satflux.io']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'evolu_relay_url' => null,
        ]);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertNull($user->evolu_relay_url);
    }

    public function test_user_can_update_password(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('old-password'),
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => __('messages.password_updated')]);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_password_update_requires_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct')]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_guest_can_upgrade_with_email_and_then_login_with_new_credentials(): void
    {
        $guest = User::factory()->create([
            'email' => 'guest-upgrade@satflux.io',
            'password' => bcrypt('old-password'),
            'is_guest' => true,
            'email_verified_at' => now(),
            'btcpay_user_id' => null,
            'btcpay_api_key' => null,
        ]);
        Sanctum::actingAs($guest);

        $upgradeResponse = $this->putJson('/api/user/guest/upgrade', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'upgraded@satflux.io',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]));

        $upgradeResponse->assertStatus(200);
        $upgradeResponse->assertJsonPath('user.email', 'upgraded@satflux.io');
        $upgradeResponse->assertJsonPath('user.is_guest', false);
        $upgradeResponse->assertJsonPath('user.allows_satflux_email_changes', true);

        $guest->refresh();
        $this->assertSame('upgraded@satflux.io', $guest->email);
        $this->assertFalse((bool) $guest->is_guest);
        $this->assertTrue((bool) $guest->allows_satflux_email_changes);
        $this->assertNull($guest->email_verified_at);
        $this->assertTrue(Hash::check('new-secure-password', $guest->password));

        $this->assertTrue(
            auth()->guard('web')->validate([
                'email' => 'upgraded@satflux.io',
                'password' => 'new-secure-password',
            ])
        );
    }

    public function test_guest_upgrade_email_trims_and_lowercases_before_save(): void
    {
        $guest = User::factory()->create([
            'email' => 'guest-trim@satflux.io',
            'password' => bcrypt('old-password'),
            'is_guest' => true,
            'email_verified_at' => now(),
            'btcpay_user_id' => null,
            'btcpay_api_key' => null,
        ]);
        Sanctum::actingAs($guest);

        $upgradeResponse = $this->putJson('/api/user/guest/upgrade', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => '  Trimmed-Case@Satflux.IO  ',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]));

        $upgradeResponse->assertStatus(200);
        $guest->refresh();
        $this->assertSame('trimmed-case@satflux.io', $guest->email);
    }

    public function test_guest_upgrade_lightning_method_is_no_longer_supported(): void
    {
        $pk = '02'.str_repeat('ab', 32);
        $guest = User::factory()->guest()->create([
            'lightning_public_key' => $pk,
        ]);
        Sanctum::actingAs($guest);

        $response = $this->putJson('/api/user/guest/upgrade', $this->guestUpgradePayload([
            'method' => 'lightning',
            'email' => 'real@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['method']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function guestUpgradePayload(array $overrides): array
    {
        return array_merge([
            'privacy_consent' => true,
            'terms_accepted' => true,
        ], $overrides);
    }
}
