<?php

namespace App\Services\BtcPay;

use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use Illuminate\Support\Facades\Log;

class MerchantApiKeyService
{
    public const LABEL_PREFIX = 'satflux.io API Key';

    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * Get required permissions for merchant API key from config.
     */
    public function getRequiredPermissions(): array
    {
        return config('btcpay_merchant_permissions.merchant_api_key', [
            'btcpay.store.cancreateinvoice',
            'btcpay.store.canviewstoresettings',
            'btcpay.store.canmodifyinvoices',
            'btcpay.store.canmodifystoresettings',
            'btcpay.store.canviewinvoices',
            'btcpay.user.canviewnotificationsforuser',
            'btcpay.user.canmanagenotificationsforuser',
            // PUT /api/v1/users/me (email sync) - keep in lockstep with the config file.
            'btcpay.user.canmodifyprofile',
        ]);
    }

    /**
     * Upgrade a user's merchant API key to current required permissions.
     * Creates new key, updates user, revokes old key.
     *
     * BTCPay Greenfield API has no GET endpoint to list user API keys, so we use
     * the stored btcpay_api_key directly. The DELETE endpoint accepts the key string.
     *
     * @return bool True if upgraded, false if skipped (e.g. no key found)
     *
     * @throws BtcPayException
     */
    public function upgradeApiKey(User $user): bool
    {
        if (! $user->btcpay_user_id || ! $user->btcpay_api_key) {
            Log::info('MerchantApiKeyService: skipping upgrade - user has no btcpay_user_id or btcpay_api_key', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        $oldKey = $user->btcpay_api_key;
        $label = self::LABEL_PREFIX.' - '.$user->email;

        try {
            $apiKeyData = $this->userService->createApiKey(
                $user->btcpay_user_id,
                $this->getRequiredPermissions(),
                [],
                $label
            );

            $newKey = $apiKeyData['apiKey'] ?? null;
            if (! $newKey) {
                Log::error('MerchantApiKeyService: createApiKey did not return apiKey', [
                    'user_id' => $user->id,
                ]);
                throw new BtcPayException('BTCPay did not return new API key');
            }

            $user->update(['btcpay_api_key' => $newKey]);

            $this->userService->deleteUserApiKey($user->btcpay_user_id, $oldKey);

            Log::info('MerchantApiKeyService: upgraded API key', [
                'user_id' => $user->id,
                'btcpay_user_id' => $user->btcpay_user_id,
            ]);

            return true;
        } catch (BtcPayException $e) {
            Log::error('MerchantApiKeyService: upgrade failed', [
                'user_id' => $user->id,
                'btcpay_user_id' => $user->btcpay_user_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
