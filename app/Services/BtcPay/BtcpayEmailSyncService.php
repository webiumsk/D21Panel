<?php

namespace App\Services\BtcPay;

use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the Satflux account email to the user's BTCPay account (email is the
 * BTCPay username). Shared by the guest-upgrade flow and SyncBtcpayEmailJob.
 *
 * Greenfield only supports self-service email updates (PUT /api/v1/users/me,
 * policy btcpay.user.canmodifyprofile), so the merchant API key must carry
 * that permission - keys minted before it was added get a 403, which this
 * service heals by re-minting the key via MerchantApiKeyService and retrying
 * once.
 */
class BtcpayEmailSyncService
{
    public function __construct(
        protected UserService $userService,
        protected MerchantApiKeyService $merchantApiKeyService,
    ) {}

    /**
     * @throws BtcPayException on failures other than a taken email
     */
    public function syncUserEmail(User $user): void
    {
        if (empty($user->email)) {
            return;
        }

        // This flow sends account credentials (currentPassword + merchant API
        // key). Refuse to put them on the wire over plain HTTP - fail safely,
        // the sync is best-effort.
        if (! str_starts_with(strtolower((string) config('services.btcpay.base_url')), 'https://')) {
            Log::warning('BTCPay email sync skipped - BTCPay base URL is not HTTPS', [
                'code' => 'btcpay_insecure_transport',
                'user_id' => $user->id,
            ]);

            return;
        }

        if (empty($user->btcpay_api_key)) {
            if (! empty($user->btcpay_user_id)) {
                // Legacy accounts without a merchant key: best-effort only -
                // current Greenfield has no admin route to change another
                // user's email, so this works only on old BTCPay servers.
                $this->userService->updateUser((string) $user->btcpay_user_id, [
                    'email' => (string) $user->email,
                ]);
            }

            return;
        }

        try {
            $this->pushEmail($user);

            return;
        } catch (BtcPayException $e) {
            if ($this->isEmailTakenError($e)) {
                $this->logEmailTaken($user, $e);

                return;
            }

            if ($this->isCurrentPasswordError($e)) {
                // BTCPay wants the account password and ours is wrong or was
                // never stored (legacy guests) - retrying cannot help.
                Log::warning('BTCPay email sync skipped - account password required and not available', [
                    'code' => 'btcpay_password_unknown',
                    'user_id' => $user->id,
                    'btcpay_user_id' => $user->btcpay_user_id,
                    'has_stored_password' => ! empty($user->btcpay_password),
                ]);

                return;
            }

            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
        }

        // 403 = merchant key predates btcpay.user.canmodifyprofile. Re-mint
        // the key with the current permission set and retry exactly once.
        $this->merchantApiKeyService->upgradeApiKey($user);

        try {
            $this->pushEmail($user);
        } catch (BtcPayException $e) {
            if ($this->isEmailTakenError($e)) {
                $this->logEmailTaken($user, $e);

                return;
            }

            if ($this->isCurrentPasswordError($e)) {
                Log::warning('BTCPay email sync skipped - account password required and not available', [
                    'code' => 'btcpay_password_unknown',
                    'user_id' => $user->id,
                    'btcpay_user_id' => $user->btcpay_user_id,
                    'has_stored_password' => ! empty($user->btcpay_password),
                ]);

                return;
            }

            throw $e;
        }
    }

    protected function pushEmail(User $user): void
    {
        $payload = ['email' => (string) $user->email];

        // Current BTCPay requires the account password as currentPassword for
        // email changes on PUT /users/me. Guests provisioned since the
        // btcpay_password column exists have it stored (encrypted); older
        // accounts had it generated and discarded, so we can only try without.
        if (! empty($user->btcpay_password)) {
            $payload['currentPassword'] = (string) $user->btcpay_password;
        }

        $this->userService->updateCurrentUserProfile($user->getBtcPayApiKeyOrFail(), $payload);
    }

    protected function isCurrentPasswordError(BtcPayException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'currentpassword');
    }

    /**
     * BTCPay email = username, unique per server. A taken email cannot be
     * fixed by retrying - surface it clearly and stop.
     */
    protected function isEmailTakenError(BtcPayException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicateusername')
            || str_contains($message, 'already taken')
            || str_contains($message, 'setting email for user');
    }

    protected function logEmailTaken(User $user, BtcPayException $e): void
    {
        $parts = explode('@', (string) $user->email, 2);

        // Deliberately no $e->getMessage(): BTCPay's DuplicateUserName text
        // carries the full email, which would defeat the masking above.
        Log::warning('BTCPay email sync skipped - email already belongs to another BTCPay user', [
            'code' => 'btcpay_email_taken',
            'user_id' => $user->id,
            'btcpay_user_id' => $user->btcpay_user_id,
            'email_masked' => count($parts) === 2
                ? (($parts[0] !== '' ? $parts[0][0] : '*').'***@'.$parts[1])
                : '***',
            'status' => $e->getStatusCode(),
        ]);
    }
}
