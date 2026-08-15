<?php

namespace App\Http\Requests\Invoicing;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rounding_method' => ['sometimes', 'string', 'in:per_line,per_document,none'],
            'invoice_line_label' => ['nullable', 'string', 'max:255'],
            'default_invoice_payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'default_delivery_method' => ['nullable', 'string', 'max:128'],
            'default_delivery_date_mode' => ['sometimes', 'string', 'in:empty,issue_date,due_date'],
            'default_payment_method' => ['nullable', 'string', 'max:128'],
            'pdf_filename_pattern' => ['sometimes', 'string', 'max:255'],
            'expense_attachment_name_pattern' => ['nullable', 'string', 'max:255'],
            'sort_lists_by' => ['sometimes', 'string', 'in:issue_date,due_date,number'],
            'number_documents_by' => ['sometimes', 'string', 'in:issue_date,calendar_year'],
            'default_constant_symbol' => ['nullable', 'string', 'max:10'],
            'tax_free_minimum' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'show_contextual_help' => ['sometimes', 'boolean'],
            'show_pay_by_square' => ['sometimes', 'boolean'],
            'show_invoice_by_square' => ['sometimes', 'boolean'],
            'show_client_phone_on_invoices' => ['sometimes', 'boolean'],
            'show_payme_on_invoices' => ['sometimes', 'boolean'],
            'variable_symbol_from_proforma' => ['sometimes', 'boolean'],
            'show_prices_on_delivery_notes' => ['sometimes', 'boolean'],
            'show_prices_on_orders' => ['sometimes', 'boolean'],
            'show_line_suggester' => ['sometimes', 'boolean'],
            'show_summary_on_quotes' => ['sometimes', 'boolean'],
            'runs_eshop' => ['sometimes', 'boolean'],
            'embed_isdoc_in_pdf' => ['sometimes', 'boolean'],
            'embed_zugferd_in_pdf' => ['sometimes', 'boolean'],
            'reverse_charge' => ['sometimes', 'boolean'],
            'reverse_charge_note' => ['nullable', 'string', 'max:2000'],
            'export_goods' => ['sometimes', 'boolean'],
            'export_note' => ['nullable', 'string', 'max:2000'],
            'us_sales_tax_provider' => ['sometimes', 'string', 'in:manual,stripe_tax,avalara'],
            'stripe_tax_secret_key' => ['nullable', 'string', 'max:255'],
            'efaktura_enabled' => ['sometimes', 'boolean'],
            'efaktura_auto_send' => ['sometimes', 'boolean'],
            'efaktura_inbound_enabled' => ['sometimes', 'boolean'],
            'efaktura_provider' => ['sometimes', 'string', 'in:sapi_sk'],
            'efaktura_sapi_base_url' => ['nullable', 'string', 'url', 'max:255'],
            // Peppol scheme syntax (e.g. 0245:2023980035) - reject typos
            // early; identifiers never contain whitespace.
            'efaktura_peppol_participant_id' => ['nullable', 'string', 'max:64', 'regex:/^\d{4}:\S+$/'],
            'efaktura_sapi_client_id' => ['nullable', 'string', 'max:128'],
            'efaktura_sapi_client_secret' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedSettings(): array
    {
        $validated = $this->validated();
        if (array_key_exists('tax_free_minimum', $validated)) {
            $validated['tax_free_minimum'] = number_format((float) $validated['tax_free_minimum'], 2, '.', '');
        }

        return $validated;
    }
}
