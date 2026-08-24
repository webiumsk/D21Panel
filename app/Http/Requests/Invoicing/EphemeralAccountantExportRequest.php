<?php

namespace App\Http\Requests\Invoicing;

use App\Enums\BusinessExpenseStatus;
use App\Services\Invoicing\Accounting\AccountantPackageOptions;
use Illuminate\Validation\Rule;

/**
 * Local-first accountant package: the same transient document payload as
 * the bulk PDF ZIP, plus received expenses with base64 attachments and the
 * package options. Nothing here is persisted server-side.
 */
class EphemeralAccountantExportRequest extends EphemeralBusinessDocumentBulkRequest
{
    /** Per attachment, base64-encoded (~512 KB decoded). */
    public const MAX_ATTACHMENT_BASE64_LENGTH = 700_000;

    /** Row cap per package (documents and expenses each), shared with server mode. */
    public static function maxRows(): int
    {
        return max(1, (int) config('invoicing.accountant_export_max_rows', 500));
    }

    /** Whole request after base64 decoding - the UI chunks by month above this. */
    public static function maxTotalAttachmentBytes(): int
    {
        return max(1, (int) config('invoicing.accountant_export_max_attachment_bytes', 64 * 1024 * 1024));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        // A period may contain only received documents - issued ones are optional here.
        $rules['documents'] = ['sometimes', 'array', 'max:'.self::maxRows()];

        return array_merge($rules, [
            'expenses' => ['sometimes', 'array', 'max:'.self::maxRows()],
            'expenses.*.internal_number' => ['required', 'string', 'max:120'],
            'expenses.*.external_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expenses.*.supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expenses.*.supplier_registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expenses.*.supplier_tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expenses.*.supplier_vat_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expenses.*.variable_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expenses.*.constant_symbol' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expenses.*.issue_date' => ['sometimes', 'nullable', 'date'],
            'expenses.*.delivery_date' => ['sometimes', 'nullable', 'date'],
            'expenses.*.due_date' => ['sometimes', 'nullable', 'date'],
            'expenses.*.paid_at' => ['sometimes', 'nullable', 'date'],
            'expenses.*.total' => ['required', 'numeric'],
            'expenses.*.currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'expenses.*.status' => ['sometimes', Rule::enum(BusinessExpenseStatus::class)],
            'expenses.*.note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'expenses.*.attachments' => ['sometimes', 'array', 'max:20'],
            'expenses.*.attachments.*.filename' => ['required', 'string', 'max:255'],
            'expenses.*.attachments.*.mime' => ['required', 'string', 'in:application/pdf,image/png,image/jpeg,image/webp,application/xml,text/xml'],
            'expenses.*.attachments.*.content_base64' => ['required', 'string', 'max:'.self::MAX_ATTACHMENT_BASE64_LENGTH],
            'options' => ['required', 'array'],
        ] + AccountantExportRequest::optionRules('options.'));
    }

    public function options(): AccountantPackageOptions
    {
        return AccountantPackageOptions::fromArray((array) ($this->validated()['options'] ?? []));
    }
}
