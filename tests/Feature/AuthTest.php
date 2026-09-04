<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fake mail to prevent view rendering and file permission issues
        Mail::fake();
        // Ensure session is started for API requests that need it
        $this->withSession([]);
    }

    public function test_register_requires_legal_consent(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'noconsent@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['privacy_consent', 'terms_accepted']);
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'privacy_consent' => true,
            'terms_accepted' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user->privacy_consent_at);
        $this->assertNotNull($user->terms_accepted_at);
    }

    public function test_register_sends_single_verification_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'email' => 'once@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'privacy_consent' => true,
            'terms_accepted' => true,
        ])->assertStatus(201);

        $user = User::where('email', 'once@example.com')->first();
        $this->assertNotNull($user);
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Use postJson() - LoginController now handles missing session gracefully
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['user']);
    }

    public function test_unverified_user_cannot_login_with_password(): void
    {
        User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'unverified@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJsonPath('errors.email.0', __('auth.email_not_verified'));
        $this->assertGuest();
    }

    public function test_unverified_user_cannot_access_protected_api(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/limits');

        $response->assertStatus(403);
        $response->assertJson(['message' => __('auth.email_not_verified')]);
    }

    public function test_unverified_user_can_still_load_own_profile(): void
    {
        // Regression: the "check your email" screen needs the profile; a 403
        // here made the SPA drop the session and show the login form instead.
        $user = User::factory()->unverified()->create(['is_guest' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('email_verified_at', null);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200);
        $response->assertJson(['message' => __('messages.logout_successful')]);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    public function test_password_reset_send_link_validates_email(): void
    {
        $response = $this->postJson('/api/auth/password/reset-link', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_password_reset_send_link_returns_200_even_for_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/password/reset-link', [
            'email' => 'unknown@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message']);
    }

    public function test_password_reset_send_link_sends_notification_for_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);

        $response = $this->postJson('/api/auth/password/reset-link', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(200);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_validates_required_fields(): void
    {
        $response = $this->postJson('/api/auth/password/reset', [
            'token' => 'invalid-token',
            'email' => 'user@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_password_reset_succeeds_with_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message']);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_email_verification_send_requires_email(): void
    {
        $response = $this->postJson('/api/auth/email/verification-notification', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_email_verification_send_returns_422_for_unknown_user(): void
    {
        $response = $this->postJson('/api/auth/email/verification-notification', [
            'email' => 'unknown@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_email_verification_send_returns_200_when_already_verified(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/email/verification-notification', [
            'email' => 'verified@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Email already verified.']);
    }

    public function test_email_verification_verify_returns_404_for_invalid_user_id(): void
    {
        $response = $this->getJson('/api/auth/verify-email/99999/invalidhash?'.http_build_query([
            'expires' => now()->addHour()->timestamp,
            'signature' => 'invalid',
        ]));

        $response->assertStatus(404);
        $response->assertJson(['message' => 'User not found.']);
    }

    public function test_email_verification_verify_requires_expires_and_signature(): void
    {
        $user = User::factory()->create();

        $response = $this->getJson("/api/auth/verify-email/{$user->id}/".sha1($user->email));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['expires', 'signature']);
    }
}
