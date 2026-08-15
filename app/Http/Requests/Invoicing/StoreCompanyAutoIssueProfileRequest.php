<?php

namespace App\Http\Requests\Invoicing;

use App\Enums\CompanyJurisdiction;
use App\Support\Invoicing\IsoCountryCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Auto-issue profile sync (WooCommerce headless invoicing): the company block
 * mirrors the ephemeral snapshot company rules - it is consumed by the same
 * EphemeralDocumentFactory::snapshotCompany().
 */
class StoreCompanyAutoIssueProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $company = $this->input('company');
        if (is_array($company) && array_key_exists('country', $company)) {
            $company['country'] = IsoCountryCode::normalize($company['country']);
            $this->merge(['company' => $company]);
        }
    }

    public function rules(): array
    {
        return [
            'auto_email' => ['sometimes', 'boolean'],
            // Highest counter ever issued locally per document type - raises
            // the server series floor so headless numbers never collide with
            // invoices issued before the shared allocator existed.
            'local_high_counters' => ['sometimes', 'array'],
            'local_high_counters.*' => ['integer', 'min:0'],
            'company' => ['required', 'array'],
            'company.legal_name' => ['required', 'string', 'max:255'],
            'company.trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company.tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company.vat_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company.country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'company.state_region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.iban' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company.bic' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company.bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.bank_account' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company.bank_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company.default_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'company.jurisdiction' => ['sometimes', Rule::enum(CompanyJurisdiction::class)],
            'company.vat_payer' => ['sometimes', 'boolean'],
            'company.vat_status' => ['sometimes', 'nullable', 'string', 'in:none,payer,partial'],
            'company.register_court' => ['sometimes', 'nullable', 'string', 'max:128'],
            'company.register_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'company.managing_directors' => ['sometimes', 'nullable', 'string', 'max:512'],
            'company.supervisory_board_chair' => ['sometimes', 'nullable', 'string', 'max:128'],
            'company.vat_rate_default' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'company.legal_footer_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'company.issuer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.issuer_phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company.issuer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'company.website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.app_settings' => ['sometimes', 'array'],
            'company.app_settings.show_pay_by_square' => ['sometimes', 'boolean'],
            'company.logo_url' => ['sometimes', 'nullable', 'string', 'max:131072'],
            'company.signature_stamp_url' => ['sometimes', 'nullable', 'string', 'max:131072'],
        ];
    }
}
