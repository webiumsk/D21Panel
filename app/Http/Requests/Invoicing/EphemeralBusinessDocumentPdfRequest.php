<?php

namespace App\Http\Requests\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Support\Invoicing\CompanyEmailSettings;
use App\Support\Invoicing\IsoCountryCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EphemeralBusinessDocumentPdfRequest extends FormRequest
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

        $contact = $this->input('contact');
        if (is_array($contact) && array_key_exists('country', $contact)) {
            $contact['country'] = IsoCountryCode::normalize($contact['country']);
            $this->merge(['contact' => $contact]);
        }
    }

    public function rules(): array
    {
        return [
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
            'company.app_settings' => ['sometimes', 'array'],
            'company.app_settings.show_pay_by_square' => ['sometimes', 'boolean'],
            'company.app_settings.efaktura_enabled' => ['sometimes', 'boolean'],
            'company.app_settings.efaktura_auto_send' => ['sometimes', 'boolean'],
            'company.app_settings.efaktura_sapi_base_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.app_settings.efaktura_peppol_participant_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.app_settings.efaktura_sapi_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.app_settings.efaktura_sapi_client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.logo_url' => ['sometimes', 'nullable', 'string', 'max:131072'],
            'company.signature_stamp_url' => ['sometimes', 'nullable', 'string', 'max:131072'],
            'company.email_settings' => ['sometimes', 'array'],
            'company.email_settings.delivery_method' => ['sometimes', 'string', Rule::in([
                CompanyEmailSettings::DELIVERY_SYSTEM,
                CompanyEmailSettings::DELIVERY_SMTP,
                CompanyEmailSettings::DELIVERY_GMAIL,
                CompanyEmailSettings::DELIVERY_OFFICE,
            ])],
            'company.email_settings.smtp' => ['sometimes', 'array'],
            'company.email_settings.smtp.username' => ['nullable', 'string', 'max:255'],
            'company.email_settings.smtp.password' => ['nullable', 'string', 'max:255'],
            'company.email_settings.smtp.host' => ['nullable', 'string', 'max:255'],
            'company.email_settings.smtp.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'company.email_settings.smtp.from_name' => ['nullable', 'string', 'max:128'],
            'company.email_settings.smtp.encryption' => ['nullable', 'string', 'in:tls,ssl,none'],
            'company.email_settings.smtp.use_smtp_email_as_from' => ['sometimes', 'boolean'],
            'company.email_settings.templates' => ['sometimes', 'array'],
            'company.email_settings.templates.*.subject' => ['nullable', 'string', 'max:500'],
            'company.email_settings.templates.*.body' => ['nullable', 'string', 'max:20000'],

            'contact' => ['sometimes', 'nullable', 'array'],
            'contact.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact.registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact.phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact.tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact.vat_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact.postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contact.state_region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact.country' => ['sometimes', 'nullable', 'string', 'max:2'],

            'document' => ['required', 'array'],
            'document.type' => ['required', Rule::enum(BusinessDocumentType::class)],
            'document.status' => ['sometimes', Rule::enum(BusinessDocumentStatus::class)],
            'document.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document.number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'document.variable_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document.constant_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document.specific_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document.issue_date' => ['sometimes', 'nullable', 'date'],
            'document.delivery_date' => ['sometimes', 'nullable', 'date'],
            'document.due_date' => ['sometimes', 'nullable', 'date'],
            'document.currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'document.note_above_lines' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'document.note_footer' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'document.internal_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'document.pdf_locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'document.pdf_bank_qr' => ['sometimes', 'nullable', 'in:auto,paybysquare,epc,swiss,none'],
            'document.pdf_show_signature' => ['sometimes', 'boolean'],
            'document.pdf_show_payment_info' => ['sometimes', 'boolean'],
            'document.payment_bank_enabled' => ['sometimes', 'boolean'],
            'document.payment_btc_enabled' => ['sometimes', 'boolean'],
            'document.discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'document.amount_paid' => ['sometimes', 'numeric', 'min:0'],

            'store_id' => ['sometimes', 'nullable', 'uuid'],
            'evolu_document_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'btcpay_checkout_link' => ['sometimes', 'nullable', 'string', 'max:2048'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.name' => ['required', 'string', 'max:500'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.line_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
