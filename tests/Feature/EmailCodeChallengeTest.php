<?php

namespace Tests\Feature;

use App\Exceptions\EmailCodeChallengeException;
use App\Models\EmailVerificationChallenge;
use App\Models\User;
use App\Notifications\EmailCodeNotification;
use App\Services\Auth\EmailCodeChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReadsEmailCodes;
use Tests\TestCase;

class EmailCodeChallengeTest extends TestCase
{
    use ReadsEmailCodes, RefreshDatabase;

    private EmailCodeChallengeService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->service = app(EmailCodeChallengeService::class);
        $this->user = User::factory()->guest()->create();
    }

    private const P = EmailVerificationChallenge::PURPOSE_GUEST_UPGRADE;

    public function test_issue_sends_code_to_target_address_and_stores_only_a_hash(): void
    {
        $challenge = $this->service->issue($this->user, self::P, 'Real@Example.com', ['k' => 'v']);

        Notification::assertSentOnDemand(
            EmailCodeNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'real@example.com'
        );
        $code = $this->lastEmailCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame('real@example.com', $challenge->email);
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertStringNotContainsString($code, $challenge->getRawOriginal('payload') ?? '');
        $this->assertSame(['k' => 'v'], $challenge->fresh()->payload);

        $summary = $this->service->summary($challenge);
        $this->assertSame('real@example.com', $summary['email']);
        $this->assertArrayNotHasKey('code_hash', $summary);
        $this->assertSame(5, $summary['attempts_left']);
    }

    public function test_issue_supersedes_previous_live_challenge_and_old_code_stops_working(): void
    {
        $first = $this->service->issue($this->user, self::P, 'a@example.com');
        $firstCode = $this->lastEmailCode('a@example.com');

        $second = $this->service->issue($this->user, self::P, 'b@example.com');

        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertNull($first->fresh()->payload);
        $this->assertSame($second->id, $this->service->active($this->user, self::P)?->id);

        try {
            $this->service->verify($this->user, self::P, $firstCode);
            $this->fail('Old code should not verify the new challenge');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_MISMATCH, $e->errorCode);
        }
    }

    public function test_verify_accepts_correct_code_and_consume_drops_payload(): void
    {
        $challenge = $this->service->issue($this->user, self::P, 'a@example.com', ['secret' => 1]);
        $code = $this->lastEmailCode();

        $verified = $this->service->verify($this->user, self::P, ' '.substr($code, 0, 3).' '.substr($code, 3).' ');
        $this->assertNotNull($verified->verified_at);
        $this->assertSame(['secret' => 1], $verified->payload);

        $this->service->consume($verified);
        $fresh = $challenge->fresh();
        $this->assertNotNull($fresh->consumed_at);
        $this->assertNull($fresh->payload);
        $this->assertNull($this->service->active($this->user, self::P));
    }

    public function test_wrong_code_counts_attempts_and_fifth_failure_locks(): void
    {
        $this->service->issue($this->user, self::P, 'a@example.com');
        $code = $this->lastEmailCode();
        $wrong = $this->wrongEmailCode($code);

        for ($i = 1; $i <= 4; $i++) {
            try {
                $this->service->verify($this->user, self::P, $wrong);
                $this->fail('Expected mismatch');
            } catch (EmailCodeChallengeException $e) {
                $this->assertSame(EmailCodeChallengeException::CODE_MISMATCH, $e->errorCode);
                $this->assertSame(5 - $i, $e->extra['attempts_left']);
                $this->assertSame(422, $e->status);
            }
        }

        try {
            $this->service->verify($this->user, self::P, $wrong);
            $this->fail('Expected lock');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_LOCKED, $e->errorCode);
            $this->assertSame(423, $e->status);
        }

        // Even the right code is dead now; a new request is required.
        try {
            $this->service->verify($this->user, self::P, $code);
            $this->fail('Locked challenge must not verify');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_MISSING, $e->errorCode);
        }
        $this->assertNull($this->service->active($this->user, self::P));
    }

    public function test_expired_challenge_is_rejected_with_410(): void
    {
        $challenge = $this->service->issue($this->user, self::P, 'a@example.com');
        $code = $this->lastEmailCode();
        $challenge->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $this->service->verify($this->user, self::P, $code);
            $this->fail('Expected expiry');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_EXPIRED, $e->errorCode);
            $this->assertSame(410, $e->status);
        }
        $this->assertNull($this->service->active($this->user, self::P));
    }

    public function test_code_is_bound_to_purpose(): void
    {
        $this->service->issue($this->user, self::P, 'a@example.com');
        $code = $this->lastEmailCode();

        try {
            $this->service->verify($this->user, EmailVerificationChallenge::PURPOSE_WALLET_CONNECTION_CHANGE, $code);
            $this->fail('Code from another purpose must not verify');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_MISSING, $e->errorCode);
        }
    }

    public function test_resend_has_cooldown_rotates_code_and_caps_sends(): void
    {
        $challenge = $this->service->issue($this->user, self::P, 'a@example.com');
        $firstCode = $this->lastEmailCode();

        try {
            $this->service->resend($this->user, self::P);
            $this->fail('Expected cooldown');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_COOLDOWN, $e->errorCode);
            $this->assertSame(429, $e->status);
            $this->assertGreaterThan(0, $e->extra['retry_after']);
            $this->assertLessThanOrEqual(60, $e->extra['retry_after']);
        }

        $this->travel(61)->seconds();
        $resent = $this->service->resend($this->user, self::P);
        $this->assertSame(2, $resent->send_count);
        $secondCode = $this->lastEmailCode();
        $this->assertSame($challenge->id, $resent->id);

        if ($firstCode !== $secondCode) {
            try {
                $this->service->verify($this->user, self::P, $firstCode);
                $this->fail('Rotated-out code must not verify');
            } catch (EmailCodeChallengeException $e) {
                $this->assertSame(EmailCodeChallengeException::CODE_MISMATCH, $e->errorCode);
            }
        }
        $this->assertNotNull($this->service->verify($this->user, self::P, $secondCode)->verified_at);

        // Sends are capped per challenge (5 incl. the first), then the address limiter kicks in.
        $challenge->forceFill(['send_count' => 5, 'consumed_at' => null, 'verified_at' => null])->save();
        $this->travel(61)->seconds();
        try {
            $this->service->resend($this->user, self::P);
            $this->fail('Expected send cap');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_SEND_LIMIT, $e->errorCode);
        }
    }

    public function test_target_address_send_limit_applies_across_users(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->issue(User::factory()->guest()->create(), self::P, 'victim@example.com');
        }

        try {
            $this->service->issue(User::factory()->guest()->create(), self::P, 'victim@example.com');
            $this->fail('Expected address send limit');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_SEND_LIMIT, $e->errorCode);
        }
    }

    public function test_resend_without_live_challenge_is_410(): void
    {
        try {
            $this->service->resend($this->user, self::P);
            $this->fail('Expected missing');
        } catch (EmailCodeChallengeException $e) {
            $this->assertSame(EmailCodeChallengeException::CODE_MISSING, $e->errorCode);
            $this->assertSame(410, $e->status);
        }
    }

    public function test_prune_removes_finished_rows_after_a_day(): void
    {
        $old = $this->service->issue($this->user, self::P, 'a@example.com');
        $old->forceFill(['consumed_at' => now()->subDays(2), 'expires_at' => now()->subDays(2)])->save();
        $live = $this->service->issue($this->user, self::P, 'b@example.com');

        Artisan::call('model:prune', ['--model' => [EmailVerificationChallenge::class]]);

        $this->assertDatabaseMissing('email_verification_challenges', ['id' => $old->id]);
        $this->assertDatabaseHas('email_verification_challenges', ['id' => $live->id]);
    }
}
