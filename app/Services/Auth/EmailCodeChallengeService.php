<?php

namespace App\Services\Auth;

use App\Exceptions\EmailCodeChallengeException;
use App\Models\AuditLog;
use App\Models\EmailVerificationChallenge;
use App\Models\User;
use App\Notifications\EmailCodeNotification;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Lifecycle of 6-digit email codes:
 *
 *   issue()   -> supersedes the live challenge for (user, purpose), stores an
 *                HMAC of the code, emails the plaintext to the target address.
 *   resend()  -> rotates the code on the live challenge (60 s cooldown, max
 *                sends per challenge), resets the expiry.
 *   verify()  -> constant-time check under a row lock; 5 wrong tries lock
 *                the challenge; success stamps verified_at.
 *   consume() -> marks the challenge used and drops the payload.
 *
 * Callers that apply a one-shot change (guest upgrade) call consume() right
 * after verify(); callers that hand out a short grant (wallet change) leave
 * the row verified and consume it once the guarded write succeeds.
 */
class EmailCodeChallengeService
{
    public const CODE_LENGTH = 6;

    public const DEFAULT_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS_PER_CHALLENGE = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Sends per hour to one inbox across all users (anti-spam; keyed by address hash). */
    public const TARGET_SENDS_PER_HOUR = 5;

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function issue(
        User $user,
        string $purpose,
        string $email,
        ?array $payload = null,
        int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
    ): EmailVerificationChallenge {
        $this->assertPurpose($purpose);
        $email = strtolower(trim($email));
        $this->assertTargetSendAllowed($email);

        $code = $this->generateCode();

        $challenge = DB::transaction(function () use ($user, $purpose, $email, $payload, $ttlMinutes, $code) {
            // Serialise concurrent requests for the same user (double submit):
            // without this both would supersede nothing and race the partial
            // unique index on insert.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            EmailVerificationChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->live()
                ->update(['superseded_at' => now(), 'payload' => null]);

            $challenge = new EmailVerificationChallenge([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'email' => $email,
                'payload' => $payload,
                'attempts' => 0,
                'send_count' => 1,
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
            // The HMAC binds the code to this row + purpose, so the id must exist first.
            $challenge->id = (string) $challenge->newUniqueId();
            $challenge->code_hash = $this->hashCode($challenge, $code);
            $challenge->save();

            return $challenge;
        });

        $this->send($challenge, $code, $ttlMinutes);
        $this->audit('email_challenge.issued', $challenge);

        return $challenge;
    }

    public function resend(User $user, string $purpose): EmailVerificationChallenge
    {
        $this->assertPurpose($purpose);

        $code = $this->generateCode();

        $challenge = DB::transaction(function () use ($user, $purpose, $code) {
            $challenge = $this->lockedLive($user, $purpose);
            if (! $challenge) {
                throw EmailCodeChallengeException::missing();
            }
            if ($challenge->send_count >= self::MAX_SENDS_PER_CHALLENGE) {
                throw EmailCodeChallengeException::sendLimit();
            }
            $retryAfter = $this->resendRetryAfterSeconds($challenge);
            if ($retryAfter > 0) {
                throw EmailCodeChallengeException::cooldown($retryAfter);
            }
            $this->assertTargetSendAllowed($challenge->email);

            $ttlMinutes = self::DEFAULT_TTL_MINUTES;
            $challenge->forceFill([
                'code_hash' => $this->hashCode($challenge, $code),
                'send_count' => $challenge->send_count + 1,
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes($ttlMinutes),
                'verified_at' => null,
            ])->save();

            return $challenge;
        });

        $this->send($challenge, $code, self::DEFAULT_TTL_MINUTES);
        $this->audit('email_challenge.resent', $challenge);

        return $challenge;
    }

    /**
     * Returns the challenge with verified_at set. Throws on any rejection.
     * The attempt counter is committed before the exception leaves, so a
     * wrong guess is never rolled back together with its rejection.
     */
    public function verify(User $user, string $purpose, string $code): EmailVerificationChallenge
    {
        $this->assertPurpose($purpose);
        $code = preg_replace('/\D+/', '', $code) ?? '';

        /** @var array{challenge: ?EmailVerificationChallenge, error: ?EmailCodeChallengeException} $result */
        $result = DB::transaction(function () use ($user, $purpose, $code) {
            $challenge = $this->lockedLive($user, $purpose);
            if (! $challenge) {
                return ['challenge' => null, 'error' => EmailCodeChallengeException::missing()];
            }
            if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                return ['challenge' => $challenge, 'error' => EmailCodeChallengeException::locked()];
            }
            if ($challenge->isExpired()) {
                return ['challenge' => $challenge, 'error' => EmailCodeChallengeException::expired()];
            }

            $matches = strlen($code) === self::CODE_LENGTH
                && hash_equals($challenge->code_hash, $this->hashCode($challenge, $code));

            if (! $matches) {
                $challenge->attempts++;
                $locked = $challenge->attempts >= self::MAX_ATTEMPTS;
                if ($locked) {
                    // Burn the challenge: a fresh request (new code) is the only way forward.
                    $challenge->consumed_at = now();
                    $challenge->payload = null;
                }
                $challenge->save();
                $this->audit($locked ? 'email_challenge.locked' : 'email_challenge.failed', $challenge);

                return [
                    'challenge' => $challenge,
                    'error' => $locked
                        ? EmailCodeChallengeException::locked()
                        : EmailCodeChallengeException::mismatch(self::MAX_ATTEMPTS - $challenge->attempts),
                ];
            }

            $challenge->verified_at = now();
            $challenge->save();
            $this->audit('email_challenge.confirmed', $challenge);

            return ['challenge' => $challenge, 'error' => null];
        });

        if ($result['error'] !== null) {
            throw $result['error'];
        }

        return $result['challenge'];
    }

    public function consume(EmailVerificationChallenge $challenge): void
    {
        $challenge->forceFill([
            'consumed_at' => now(),
            'payload' => null,
        ])->save();
    }

    /** Live challenge for (user, purpose) that has not expired, or null. */
    public function active(User $user, string $purpose): ?EmailVerificationChallenge
    {
        $challenge = EmailVerificationChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->live()
            ->latest('created_at')
            ->first();

        if (! $challenge || $challenge->isExpired() || $challenge->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        return $challenge;
    }

    /**
     * Client-facing shape (never the hash or payload).
     *
     * @return array<string, mixed>|null
     */
    public function summary(?EmailVerificationChallenge $challenge): ?array
    {
        if (! $challenge) {
            return null;
        }

        return [
            'purpose' => $challenge->purpose,
            'email' => $challenge->email,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_at' => ($challenge->last_sent_at ?? $challenge->created_at)
                ->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
            'attempts_left' => max(0, self::MAX_ATTEMPTS - $challenge->attempts),
            'sends_left' => max(0, self::MAX_SENDS_PER_CHALLENGE - $challenge->send_count),
        ];
    }

    private function lockedLive(User $user, string $purpose): ?EmailVerificationChallenge
    {
        return EmailVerificationChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->live()
            ->latest('created_at')
            ->lockForUpdate()
            ->first();
    }

    private function resendRetryAfterSeconds(EmailVerificationChallenge $challenge): int
    {
        $lastSent = $challenge->last_sent_at ?? $challenge->created_at;
        $availableAt = $lastSent->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return max(0, (int) ceil(now()->diffInSeconds($availableAt, false)));
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * HMAC over id|purpose|code. A 6-digit space gains nothing from a slow
     * hash (attempts are capped); binding id + purpose makes a code useless
     * against any other challenge even if hashes leak.
     */
    private function hashCode(EmailVerificationChallenge $challenge, string $code): string
    {
        return hash_hmac('sha256', $challenge->id.'|'.$challenge->purpose.'|'.$code, (string) config('app.key'));
    }

    private function send(EmailVerificationChallenge $challenge, string $code, int $ttlMinutes): void
    {
        try {
            Notification::route('mail', $challenge->email)
                ->notify(new EmailCodeNotification($code, $challenge->purpose, $ttlMinutes));
        } catch (\Throwable $e) {
            Log::warning('Email code delivery failed', [
                'challenge_id' => $challenge->id,
                'purpose' => $challenge->purpose,
                'email' => LogSanitizer::email($challenge->email),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(__('auth.email_code_send_failed'), 0, $e);
        }
    }

    private function assertTargetSendAllowed(string $email): void
    {
        // Reserve first, then check: hit() is an atomic increment, so two
        // concurrent requests cannot both pass a separate "is there room" check.
        $key = 'email-code-target:'.hash('sha256', $email);
        if (RateLimiter::hit($key, 3600) > self::TARGET_SENDS_PER_HOUR) {
            throw EmailCodeChallengeException::sendLimit();
        }
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, EmailVerificationChallenge::PURPOSES, true)) {
            throw new \InvalidArgumentException("Unknown email challenge purpose [{$purpose}].");
        }
    }

    private function audit(string $action, EmailVerificationChallenge $challenge): void
    {
        try {
            AuditLog::log($action, 'user', (string) $challenge->user_id, [
                'purpose' => $challenge->purpose,
                'challenge_id' => $challenge->id,
                'email' => LogSanitizer::email($challenge->email),
                'attempts' => $challenge->attempts,
                'send_count' => $challenge->send_count,
            ], $challenge->user_id);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed for email challenge', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
