<?php

namespace App\Services;

use App\Jobs\SyncBtcpayEmailJob;
use App\Models\EmailVerificationChallenge;
use App\Models\User;
use App\Services\Auth\EmailCodeChallengeService;
use App\Services\BtcPay\BtcpayEmailSyncService;
use App\Services\Compliance\ComplianceGate;
use App\Support\Legal\LegalConsent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Guest -> Free in two steps: stage() emails a 6-digit code to the address
 * the guest typed (the guest row is untouched), apply() flips the account
 * once the code is confirmed. Nothing is persisted for an unverified
 * address, so the account never sits in a "non-guest but unverified" limbo.
 */
class GuestUpgradeService
{
    public function __construct(
        protected BtcpayEmailSyncService $emailSync,
        protected EmailCodeChallengeService $challenges,
        protected ComplianceGate $complianceGate,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function stage(User $guest, array $validated): EmailVerificationChallenge
    {
        $email = strtolower(trim((string) $validated['email']));
        $plainPassword = isset($validated['password']) ? trim((string) $validated['password']) : '';

        return $this->challenges->issue(
            $guest,
            EmailVerificationChallenge::PURPOSE_GUEST_UPGRADE,
            $email,
            // Only a hash is staged; the plaintext never touches the DB.
            ['password_hash' => $plainPassword !== '' ? Hash::make($plainPassword) : null],
        );
    }

    public function confirm(User $guest, string $code): User
    {
        $challenge = $this->challenges->verify($guest, EmailVerificationChallenge::PURPOSE_GUEST_UPGRADE, $code);

        return $this->apply($guest, $challenge);
    }

    public function resend(User $guest): EmailVerificationChallenge
    {
        return $this->challenges->resend($guest, EmailVerificationChallenge::PURPOSE_GUEST_UPGRADE);
    }

    public function pending(User $guest): ?EmailVerificationChallenge
    {
        return $this->challenges->active($guest, EmailVerificationChallenge::PURPOSE_GUEST_UPGRADE);
    }

    public function apply(User $guest, EmailVerificationChallenge $challenge): User
    {
        $email = $challenge->email;
        $payload = $challenge->payload ?? [];

        // The address may have been registered by someone else between
        // request and confirm - the guest stays a guest and picks another.
        if ($this->emailTaken($email, $guest)) {
            $this->challenges->consume($challenge);
            throw $this->emailTakenException();
        }

        $fill = [
            'email' => $email,
            'is_guest' => false,
            // Proven by the code - no second (link) verification round.
            'email_verified_at' => now(),
            'allows_satflux_email_changes' => true,
        ];
        if (! empty($payload['password_hash'])) {
            $fill['password'] = $payload['password_hash'];
        }

        try {
            DB::transaction(function () use ($guest, $challenge, $fill, $email) {
                $guest->forceFill($fill)->save();
                LegalConsent::recordRegistration($guest);
                $this->complianceGate->linkLatestRegistrationScreening($email, $guest);
                $this->challenges->consume($challenge);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $this->challenges->consume($challenge);
                throw $this->emailTakenException();
            }
            throw $e;
        }

        try {
            // Shared sync (403 -> merchant key re-mint + retry; taken email is
            // logged and skipped). BTCPay sync stays best-effort: the Satflux
            // upgrade must succeed even when BTCPay is unreachable.
            $this->emailSync->syncUserEmail($guest);
        } catch (\Throwable $e) {
            Log::warning('BTCPay email update failed during guest upgrade', [
                'user_id' => $guest->id,
                'btcpay_user_id' => $guest->btcpay_user_id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatchBtcpayEmailSyncSafely($guest->id);
        }

        return $guest->fresh();
    }

    private function emailTaken(string $email, User $guest): bool
    {
        return User::query()
            ->where('email', $email)
            ->where('id', '!=', $guest->id)
            ->exists();
    }

    private function emailTakenException(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => [__('validation.unique', ['attribute' => 'email'])],
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // pgsql SQLSTATE 23505; sqlite reports "UNIQUE constraint failed" with 23000.
        return $e->getCode() === '23505'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'duplicate key');
    }

    /**
     * Queue a BTCPay email sync; swallow sync-driver failures so the Satflux upgrade still returns success.
     */
    private function dispatchBtcpayEmailSyncSafely(int $userId): void
    {
        try {
            SyncBtcpayEmailJob::dispatch($userId);
        } catch (\Throwable $e) {
            Log::warning('SyncBtcpayEmailJob dispatch/run failed after guest upgrade', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
