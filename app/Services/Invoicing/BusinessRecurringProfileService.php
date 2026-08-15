<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentType;
use App\Enums\RecurringInterval;
use App\Models\BusinessDocument;
use App\Models\BusinessRecurringProfile;
use App\Models\BusinessRecurringProfileLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Store;
use App\Support\Invoicing\CompanyAppSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessRecurringProfileService
{
    public function __construct(
        protected DocumentTotalsCalculator $totalsCalculator,
        protected CanonicalInvoiceBuilder $canonicalBuilder,
        protected RecurringNextDateCalculator $nextDateCalculator,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serializeProfile(BusinessRecurringProfile $profile): array
    {
        $profile->loadMissing(['contact:id,name', 'lines']);

        return array_merge($profile->toArray(), [
            'recurrence_interval' => $profile->recurrence_interval->value,
            'contact' => $profile->contact,
            'lines' => $profile->lines->map(fn (BusinessRecurringProfileLine $line) => [
                'name' => $line->name,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'line_discount_percent' => (float) $line->line_discount_percent,
                'tax_rate' => (float) $line->tax_rate,
                'line_total' => (float) $line->line_total,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): BusinessRecurringProfile
    {
        return DB::transaction(function () use ($company, $data) {
            $profile = new BusinessRecurringProfile($this->profileAttributes($company, $data));
            $profile->company_id = $company->id;
            $this->applyTotals($profile, $data['lines'] ?? []);
            $profile->save();
            $this->syncLines($profile, $data['lines'] ?? []);

            return $profile->fresh(['lines', 'contact']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BusinessRecurringProfile $profile, Company $company, array $data): BusinessRecurringProfile
    {
        return DB::transaction(function () use ($profile, $company, $data) {
            $profile->fill($this->profileAttributes($company, $data, $profile));
            $this->applyTotals($profile, $data['lines'] ?? []);
            $profile->save();
            $profile->lines()->delete();
            $this->syncLines($profile, $data['lines'] ?? []);

            return $profile->fresh(['lines', 'contact']);
        });
    }

    public function createFromDocument(Company $company, BusinessDocument $document): BusinessRecurringProfile
    {
        if ($document->company_id !== $company->id) {
            abort(404);
        }

        $document->load(['lines', 'contact']);

        $docType = $document->type === BusinessDocumentType::Proforma ? 'proforma' : 'invoice';
        $title = $document->title ?: ($docType === 'proforma'
            ? 'Zálohová faktúra #INVOICE_NUMBER#'
            : 'Faktúra #INVOICE_NUMBER#');

        return $this->create($company, [
            'document_type' => $docType,
            'company_contact_id' => $document->company_contact_id,
            'store_id' => $document->store_id,
            'title' => $this->restorePlaceholders($title, $document->number),
            'variable_symbol' => $document->variable_symbol ?: '#VARIABLE_SYMBOL#',
            'constant_symbol' => $document->constant_symbol,
            'specific_symbol' => $document->specific_symbol,
            'currency' => $document->currency,
            'discount_percent' => (float) $document->discount_percent,
            'note_above_lines' => $document->note_above_lines,
            'note_footer' => $document->note_footer,
            'internal_note' => $document->internal_note,
            'pdf_locale' => $document->pdf_locale,
            'pdf_show_signature' => $document->pdf_show_signature,
            'pdf_show_payment_info' => $document->pdf_show_payment_info,
            'payment_btc_enabled' => $document->payment_btc_enabled,
            'payment_bank_enabled' => $document->payment_bank_enabled,
            'tags' => $document->tags,
            'recurrence_interval' => RecurringInterval::Yearly->value,
            'first_issue_date' => now()->addYear()->toDateString(),
            'next_issue_date' => now()->addYear()->toDateString(),
            'repeat_indefinitely' => true,
            'lines' => $document->lines->map(fn ($l) => [
                'name' => $l->name,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit' => $l->unit,
                'unit_price' => (float) $l->unit_price,
                'line_discount_percent' => (float) $l->line_discount_percent,
                'tax_rate' => (float) $l->tax_rate,
            ])->all(),
        ]);
    }

    protected function restorePlaceholders(string $title, ?string $number): string
    {
        if (! $number) {
            return $title;
        }

        return str_replace($number, '#INVOICE_NUMBER#', $title);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function profileAttributes(Company $company, array $data, ?BusinessRecurringProfile $existing = null): array
    {
        $this->assertContact($data['company_contact_id'] ?? null, $company);
        $this->assertStore($data['store_id'] ?? null, $company);

        $interval = RecurringInterval::tryFrom($data['recurrence_interval'] ?? '')
            ?? RecurringInterval::Yearly;

        $firstIssue = $data['first_issue_date'] ?? now()->toDateString();
        $nextIssue = $data['next_issue_date'] ?? $firstIssue;

        $docType = in_array($data['document_type'] ?? 'invoice', ['invoice', 'proforma'], true)
            ? $data['document_type']
            : 'invoice';

        $settings = CompanyAppSettings::from($company->app_settings);

        return [
            'company_contact_id' => $data['company_contact_id'] ?? null,
            'store_id' => $data['store_id'] ?? $this->defaultStoreId($company),
            'document_type' => $docType,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'recurrence_interval' => $interval,
            'first_issue_date' => $firstIssue,
            'next_issue_date' => $nextIssue,
            'repeat_indefinitely' => (bool) ($data['repeat_indefinitely'] ?? true),
            'ends_at' => ($data['repeat_indefinitely'] ?? true) ? null : ($data['ends_at'] ?? null),
            'issue_last_day_of_month' => (bool) ($data['issue_last_day_of_month'] ?? false),
            'title' => $data['title'] ?? null,
            'variable_symbol' => $data['variable_symbol'] ?? null,
            'constant_symbol' => $data['constant_symbol'] ?? $settings->get('default_constant_symbol'),
            'specific_symbol' => $data['specific_symbol'] ?? null,
            'payment_terms_days' => (int) ($data['payment_terms_days'] ?? 14),
            'delivery_date_mode' => $data['delivery_date_mode'] ?? 'on_issue',
            'currency' => $data['currency'] ?? $company->default_currency,
            'discount_percent' => (float) ($data['discount_percent'] ?? 0),
            'note_above_lines' => $data['note_above_lines'] ?? null,
            'note_footer' => $data['note_footer'] ?? $company->legal_footer_note,
            'internal_note' => $data['internal_note'] ?? null,
            'pdf_locale' => $data['pdf_locale'] ?? 'sk',
            'pdf_show_signature' => (bool) ($data['pdf_show_signature'] ?? true),
            'pdf_show_payment_info' => (bool) ($data['pdf_show_payment_info'] ?? true),
            'payment_btc_enabled' => (bool) ($data['payment_btc_enabled'] ?? false),
            'payment_bank_enabled' => (bool) ($data['payment_bank_enabled'] ?? true),
            'send_email_after_issue' => (bool) ($data['send_email_after_issue'] ?? false),
            'email_bcc' => $data['email_bcc'] ?? null,
            'tags' => $data['tags'] ?? null,
        ];
    }

    protected function defaultStoreId(Company $company): ?string
    {
        return Store::query()
            ->where('company_id', $company->id)
            ->where('user_id', $company->user_id)
            ->orderBy('name')
            ->value('id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function applyTotals(BusinessRecurringProfile $profile, array $lines): void
    {
        $profile->setRelation('company', $profile->company ?? Company::find($profile->company_id));
        $totals = $this->totalsCalculator->calculate(
            $profile->company,
            $lines,
            (float) ($profile->discount_percent ?? 0)
        );
        $profile->subtotal = $totals['subtotal'];
        $profile->tax_total = $totals['tax_total'];
        $profile->total = $totals['total'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function syncLines(BusinessRecurringProfile $profile, array $lines): void
    {
        $company = $profile->company ?? Company::find($profile->company_id);

        foreach ($lines as $index => $line) {
            if (! $company) {
                continue;
            }

            $contact = $profile->relationLoaded('contact')
                ? $profile->contact
                : ($profile->company_contact_id
                    ? CompanyContact::query()->find($profile->company_contact_id)
                    : null);
            $amounts = $this->canonicalBuilder->computeLineAmounts($company, $line, $contact);

            BusinessRecurringProfileLine::create([
                'business_recurring_profile_id' => $profile->id,
                'sort_order' => $index,
                'name' => $line['name'],
                'description' => $line['description'] ?? null,
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit' => $line['unit'] ?? 'ks.',
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'line_discount_percent' => (float) ($line['line_discount_percent'] ?? 0),
                'tax_rate' => $amounts['tax_rate'],
                'line_total' => number_format($amounts['gross'], 2, '.', ''),
            ]);
        }
    }

    protected function assertContact(?string $contactId, Company $company): void
    {
        if (! $contactId) {
            return;
        }

        if (! CompanyContact::where('id', $contactId)->where('company_id', $company->id)->exists()) {
            throw ValidationException::withMessages([
                'company_contact_id' => ['Invalid contact for this company.'],
            ]);
        }
    }

    protected function assertStore(?string $storeId, Company $company): void
    {
        if (! $storeId) {
            return;
        }

        if (! Store::where('id', $storeId)->where('company_id', $company->id)->where('user_id', $company->user_id)->exists()) {
            throw ValidationException::withMessages([
                'store_id' => ['Store must be linked to this company.'],
            ]);
        }
    }
}
