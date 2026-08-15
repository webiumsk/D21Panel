<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {}

    /**
     * Get Stripe settings for a store.
     */
    public function getSettings(Request $request, Store $store): JsonResponse
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        try {
            $settings = $this->stripeService->getSettings($store->btcpay_store_id, $userApiKey);

            return response()->json($settings);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e, 'get Stripe settings');
        }
    }

    /**
     * Update Stripe settings.
     */
    public function updateSettings(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'publishableKey' => ['nullable', 'string'],
            'secretKey' => ['nullable', 'string'],
            'settlementCurrency' => ['nullable', 'string', 'max:10'],
            'advancedConfig' => ['nullable', 'string'],
            'webhookSigningSecret' => ['nullable', 'string'],
        ]);

        // Build payload - only include non-null, non-empty values (unchanged fields stay)
        $payload = array_filter($validated, fn ($v) => $v !== null && $v !== '');
        if (isset($payload['advancedConfig']) && $payload['advancedConfig'] === '') {
            unset($payload['advancedConfig']);
        }

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        try {
            $settings = $this->stripeService->updateSettings($store->btcpay_store_id, $payload, $userApiKey);

            return response()->json($settings);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e, 'update Stripe settings', true);
        }
    }

    /**
     * Delete Stripe credentials.
     */
    public function deleteSettings(Request $request, Store $store): JsonResponse
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        try {
            $this->stripeService->deleteSettings($store->btcpay_store_id, $userApiKey);

            return response()->json([], 200);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e, 'delete Stripe credentials');
        }
    }

    /**
     * Test Stripe connection.
     */
    public function testConnection(Request $request, Store $store): JsonResponse
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        try {
            $result = $this->stripeService->testConnection($store->btcpay_store_id, $userApiKey);

            return response()->json($result);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e, 'test Stripe connection');
        }
    }

    /**
     * Register Stripe webhook.
     */
    public function registerWebhook(Request $request, Store $store): JsonResponse
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        try {
            $result = $this->stripeService->registerWebhook($store->btcpay_store_id, $userApiKey);

            return response()->json($result);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e, 'register Stripe webhook');
        }
    }

    /**
     * Get webhook status.
     */
    public function getWebhookStatus(Request $request, Store $store): JsonResponse
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        try {
            $status = $this->stripeService->getWebhookStatus($store->btcpay_store_id, $userApiKey);

            return response()->json($status);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e, 'get webhook status');
        }
    }

    protected function handleBtcPayError(
        BtcPayException $e,
        string $action,
        bool $isValidation = false
    ): JsonResponse {
        $statusCode = $e->getStatusCode() ?: 500;
        $message = $e->getMessage();

        // For 422, BTCPay may return array of validation errors
        if ($statusCode === 422 && $isValidation) {
            try {
                $decoded = json_decode($message, true);
                if (is_array($decoded)) {
                    return response()->json(['errors' => $decoded, 'message' => $message], 422);
                }
            } catch (\Throwable $t) {
                // Fall through to generic response
            }
        }

        return response()->json(['message' => $message], $statusCode);
    }
}
