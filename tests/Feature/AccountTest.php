<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsEmailCodes;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use ReadsEmailCodes, RefreshDatabase;

    public function test_authenticated_user_can_get_own_profile(): void
    {
        $user = User::factory()->create(['name' => 'Test User', 'email' => 'user@example.com']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => 'user@example.com', 'name' => 'Test User']);
        $response->assertJsonStructure(['id', 'name', 'email']);
    }

    public function test_user_payload_exposes_guest_upgrade_email_only_flag(): void
    {
        config(['guest.upgrade_email_only' => true]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('guest_upgrade_email_only', true);

        config(['guest.upgrade_email_only' => false]);

        $this->getJson('/api/user')
            ->assertJsonPath('guest_upgrade_email_only', false);
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

    public function test_guest_upgrade_request_stages_code_without_touching_guest_row(): void
    {
        Notification::fake();
        $guest = $this->makeGuest('guest+abc@guest.satflux.io');
        Sanctum::actingAs($guest);

        $response = $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => '  Upgraded@Satflux.IO  ',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('challenge.email', 'upgraded@satflux.io')
            ->assertJsonPath('challenge.purpose', 'guest_upgrade')
            ->assertJsonPath('challenge.attempts_left', 5)
            ->assertJsonMissingPath('challenge.code_hash');

        $guest->refresh();
        $this->assertTrue((bool) $guest->is_guest);
        $this->assertSame('guest+abc@guest.satflux.io', $guest->email);
        $this->assertTrue(Hash::check('old-password', $guest->password));
        $this->assertNull($guest->privacy_consent_at);

        // The staged challenge is exposed on /api/user so the SPA can resume the code step.
        $this->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('pending_email_challenge.email', 'upgraded@satflux.io')
            ->assertJsonPath('is_guest', true);

        $this->lastEmailCode('upgraded@satflux.io');
    }

    public function test_guest_can_upgrade_with_code_and_then_login_with_new_credentials(): void
    {
        Notification::fake();
        $guest = $this->makeGuest('guest-upgrade@satflux.io');
        Sanctum::actingAs($guest);

        $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'upgraded@satflux.io',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]))->assertStatus(200);

        $confirm = $this->postJson('/api/user/guest/upgrade/confirm', ['code' => $this->lastEmailCode()]);

        $confirm->assertStatus(200);
        $confirm->assertJsonPath('user.email', 'upgraded@satflux.io');
        $confirm->assertJsonPath('user.is_guest', false);
        $confirm->assertJsonPath('user.allows_satflux_email_changes', true);

        $guest->refresh();
        $this->assertSame('upgraded@satflux.io', $guest->email);
        $this->assertFalse((bool) $guest->is_guest);
        $this->assertTrue((bool) $guest->allows_satflux_email_changes);
        // Proven by the code - no second verification round, no limbo.
        $this->assertNotNull($guest->email_verified_at);
        $this->assertNotNull($guest->privacy_consent_at);
        $this->assertNotNull($guest->terms_accepted_at);
        $this->assertTrue(Hash::check('new-secure-password', $guest->password));

        $this->assertTrue(
            auth()->guard('web')->validate([
                'email' => 'upgraded@satflux.io',
                'password' => 'new-secure-password',
            ])
        );

        $this->getJson('/api/user')->assertJsonPath('pending_email_challenge', null);

        // Second tab confirming again is a no-op success.
        $this->postJson('/api/user/guest/upgrade/confirm', ['code' => '000000'])
            ->assertStatus(200)
            ->assertJsonPath('user.is_guest', false);
    }

    public function test_guest_upgrade_wrong_code_reports_attempts_and_keeps_guest(): void
    {
        config(['guest.upgrade_email_only' => true]);
        Notification::fake();
        $guest = $this->makeGuest('guest-wrong@satflux.io');
        Sanctum::actingAs($guest);

        $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'wrong@satflux.io',
        ]))->assertStatus(200);
        $wrong = $this->wrongEmailCode($this->lastEmailCode());

        $this->postJson('/api/user/guest/upgrade/confirm', ['code' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_mismatch')
            ->assertJsonPath('attempts_left', 4);

        $this->assertTrue((bool) $guest->fresh()->is_guest);
    }

    public function test_guest_upgrade_confirm_without_request_is_410(): void
    {
        $guest = $this->makeGuest('guest-none@satflux.io');
        Sanctum::actingAs($guest);

        $this->postJson('/api/user/guest/upgrade/confirm', ['code' => '123456'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'challenge_missing');
    }

    public function test_guest_upgrade_resend_respects_server_cooldown(): void
    {
        config(['guest.upgrade_email_only' => true]);
        Notification::fake();
        $guest = $this->makeGuest('guest-resend@satflux.io');
        Sanctum::actingAs($guest);

        $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'resend@satflux.io',
        ]))->assertStatus(200);

        $this->postJson('/api/user/guest/upgrade/resend')
            ->assertStatus(429)
            ->assertJsonPath('code', 'resend_cooldown')
            ->assertHeader('Retry-After');

        $this->travel(61)->seconds();

        $this->postJson('/api/user/guest/upgrade/resend')
            ->assertStatus(200)
            ->assertJsonPath('challenge.sends_left', 3);

        $this->postJson('/api/user/guest/upgrade/confirm', ['code' => $this->lastEmailCode()])
            ->assertStatus(200)
            ->assertJsonPath('user.is_guest', false);
    }

    public function test_guest_upgrade_fails_when_email_taken_between_request_and_confirm(): void
    {
        config(['guest.upgrade_email_only' => true]);
        Notification::fake();
        $guest = $this->makeGuest('guest-race@satflux.io');
        Sanctum::actingAs($guest);

        $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'race@satflux.io',
        ]))->assertStatus(200);
        $code = $this->lastEmailCode();

        User::factory()->create(['email' => 'race@satflux.io']);

        $this->postJson('/api/user/guest/upgrade/confirm', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $guest->refresh();
        $this->assertTrue((bool) $guest->is_guest);
        $this->assertSame('guest-race@satflux.io', $guest->email);
        // The burnt challenge is gone; the guest has to pick another address.
        $this->getJson('/api/user')->assertJsonPath('pending_email_challenge', null);
    }

    public function test_guest_upgrade_request_rejects_taken_email_upfront(): void
    {
        config(['guest.upgrade_email_only' => true]);
        User::factory()->create(['email' => 'taken@satflux.io']);
        $guest = $this->makeGuest('guest-taken@satflux.io');
        Sanctum::actingAs($guest);

        $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'taken@satflux.io',
        ]))->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_non_guest_cannot_request_guest_upgrade(): void
    {
        config(['guest.upgrade_email_only' => true]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'other@satflux.io',
        ]))->assertStatus(422);
    }

    public function test_legacy_put_guest_upgrade_route_is_gone(): void
    {
        config(['guest.upgrade_email_only' => true]);
        $guest = $this->makeGuest('guest-legacy@satflux.io');
        Sanctum::actingAs($guest);

        $this->putJson('/api/user/guest/upgrade', $this->guestUpgradePayload([
            'method' => 'email',
            'email' => 'legacy@satflux.io',
        ]))->assertStatus(405);
    }

    public function test_guest_upgrade_lightning_method_is_no_longer_supported(): void
    {
        $pk = '02'.str_repeat('ab', 32);
        $guest = User::factory()->guest()->create([
            'lightning_public_key' => $pk,
        ]);
        Sanctum::actingAs($guest);

        $response = $this->postJson('/api/user/guest/upgrade/request', $this->guestUpgradePayload([
            'method' => 'lightning',
            'email' => 'real@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['method']);
    }

    private function makeGuest(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => bcrypt('old-password'),
            'is_guest' => true,
            'email_verified_at' => now(),
            'btcpay_user_id' => null,
            'btcpay_api_key' => null,
        ]);
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
