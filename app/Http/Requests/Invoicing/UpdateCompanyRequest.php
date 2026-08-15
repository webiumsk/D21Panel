<?php

namespace App\Http\Requests\Invoicing;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Support\Invoicing\JurisdictionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<CompanyJurisdiction>
     */
    protected function allowedJurisdictions(): array
    {
        $allowed = CompanyJurisdiction::enabled();
        $company = $this->route('company');
        // normalizeValue tolerates both the enum cast and the string PHPDoc
        // shape (pre-existing looseness on Company::$jurisdiction).
        $current = $company instanceof Company
            ? CompanyJurisdiction::tryFrom(JurisdictionRules::normalizeValue($company->jurisdiction))
            : null;
        if ($current !== null && ! in_array($current, $allowed, true)) {
            $allowed[] = $current;
        }

        return $allowed;
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'commercial_register' => ['sometimes', 'nullable', 'string', 'max:512'],
            'register_court' => ['sometimes', 'nullable', 'string', 'max:128'],
            'register_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'managing_directors' => ['sometimes', 'nullable', 'string', 'max:512'],
            'supervisory_board_chair' => ['sometimes', 'nullable', 'string', 'max:128'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'state_region' => ['sometimes', 'nullable', 'string', 'max:64'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bic' => ['sometimes', 'nullable', 'string', 'max:16'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'bank_account' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'default_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            // Enabled set + the company's current value: an existing company
            // in a disabled jurisdiction keeps saving its profile, but cannot
            // switch to another disabled one.
            'jurisdiction' => ['sometimes', 'required', Rule::enum(CompanyJurisdiction::class)->only($this->allowedJurisdictions())],
            'vat_payer' => ['sometimes', 'boolean'],
            'vat_status' => ['sometimes', 'string', 'in:none,payer,partial'],
            'vat_rate_default' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'legal_footer_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'issuer_name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'issuer_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'issuer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'invoice_number_prefix' => ['sometimes', 'nullable', 'string', 'max:16'],
        ];
    }
}
