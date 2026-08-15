<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Unified WooCommerce plugin connect flow.
 * GET /woocommerce/connect?return_url=...
 */
class WooCommerceConnectController extends Controller
{
    public function __construct(
        protected SubscriptionEntitlementService $subscriptionService,
    ) {}

    public function connect(Request $request)
    {
        $validated = $request->validate([
            'return_url' => ['required', 'url', 'max:2000'],
        ]);

        $returnUrl = $validated['return_url'];
        $parsed = parse_url($returnUrl);
        if (($parsed['scheme'] ?? '') !== 'https') {
            throw ValidationException::withMessages([
                'return_url' => ['return_url must use HTTPS'],
            ]);
        }

        $user = $request->user();

        try {
            $user->getBtcPayApiKeyOrFail();
        } catch (\Throwable $e) {
            Log::warning('WooCommerce connect: user has no BTCPay API key', ['user_id' => $user->id]);

            return $this->redirectWithError($returnUrl, 'btcpay_not_configured');
        }

        $returnSatfluxStoreId = $request->query('return_satflux_store_id') === '1';
        $stores = $user->stores()->with('company')->orderBy('name')->get();

        if ($stores->isEmpty()) {
            return $this->redirectWithError($returnUrl, 'no_stores');
        }

        if ($stores->count() === 1) {
            return $this->redirectToReturnUrl($returnUrl, $stores->first(), $user, $returnSatfluxStoreId);
        }

        $storeId = $request->query('store_id');
        if ($storeId) {
            $store = $stores->firstWhere('id', $storeId);
            if ($store) {
                return $this->redirectToReturnUrl($returnUrl, $store, $user, $returnSatfluxStoreId);
            }
        }

        return view('woocommerce.connect', [
            'stores' => $stores,
            'returnUrl' => $returnUrl,
            'returnSatfluxStoreId' => $returnSatfluxStoreId,
        ]);
    }

    public function selectStore(Request $request)
    {
        $validated = $request->validate([
            'return_url' => ['required', 'url', 'max:2000'],
            'store_id' => ['required', 'uuid', 'exists:stores,id'],
            'return_satflux_store_id' => ['sometimes', 'in:0,1'],
        ]);

        $returnUrl = $validated['return_url'];
        $parsed = parse_url($returnUrl);
        if (($parsed['scheme'] ?? '') !== 'https') {
            throw ValidationException::withMessages([
                'return_url' => ['return_url must use HTTPS'],
            ]);
        }

        $store = Store::findOrFail($validated['store_id']);
        if ($store->user_id !== $request->user()->id) {
            abort(403);
        }

        $returnSatfluxStoreId = ($validated['return_satflux_store_id'] ?? '0') === '1';

        return $this->redirectToReturnUrl($returnUrl, $store, $request->user(), $returnSatfluxStoreId);
    }

    /**
     * One-time exchange for WooCommerce OAuth connect (avoids long query strings).
     * GET /api/integrations/woocommerce/oauth-exchange?code=...
     */
    public function exchangeConnectCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:64'],
        ]);

        $cacheKey = 'woocommerce_connect_'.$validated['code'];
        $payload = Cache::pull($cacheKey);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Connect code invalid or expired'], 404);
        }

        return response()->json(['data' => $payload]);
    }

    private function redirectToReturnUrl(string $returnUrl, Store $store, $user, bool $includeSatfluxStoreId = false): RedirectResponse
    {
        $btcpayUrl = rtrim((string) config('services.btcpay.base_url'), '/');
        $apiKey = $user->getBtcPayApiKeyOrFail();

        $webhookUrl = $this->siteBaseFromReturnUrl($returnUrl);
        if ($webhookUrl) {
            $webhookUrl = rtrim($webhookUrl, '/').'/wp-json/satflux/v1/invoicing-webhook';
        }

        $credentials = StoreIntegration::createForStore($store, $webhookUrl);
        $invoicingEnabled = $store->company_id && $this->subscriptionService->canUseBusinessInvoicing($user);

        $payload = [
            'btcpay_url' => $btcpayUrl,
            'api_key' => $apiKey,
            'store_id' => $store->btcpay_store_id,
            'integration_token' => $credentials['token'],
            'integration_secret' => $credentials['secret'],
            'invoicing_enabled' => $invoicingEnabled ? '1' : '0',
        ];

        if ($includeSatfluxStoreId) {
            $payload['satflux_store_id'] = $store->id;
        }
        if ($store->company_id) {
            $payload['satflux_company_id'] = $store->company_id;
        }

        $code = bin2hex(random_bytes(32));
        Cache::put('woocommerce_connect_'.$code, $payload, now()->addMinutes(10));

        $queryString = http_build_query([
            'satflux_return' => '1',
            'satflux_connect_code' => $code,
        ]);
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        Log::info('WooCommerce connect: redirecting to return_url with connect code', [
            'user_id' => $user->id,
            'store_id' => $store->id,
        ]);

        return redirect()->away($returnUrl.$separator.$queryString);
    }

    private function redirectWithError(string $returnUrl, string $errorCode): RedirectResponse
    {
        $params = http_build_query([
            'satflux_return' => '1',
            'error' => $errorCode,
        ]);
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return redirect()->away($returnUrl.$separator.$params);
    }

    private function siteBaseFromReturnUrl(string $returnUrl): ?string
    {
        $parsed = parse_url($returnUrl);
        if (! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $parsed['scheme'].'://'.$parsed['host'].$port;
    }
}
