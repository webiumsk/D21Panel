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
                // The change resets emailConfirmed here too.
                $this->reconfirmEmail($user);
            }

            return;
        }

        // Changing the email resets emailConfirmed and we can only re-confirm
        // via the admin API with the BTCPay user id - without it the change
        // would leave the account unable to authenticate. Don't touch it.
        if (empty($user->btcpay_user_id)) {
            Log::warning('BTCPay email sync skipped - no btcpay_user_id to re-confirm the changed email with', [
                'code' => 'btcpay_no_user_id',
                'user_id' => $user->id,
            ]);

            return;
        }

        try {
            $this->pushEmail($user);
            $this->reconfirmEmail($user);

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
            $this->reconfirmEmail($user);
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

    /**
     * Changing the email resets emailConfirmed on BTCPay; with the server's
     * "confirmed email required" policy every merchant-API-key call then fails
     * ("You must have a confirmed email to log in") and the whole account is
     * dead. Re-confirm via the server key, exactly like guest provisioning
     * does. A failure is rethrown so SyncBtcpayEmailJob retries it - pushing
     * the (now unchanged) email again is a no-op on BTCPay.
     */
    protected function reconfirmEmail(User $user): void
    {
        if (empty($user->btcpay_user_id)) {
            return;
        }

        try {
            $this->userService->confirmUserEmail((string) $user->btcpay_user_id);
        } catch (\Throwable $e) {
            Log::error('BTCPay re-confirm after email change failed - merchant API key will not authenticate', [
                'code' => 'btcpay_email_reconfirm_failed',
                'user_id' => $user->id,
                'btcpay_user_id' => $user->btcpay_user_id,
                'error' => $e->getMessage(),
            ]);

            // Wrapped so the push-email error classification above cannot
            // swallow it - this must bubble to the queued job for a retry.
            throw new \RuntimeException('BTCPay email re-confirm failed', 0, $e);
        }
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
