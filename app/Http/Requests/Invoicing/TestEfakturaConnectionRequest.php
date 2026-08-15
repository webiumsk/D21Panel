<?php

namespace App\Http\Requests\Invoicing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Credentials for a one-shot SAPI-SK connection test. All fields are
 * optional on the company route (stored settings back-fill them); the
 * ephemeral (local-first) route requires all three in the body because the
 * credentials live only in the client's Evolu database.
 */
class TestEfakturaConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'efaktura_sapi_base_url' => ['nullable', 'string', 'url', 'max:255'],
            'efaktura_sapi_client_id' => ['nullable', 'string', 'max:128'],
            'efaktura_sapi_client_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
