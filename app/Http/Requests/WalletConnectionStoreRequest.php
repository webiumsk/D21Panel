<?php

namespace App\Http\Requests;

use App\Services\WalletConnectionValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class WalletConnectionStoreRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:blink,aqua_descriptor,nwc,blitz'],
            'secret' => ['required', 'string', 'min:10'],
            // Every store keeps at least one Lightning address as the CashuMelt fallback.
            // Optional only when it can be derived from the secret (blink/blitz ln-address).
            'fallback_lightning_address' => ['nullable', 'string', 'max:320', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('type');
            $secret = $this->input('secret');

            if ($type && $secret) {
                $connectionValidator = new WalletConnectionValidator;
                $validation = $connectionValidator->validate($type, $secret);

                if (! $validation['valid']) {
                    foreach ($validation['errors'] as $error) {
                        $validator->errors()->add('secret', $error);
                    }

                    return;
                }

                $fallback = trim((string) $this->input('fallback_lightning_address', ''));
                if ($fallback === '' && $connectionValidator->deriveLightningAddressFromSecret($type, $secret) === null) {
                    $validator->errors()->add(
                        'fallback_lightning_address',
                        'A Lightning address is required as the CashuMelt fallback for this connection type.'
                    );
                }
            }
        });
    }
}
