<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCreateRequest;
use App\Models\Store;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\StoreService;
use App\Services\StoreChecklistService;
use App\Services\StoreProvisioningService;
use App\Services\StoreResponseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
    public function __construct(
        protected StoreService $storeService,
        protected StoreProvisioningService $storeProvisioning,
        protected StoreResponseFormatter $formatter,
    ) {}

    /**
     * List all stores for the authenticated user.
     * Stores are loaded from BTCPay API, then merged with local metadata.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get local stores first - these are the source of truth for what stores belong to this user
        $localStores = Store::where('user_id', $user->id)
            ->with(['checklistItems', 'walletConnection'])
            ->get()
            ->keyBy('btcpay_store_id');

        // Try to load stores from BTCPay API if merchant has API key
        $btcpayStores = [];
        try {
            if ($user->btcpay_api_key) {
                // Load stores from BTCPay API using merchant token
                $btcpayStores = $this->storeService->listStores($user->btcpay_api_key);

                Log::info('BTCPay API returned stores', [
                    'user_id' => $user->id,
                    'btcpay_stores_count' => is_array($btcpayStores) ? count($btcpayStores) : 0,
                    'btcpay_store_ids' => is_array($btcpayStores) ? array_map(function ($s) {
                        return $s['id'] ?? $s['storeId'] ?? null;
                    }, $btcpayStores) : [],
                ]);
            } else {
                Log::info('User does not have BTCPay API key', [
                    'user_id' => $user->id,
                ]);
            }
        } catch (BtcPayException $e) {
            // If API fails, we'll use local stores only
            Log::warning('BTCPay API failed when listing stores', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
        }

        Log::info('Store listing summary', [
            'user_id' => $user->id,
            'local_stores_count' => $localStores->count(),
            'btcpay_stores_count' => count($btcpayStores),
            'local_store_ids' => $localStores->keys()->toArray(),
        ]);

        // Only return stores that exist in BOTH local DB AND BTCPay Server
        // Store must be in local DB (metadata) AND merchant must have access via BTCPay API
        if (empty($btcpayStores)) {
            // If no BTCPay stores returned (no API key or API failed), return empty array
            // Store must exist on BTCPay server to be visible
            $stores = collect([]);
        } else {
            // Filter local stores to only include those that exist in BTCPay API response
            $btcpayStoreIds = collect($btcpayStores)->map(function ($bs) {
                return $bs['id'] ?? $bs['storeId'] ?? null;
            })->filter()->values()->toArray();

            Log::info('Filtering stores', [
                'user_id' => $user->id,
                'btcpay_store_ids' => $btcpayStoreIds,
                'local_stores_keys' => $localStores->keys()->toArray(),
            ]);

            $filteredStores = $localStores->filter(function ($localStore) use ($btcpayStoreIds, $user) {
                $matches = in_array($localStore->btcpay_store_id, $btcpayStoreIds);
                Log::info('Store filter check', [
                    'user_id' => $user->id,
                    'local_store_id' => $localStore->id,
                    'btcpay_store_id' => $localStore->btcpay_store_id,
                    'matches' => $matches,
                ]);

                return $matches;
            });

            Log::info('Filtered stores count', [
                'user_id' => $user->id,
                'filtered_count' => $filteredStores->count(),
            ]);

            $stores = $filteredStores->map(function ($localStore) use ($btcpayStores, $user) {
                // Find matching BTCPay store data
                $btcpayStore = collect($btcpayStores)->first(function ($bs) use ($localStore) {
                    $btcpayStoreId = $bs['id'] ?? $bs['storeId'] ?? null;

                    return $btcpayStoreId === $localStore->btcpay_store_id;
                });

                if (! $btcpayStore) {
                    Log::warning('BTCPay store not found for local store', [
                        'user_id' => $user->id,
                        'local_store_id' => $localStore->id,
                        'btcpay_store_id' => $localStore->btcpay_store_id,
                    ]);

                    return null; // Skip this store
                }

                try {
                    // Merge BTCPay data with local metadata
                    $formatted = $this->formatter->fromBtcPay($btcpayStore, $localStore);
                    Log::info('Store formatted successfully', [
                        'user_id' => $user->id,
                        'store_id' => $formatted['id'] ?? null,
                        'store_name' => $formatted['name'] ?? null,
                    ]);

                    return $formatted;
                } catch (\Exception $e) {
                    Log::error('Error formatting store', [
                        'user_id' => $user->id,
                        'local_store_id' => $localStore->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return null; // Skip this store
                }
            })->filter()->values(); // Filter out null values

            Log::info('Final stores count', [
                'user_id' => $user->id,
                'final_count' => $stores->count(),
            ]);
        }

        return response()->json(['data' => $stores]);
    }

    /**
     * Create a new store. Orchestration lives in StoreProvisioningService.
     */
    public function store(StoreCreateRequest $request)
    {
        $store = $this->storeProvisioning->create($request->user(), $request->validated());

        // The cached limits payload holds the store count - without this the
        // UI reports a stale count for up to a minute after the change.
        Cache::forget('user_limits_'.$request->user()->id);

        return response()->json([
            'data' => $this->formatter->fromLocal($store),
            'message' => __('messages.store_created'),
        ], 201);
    }

    /**
     * Set local wallet_type once (e.g. create-wizard step 2). SamRock works for Blink/unset stores too; Cashu is blocked in SamRockController.
     */
    public function setWalletType(Request $request, Store $store)
    {
        $validated = $request->validate([
            'wallet_type' => ['required', 'string', Rule::in(['blink', 'aqua_boltz', 'cashu', 'nwc', 'blitz', 'flash'])],
        ]);

        $next = $validated['wallet_type'];

        if ($store->wallet_type === $next) {
            StoreChecklistService::ensureChecklistInitialized($store);
            $store->load('checklistItems', 'walletConnection');

            return response()->json([
                'data' => $this->formatter->fromLocal($store),
            ]);
        }

        if ($store->wallet_type !== null) {
            abort(422, 'Wallet type is already set for this store.');
        }

        $store->update(['wallet_type' => $next]);
        StoreChecklistService::ensureChecklistInitialized($store);
        $store->load('checklistItems', 'walletConnection');

        // $store is current after update() + load() - fresh() would drop the loaded relations
        return response()->json([
            'data' => $this->formatter->fromLocal($store),
        ]);
    }

    /**
     * Get a specific store.
     * Store is loaded from BTCPay API, then merged with local metadata.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $store = $request->route('store'); // This is the local Store model from route binding

        try {
            // Load merchant API key from store owner
            $userApiKey = $store->user->getBtcPayApiKeyOrFail();

            // Load store from BTCPay API using merchant token
            $btcpayStore = $this->storeService->getStore($store->btcpay_store_id, $userApiKey);

            // Load local metadata
            $store->load('checklistItems', 'walletConnection');

            // Merge BTCPay data with local metadata
            return response()->json([
                'data' => $this->formatter->fromBtcPay($btcpayStore, $store),
            ]);
        } catch (BtcPayException $e) {
            // If API fails, return store from local DB as fallback
            Log::warning('BTCPay API failed when loading store, using local fallback', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'error' => $e->getMessage(),
            ]);

            $store->load('checklistItems');

            return response()->json(['data' => $this->formatter->fromLocal($store)]);
        }
    }

    /**
     * Delete a store.
     */
    public function destroy(Request $request, Store $store)
    {
        $user = $request->user();

        // Owner or support/admin can delete the store.
        if ($store->user_id !== $user->id && ! $user->isSupport()) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        $btcpayStoreId = $store->btcpay_store_id;
        $localStoreId = $store->id;

        try {
            // Delete store in BTCPay Server (DELETE /api/v1/stores/{storeId})
            // Uses server-level API key - user keys typically lack permission to delete stores
            try {
                Log::info('Attempting to delete store in BTCPay', [
                    'store_id' => $localStoreId,
                    'btcpay_store_id' => $btcpayStoreId,
                    'user_id' => $user->id,
                ]);

                $this->storeService->deleteStore($btcpayStoreId, null);

                Log::info('Store deleted in BTCPay', [
                    'store_id' => $localStoreId,
                    'btcpay_store_id' => $btcpayStoreId,
                ]);
            } catch (BtcPayException $e) {
                // If DELETE fails, log but continue - we'll still delete locally
                Log::warning('Failed to delete store in BTCPay', [
                    'store_id' => $localStoreId,
                    'btcpay_store_id' => $btcpayStoreId,
                    'error_code' => $e->getCode(),
                    'error_message' => $e->getMessage(),
                ]);
            }

            // Delete local record (this will cascade delete related records)
            $store->delete();

            // Same stale-count problem as in store(): a user who deletes a
            // store must be able to create a new one immediately.
            Cache::forget('user_limits_'.$user->id);

            Log::info('Store deleted from local database', [
                'store_id' => $localStoreId,
                'btcpay_store_id' => $btcpayStoreId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => __('messages.store_deleted'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete store', [
                'store_id' => $localStoreId,
                'btcpay_store_id' => $btcpayStoreId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            return response()->json(['message' => 'Failed to delete store: '.$e->getMessage()], 500);
        }
    }

    /**
     * Upload store logo.
     */
    public function uploadLogo(Request $request, Store $store)
    {
        $user = $request->user();

        // Ensure user owns the store
        if ($store->user_id !== $user->id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        $request->validate([
            'file' => 'required|image|max:2048', // Max 2MB
        ]);

        try {
            $userApiKey = $user->getBtcPayApiKeyOrFail();
            $result = $this->storeService->uploadLogo($store->btcpay_store_id, $request->file('file'), $userApiKey);

            Log::info('Store logo uploaded', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => __('messages.logo_uploaded'),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to upload store logo', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('messages.logo_upload_failed', ['error' => $e->getMessage()])], 500);
        }
    }

    /**
     * Delete store logo.
     */
    public function deleteLogo(Request $request, Store $store)
    {
        $user = $request->user();

        // Ensure user owns the store
        if ($store->user_id !== $user->id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        try {
            $userApiKey = $user->getBtcPayApiKeyOrFail();
            $this->storeService->deleteLogo($store->btcpay_store_id, $userApiKey);

            Log::info('Store logo deleted', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => __('messages.logo_deleted')]);
        } catch (\Exception $e) {
            Log::error('Failed to delete store logo', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('messages.logo_delete_failed', ['error' => $e->getMessage()])], 500);
        }
    }
}
