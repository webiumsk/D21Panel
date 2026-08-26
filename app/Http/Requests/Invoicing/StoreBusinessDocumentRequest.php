<?php

namespace App\Http\Requests\Invoicing;

use App\Enums\BusinessDocumentType;
use App\Support\Invoicing\BankSymbolNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('variable_symbol')) {
            $this->merge([
                'variable_symbol' => BankSymbolNormalizer::variableSymbol($this->input('variable_symbol')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => [
                Rule::requiredIf($this->isMethod('POST')),
                Rule::enum(BusinessDocumentType::class),
            ],
            'company_contact_id' => ['nullable', 'uuid'],
            'store_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:255'],
            'variable_symbol' => ['nullable', 'string', 'max:20'],
            'constant_symbol' => ['nullable', 'string', 'max:10'],
            'specific_symbol' => ['nullable', 'string', 'max:10'],
            'issue_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note_above_lines' => ['nullable', 'string', 'max:10000'],
            'note_footer' => ['nullable', 'string', 'max:10000'],
            'internal_note' => ['nullable', 'string', 'max:10000'],
            'pdf_locale' => ['nullable', 'string', 'max:8'],
            'pdf_bank_qr' => ['nullable', 'in:auto,paybysquare,epc,swiss,none'],
            'pdf_show_signature' => ['sometimes', 'boolean'],
            'pdf_show_payment_info' => ['sometimes', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'payment_btc_enabled' => ['sometimes', 'boolean'],
            'payment_bank_enabled' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.line_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.company_stock_item_id' => ['nullable', 'uuid'],
            'lines.*.company_warehouse_id' => ['nullable', 'uuid'],
        ];
    }
}
