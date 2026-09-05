<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Rejected email-code operation. Carries a stable machine code and an HTTP
 * status so the SPA can branch (expired -> offer resend, locked -> offer a
 * different address, cooldown -> countdown) without parsing messages.
 */
class EmailCodeChallengeException extends RuntimeException
{
    public const CODE_MISSING = 'challenge_missing';

    public const CODE_EXPIRED = 'challenge_expired';

    public const CODE_LOCKED = 'challenge_locked';

    public const CODE_MISMATCH = 'code_mismatch';

    public const CODE_COOLDOWN = 'resend_cooldown';

    public const CODE_SEND_LIMIT = 'send_limit_reached';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self(self::CODE_MISSING, 410, __('auth.email_code_missing'));
    }

    public static function expired(): self
    {
        return new self(self::CODE_EXPIRED, 410, __('auth.email_code_expired'));
    }

    public static function locked(): self
    {
        return new self(self::CODE_LOCKED, 423, __('auth.email_code_locked'));
    }

    public static function mismatch(int $attemptsLeft): self
    {
        return new self(
            self::CODE_MISMATCH,
            422,
            __('auth.email_code_mismatch', ['attempts' => $attemptsLeft]),
            ['attempts_left' => $attemptsLeft],
        );
    }

    public static function cooldown(int $retryAfterSeconds): self
    {
        return new self(
            self::CODE_COOLDOWN,
            429,
            __('auth.email_code_cooldown', ['seconds' => $retryAfterSeconds]),
            ['retry_after' => $retryAfterSeconds],
        );
    }

    public static function sendLimit(): self
    {
        return new self(self::CODE_SEND_LIMIT, 429, __('auth.email_code_send_limit'));
    }

    public function render(): JsonResponse
    {
        $response = response()->json(array_merge([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->extra), $this->status);

        if (isset($this->extra['retry_after'])) {
            $response->header('Retry-After', (string) $this->extra['retry_after']);
        }

        return $response;
    }
}
