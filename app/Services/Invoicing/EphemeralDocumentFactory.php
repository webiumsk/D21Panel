<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\User;
use App\Support\Invoicing\BuyerSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds in-memory (never persisted) Company snapshots and BusinessDocument
 * models from ephemeral request payloads - the local-first flow where the
 * document of record lives in Evolu on the client. Extracted from
 * EphemeralBusinessDocumentController; behavior preserved 1:1.
 */
class EphemeralDocumentFactory
{
    public function __construct(
        protected DocumentTotalsCalculator $totalsCalculator,
        protected CompanyEmailSettingsService $emailSettingsService,
    ) {}

    /**
     * Snapshot of the user's first company merged with the payload, or a
     * payload-only company when the user has none.
     *
     * @param  array<string, mixed>  $companyPayload
     */
    public function resolveCompany(User $user, array $companyPayload): Company
    {
        $template = Company::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->first();

        if ($template) {
            return $this->snapshotCompany($template, $companyPayload);
        }

        return $this->payloadOnlyCompany($user, $companyPayload);
    }

    /**
     * In-memory copy of an existing company with payload overrides applied.
     *
     * @param  array<string, mixed>  $payload
     */
    public function snapshotCompany(Company $company, array $payload): Company
    {
        $snapshot = $company->replicate();
        $snapshot->exists = false;
        $snapshot->id = $company->id;

        $snapshot->forceFill($this->companyPayloadAttributes($payload));

        $this->applyEphemeralBrandingUrls($snapshot, $payload);
        $this->mergeSnapshotAppSettings($snapshot, $payload);
        $this->mergeSnapshotEmailSettings($snapshot, $payload);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadOnlyCompany(User $user, array $payload): Company
    {
        $company = new Company([
            'user_id' => $user->id,
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);
        $company->exists = false;
        $company->id = (string) Str::uuid();

        $company->forceFill($this->companyPayloadAttributes($payload));

        $this->applyEphemeralBrandingUrls($company, $payload);

        if (! $company->jurisdiction) {
            $company->jurisdiction = CompanyJurisdiction::EuSk;
        }

        if (! $company->default_currency) {
            $company->default_currency = 'EUR';
        }

        $this->mergeSnapshotAppSettings($company, $payload);
        $this->mergeSnapshotEmailSettings($company, $payload);

        return $company;
    }

    /**
     * In-memory BusinessDocument with lines and totals from the request payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function document(Company $company, array $payload): BusinessDocument
    {
        /** @var array<string, mixed> $documentPayload */
        $documentPayload = (array) ($payload['document'] ?? []);
        /** @var array<string, mixed>|null $contactPayload */
        $contactPayload = isset($payload['contact']) && is_array($payload['contact']) ? $payload['contact'] : null;
        /** @var array<int, array<string, mixed>> $linesPayload */
        $linesPayload = array_values((array) ($payload['lines'] ?? []));

        $document = new BusinessDocument([
            'company_id' => $company->id,
            'type' => $documentPayload['type'] ?? null,
            'status' => $documentPayload['status'] ?? BusinessDocumentStatus::Issued->value,
            'title' => $documentPayload['title'] ?? null,
            'number' => $documentPayload['number'] ?? null,
            'variable_symbol' => $documentPayload['variable_symbol'] ?? null,
            'constant_symbol' => $documentPayload['constant_symbol'] ?? null,
            'specific_symbol' => $documentPayload['specific_symbol'] ?? null,
            'issue_date' => $documentPayload['issue_date'] ?? null,
            'delivery_date' => $documentPayload['delivery_date'] ?? null,
            'due_date' => $documentPayload['due_date'] ?? null,
            'currency' => $documentPayload['currency'] ?? $company->default_currency,
            'note_above_lines' => $documentPayload['note_above_lines'] ?? null,
            'note_footer' => $documentPayload['note_footer'] ?? $company->legal_footer_note,
            'internal_note' => $documentPayload['internal_note'] ?? null,
            'pdf_locale' => $documentPayload['pdf_locale'] ?? null,
            'pdf_bank_qr' => $documentPayload['pdf_bank_qr'] ?? null,
            'pdf_show_signature' => (bool) ($documentPayload['pdf_show_signature'] ?? true),
            'pdf_show_payment_info' => (bool) ($documentPayload['pdf_show_payment_info'] ?? true),
            'payment_btc_enabled' => (bool) ($documentPayload['payment_btc_enabled'] ?? false),
            'payment_bank_enabled' => (bool) ($documentPayload['payment_bank_enabled'] ?? true),
            'amount_paid' => (float) ($documentPayload['amount_paid'] ?? 0),
            'buyer_snapshot' => $contactPayload,
        ]);

        if (! empty($payload['store_id'])) {
            $document->store_id = (string) $payload['store_id'];
        }

        if (! empty($payload['btcpay_checkout_link'])) {
            $document->btcpay_checkout_link = (string) $payload['btcpay_checkout_link'];
        }

        if (! empty($payload['evolu_document_id'])) {
            $document->setAttribute('ephemeral_evolu_document_id', (string) $payload['evolu_document_id']);
        }

        $document->exists = false;
        $document->id = (string) Str::uuid();
        $document->setRelation('company', $company);

        if ($contactPayload !== null) {
            $document->setRelation('contact', BuyerSnapshot::asContact($contactPayload));
        } else {
            $document->setRelation('contact', null);
        }

        $lineModels = collect($linesPayload)->values()->map(function (array $line, int $index) {
            return new BusinessDocumentLine([
                'sort_order' => $index,
                'name' => (string) ($line['name'] ?? ''),
                'description' => $line['description'] ?? null,
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit' => $line['unit'] ?? 'ks.',
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'line_discount_percent' => (float) ($line['line_discount_percent'] ?? 0),
                'tax_rate' => isset($line['tax_rate']) ? (float) $line['tax_rate'] : 0,
                'line_total' => 0,
            ]);
        });
        $document->setRelation('lines', $lineModels);

        $this->totalsCalculator->applyToDocument(
            $document,
            $linesPayload,
            (float) ($documentPayload['discount_percent'] ?? 0)
        );

        return $document;
    }

    /**
     * @param  array<string, mixed>  $validated  Bulk request payload (company + documents[])
     * @return Collection<int, BusinessDocument>
     */
    public function documentsFromBulk(Company $snapshotCompany, array $validated): Collection
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = array_values((array) ($validated['documents'] ?? []));

        return collect($items)->map(function (array $item) use ($snapshotCompany, $validated) {
            return $this->document($snapshotCompany, [
                'company' => $validated['company'] ?? [],
                'contact' => $item['contact'] ?? null,
                'document' => $item['document'] ?? [],
                'lines' => $item['lines'] ?? [],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyEphemeralBrandingUrls(Company $company, array $payload): void
    {
        if (array_key_exists('logo_url', $payload)) {
            $company->setAttribute('ephemeral_logo_url', $payload['logo_url'] ?: null);
        }
        if (array_key_exists('signature_stamp_url', $payload)) {
            $company->setAttribute('ephemeral_signature_stamp_url', $payload['signature_stamp_url'] ?: null);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mergeSnapshotAppSettings(Company $company, array $payload): void
    {
        if (! isset($payload['app_settings']) || ! is_array($payload['app_settings'])) {
            return;
        }

        $current = is_array($company->app_settings) ? $company->app_settings : [];
        $company->app_settings = array_merge($current, $payload['app_settings']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mergeSnapshotEmailSettings(Company $company, array $payload): void
    {
        if (! isset($payload['email_settings']) || ! is_array($payload['email_settings'])) {
            return;
        }

        $this->emailSettingsService->applyIncomingToCompany($company, $payload['email_settings']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function companyPayloadAttributes(array $payload): array
    {
        return Arr::only($payload, [
            'legal_name',
            'trade_name',
            'registration_number',
            'tax_id',
            'vat_number',
            'street',
            'city',
            'postal_code',
            'country',
            'state_region',
            'iban',
            'bic',
            'bank_name',
            'bank_account',
            'bank_code',
            'default_currency',
            'jurisdiction',
            'vat_payer',
            'vat_status',
            'vat_rate_default',
            'register_court',
            'register_number',
            'managing_directors',
            'supervisory_board_chair',
            'legal_footer_note',
            'issuer_name',
            'issuer_phone',
            'issuer_email',
            'website',
        ]);
    }
}
