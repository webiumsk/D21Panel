<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\SepaService;
use App\Services\Invoicing\BankInboundAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SEPA Instant QR management - proxies the BTCPay plugin Greenfield API.
 *
 * Deliberately available to every role including guest accounts: accepting
 * instant SEPA payments to the merchant's own IBAN is a core free feature
 * (Slovak cashless mandate), so these routes carry no guest.restrict and no
 * plan middleware - only EnsureStoreOwnership.
 */
class SepaController extends Controller
{
    public function __construct(protected SepaService $sepaService) {}

    /**
     * Store-scoped b-mail inbound address: the merchant points the bank's
     * credit-notification e-mails here and pending SEPA requests confirm
     * automatically (amount-verified plugin report). Safe to expose
     * unconditionally - the channel can only settle a matching pending
     * reference with a matching amount.
     */
    public function inboundEmail(Store $store, BankInboundAddressService $addressService): JsonResponse
    {
        $enabled = (bool) config('bank_inbound.enabled', false);
        $address = null;
        if ($enabled) {
            // Token generation is a write - never do it while the channel is
            // disabled, and never let a misconfigured domain/prefix break
            // the SEPA page.
            try {
                $address = $addressService->buildStoreAddress($store);
            } catch (\InvalidArgumentException $e) {
                Log::error('SEPA b-mail address unavailable', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => [
            'enabled' => $enabled && $address !== null,
            'address' => $address,
        ]]);
    }

    public function status(Request $request, Store $store): JsonResponse
    {
        $userApiKey = $this->ownerApiKey($store);
        $cacheKey = "btcpay:sepa_probe:{$store->id}:".hash('sha256', $userApiKey);

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        try {
            $available = Cache::remember($cacheKey, 900, function () use ($store, $userApiKey) {
                return $this->sepaService->probe($store->btcpay_store_id, $userApiKey);
            });

            return response()->json(['data' => ['available' => (bool) $available]]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function getSettings(Store $store): JsonResponse
    {
        $userApiKey = $this->ownerApiKey($store);

        try {
            $settings = $this->sepaService->getSettings($store->btcpay_store_id, $userApiKey);

            return response()->json(['data' => $settings]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function updateSettings(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'country_profile' => 'required|string|in:SK,CZ,EU',
            'iban' => 'required|string|max:42',
            'beneficiary' => 'required|string|max:70',
            'bic' => 'nullable|string|max:11',
            'message' => 'nullable|string|max:60',
            'confirmation_backend' => 'required|string|in:manual,fio,nop-mqtt,nop-rest',
            'sk_qr_variant' => 'required|string|in:payme,bysquare',
            'checkout_confirm_enabled' => 'sometimes|boolean',
            'amount_tolerance' => 'required|numeric|min:0|max:10',
            'nop_environment' => 'nullable|string|in:INT,PROD',
        ]);

        $payload = [
            'enabled' => (bool) $validated['enabled'],
            'countryProfile' => $validated['country_profile'],
            'iban' => $validated['iban'],
            'beneficiary' => $validated['beneficiary'],
            'bic' => $validated['bic'] ?? null,
            'message' => $validated['message'] ?? null,
            'confirmationBackend' => $validated['confirmation_backend'],
            'skQrVariant' => $validated['sk_qr_variant'],
            'checkoutConfirmEnabled' => (bool) ($validated['checkout_confirm_enabled'] ?? false),
            'amountTolerance' => (float) $validated['amount_tolerance'],
        ];
        if (! empty($validated['nop_environment'])) {
            $payload['nopEnvironment'] = $validated['nop_environment'];
        }

        $userApiKey = $this->ownerApiKey($store);

        try {
            $settings = $this->sepaService->updateSettings($store->btcpay_store_id, $payload, $userApiKey);

            return response()->json(['data' => $settings]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    /**
     * eKasa certificate upload: the browser sends the file(s), satflux
     * relays the contents as the plugin's JSON shape. Nothing is persisted
     * on the satflux side - material goes straight to BTCPay.
     */
    public function uploadCertificate(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'pfx_file' => 'nullable|file|max:64',
            'pfx_password' => 'nullable|string|max:200',
            'cert_pem_file' => 'nullable|file|max:64',
            'key_pem_file' => 'nullable|file|max:64',
            'nop_environment' => 'nullable|string|in:INT,PROD',
        ]);

        $hasPfx = $request->hasFile('pfx_file');
        $hasPemPair = $request->hasFile('cert_pem_file') && $request->hasFile('key_pem_file');
        if (! $hasPfx && ! $hasPemPair) {
            return response()->json([
                'message' => __('messages.sepa_certificate_files_required'),
            ], 422);
        }

        $payload = [];
        if ($hasPfx) {
            $payload['pfxBase64'] = base64_encode((string) $request->file('pfx_file')->get());
            if (($validated['pfx_password'] ?? '') !== '') {
                $payload['pfxPassword'] = $validated['pfx_password'];
            }
        } else {
            $payload['certPem'] = (string) $request->file('cert_pem_file')->get();
            $payload['keyPem'] = (string) $request->file('key_pem_file')->get();
        }
        if (! empty($validated['nop_environment'])) {
            $payload['nopEnvironment'] = $validated['nop_environment'];
        }

        $userApiKey = $this->ownerApiKey($store);

        try {
            $settings = $this->sepaService->uploadCertificate($store->btcpay_store_id, $payload, $userApiKey);

            return response()->json(['data' => $settings]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function setFioToken(Request $request, Store $store): JsonResponse
    {
        // Normalize before validating so surrounding whitespace is tolerated
        // but anything that is not exactly a 64-char token never reaches the
        // plugin (Fio tokens are exactly 64 characters).
        $request->merge(['token' => trim((string) $request->input('token'))]);
        $validated = $request->validate([
            'token' => 'required|string|size:64',
        ]);

        $userApiKey = $this->ownerApiKey($store);

        try {
            $settings = $this->sepaService->setFioToken(
                $store->btcpay_store_id,
                ['token' => $validated['token']],
                $userApiKey
            );

            return response()->json(['data' => $settings]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function clearFioToken(Store $store): JsonResponse
    {
        $userApiKey = $this->ownerApiKey($store);

        try {
            $settings = $this->sepaService->clearFioToken($store->btcpay_store_id, $userApiKey);

            return response()->json(['data' => $settings]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function deleteCertificate(Store $store): JsonResponse
    {
        $userApiKey = $this->ownerApiKey($store);

        try {
            $settings = $this->sepaService->deleteCertificate($store->btcpay_store_id, $userApiKey);

            return response()->json(['data' => $settings]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function testBackend(Store $store): JsonResponse
    {
        $userApiKey = $this->ownerApiKey($store);

        try {
            $result = $this->sepaService->testBackend($store->btcpay_store_id, $userApiKey);

            return response()->json(['data' => $result]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function paymentRequests(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'state' => 'nullable|string|in:pending,review',
        ]);

        $userApiKey = $this->ownerApiKey($store);

        try {
            $rows = $this->sepaService->listPaymentRequests(
                $store->btcpay_store_id,
                $validated['state'] ?? null,
                $userApiKey
            );

            return response()->json(['data' => $rows]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    public function confirmPaymentRequest(Store $store, string $reference): JsonResponse
    {
        $userApiKey = $this->ownerApiKey($store);

        try {
            $result = $this->sepaService->confirmPaymentRequest($store->btcpay_store_id, $reference, $userApiKey);

            return response()->json(['data' => $result]);
        } catch (BtcPayException $e) {
            return $this->handleBtcPayError($e);
        }
    }

    private function ownerApiKey(Store $store): string
    {
        /** @var User $owner */
        $owner = $store->user;

        return $owner->getBtcPayApiKeyOrFail();
    }

    protected function handleBtcPayError(BtcPayException $e): JsonResponse
    {
        $statusCode = $e->getStatusCode() ?: 500;

        return response()->json(['message' => $e->getMessage()], $statusCode);
    }
}
