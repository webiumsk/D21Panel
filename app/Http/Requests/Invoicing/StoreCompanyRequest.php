<?php

namespace App\Http\Requests\Invoicing;

use App\Enums\CompanyJurisdiction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'vat_number' => ['nullable', 'string', 'max:32'],
            'commercial_register' => ['nullable', 'string', 'max:512'],
            'register_court' => ['nullable', 'string', 'max:128'],
            'register_number' => ['nullable', 'string', 'max:64'],
            'managing_directors' => ['nullable', 'string', 'max:512'],
            'supervisory_board_chair' => ['nullable', 'string', 'max:128'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'state_region' => ['nullable', 'string', 'max:64'],
            'iban' => ['nullable', 'string', 'max:64'],
            'bic' => ['nullable', 'string', 'max:16'],
            'bank_name' => ['nullable', 'string', 'max:128'],
            'bank_account' => ['nullable', 'string', 'max:64'],
            'bank_code' => ['nullable', 'string', 'max:16'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'jurisdiction' => ['required', Rule::enum(CompanyJurisdiction::class)->only(CompanyJurisdiction::enabled())],
            'vat_payer' => ['sometimes', 'boolean'],
            'vat_status' => ['sometimes', 'string', 'in:none,payer,partial'],
            'vat_rate_default' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'legal_footer_note' => ['nullable', 'string', 'max:2000'],
            'issuer_name' => ['nullable', 'string', 'max:128'],
            'issuer_phone' => ['nullable', 'string', 'max:64'],
            'issuer_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'invoice_number_prefix' => ['nullable', 'string', 'max:16'],
            'store_id' => [
                'nullable',
                'uuid',
                Rule::exists('stores', 'id')->where(fn ($q) => $q->where('user_id', $this->user()->id)),
            ],
        ];
    }
}
