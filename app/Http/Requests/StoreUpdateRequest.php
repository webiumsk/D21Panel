<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     * Empty strings for URL fields fail the 'url' rule; convert to null.
     */
    protected function prepareForValidation(): void
    {
        $urlFields = ['website', 'support_url', 'logo_url', 'css_url', 'payment_sound_url'];
        $merge = [];
        foreach ($urlFields as $key) {
            if ($this->has($key) && $this->input($key) === '') {
                $merge[$key] = null;
            }
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'url', 'max:500'],
            'support_url' => ['nullable', 'string', 'max:500'],
            'logo_url' => ['nullable', 'string', 'url', 'max:500'],
            'css_url' => ['nullable', 'string', 'url', 'max:500'],
            'payment_sound_url' => ['nullable', 'string', 'url', 'max:500'],
            'brand_color' => ['nullable', 'string', 'max:20'],
            'apply_brand_color_to_backend' => ['nullable', 'boolean'],
            'default_currency' => ['required', 'string', 'max:10'],
            'additional_tracked_rates' => ['nullable', 'array'],
            'additional_tracked_rates.*' => ['string', 'max:10'],
            'invoice_expiration' => ['nullable', 'integer', 'min:1'],
            'refund_bolt11_expiration' => ['nullable', 'integer', 'min:1'],
            'display_expiration_timer' => ['nullable', 'integer', 'min:0'],
            'monitoring_expiration' => ['nullable', 'integer', 'min:0'],
            'speed_policy' => ['nullable', 'string', 'in:HighSpeed,MediumSpeed,LowSpeed'],
            'lightning_description_template' => ['nullable', 'string', 'max:500'],
            'payment_tolerance' => ['nullable', 'numeric', 'min:0'],
            'archived' => ['nullable', 'boolean'],
            'anyone_can_create_invoice' => ['nullable', 'boolean'],
            'receipt' => ['nullable', 'array'],
            'receipt.enabled' => ['nullable', 'boolean'],
            'receipt.showQR' => ['nullable', 'boolean'],
            'receipt.show_qr' => ['nullable', 'boolean'],
            'receipt.showPayments' => ['nullable', 'boolean'],
            'receipt.show_payments' => ['nullable', 'boolean'],
            'lightning_amount_in_satoshi' => ['nullable', 'boolean'],
            'lightning_private_route_hints' => ['nullable', 'boolean'],
            'on_chain_with_ln_invoice_fallback' => ['nullable', 'boolean'],
            'redirect_automatically' => ['nullable', 'boolean'],
            'show_recommended_fee' => ['nullable', 'boolean'],
            'recommended_fee_block_target' => ['nullable', 'integer', 'min:1'],
            'default_lang' => ['nullable', 'string', 'max:10'],
            'html_title' => ['nullable', 'string', 'max:255'],
            'network_fee_mode' => ['nullable', 'string', 'in:Always,MultiplePaymentsOnly,Never'],
            'pay_join_enabled' => ['nullable', 'boolean'],
            'auto_detect_language' => ['nullable', 'boolean'],
            'show_pay_in_wallet_button' => ['nullable', 'boolean'],
            'show_store_header' => ['nullable', 'boolean'],
            'celebrate_payment' => ['nullable', 'boolean'],
            'play_sound_on_payment' => ['nullable', 'boolean'],
            'lazy_payment_methods' => ['nullable', 'boolean'],
            'default_payment_method' => ['nullable', 'string', 'max:50'],
            'payment_method_criteria' => ['nullable', 'array'],
            'timezone' => ['required', 'string', 'timezone'],
            'preferred_exchange' => ['nullable', 'string', 'max:255'],
            'lnurl_enabled' => ['nullable', 'boolean'],
            'lnurl_classic_mode' => ['nullable', 'boolean'],
            'lnurl_allow_payee_comment' => ['nullable', 'boolean'],
        ];
    }
}
