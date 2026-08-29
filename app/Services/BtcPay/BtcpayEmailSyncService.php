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

            throw $e;
        }
    }

    protected function pushEmail(User $user): void
    {
        $this->userService->updateCurrentUserProfile($user->getBtcPayApiKeyOrFail(), [
            'email' => (string) $user->email,
        ]);
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

        Log::warning('BTCPay email sync skipped - email already belongs to another BTCPay user', [
            'code' => 'btcpay_email_taken',
            'user_id' => $user->id,
            'btcpay_user_id' => $user->btcpay_user_id,
            'email_masked' => count($parts) === 2
                ? (($parts[0] !== '' ? $parts[0][0] : '*').'***@'.$parts[1])
                : '***',
            'error' => $e->getMessage(),
        ]);
    }
}
