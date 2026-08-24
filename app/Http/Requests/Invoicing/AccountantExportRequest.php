<?php

namespace App\Http\Requests\Invoicing;

use App\Services\Invoicing\Accounting\AccountantPackageOptions;
use Illuminate\Foundation\Http\FormRequest;

/** Query options for the server-mode accountant package download. */
class AccountantExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::optionRules();
    }

    /**
     * Shared with the ephemeral variant (there the options sit under `options.`).
     *
     * @return array<string, mixed>
     */
    public static function optionRules(string $prefix = ''): array
    {
        return [
            "{$prefix}from" => ['required', 'date_format:Y-m-d'],
            "{$prefix}to" => ['required', 'date_format:Y-m-d', "after_or_equal:{$prefix}from"],
            "{$prefix}formats" => ['sometimes', 'array'],
            "{$prefix}formats.*" => ['string', 'in:'.AccountantPackageOptions::FORMAT_POHODA.','.AccountantPackageOptions::FORMAT_CSV],
            "{$prefix}include_pdf" => ['sometimes', 'boolean'],
            "{$prefix}include_isdoc" => ['sometimes', 'boolean'],
            "{$prefix}include_ubl" => ['sometimes', 'boolean'],
            "{$prefix}include_expense_attachments" => ['sometimes', 'boolean'],
        ];
    }

    public function options(): AccountantPackageOptions
    {
        return AccountantPackageOptions::fromArray($this->validated());
    }
}
