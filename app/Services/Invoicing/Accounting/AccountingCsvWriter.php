<?php

namespace App\Services\Invoicing\Accounting;

use App\Support\Invoicing\Accounting\ReceivedExpenseItem;
use App\Support\Invoicing\Canonical\CanonicalInvoice;
use DateTimeInterface;

/**
 * Generic spreadsheet-friendly CSV files for accountants whose software has
 * no structured import (or who reconcile in Excel): RFC 4180 quoting, UTF-8
 * BOM so Excel picks the encoding, CRLF line ends and a formula-injection
 * guard (mirrors resources/js/evolu/gobdExport.ts).
 */
class AccountingCsvWriter
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  list<CanonicalInvoice>  $issued
     */
    public function issued(array $issued): string
    {
        $rows = [[
            'number', 'type', 'status', 'issue_date', 'delivery_date', 'due_date', 'paid_at',
            'buyer_name', 'buyer_registration_number', 'buyer_tax_id', 'buyer_vat_id', 'buyer_country',
            'variable_symbol', 'currency', 'subtotal', 'tax_total', 'total', 'amount_paid',
        ]];

        foreach ($issued as $canonical) {
            $document = $canonical->document;
            if ($document === null) {
                continue;
            }
            $buyer = $canonical->contact;
            $rows[] = [
                (string) ($document->number ?: $document->id),
                $document->type->value,
                $document->status->value,
                $this->date($document->issue_date),
                $this->date($document->delivery_date),
                $this->date($document->due_date),
                $this->date($document->paid_at),
                $buyer?->name,
                $buyer?->registration_number,
                $buyer?->tax_id,
                $buyer?->vat_id,
                $buyer?->country,
                $document->variable_symbol,
                $canonical->currency,
                $canonical->subtotal,
                $canonical->taxTotal,
                $canonical->total,
                number_format((float) ($document->amount_paid ?? 0), 2, '.', ''),
            ];
        }

        return $this->csv($rows);
    }

    /**
     * @param  list<ReceivedExpenseItem>  $received
     */
    public function received(array $received): string
    {
        $rows = [[
            'internal_number', 'external_number', 'supplier_name', 'supplier_registration_number', 'supplier_tax_id', 'supplier_vat_id',
            'variable_symbol', 'issue_date', 'delivery_date', 'due_date', 'paid_at', 'status', 'currency', 'total', 'attachments',
        ]];

        foreach ($received as $expense) {
            $rows[] = [
                $expense->internalNumber,
                $expense->externalNumber,
                $expense->supplierName,
                $expense->supplierRegistrationNumber,
                $expense->supplierTaxId,
                $expense->supplierVatId,
                $expense->variableSymbol,
                $this->date($expense->issueDate),
                $this->date($expense->deliveryDate),
                $this->date($expense->dueDate),
                $this->date($expense->paidAt),
                $expense->status->value,
                $expense->currency,
                $expense->total,
                count($expense->attachments),
            ];
        }

        return $this->csv($rows);
    }

    /**
     * VAT summary per (currency, rate) across issued documents that apply VAT.
     *
     * @param  list<CanonicalInvoice>  $issued
     */
    public function vatSummary(array $issued): string
    {
        $buckets = [];
        foreach ($issued as $canonical) {
            if (! $canonical->vatApplicable()) {
                continue;
            }
            $sign = $canonical->document?->type->value === 'credit_note' ? -1 : 1;
            foreach ($canonical->taxBreakdown as $row) {
                $key = $canonical->currency.'|'.number_format($row->ratePercent, 2, '.', '');
                $buckets[$key] ??= [
                    'currency' => $canonical->currency,
                    'rate' => number_format($row->ratePercent, 2, '.', ''),
                    'taxable' => 0.0,
                    'tax' => 0.0,
                    'documents' => 0,
                ];
                $buckets[$key]['taxable'] += $sign * (float) $row->taxableAmount;
                $buckets[$key]['tax'] += $sign * (float) $row->taxAmount;
                $buckets[$key]['documents']++;
            }
        }
        ksort($buckets);

        $rows = [['currency', 'vat_rate', 'taxable_amount', 'vat_amount', 'gross_amount', 'documents']];
        foreach ($buckets as $bucket) {
            $rows[] = [
                $bucket['currency'],
                $bucket['rate'],
                number_format($bucket['taxable'], 2, '.', ''),
                number_format($bucket['tax'], 2, '.', ''),
                number_format($bucket['taxable'] + $bucket['tax'], 2, '.', ''),
                $bucket['documents'],
            ];
        }

        return $this->csv($rows);
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function csv(array $rows): string
    {
        $lines = array_map(
            fn (array $row): string => implode(',', array_map($this->cell(...), $row)),
            $rows,
        );

        return self::BOM.implode("\r\n", $lines)."\r\n";
    }

    protected function cell(string|int|float|null $value): string
    {
        $text = (string) ($value ?? '');
        // A leading =, +, -, @, tab or CR would execute as a formula when the
        // file is opened in a spreadsheet - neutralize it like the GoBD export.
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            $text = "'".$text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }

    protected function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }
}
