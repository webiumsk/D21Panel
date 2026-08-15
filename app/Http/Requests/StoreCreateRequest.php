<?php

namespace App\Http\Requests;

use App\Services\WalletConnectionValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
            'default_currency' => ['required', 'string', 'max:10'], // Allow BTC, SATS, and 3-letter codes
            'timezone' => ['required', 'string', 'timezone'],
            'preferred_exchange' => ['nullable', 'string', 'max:255'],
            // Omit on first-step create; set later when user picks Blink / Aqua / Cashu / Blitz.
            'wallet_type' => ['nullable', 'string', Rule::in(['blink', 'aqua_boltz', 'cashu', 'nwc', 'blitz', 'flash', 'lnaddress'])],

            // Blink/Aqua/Blitz/LN-address wallet connection string.
            'connection_string' => [
                'nullable',
                'string',
                'max:2000',
                // Only when configuring a connection during create; Cashu uses mint/LN fields.
                'prohibited_unless:wallet_type,blink,aqua_boltz,blitz,flash,lnaddress',
            ],

            // Optional CashuMelt fallback address for Lightning wallet types.
            'fallback_lightning_address' => ['nullable', 'string', 'max:320', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/'],

            // Cashu plugin settings.
            'mint_url' => ['required_if:wallet_type,cashu', 'string', 'url', 'starts_with:https://'],
            'lightning_address' => ['required_if:wallet_type,cashu', 'string', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // If connection_string is provided, validate it matches the wallet_type
            if ($this->filled('connection_string') && in_array($this->wallet_type, ['blink', 'aqua_boltz', 'blitz', 'flash', 'lnaddress'], true)) {
                $connectionString = $this->connection_string;
                $walletType = $this->wallet_type;

                $connectionValidator = app(WalletConnectionValidator::class);

                if ($walletType === 'blink') {
                    // Validate Blink connection string format
                    $validation = $connectionValidator->validate('blink', $connectionString);
                    if (! $validation['valid']) {
                        $errors = $validation['errors'] ?? ['Invalid Blink connection string format. Expected: type=blink;server=https://...;api-key=...;wallet-id=...'];
                        foreach ($errors as $error) {
                            $validator->errors()->add('connection_string', $error);
                        }
                    }
                } elseif ($walletType === 'aqua_boltz') {
                    // Validate Aqua descriptor format
                    $validation = $connectionValidator->validate('aqua_descriptor', $connectionString);
                    if (! $validation['valid']) {
                        $errors = $validation['errors'] ?? ['Invalid descriptor format. Must be a valid Aqua wallet output descriptor (e.g., wpkh(), tr(), wsh(), or complex formats like ct(slip77(...),elsh(wpkh(...)))) and must not contain private keys.'];
                        foreach ($errors as $error) {
                            $validator->errors()->add('connection_string', $error);
                        }
                    }
                } else {
                    // blitz, flash or lnaddress - the in_array gate above leaves no other values.
                    $validation = $connectionValidator->validate($walletType, $connectionString);
                    if (! $validation['valid']) {
                        foreach ($validation['errors'] as $error) {
                            $validator->errors()->add('connection_string', $error);
                        }
                    }
                }
            }
        });
    }
}
