<?php

namespace App\Services\BtcPay;

use App\Models\Store;
use App\Models\StoreApiKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StoreApiKeyService
{
    protected UserService $userService;

    protected BtcPayClient $client;

    public function __construct(UserService $userService, BtcPayClient $client)
    {
        $this->userService = $userService;
        $this->client = $client;
    }

    /**
     * Generate an API key for a store.
     *
     * @param  string  $storeId  Local store UUID
     * @param  array  $permissions  Permissions for the API key
     * @param  string  $label  Label for the API key
     * @param  string|null  $callbackUrl  Optional callback URL to send the API key to
     * @return StoreApiKey Created API key model
     *
     * @throws \Exception
     */
    public function generateApiKey(string $storeId, array $permissions, string $label, ?string $callbackUrl = null): StoreApiKey
    {
        $store = Store::findOrFail($storeId);
        $user = $store->user;

        if (! $user->btcpay_user_id) {
            throw new \Exception('User does not have a BTCPay user ID. Please ensure the user account is properly linked to BTCPay.');
        }

        // Default permissions if none provided
        $defaultPermissions = [
            'btcpay.store.canviewinvoices',
            'btcpay.store.cancreateinvoice',
            'btcpay.store.canmodifyinvoices',
            'btcpay.store.webhooks.canmodifywebhooks',
            'btcpay.store.canviewstoresettings',
            'btcpay.store.canmodifystoresettings',
        ];

        $finalPermissions = ! empty($permissions) ? $permissions : $defaultPermissions;

        // Create API key in BTCPay
        $btcpayApiKeyData = $this->userService->createApiKey(
            $user->btcpay_user_id,
            $finalPermissions,
            [$store->btcpay_store_id], // specificStores
            $label
        );

        if (! isset($btcpayApiKeyData['apiKey'])) {
            throw new \Exception('BTCPay API did not return an API key');
        }

        $btcpayApiKey = $btcpayApiKeyData['apiKey'];

        // Store API key in local database
        $storeApiKey = StoreApiKey::create([
            'store_id' => $store->id,
            'label' => $label,
            'btcpay_api_key' => $btcpayApiKey,
            'permissions' => $finalPermissions,
            'callback_url' => $callbackUrl,
            'is_active' => true,
            'metadata' => [
                'created_via' => 'panel',
                'btcpay_key_id' => $btcpayApiKeyData['id'] ?? null,
            ],
        ]);

        // Send API key to callback URL if provided
        if ($callbackUrl) {
            $this->sendApiKeyToCallback($storeApiKey, $callbackUrl);
        }

        Log::info('Store API key created', [
            'store_id' => $storeId,
            'api_key_id' => $storeApiKey->id,
            'label' => $label,
            'has_callback_url' => ! empty($callbackUrl),
        ]);

        return $storeApiKey;
    }

    /**
     * Send API key to callback URL.
     */
    protected function sendApiKeyToCallback(StoreApiKey $storeApiKey, string $callbackUrl): void
    {
        try {
            $response = Http::timeout(10)->post($callbackUrl, [
                'api_key' => $storeApiKey->btcpay_api_key,
                'store_id' => $storeApiKey->store->btcpay_store_id,
                'permissions' => $storeApiKey->permissions,
                'label' => $storeApiKey->label,
            ]);

            if ($response->successful()) {
                Log::info('API key sent to callback URL successfully', [
                    'api_key_id' => $storeApiKey->id,
                    'callback_url' => $callbackUrl,
                ]);
            } else {
                Log::warning('Callback URL returned error', [
                    'api_key_id' => $storeApiKey->id,
                    'callback_url' => $callbackUrl,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send API key to callback URL', [
                'api_key_id' => $storeApiKey->id,
                'callback_url' => $callbackUrl,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - callback failure shouldn't fail the API key creation
        }
    }

    /**
     * Revoke (deactivate) an API key.
     */
    public function revokeApiKey(StoreApiKey|string $apiKey): void
    {
        $storeApiKey = $apiKey instanceof StoreApiKey ? $apiKey : StoreApiKey::findOrFail($apiKey);
        $storeApiKey->update(['is_active' => false]);

        Log::info('Store API key revoked', [
            'api_key_id' => $storeApiKey->id,
            'store_id' => $storeApiKey->store_id,
        ]);
    }

    /**
     * List all API keys for a store.
     *
     * @param  string  $storeId  Local store UUID
     * @return Collection
     */
    public function listApiKeys(string $storeId)
    {
        return StoreApiKey::where('store_id', $storeId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Test if an API key is valid by making a test request to BTCPay.
     */
    public function testApiKey(string $apiKey): bool
    {
        try {
            // Create a temporary client with the API key
            $testClient = new BtcPayClient($apiKey);

            // Try to get current API key info (lightweight test)
            $testClient->get('/api/v1/api-keys/current');

            return true;
        } catch (\Exception $e) {
            Log::warning('API key test failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Regenerate an API key (create new, deactivate old).
     */
    public function regenerateApiKey(StoreApiKey|string $apiKey, array $permissions = [], ?string $label = null, ?string $callbackUrl = null): StoreApiKey
    {
        $oldApiKey = $apiKey instanceof StoreApiKey ? $apiKey : StoreApiKey::findOrFail($apiKey);

        // Deactivate old key
        $oldApiKey->update(['is_active' => false]);

        // Create new key with same or new permissions
        $newPermissions = ! empty($permissions) ? $permissions : $oldApiKey->permissions;
        $newLabel = $label ?? $oldApiKey->label;
        $newCallbackUrl = $callbackUrl ?? $oldApiKey->callback_url;

        return $this->generateApiKey(
            $oldApiKey->store_id,
            $newPermissions,
            $newLabel,
            $newCallbackUrl
        );
    }
}
