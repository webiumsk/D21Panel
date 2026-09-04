<?php

namespace App\Services;

use App\Exceptions\WalletChangeConfirmationException;
use App\Models\EmailVerificationChallenge;
use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\Auth\EmailCodeChallengeService;
use Carbon\CarbonInterface;

/**
 * Replacing a CONNECTED wallet must be confirmed with a 6-digit email code
 * first. The confirmed challenge acts as a short grant (GRANT_MINUTES); every
 * write path that can swap the live wallet (wallet-connection POST and
 * /configure, SamRock complete, Lightning -> Cashu switch) calls assert() and
 * consumes the grant once its write succeeded. First connections and rows
 * that never connected (pending / needs_support) are not gated. Guests have
 * no inbox for the code, so they are asked to upgrade to Free first.
 */
class WalletChangeConfirmationGuard
{
    public const GRANT_MINUTES = 15;

    public function __construct(protected EmailCodeChallengeService $challenges) {}

    public function requiresConfirmation(Store $store): bool
    {
        return WalletConnection::query()
            ->where('store_id', $store->id)
            ->where('status', 'connected')
            ->exists();
    }

    /**
     * @throws WalletChangeConfirmationException
     */
    public function assert(Store $store, User $user, bool $allowGuest = false): void
    {
        if (! $this->requiresConfirmation($store)) {
            return;
        }
        if ((bool) ($user->is_guest ?? false)) {
            if ($allowGuest) {
                return;
            }
            throw WalletChangeConfirmationException::guestUpgradeRequired();
        }
        if (! $this->activeGrant($store, $user)) {
            throw WalletChangeConfirmationException::confirmationRequired();
        }
    }

    public function activeGrant(Store $store, User $user): ?EmailVerificationChallenge
    {
        $challenge = EmailVerificationChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', EmailVerificationChallenge::PURPOSE_WALLET_CONNECTION_CHANGE)
            ->live()
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', now()->subMinutes(self::GRANT_MINUTES))
            ->latest('verified_at')
            ->first();

        if (! $challenge || ($challenge->payload['store_id'] ?? null) !== $store->id) {
            return null;
        }

        return $challenge;
    }

    public function grantedUntil(Store $store, User $user): ?CarbonInterface
    {
        $grant = $this->activeGrant($store, $user);

        return $grant?->verified_at?->copy()->addMinutes(self::GRANT_MINUTES);
    }

    public function consumeGrant(Store $store, User $user): void
    {
        $grant = $this->activeGrant($store, $user);
        if ($grant) {
            $this->challenges->consume($grant);
        }
    }

    /**
     * Client-facing state for the wallet-connection page.
     *
     * @return array<string, mixed>
     */
    public function state(Store $store, User $user): array
    {
        $required = $this->requiresConfirmation($store);
        $isGuest = (bool) ($user->is_guest ?? false);
        $pending = $required && ! $isGuest
            ? $this->challenges->active($user, EmailVerificationChallenge::PURPOSE_WALLET_CONNECTION_CHANGE)
            : null;
        if ($pending && ($pending->payload['store_id'] ?? null) !== $store->id) {
            $pending = null;
        }

        return [
            'required' => $required,
            'guest_upgrade_required' => $required && $isGuest,
            'pending' => $pending && $pending->verified_at === null ? $this->challenges->summary($pending) : null,
            'granted_until' => $required && ! $isGuest
                ? $this->grantedUntil($store, $user)?->toIso8601String()
                : null,
        ];
    }
}
