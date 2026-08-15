<?php

namespace App\Http\Requests\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Support\Invoicing\IsoCountryCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EphemeralBusinessDocumentBulkRequest extends FormRequest
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

        $documents = $this->input('documents');
        if (! is_array($documents)) {
            return;
        }

        foreach ($documents as $index => $document) {
            if (! is_array($document)) {
                continue;
            }
            $contact = $document['contact'] ?? null;
            if (is_array($contact) && array_key_exists('country', $contact)) {
                $documents[$index]['contact']['country'] = IsoCountryCode::normalize($contact['country']);
            }
        }

        $this->merge(['documents' => $documents]);
    }

    public function rules(): array
    {
        $companyRules = [
            'company' => ['required', 'array'],
            'company.legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'company.logo_url' => ['sometimes', 'nullable', 'string', 'max:131072'],
            'company.signature_stamp_url' => ['sometimes', 'nullable', 'string', 'max:131072'],
            'documents' => ['required', 'array', 'min:1', 'max:50'],
        ];

        $documentItemRules = [
            'documents.*.contact' => ['sometimes', 'nullable', 'array'],
            'documents.*.contact.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documents.*.contact.registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.contact.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'documents.*.contact.phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.contact.tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.contact.vat_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.contact.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documents.*.contact.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documents.*.contact.postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'documents.*.contact.state_region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documents.*.contact.country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'documents.*.document' => ['required', 'array'],
            'documents.*.document.type' => ['required', Rule::enum(BusinessDocumentType::class)],
            'documents.*.document.status' => ['sometimes', Rule::enum(BusinessDocumentStatus::class)],
            'documents.*.document.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documents.*.document.number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'documents.*.document.variable_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.document.constant_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.document.specific_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'documents.*.document.issue_date' => ['sometimes', 'nullable', 'date'],
            'documents.*.document.delivery_date' => ['sometimes', 'nullable', 'date'],
            'documents.*.document.due_date' => ['sometimes', 'nullable', 'date'],
            'documents.*.document.currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'documents.*.document.note_above_lines' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'documents.*.document.note_footer' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'documents.*.document.internal_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'documents.*.document.pdf_locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'documents.*.document.pdf_bank_qr' => ['sometimes', 'nullable', 'in:auto,paybysquare,epc,swiss,none'],
            'documents.*.document.pdf_show_signature' => ['sometimes', 'boolean'],
            'documents.*.document.pdf_show_payment_info' => ['sometimes', 'boolean'],
            'documents.*.document.payment_bank_enabled' => ['sometimes', 'boolean'],
            'documents.*.document.discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'documents.*.document.amount_paid' => ['sometimes', 'numeric', 'min:0'],
            'documents.*.lines' => ['required', 'array', 'min:1'],
            'documents.*.lines.*.name' => ['required', 'string', 'max:500'],
            'documents.*.lines.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'documents.*.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'documents.*.lines.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'documents.*.lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'documents.*.lines.*.line_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'documents.*.lines.*.tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];

        return array_merge($companyRules, $documentItemRules);
    }
}
