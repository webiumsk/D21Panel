<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsEmailCodes;
use Tests\TestCase;

class SeedFirstLoginTest extends TestCase
{
    use ReadsEmailCodes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['guest.seed_first_registration' => true]);
        config(['guest.upgrade_email_only' => true]);
        Notification::fake();
    }

    public function test_legacy_user_requires_recovery_migration_on_user_endpoint(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy@example.com',
            'password' => bcrypt('secret-password'),
            'email_verified_at' => now(),
            'is_guest' => false,
            'guest_recovery_public_key' => null,
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonPath('requires_recovery_migration', true);
        $response->assertJsonPath('can_use_password_login', true);
    }

    public function test_user_with_recovery_phrase_does_not_require_migration(): void
    {
        $user = User::factory()->create([
            'email' => 'migrated@example.com',
            'password' => bcrypt('secret-password'),
            'email_verified_at' => now(),
            'is_guest' => false,
            'guest_recovery_public_key' => str_repeat('a', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonPath('requires_recovery_migration', false);
        $response->assertJsonPath('can_use_password_login', false);
    }

    public function test_password_login_blocked_after_recovery_phrase_enrolled(): void
    {
        User::factory()->create([
            'email' => 'blocked@example.com',
            'password' => bcrypt('secret-password'),
            'email_verified_at' => now(),
            'is_guest' => false,
            'guest_recovery_public_key' => str_repeat('b', 64),
            'guest_recovery_enrolled_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'blocked@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_legacy_user_can_password_login_once_and_gets_migration_flag(): void
    {
        User::factory()->create([
            'email' => 'once@example.com',
            'password' => bcrypt('secret-password'),
            'email_verified_at' => now(),
            'is_guest' => false,
            'guest_recovery_public_key' => null,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'once@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('requires_recovery_migration', true);
        $response->assertJsonPath('user.email', 'once@example.com');
    }

    public function test_guest_upgrade_email_only_without_password(): void
    {
        $guest = User::factory()->create([
            'email' => 'guest-only@satflux.io',
            'password' => bcrypt('old-password'),
            'is_guest' => true,
            'email_verified_at' => now(),
            'btcpay_user_id' => null,
            'btcpay_api_key' => null,
        ]);
        Sanctum::actingAs($guest);

        $this->postJson('/api/user/guest/upgrade/request', [
            'method' => 'email',
            'email' => 'free-user@satflux.io',
            'privacy_consent' => true,
            'terms_accepted' => true,
        ])->assertStatus(200)->assertJsonPath('challenge.email', 'free-user@satflux.io');

        // Nothing changes until the code is confirmed.
        $this->assertTrue((bool) $guest->fresh()->is_guest);

        $response = $this->postJson('/api/user/guest/upgrade/confirm', ['code' => $this->lastEmailCode()]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.email', 'free-user@satflux.io');
        $response->assertJsonPath('user.is_guest', false);

        $guest->refresh();
        $this->assertSame('free-user@satflux.io', $guest->email);
        $this->assertFalse((bool) $guest->is_guest);
        $this->assertNotNull($guest->email_verified_at);
        // Email-only upgrade keeps the existing (dead) password untouched.
        $this->assertTrue(Hash::check('old-password', (string) $guest->password));
        $this->assertFalse(Hash::check('new-secure-password', (string) $guest->password));
    }
}
