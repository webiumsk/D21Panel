<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyVatPolicy;
use Illuminate\Validation\ValidationException;

class BusinessDocumentFromProformaService
{
    public function __construct(
        protected DocumentTotalsCalculator $totalsCalculator,
        protected BusinessDocumentIssueService $issueService,
    ) {}

    public function createFinalInvoice(Company $company, BusinessDocument $proforma): BusinessDocument
    {
        if ($proforma->company_id !== $company->id) {
            abort(404);
        }

        if ($proforma->type !== BusinessDocumentType::Proforma) {
            throw ValidationException::withMessages([
                'type' => ['Only proforma invoices can be converted to a final invoice.'],
            ]);
        }

        if ($proforma->status !== BusinessDocumentStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => ['Mark the proforma as paid before issuing a final invoice.'],
            ]);
        }

        if ($proforma->number === null) {
            throw ValidationException::withMessages([
                'status' => ['Issue the proforma before creating a final invoice.'],
            ]);
        }

        $existing = BusinessDocument::query()
            ->where('source_document_id', $proforma->id)
            ->where('type', BusinessDocumentType::Invoice)
            ->where('status', '!=', BusinessDocumentStatus::Cancelled)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'source_document_id' => ['A final invoice already exists for this proforma.'],
            ]);
        }

        $proforma->load(['lines', 'contact', 'company']);

        $settings = CompanyAppSettings::from($company->app_settings);
        $variableSymbol = $settings->bool('variable_symbol_from_proforma')
            ? $proforma->variable_symbol
            : null;

        $paidNote = $this->paidReferenceNote($proforma);
        $noteAbove = trim(implode("\n", array_filter([
            $proforma->note_above_lines,
            $paidNote,
        ])));

        $document = new BusinessDocument([
            'company_id' => $company->id,
            'company_contact_id' => $proforma->company_contact_id,
            'store_id' => $proforma->store_id,
            'source_document_id' => $proforma->id,
            'type' => BusinessDocumentType::Invoice,
            'status' => BusinessDocumentStatus::Draft,
            'title' => null,
            'variable_symbol' => $variableSymbol,
            'constant_symbol' => $proforma->constant_symbol,
            'specific_symbol' => $proforma->specific_symbol,
            'issue_date' => now()->toDateString(),
            'delivery_date' => $proforma->delivery_date?->toDateString(),
            'due_date' => now()->addDays($this->paymentTermsDays($proforma))->toDateString(),
            'currency' => $proforma->currency,
            'discount_percent' => $proforma->discount_percent,
            'note_above_lines' => $noteAbove !== '' ? $noteAbove : null,
            'note_footer' => $proforma->note_footer,
            'internal_note' => $proforma->internal_note,
            'pdf_locale' => $proforma->pdf_locale,
            'pdf_show_signature' => $proforma->pdf_show_signature,
            'pdf_show_payment_info' => $proforma->pdf_show_payment_info,
            'payment_btc_enabled' => $proforma->payment_btc_enabled,
            'payment_bank_enabled' => $proforma->payment_bank_enabled,
            'tags' => $proforma->tags,
        ]);

        $document->setRelation('company', $company);
        $lines = $proforma->lines->map(fn (BusinessDocumentLine $line) => [
            'name' => $line->name,
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'unit' => $line->unit,
            'unit_price' => (float) $line->unit_price,
            'line_discount_percent' => (float) $line->line_discount_percent,
            'tax_rate' => (float) $line->tax_rate,
        ])->all();

        $this->totalsCalculator->applyToDocument(
            $document,
            $lines,
            (float) $proforma->discount_percent
        );

        $document->save();

        foreach ($lines as $index => $line) {
            $qty = (float) ($line['quantity'] ?? 1);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $lineDiscount = (float) ($line['line_discount_percent'] ?? 0);
            $taxRate = (float) ($line['tax_rate'] ?? 0);
            $lineNet = $qty * $unitPrice * (1 - $lineDiscount / 100);
            $buyer = $document->resolvedBuyer();
            $vatPolicy = app(CompanyVatPolicy::class);
            $taxRate = $vatPolicy->resolveLineTaxRate($company, $buyer, $taxRate);
            $lineTax = $vatPolicy->calculatesVatAmounts($company, $buyer) ? $lineNet * ($taxRate / 100) : 0;

            BusinessDocumentLine::create([
                'business_document_id' => $document->id,
                'sort_order' => $index,
                'name' => $line['name'],
                'description' => $line['description'] ?? null,
                'quantity' => $qty,
                'unit' => $line['unit'] ?? 'pcs',
                'unit_price' => $unitPrice,
                'line_discount_percent' => $lineDiscount,
                'tax_rate' => $taxRate,
                'line_total' => number_format($lineNet + $lineTax, 2, '.', ''),
            ]);
        }

        $document = $this->issueService->issue($document);

        $document->update([
            'title' => $this->finalInvoiceTitle($document->number),
            'status' => BusinessDocumentStatus::Paid,
            'paid_at' => $proforma->paid_at ?? now(),
            'amount_paid' => $proforma->amount_paid ?? $proforma->total,
        ]);

        AuditLog::log('business_document.final_from_proforma', 'business_document', $document->id, [
            'company_id' => $company->id,
            'proforma_id' => $proforma->id,
            'proforma_number' => $proforma->number,
            'invoice_number' => $document->number,
        ]);

        return $document->fresh(['lines', 'contact', 'store', 'sourceDocument']);
    }

    protected function finalInvoiceTitle(string $number): string
    {
        return 'Faktúra '.$number;
    }

    protected function paymentTermsDays(BusinessDocument $proforma): int
    {
        if ($proforma->relationLoaded('contact') && $proforma->contact) {
            return (int) $proforma->contact->default_payment_terms_days;
        }

        return 14;
    }

    protected function paidReferenceNote(BusinessDocument $proforma): string
    {
        $amount = number_format((float) $proforma->total, 2, ',', ' ');
        $currency = $proforma->currency ?? 'EUR';
        $datePart = $proforma->paid_at
            ? ' dňa '.$proforma->paid_at->format('d.m.Y')
            : '';

        return sprintf(
            'K zálohovej faktúre č. %s uhradená suma %s %s%s.',
            $proforma->number,
            $amount,
            $currency,
            $datePart
        );
    }
}
