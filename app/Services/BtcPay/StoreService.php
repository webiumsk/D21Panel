<?php

namespace App\Services\BtcPay;

use App\Services\BtcPay\Exceptions\BtcPayException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StoreService
{
    protected BtcPayClient $client;

    public function __construct(BtcPayClient $client)
    {
        $this->client = $client;
    }

    /**
     * Create a new store in BTCPay Server.
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function createStore(array $data, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, fn () => $this->client->post('/api/v1/stores', $data));
    }

    /**
     * Get a store by ID.
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function getStore(string $storeId, ?string $userApiKey = null): array
    {
        // Include API key hash in cache key to prevent cross-merchant cache pollution
        $apiKeyHash = $userApiKey ? hash('sha256', $userApiKey) : 'server';
        $cacheKey = "btcpay:store:{$storeId}:{$apiKeyHash}";

        return Cache::remember($cacheKey, 60, fn () => $this->client->withUserKey(
            $userApiKey,
            fn () => $this->client->get("/api/v1/stores/{$storeId}")
        ));
    }

    /**
     * Store-level payment methods (e.g. BTC-CHAIN, BTC-LN) as configured in BTCPay.
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function getStorePaymentMethods(string $storeId, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey(
            $userApiKey,
            fn () => $this->client->get("/api/v1/stores/{$storeId}/payment-methods")
        );
    }

    /**
     * List all stores (from BTCPay - application layer filters by user mapping).
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function listStores(?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, fn () => $this->client->get('/api/v1/stores'));
    }

    /**
     * Update store settings (safe fields only).
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function updateStore(string $storeId, array $data, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId, $data, $userApiKey) {
            $result = $this->client->put("/api/v1/stores/{$storeId}", $data);
            $this->forgetStoreCache($storeId, $userApiKey);

            return $result;
        });
    }

    /**
     * Add a user to a store.
     * Requires server-level API key with store management permissions.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $userId  BTCPay user ID
     * @param  string  $role  User role in store (e.g., 'Owner', 'Guest', 'Viewer')
     * @return array Store user data
     *
     * @throws BtcPayException
     */
    public function addUserToStore(string $storeId, string $userId, string $role = 'Owner'): array
    {
        try {
            return $this->client->post("/api/v1/stores/{$storeId}/users", [
                'userId' => $userId,
                'role' => $role,
            ]);
        } catch (BtcPayException $e) {
            // If user is already in store (409 Conflict or error message contains "already"), this is OK
            $errorMessage = strtolower($e->getMessage());
            if (
                $e->getCode() === 409 ||
                str_contains($errorMessage, 'already') ||
                str_contains($errorMessage, 'already added') ||
                str_contains($errorMessage, 'already exists')
            ) {
                Log::info('User already in store, skipping add', [
                    'store_id' => $storeId,
                    'user_id' => $userId,
                    'role' => $role,
                    'error_code' => $e->getCode(),
                ]);
                // Try to get existing user data and return it
                try {
                    $users = $this->getStoreUsers($storeId);
                    $existingUser = collect($users)->firstWhere('userId', $userId);
                    if ($existingUser) {
                        return $existingUser;
                    }
                } catch (\Exception $fetchE) {
                    // If we can't fetch users, just return empty array
                    Log::debug('Could not fetch store users to verify existing user', [
                        'store_id' => $storeId,
                        'error' => $fetchE->getMessage(),
                    ]);
                }

                // Return empty array to indicate success (user already exists)
                return [];
            }
            // Re-throw other errors
            throw $e;
        }
    }

    /**
     * Get all users for a store.
     *
     * @param  string  $storeId  BTCPay store ID
     * @return array List of store users
     *
     * @throws BtcPayException
     */
    public function getStoreUsers(string $storeId): array
    {
        return $this->client->get("/api/v1/stores/{$storeId}/users");
    }

    /**
     * Remove a user from a store.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $userId  BTCPay user ID
     * @return bool True if successful
     *
     * @throws BtcPayException
     */
    public function removeUserFromStore(string $storeId, string $userId): bool
    {
        $this->client->delete("/api/v1/stores/{$storeId}/users/{$userId}");

        return true;
    }

    /**
     * Delete a store in BTCPay Server (DELETE /api/v1/stores/{storeId}).
     * Must use server-level API key - merchant keys typically lack this permission.
     *
     * @param  string|null  $userApiKey  Optional merchant key: HTTP delete always uses server key; when set, its hash-scoped store cache is cleared too
     */
    public function deleteStore(string $storeId, ?string $userApiKey = null): void
    {
        // Store deletion requires server-level key (merchant keys lack this permission)
        $this->client->delete("/api/v1/stores/{$storeId}");

        $this->forgetStoreCache($storeId, $userApiKey);
    }

    /**
     * Invalidate cached BTCPay store payload (logo and other fields may change outside PUT /stores).
     */
    protected function forgetStoreCache(string $storeId, ?string $userApiKey): void
    {
        $apiKeyHash = $userApiKey ? hash('sha256', $userApiKey) : 'server';
        Cache::forget("btcpay:store:{$storeId}:{$apiKeyHash}");
        Cache::forget("btcpay:store:{$storeId}:server");
    }

    /**
     * Upload a store logo.
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function uploadLogo(string $storeId, $file, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId, $file, $userApiKey) {
            $result = $this->client->postMultipart("/api/v1/stores/{$storeId}/logo", $file);
            $this->forgetStoreCache($storeId, $userApiKey);

            return $result;
        });
    }

    /**
     * Delete a store logo.
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function deleteLogo(string $storeId, ?string $userApiKey = null): void
    {
        $this->client->withUserKey($userApiKey, function () use ($storeId, $userApiKey) {
            try {
                $this->client->delete("/api/v1/stores/{$storeId}/logo");
            } catch (BtcPayException $e) {
                // No uploaded logo blob (e.g. only external logoUrl) - still clear logoUrl on the store below
                if ($e->getStatusCode() !== 404) {
                    throw $e;
                }
            }

            // BTCPay may keep logoUrl on the store after DELETE /logo (external URL, fileid reference, or 2.x branding).
            // Partial PUT matches how StoreSettingsController updates BTCPay.
            $this->updateStore($storeId, ['logoUrl' => null], $userApiKey);
        });
    }
}
