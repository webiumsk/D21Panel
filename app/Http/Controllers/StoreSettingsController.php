<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpdateRequest;
use App\Services\BtcPay\StoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StoreSettingsController extends Controller
{
    protected StoreService $storeService;

    public function __construct(StoreService $storeService)
    {
        $this->storeService = $storeService;
    }

    /**
     * Get store settings.
     */
    public function show(Request $request)
    {
        $store = $request->route('store');

        // Load merchant API key from store owner
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        // Clear cache before fetching to ensure we get the latest data
        $apiKeyHash = md5($userApiKey);
        $cacheKey = "btcpay:store:{$store->btcpay_store_id}:{$apiKeyHash}";
        Cache::forget($cacheKey);

        // Get store data from BTCPay using merchant token
        $btcpayStore = $this->storeService->getStore($store->btcpay_store_id, $userApiKey);

        $data = $this->mapBtcPayStoreToResponse($btcpayStore, $store);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Map BTCPay store (camelCase) to frontend response (snake_case).
     */
    protected function mapBtcPayStoreToResponse(array $btcpayStore, $store): array
    {
        $get = fn (string $camel, $default = null) => $btcpayStore[$camel] ?? $default;

        return [
            'id' => $get('id'),
            'name' => $store->name,
            'website' => $get('website'),
            'support_url' => $get('supportUrl'),
            'logo_url' => $get('logoUrl') ?? $get('imageUrl'),
            'css_url' => $get('cssUrl'),
            'payment_sound_url' => $get('paymentSoundUrl'),
            'brand_color' => $get('brandColor'),
            'apply_brand_color_to_backend' => $get('applyBrandColorToBackend', false),
            'default_currency' => $get('defaultCurrency', 'USD'),
            'additional_tracked_rates' => $get('additionalTrackedRates', []),
            'invoice_expiration' => $get('invoiceExpiration'),
            'refund_bolt11_expiration' => $get('refundBOLT11Expiration'),
            'display_expiration_timer' => $get('displayExpirationTimer'),
            'monitoring_expiration' => $get('monitoringExpiration'),
            'speed_policy' => $get('speedPolicy'),
            'lightning_description_template' => $get('lightningDescriptionTemplate'),
            'payment_tolerance' => $get('paymentTolerance'),
            'archived' => $get('archived', false),
            'anyone_can_create_invoice' => $get('anyoneCanCreateInvoice', false),
            'receipt' => $this->normalizeReceipt($get('receipt')),
            'lightning_amount_in_satoshi' => $get('lightningAmountInSatoshi', false),
            'lightning_private_route_hints' => $get('lightningPrivateRouteHints', false),
            'on_chain_with_ln_invoice_fallback' => $get('onChainWithLnInvoiceFallback', false),
            'redirect_automatically' => $get('redirectAutomatically', false),
            'show_recommended_fee' => $get('showRecommendedFee', true),
            'recommended_fee_block_target' => $get('recommendedFeeBlockTarget', 1),
            'default_lang' => $get('defaultLang', 'en'),
            'html_title' => $get('htmlTitle'),
            'network_fee_mode' => $get('networkFeeMode'),
            'pay_join_enabled' => $get('payJoinEnabled', false),
            'auto_detect_language' => $get('autoDetectLanguage', false),
            'show_pay_in_wallet_button' => $get('showPayInWalletButton', true),
            'show_store_header' => $get('showStoreHeader', true),
            'celebrate_payment' => $get('celebratePayment', true),
            'play_sound_on_payment' => $get('playSoundOnPayment', false),
            'lazy_payment_methods' => $get('lazyPaymentMethods', false),
            'default_payment_method' => $get('defaultPaymentMethod'),
            'payment_method_criteria' => $get('paymentMethodCriteria', []),
            'timezone' => $get('timeZone', $get('timezone', 'UTC')),
            'preferred_exchange' => $get('preferredExchange', $get('preferred_exchange')),
            'store_url' => rtrim(config('app.url', ''), '/').'/stores/'.$store->id,
            // LNURL: default enabled so Lightning Addresses work; BTCPay may expose lnurlEnabled, lnurlClassicMode, allowPayeeToPassComment
            'lnurl_enabled' => $get('lnurlEnabled', true),
            'lnurl_classic_mode' => $get('lnurlClassicMode', true),
            'lnurl_allow_payee_comment' => $get('allowPayeeToPassComment', true),
        ];
    }

    protected function normalizeReceipt($receipt): array
    {
        if (! is_array($receipt)) {
            return ['enabled' => true, 'show_qr' => null, 'show_payments' => null];
        }

        return [
            'enabled' => $receipt['enabled'] ?? true,
            'show_qr' => $receipt['showQR'] ?? $receipt['show_qr'] ?? null,
            'show_payments' => $receipt['showPayments'] ?? $receipt['show_payments'] ?? null,
        ];
    }

    /**
     * Update store settings.
     */
    public function update(StoreUpdateRequest $request)
    {
        $store = $request->route('store');

        // Load merchant API key from store owner
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $updateData = $this->buildBtcPayUpdatePayload($request);

        $this->storeService->updateStore($store->btcpay_store_id, $updateData, $userApiKey);

        // Update local record
        $store->update([
            'name' => $request->name,
        ]);

        // Build response from request so the client gets the updated values without a second API call
        $data = $this->buildResponseFromRequest($request, $store->fresh());

        return response()->json([
            'data' => $data,
            'message' => 'Store settings updated successfully',
        ]);
    }

    /**
     * Build response shape from request (after update).
     */
    protected function buildResponseFromRequest(StoreUpdateRequest $request, $store): array
    {
        $receipt = $request->input('receipt');
        $receiptNormalized = is_array($receipt)
            ? [
                'enabled' => $receipt['enabled'] ?? true,
                'show_qr' => $receipt['show_qr'] ?? $receipt['showQR'] ?? null,
                'show_payments' => $receipt['show_payments'] ?? $receipt['showPayments'] ?? null,
            ]
            : ['enabled' => true, 'show_qr' => null, 'show_payments' => null];

        return [
            'id' => $store->btcpay_store_id,
            'name' => $request->name,
            'website' => $request->input('website'),
            'support_url' => $request->input('support_url'),
            'logo_url' => $request->input('logo_url'),
            'css_url' => $request->input('css_url'),
            'payment_sound_url' => $request->input('payment_sound_url'),
            'brand_color' => $request->input('brand_color'),
            'apply_brand_color_to_backend' => $request->boolean('apply_brand_color_to_backend'),
            'default_currency' => $request->default_currency,
            'additional_tracked_rates' => $request->input('additional_tracked_rates', []),
            'invoice_expiration' => $request->input('invoice_expiration'),
            'refund_bolt11_expiration' => $request->input('refund_bolt11_expiration'),
            'display_expiration_timer' => $request->input('display_expiration_timer'),
            'monitoring_expiration' => $request->input('monitoring_expiration'),
            'speed_policy' => $request->input('speed_policy'),
            'lightning_description_template' => $request->input('lightning_description_template'),
            'payment_tolerance' => $request->input('payment_tolerance'),
            'archived' => $request->boolean('archived'),
            'anyone_can_create_invoice' => $request->boolean('anyone_can_create_invoice'),
            'receipt' => $receiptNormalized,
            'lightning_amount_in_satoshi' => $request->boolean('lightning_amount_in_satoshi'),
            'lightning_private_route_hints' => $request->boolean('lightning_private_route_hints'),
            'on_chain_with_ln_invoice_fallback' => $request->boolean('on_chain_with_ln_invoice_fallback'),
            'redirect_automatically' => $request->boolean('redirect_automatically'),
            'show_recommended_fee' => $request->boolean('show_recommended_fee'),
            'recommended_fee_block_target' => $request->input('recommended_fee_block_target'),
            'default_lang' => $request->input('default_lang'),
            'html_title' => $request->input('html_title'),
            'network_fee_mode' => $request->input('network_fee_mode'),
            'pay_join_enabled' => $request->boolean('pay_join_enabled'),
            'auto_detect_language' => $request->boolean('auto_detect_language'),
            'show_pay_in_wallet_button' => $request->boolean('show_pay_in_wallet_button'),
            'show_store_header' => $request->boolean('show_store_header'),
            'celebrate_payment' => $request->boolean('celebrate_payment'),
            'play_sound_on_payment' => $request->boolean('play_sound_on_payment'),
            'lazy_payment_methods' => $request->boolean('lazy_payment_methods'),
            'default_payment_method' => $request->input('default_payment_method'),
            'payment_method_criteria' => $request->input('payment_method_criteria', []),
            'timezone' => $request->timezone,
            'preferred_exchange' => $request->input('preferred_exchange'),
            'store_url' => rtrim(config('app.url', ''), '/').'/stores/'.$store->id,
            'lnurl_enabled' => $request->boolean('lnurl_enabled'),
            'lnurl_classic_mode' => $request->boolean('lnurl_classic_mode'),
            'lnurl_allow_payee_comment' => $request->boolean('lnurl_allow_payee_comment'),
        ];
    }

    /**
     * Build BTCPay store update payload (camelCase) from request (snake_case).
     */
    protected function buildBtcPayUpdatePayload(StoreUpdateRequest $request): array
    {
        $map = [
            'name' => 'name',
            'website' => 'website',
            'support_url' => 'supportUrl',
            'logo_url' => 'logoUrl',
            'css_url' => 'cssUrl',
            'payment_sound_url' => 'paymentSoundUrl',
            'brand_color' => 'brandColor',
            'apply_brand_color_to_backend' => 'applyBrandColorToBackend',
            'default_currency' => 'defaultCurrency',
            'additional_tracked_rates' => 'additionalTrackedRates',
            'invoice_expiration' => 'invoiceExpiration',
            'refund_bolt11_expiration' => 'refundBOLT11Expiration',
            'display_expiration_timer' => 'displayExpirationTimer',
            'monitoring_expiration' => 'monitoringExpiration',
            'speed_policy' => 'speedPolicy',
            'lightning_description_template' => 'lightningDescriptionTemplate',
            'payment_tolerance' => 'paymentTolerance',
            'archived' => 'archived',
            'anyone_can_create_invoice' => 'anyoneCanCreateInvoice',
            'receipt' => 'receipt',
            'lightning_amount_in_satoshi' => 'lightningAmountInSatoshi',
            'lightning_private_route_hints' => 'lightningPrivateRouteHints',
            'on_chain_with_ln_invoice_fallback' => 'onChainWithLnInvoiceFallback',
            'redirect_automatically' => 'redirectAutomatically',
            'show_recommended_fee' => 'showRecommendedFee',
            'recommended_fee_block_target' => 'recommendedFeeBlockTarget',
            'default_lang' => 'defaultLang',
            'html_title' => 'htmlTitle',
            'network_fee_mode' => 'networkFeeMode',
            'pay_join_enabled' => 'payJoinEnabled',
            'auto_detect_language' => 'autoDetectLanguage',
            'show_pay_in_wallet_button' => 'showPayInWalletButton',
            'show_store_header' => 'showStoreHeader',
            'celebrate_payment' => 'celebratePayment',
            'play_sound_on_payment' => 'playSoundOnPayment',
            'lazy_payment_methods' => 'lazyPaymentMethods',
            'default_payment_method' => 'defaultPaymentMethod',
            'payment_method_criteria' => 'paymentMethodCriteria',
            'timezone' => 'timeZone',
            'preferred_exchange' => 'preferredExchange',
            'lnurl_enabled' => 'lnurlEnabled',
            'lnurl_classic_mode' => 'lnurlClassicMode',
            'lnurl_allow_payee_comment' => 'allowPayeeToPassComment',
        ];

        $payload = [];
        foreach ($map as $snake => $camel) {
            if (! $request->has($snake)) {
                continue;
            }
            $value = $request->input($snake);
            if ($snake === 'receipt' && is_array($value)) {
                $payload[$camel] = [
                    'enabled' => $value['enabled'] ?? true,
                    'showQR' => $value['show_qr'] ?? $value['showQR'] ?? null,
                    'showPayments' => $value['show_payments'] ?? $value['showPayments'] ?? null,
                ];

                continue;
            }
            if ($snake === 'payment_method_criteria' && is_array($value)) {
                $criteria = $this->mapPaymentMethodCriteriaForBtcPay($value, $request->input('default_currency', 'USD'));
                if (! empty($criteria)) {
                    $payload[$camel] = $criteria;
                }

                continue;
            }
            if ($snake === 'additional_tracked_rates') {
                $payload[$camel] = is_array($value) ? $value : (is_string($value) ? array_map('trim', explode(',', $value)) : []);

                continue;
            }
            $payload[$camel] = $value;
        }

        return $payload;
    }

    /**
     * Map frontend payment_method_criteria to BTCPay API format.
     * BTCPay expects: PaymentMethodId, CurrencyCode, Amount, Above.
     * Skips entries with empty value or invalid format.
     */
    protected function mapPaymentMethodCriteriaForBtcPay(array $items, string $defaultCurrency): array
    {
        $paymentMethodIdMap = [
            'BTC-LN' => 'BTC_LightningNetwork',
            'BTC-LNURL' => 'BTC_LightningNetwork',
            'BTC-CHAIN' => 'BTC',
            'BTC' => 'BTC',
            'BTC_LightningNetwork' => 'BTC_LightningNetwork',
        ];

        $result = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rawMethod = $item['paymentMethod'] ?? $item['payment_method'] ?? null;
            $paymentMethodId = $paymentMethodIdMap[$rawMethod] ?? $rawMethod;
            if (empty($paymentMethodId)) {
                continue;
            }
            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            // Parse "6.15 USD" or "100" (use default currency)
            if (preg_match('/^([\d.,]+)\s*([A-Z]{3})$/i', $value, $m)) {
                $amount = (float) str_replace(',', '.', $m[1]);
                $currencyCode = strtoupper($m[2]);
            } elseif (preg_match('/^[\d.,]+$/', $value)) {
                $amount = (float) str_replace(',', '.', $value);
                $currencyCode = $defaultCurrency;
            } else {
                continue;
            }
            $type = $item['type'] ?? 'GreaterThan';
            $above = strtolower($type) === 'greaterthan';

            $result[] = [
                'PaymentMethodId' => $paymentMethodId,
                'CurrencyCode' => $currencyCode,
                'Amount' => $amount,
                'Above' => $above,
            ];
        }

        return $result;
    }
}
