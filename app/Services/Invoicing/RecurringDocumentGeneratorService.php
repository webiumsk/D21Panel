<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\BusinessRecurringProfile;
use App\Support\Invoicing\BankSymbolNormalizer;
use App\Support\Invoicing\CompanyVatPolicy;
use Carbon\Carbon;

class RecurringDocumentGeneratorService
{
    public function __construct(
        protected RecurringPlaceholderResolver $placeholders,
        protected RecurringNextDateCalculator $nextDateCalculator,
        protected BusinessDocumentIssueService $issueService,
        protected DocumentSequenceService $sequenceService,
    ) {}

    public function generateDueProfiles(?Carbon $today = null): int
    {
        $today = $today ?? Carbon::today();
        $count = 0;

        BusinessRecurringProfile::query()
            ->where('is_active', true)
            ->whereDate('next_issue_date', '<=', $today->toDateString())
            ->with(['company', 'lines', 'contact'])
            ->orderBy('next_issue_date')
            ->chunkById(50, function ($profiles) use ($today, &$count) {
                foreach ($profiles as $profile) {
                    if (! $this->nextDateCalculator->isDue($profile, $today)) {
                        continue;
                    }

                    try {
                        $this->generateForProfile($profile, $today);
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    public function generateForProfile(BusinessRecurringProfile $profile, ?Carbon $issueDate = null): BusinessDocument
    {
        $profile->loadMissing(['company', 'lines', 'contact']);
        $issueDate = $issueDate ?? Carbon::parse($profile->next_issue_date);
        $docType = $profile->document_type === 'proforma'
            ? BusinessDocumentType::Proforma
            : BusinessDocumentType::Invoice;

        $previewNumber = $this->sequenceService->previewNextNumber($profile->company, $docType->value);
        $vsTemplate = $profile->variable_symbol ?: '#VARIABLE_SYMBOL#';

        $issueDateStr = $issueDate->toDateString();
        $dueDate = $issueDate->copy()->addDays($profile->payment_terms_days)->toDateString();
        $deliveryDate = match ($profile->delivery_date_mode) {
            'on_issue' => $issueDateStr,
            default => null,
        };

        $document = new BusinessDocument([
            'company_id' => $profile->company_id,
            'company_contact_id' => $profile->company_contact_id,
            'store_id' => $profile->store_id,
            'type' => $docType,
            'status' => BusinessDocumentStatus::Draft,
            'title' => $this->placeholders->resolve($profile->title, $issueDate, $previewNumber, $vsTemplate),
            'variable_symbol' => BankSymbolNormalizer::variableSymbol(
                $this->placeholders->resolve($vsTemplate, $issueDate, $previewNumber, $vsTemplate)
            ) ?? BankSymbolNormalizer::variableSymbol($previewNumber),
            'constant_symbol' => $profile->constant_symbol,
            'specific_symbol' => $profile->specific_symbol,
            'issue_date' => $issueDateStr,
            'delivery_date' => $deliveryDate,
            'due_date' => $dueDate,
            'currency' => $profile->currency,
            'discount_percent' => $profile->discount_percent,
            'note_above_lines' => $this->placeholders->resolve($profile->note_above_lines, $issueDate, $previewNumber, $vsTemplate),
            'note_footer' => $profile->note_footer,
            'internal_note' => $profile->internal_note,
            'pdf_locale' => $profile->pdf_locale,
            'pdf_show_signature' => $profile->pdf_show_signature,
            'pdf_show_payment_info' => $profile->pdf_show_payment_info,
            'payment_btc_enabled' => $profile->payment_btc_enabled,
            'payment_bank_enabled' => $profile->payment_bank_enabled,
            'tags' => $profile->tags,
        ]);

        $document->setRelation('company', $profile->company);
        $linePayloads = $profile->lines->map(fn ($line) => [
            'name' => $this->placeholders->resolve($line->name, $issueDate, $previewNumber, $vsTemplate),
            'description' => $this->placeholders->resolve($line->description, $issueDate, $previewNumber, $vsTemplate),
            'quantity' => (float) $line->quantity,
            'unit' => $line->unit,
            'unit_price' => (float) $line->unit_price,
            'line_discount_percent' => (float) $line->line_discount_percent,
            'tax_rate' => (float) $line->tax_rate,
        ])->all();

        app(DocumentTotalsCalculator::class)->applyToDocument(
            $document,
            $linePayloads,
            (float) $profile->discount_percent
        );

        $document->save();

        foreach ($linePayloads as $index => $line) {
            $qty = (float) $line['quantity'];
            $unitPrice = (float) $line['unit_price'];
            $lineDiscount = (float) ($line['line_discount_percent'] ?? 0);
            $taxRate = (float) ($line['tax_rate'] ?? 0);
            $lineNet = $qty * $unitPrice * (1 - $lineDiscount / 100);
            $buyer = $profile->contact;
            $vatPolicy = app(CompanyVatPolicy::class);
            $taxRate = $vatPolicy->resolveLineTaxRate($profile->company, $buyer, $taxRate);
            $lineTax = $vatPolicy->calculatesVatAmounts($profile->company, $buyer) ? $lineNet * ($taxRate / 100) : 0;

            BusinessDocumentLine::create([
                'business_document_id' => $document->id,
                'sort_order' => $index,
                'name' => $line['name'],
                'description' => $line['description'] ?? null,
                'quantity' => $qty,
                'unit' => $line['unit'] ?? 'ks',
                'unit_price' => $unitPrice,
                'line_discount_percent' => $lineDiscount,
                'tax_rate' => $taxRate,
                'line_total' => number_format($lineNet + $lineTax, 2, '.', ''),
            ]);
        }

        $issued = $this->issueService->issue($document);

        if ($issued->number && $profile->title) {
            $issued->title = $this->placeholders->resolve(
                $profile->title,
                $issueDate,
                $issued->number,
                $issued->variable_symbol
            );
            $issued->save();
        }

        $profile->last_generated_document_id = $issued->id;
        $profile->last_generated_at = now();
        $profile->next_issue_date = $this->nextDateCalculator->advance($profile, $issueDate);
        $profile->save();

        AuditLog::log('business_recurring.generated', 'business_recurring_profile', $profile->id, [
            'document_id' => $issued->id,
            'number' => $issued->number,
        ]);

        return $issued->fresh(['lines', 'contact', 'store']);
    }
}
