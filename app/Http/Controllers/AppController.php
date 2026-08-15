<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\Store;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\StoreAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppController extends Controller
{
    public function __construct(protected StoreAppService $storeApps) {}

    /**
     * List all apps for a store.
     */
    public function index(Request $request, Store $store)
    {
        return response()->json(['data' => $this->storeApps->listForStore($store)]);
    }

    /**
     * Create a new app.
     */
    public function store(Request $request, Store $store)
    {
        $request->validate([
            'app_type' => ['required', 'string', 'in:PointOfSale,Crowdfund,PaymentButton,LightningAddress,Tickets'],
            'name' => ['required', 'string', 'max:255'],
            'config' => ['sometimes', 'array'],
        ]);

        // Tickets are a store-level feature (like LN Address): use /stores/{store}/tickets, not apps.
        if ($request->app_type === 'Tickets') {
            return response()->json([
                'message' => 'Tickets are managed per store. Use the Tickets section in the store sidebar.',
            ], 400);
        }

        $owner = $store->user;
        if ($owner && (bool) ($owner->is_guest ?? false)) {
            if ($request->app_type !== 'PointOfSale') {
                return response()->json([
                    'message' => __('auth.guest_feature_requires_account'),
                    'code' => 'guest_feature_locked',
                ], 403);
            }
            if ($this->storeApps->countActivePointOfSaleApps($store) >= 1) {
                return response()->json([
                    'message' => __('auth.guest_pos_limit_one'),
                    'code' => 'guest_pos_limit',
                ], 403);
            }
        }

        $data = $this->storeApps->create($store, $request->app_type, $request->name, $request->config ?? []);

        return response()->json([
            'data' => $data,
            'message' => 'App created successfully',
        ], 201);
    }

    /**
     * Get a specific app.
     */
    public function show(Request $request, Store $store, App $app)
    {
        // Belongs-to first (foreign apps stay 404), then the guest gate -
        // both before the BTCPay fetch so rejected requests cost no API call.
        if ($app->store_id !== $store->id) {
            return response()->json(['message' => 'App not found for this store.'], 404);
        }
        if ($guestGate = $this->guestGateResponse($store, $app)) {
            return $guestGate;
        }

        $data = $this->storeApps->getForStore($store, $app);
        if ($data === null) {
            return response()->json(['message' => 'App not found for this store.'], 404);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Update app settings.
     */
    public function update(Request $request, Store $store, App $app)
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'config' => ['sometimes', 'array'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        if ($app->store_id !== $store->id) {
            return response()->json(['message' => 'App not found for this store.'], 404);
        }
        if ($guestGate = $this->guestGateResponse($store, $app)) {
            return $guestGate;
        }

        $name = $request->has('name') ? $request->input('name') : null;
        $requestConfig = $request->has('config') ? $request->input('config') : null;

        // Legacy records without btcpay_app_id: create the app in BTCPay first
        if (! $app->btcpay_app_id) {
            return response()->json([
                'data' => $this->storeApps->createInBtcPayForLegacyApp($store, $app, $name, $requestConfig),
                'message' => 'App created and updated successfully',
            ]);
        }

        // Greenfield has no PUT for Payment Button apps; only local archived flag is supported here.
        if (strtolower($app->app_type) === 'paymentbutton') {
            return $this->updatePaymentButton($request, $app);
        }

        if (! $this->storeApps->recoverMissingBtcpayAppId($store, $app)) {
            return response()->json([
                'message' => 'Cannot update app: BTCPay app ID is missing. Please contact support.',
            ], 400);
        }

        $archived = null;
        if ($request->has('archived')) {
            $archived = filter_var($request->input('archived'), FILTER_VALIDATE_BOOLEAN);
        } elseif ($request->has('config.archived')) {
            $archived = filter_var($request->input('config.archived'), FILTER_VALIDATE_BOOLEAN);
        }

        $config = $this->storeApps->buildUpdateConfig($app, $requestConfig, $name, $archived);

        return response()->json([
            'data' => $this->storeApps->update($store, $app, $config, $name),
            'message' => 'App updated successfully',
        ]);
    }

    /**
     * Delete an app.
     */
    public function destroy(Request $request, Store $store, App $app)
    {
        if ($app->store_id !== $store->id) {
            return response()->json(['message' => 'App not found for this store.'], 404);
        }
        if ($guestGate = $this->guestGateResponse($store, $app)) {
            return $guestGate;
        }

        try {
            $this->storeApps->delete($store, $app);

            return response()->json(['message' => 'App deleted successfully']);
        } catch (BtcPayException $e) {
            // BTCPay deletion failed - the local record is kept
            Log::error('Failed to delete app from BTCPay', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'app_id' => $app->id,
                'btcpay_app_id' => $app->btcpay_app_id,
                'app_type' => $app->app_type,
                'error' => $e->getMessage(),
                'status_code' => $e->getCode(),
            ]);

            return response()->json([
                'message' => 'Failed to delete app from BTCPay: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Guests may only touch PointOfSale apps.
     */
    protected function guestGateResponse(Store $store, App $app): ?JsonResponse
    {
        if ($store->user && (bool) ($store->user->is_guest ?? false) && $app->app_type !== 'PointOfSale') {
            return response()->json([
                'message' => __('auth.guest_feature_requires_account'),
                'code' => 'guest_feature_locked',
            ], 403);
        }

        return null;
    }

    /**
     * Payment Button update: no Greenfield PUT exists, only the local archived flag.
     */
    protected function updatePaymentButton(Request $request, App $app)
    {
        $configPayload = $request->input('config');
        $hasConfigBody = is_array($configPayload) && count($configPayload) > 0;
        if ($request->filled('name') || $hasConfigBody) {
            return response()->json([
                'message' => 'BTCPay Greenfield API does not expose PUT for Payment Button apps. Change settings in BTCPay Server UI or recreate the app.',
            ], 422);
        }
        if (! $request->has('archived')) {
            return response()->json([
                'message' => 'No updatable fields provided for this app type.',
            ], 422);
        }

        $archived = filter_var($request->input('archived'), FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'data' => $this->storeApps->archivePaymentButtonLocally($app, $archived),
            'message' => 'App updated successfully',
        ]);
    }
}
