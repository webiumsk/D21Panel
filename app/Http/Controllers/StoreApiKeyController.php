<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreApiKey;
use App\Services\BtcPay\StoreApiKeyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StoreApiKeyController extends Controller
{
    protected StoreApiKeyService $storeApiKeyService;

    public function __construct(StoreApiKeyService $storeApiKeyService)
    {
        $this->storeApiKeyService = $storeApiKeyService;
    }

    /**
     * List all API keys for a store.
     */
    public function index(Request $request, Store $store)
    {
        $apiKeys = $this->storeApiKeyService->listApiKeys($store->id);

        // Return metadata only (not the actual API key for security)
        $apiKeysData = $apiKeys->map(function ($apiKey) {
            return [
                'id' => $apiKey->id,
                'label' => $apiKey->label,
                'permissions' => $apiKey->permissions,
                'callback_url' => $apiKey->callback_url,
                'is_active' => $apiKey->is_active,
                'last_used_at' => $apiKey->last_used_at?->toISOString(),
                'expires_at' => $apiKey->expires_at?->toISOString(),
                'created_at' => $apiKey->created_at->toISOString(),
                'has_api_key' => ! empty($apiKey->btcpay_api_key), // Just indicate if it exists
            ];
        });

        // Limit is per store; only active keys count
        $activeCount = $store->apiKeys()->where('is_active', true)->count();
        $viewer = $request->user();
        // Admin/support see Pro-like limit (3 per store) so display shows e.g. 2/3
        if ($viewer->isSupport()) {
            $maxApiKeys = 3;
        } else {
            $owner = $store->user;
            $plan = $owner->currentSubscriptionPlan();
            $maxApiKeys = $plan?->max_api_keys;
        }

        return response()->json([
            'data' => $apiKeysData,
            'limit' => [
                'max' => $maxApiKeys,
                'current' => $activeCount,
                'unlimited' => $maxApiKeys === null,
            ],
        ]);
    }

    /**
     * Create a new API key for a store.
     */
    public function store(Request $request, Store $store)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
            'callback_url' => ['nullable', 'url', 'max:500'],
        ]);

        try {
            $apiKey = $this->storeApiKeyService->generateApiKey(
                $store->id,
                $validated['permissions'] ?? [],
                $validated['label'],
                $validated['callback_url'] ?? null
            );

            Log::info('Store API key created via controller', [
                'store_id' => $store->id,
                'api_key_id' => $apiKey->id,
                'user_id' => auth()->id(),
            ]);

            // Return the API key only once (first time)
            return response()->json([
                'data' => [
                    'id' => $apiKey->id,
                    'label' => $apiKey->label,
                    'api_key' => $apiKey->btcpay_api_key, // Return it once
                    'permissions' => $apiKey->permissions,
                    'callback_url' => $apiKey->callback_url,
                    'is_active' => $apiKey->is_active,
                    'created_at' => $apiKey->created_at->toISOString(),
                    'store_id' => $store->btcpay_store_id, // Include BTCPay Store ID
                ],
                'message' => 'API key created successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create store API key', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific API key (metadata only, not the actual key).
     */
    public function show(Request $request, Store $store, StoreApiKey $apiKey)
    {

        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'label' => $apiKey->label,
                'permissions' => $apiKey->permissions,
                'callback_url' => $apiKey->callback_url,
                'is_active' => $apiKey->is_active,
                'last_used_at' => $apiKey->last_used_at?->toISOString(),
                'expires_at' => $apiKey->expires_at?->toISOString(),
                'created_at' => $apiKey->created_at->toISOString(),
                'has_api_key' => ! empty($apiKey->btcpay_api_key),
            ],
        ]);
    }

    /**
     * Delete (revoke) an API key.
     */
    public function destroy(Request $request, Store $store, StoreApiKey $apiKey)
    {

        try {
            $this->storeApiKeyService->revokeApiKey($apiKey);

            Log::info('Store API key revoked via controller', [
                'store_id' => $store->id,
                'api_key_id' => $apiKey->id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'API key revoked successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to revoke store API key', [
                'store_id' => $store->id,
                'api_key_id' => $apiKey->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenerate an API key.
     */
    public function regenerate(Request $request, Store $store, StoreApiKey $apiKey)
    {

        $validated = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
            'label' => ['nullable', 'string', 'max:255'],
            'callback_url' => ['nullable', 'url', 'max:500'],
        ]);

        try {
            $newApiKey = $this->storeApiKeyService->regenerateApiKey(
                $apiKey,
                $validated['permissions'] ?? [],
                $validated['label'] ?? null,
                $validated['callback_url'] ?? null
            );

            Log::info('Store API key regenerated via controller', [
                'store_id' => $store->id,
                'old_api_key_id' => $apiKey->id,
                'new_api_key_id' => $newApiKey->id,
                'user_id' => auth()->id(),
            ]);

            // Return the new API key only once
            return response()->json([
                'data' => [
                    'id' => $newApiKey->id,
                    'label' => $newApiKey->label,
                    'api_key' => $newApiKey->btcpay_api_key, // Return it once
                    'permissions' => $newApiKey->permissions,
                    'callback_url' => $newApiKey->callback_url,
                    'is_active' => $newApiKey->is_active,
                    'created_at' => $newApiKey->created_at->toISOString(),
                    'store_id' => $store->btcpay_store_id, // Include BTCPay Store ID
                ],
                'message' => 'API key regenerated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to regenerate store API key', [
                'store_id' => $store->id,
                'api_key_id' => $apiKey->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a one-time token for e-shop integration.
     */
    public function generateToken(Request $request, Store $store)
    {
        $validated = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
            'label' => ['nullable', 'string', 'max:255'],
            'expiration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], // Max 24 hours
        ]);

        $permissions = $validated['permissions'] ?? [];
        $label = $validated['label'] ?? 'E-shop Integration';
        $expirationMinutes = $validated['expiration_minutes'] ?? 60;

        $token = EshopIntegrationController::generateToken(
            $store->id,
            $permissions,
            $label,
            $expirationMinutes
        );

        Log::info('E-shop integration token generated', [
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'label' => $label,
            'expiration_minutes' => $expirationMinutes,
        ]);

        return response()->json([
            'data' => [
                'token' => $token,
                'panel_url' => config('app.url'),
                'api_endpoint' => config('app.url').'/api/public/eshop/token/'.$token,
                'expires_in_minutes' => $expirationMinutes,
            ],
            'message' => 'Token generated successfully',
        ]);
    }
}
