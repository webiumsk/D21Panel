<?php

namespace App\Services\Invoicing;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Services\Invoicing\UsSalesTax\UsSalesTaxCalculationService;
use App\Support\Invoicing\Canonical\CanonicalInvoice;
use App\Support\Invoicing\Canonical\CanonicalInvoiceLine;
use App\Support\Invoicing\Canonical\CanonicalTaxBreakdownRow;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyVatPolicy;

class CanonicalInvoiceBuilder
{
    public function __construct(
        protected UsSalesTaxCalculationService $usSalesTax,
        protected CompanyVatPolicy $vatPolicy,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $linePayloads
     */
    public function fromLinePayloads(
        Company $company,
        array $linePayloads,
        float $documentDiscountPercent = 0,
        ?BusinessDocument $document = null,
        ?CompanyContact $contact = null,
    ): CanonicalInvoice {
        $settings = CompanyAppSettings::from($company->app_settings);
        $roundingMethod = (string) $settings->get('rounding_method', 'per_line');

        $computedLines = [];
        foreach ($linePayloads as $index => $payload) {
            $computedLines[] = $this->computeLineFromPayload($company, $payload, $index, $roundingMethod, $contact);
        }

        $currency = $document?->currency ?? $company->default_currency ?? 'EUR';
        $usResult = $this->usSalesTax->applyIfNeeded($company, $contact, $computedLines, $currency);

        return $this->assemble(
            $company,
            $contact,
            $document,
            $usResult->lines,
            $documentDiscountPercent,
            $roundingMethod,
            $currency,
            $document !== null ? (float) ($document->amount_paid ?? 0) : 0.0,
            $usResult->taxBreakdown !== [] ? $usResult->taxBreakdown : null,
        );
    }

    public function fromDocument(BusinessDocument $document): CanonicalInvoice
    {
        $document->loadMissing(['company', 'contact', 'lines']);

        $buyer = $document->resolvedBuyer();

        $company = $document->company;
        $settings = CompanyAppSettings::from($company->app_settings);
        $roundingMethod = (string) $settings->get('rounding_method', 'per_line');

        $computedLines = [];
        foreach ($document->lines as $line) {
            $computedLines[] = $this->computeLineFromModel($company, $line, $roundingMethod, $buyer);
        }

        $currency = $document->currency ?: $company->default_currency ?? 'EUR';
        $usResult = $this->usSalesTax->applyIfNeeded($company, $buyer, $computedLines, $currency);

        return $this->assemble(
            $company,
            $buyer,
            $document,
            $usResult->lines,
            (float) $document->discount_percent,
            $roundingMethod,
            $currency,
            (float) ($document->amount_paid ?? 0),
            $usResult->taxBreakdown !== [] ? $usResult->taxBreakdown : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{net: float, tax: float, gross: float, tax_rate: float}
     */
    public function computeLineAmounts(Company $company, array $payload, ?CompanyContact $contact = null): array
    {
        $settings = CompanyAppSettings::from($company->app_settings);
        $roundingMethod = (string) $settings->get('rounding_method', 'per_line');
        $line = $this->computeLineFromPayload($company, $payload, 0, $roundingMethod, $contact);

        return [
            'net' => (float) $line->netAmount,
            'tax' => (float) $line->taxAmount,
            'gross' => (float) $line->grossAmount,
            'tax_rate' => $line->taxRate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function computeLineFromPayload(
        Company $company,
        array $payload,
        int $sortOrder,
        string $roundingMethod,
        ?CompanyContact $contact = null,
    ): CanonicalInvoiceLine {
        $qty = (float) ($payload['quantity'] ?? 1);
        $unitPrice = (float) ($payload['unit_price'] ?? 0);
        $lineDiscount = (float) ($payload['line_discount_percent'] ?? 0);
        $taxRate = $this->vatPolicy->resolveLineTaxRate(
            $company,
            $contact,
            isset($payload['tax_rate']) ? (float) $payload['tax_rate'] : null,
        );

        [$net, $tax, $gross] = $this->rawLineAmounts($company, $contact, $qty, $unitPrice, $lineDiscount, $taxRate, $roundingMethod);

        return new CanonicalInvoiceLine(
            name: (string) ($payload['name'] ?? ''),
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            quantity: $qty,
            unit: isset($payload['unit']) ? (string) $payload['unit'] : null,
            unitPrice: $unitPrice,
            lineDiscountPercent: $lineDiscount,
            taxRate: $taxRate,
            netAmount: $this->formatMoney($net),
            taxAmount: $this->formatMoney($tax),
            grossAmount: $this->formatMoney($gross),
            sortOrder: $sortOrder,
        );
    }

    protected function computeLineFromModel(
        Company $company,
        BusinessDocumentLine $line,
        string $roundingMethod,
        ?CompanyContact $contact = null,
    ): CanonicalInvoiceLine {
        $qty = (float) $line->quantity;
        $unitPrice = (float) $line->unit_price;
        $lineDiscount = (float) $line->line_discount_percent;
        $taxRate = $this->vatPolicy->resolveLineTaxRate($company, $contact, (float) $line->tax_rate);

        [$net, $tax, $gross] = $this->rawLineAmounts($company, $contact, $qty, $unitPrice, $lineDiscount, $taxRate, $roundingMethod);

        return new CanonicalInvoiceLine(
            name: $line->name,
            description: $line->description,
            quantity: $qty,
            unit: $line->unit,
            unitPrice: $unitPrice,
            lineDiscountPercent: $lineDiscount,
            taxRate: $taxRate,
            netAmount: $this->formatMoney($net),
            taxAmount: $this->formatMoney($tax),
            grossAmount: $this->formatMoney($gross),
            sortOrder: (int) $line->sort_order,
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    protected function rawLineAmounts(
        Company $company,
        ?CompanyContact $contact,
        float $qty,
        float $unitPrice,
        float $lineDiscountPercent,
        float $taxRate,
        string $roundingMethod,
    ): array {
        $net = $qty * $unitPrice * (1 - $lineDiscountPercent / 100);
        $tax = $this->vatPolicy->calculatesVatAmounts($company, $contact) ? $net * ($taxRate / 100) : 0.0;

        if ($roundingMethod === 'per_line') {
            $net = round($net, 2);
            $tax = round($tax, 2);
        }

        $gross = $net + $tax;

        if ($roundingMethod === 'per_line') {
            $gross = round($gross, 2);
        }

        return [$net, $tax, $gross];
    }

    /**
     * @param  list<CanonicalInvoiceLine>  $lines
     */
    protected function assemble(
        Company $company,
        ?CompanyContact $contact,
        ?BusinessDocument $document,
        array $lines,
        float $documentDiscountPercent,
        string $roundingMethod,
        string $currency,
        float $amountPaid,
        ?array $taxBreakdownOverride = null,
    ): CanonicalInvoice {
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $breakdownBuckets = [];

        foreach ($lines as $line) {
            $net = (float) $line->netAmount;
            $tax = (float) $line->taxAmount;
            $subtotal += $net;
            $taxTotal += $tax;

            if ($this->vatPolicy->calculatesVatAmounts($company, $contact) && $tax > 0) {
                $key = $this->taxRateKey($line->taxRate);
                if (! isset($breakdownBuckets[$key])) {
                    $breakdownBuckets[$key] = ['rate' => $line->taxRate, 'net' => 0.0, 'tax' => 0.0];
                }
                $breakdownBuckets[$key]['net'] += $net;
                $breakdownBuckets[$key]['tax'] += $tax;
            }
        }

        $gross = $subtotal + $taxTotal;
        $total = $gross * (1 - $documentDiscountPercent / 100);

        if ($documentDiscountPercent > 0 && $gross > 0) {
            $ratio = $total / $gross;
            $subtotal *= $ratio;
            $taxTotal *= $ratio;
            foreach ($breakdownBuckets as $key => $bucket) {
                $breakdownBuckets[$key]['net'] *= $ratio;
                $breakdownBuckets[$key]['tax'] *= $ratio;
            }
        }

        if ($roundingMethod !== 'none') {
            $subtotal = round($subtotal, 2);
            $taxTotal = round($taxTotal, 2);
            $total = round($total, 2);
        }

        if ($taxBreakdownOverride !== null) {
            $taxBreakdown = $this->scaleTaxBreakdown($taxBreakdownOverride, $documentDiscountPercent, $gross, $total);
        } else {
            $taxBreakdown = [];
            ksort($breakdownBuckets);
            foreach ($breakdownBuckets as $bucket) {
                $net = round($bucket['net'], 2);
                $tax = round($bucket['tax'], 2);
                $taxBreakdown[] = new CanonicalTaxBreakdownRow(
                    ratePercent: $bucket['rate'],
                    taxableAmount: $this->formatMoney($net),
                    taxAmount: $this->formatMoney($tax),
                    grossAmount: $this->formatMoney($net + $tax),
                );
            }
        }

        $amountDue = max(0, $total - $amountPaid);

        return new CanonicalInvoice(
            company: $company,
            contact: $contact,
            document: $document,
            subtotal: $this->formatMoney($subtotal),
            taxTotal: $this->formatMoney($taxTotal),
            total: $this->formatMoney($total),
            discountPercent: $documentDiscountPercent,
            amountDue: $this->formatMoney($amountDue),
            roundingMethod: $roundingMethod,
            lines: $lines,
            taxBreakdown: $taxBreakdown,
            currency: $currency,
        );
    }

    /**
     * @param  list<CanonicalTaxBreakdownRow>  $rows
     * @return list<CanonicalTaxBreakdownRow>
     */
    protected function scaleTaxBreakdown(array $rows, float $documentDiscountPercent, float $gross, float $total): array
    {
        if ($documentDiscountPercent <= 0 || $gross <= 0) {
            return $rows;
        }

        $ratio = $total / $gross;
        $scaled = [];
        foreach ($rows as $row) {
            $net = (float) $row->taxableAmount * $ratio;
            $tax = (float) $row->taxAmount * $ratio;
            $scaled[] = new CanonicalTaxBreakdownRow(
                ratePercent: $row->ratePercent,
                taxableAmount: $this->formatMoney($net),
                taxAmount: $this->formatMoney($tax),
                grossAmount: $this->formatMoney($net + $tax),
                label: $row->label,
            );
        }

        return $scaled;
    }

    protected function taxRateKey(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }

    protected function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
