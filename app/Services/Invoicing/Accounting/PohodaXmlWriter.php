<?php

namespace App\Services\Invoicing\Accounting;

use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Support\Invoicing\Accounting\PohodaVatRateMapper;
use App\Support\Invoicing\Accounting\ReceivedExpenseItem;
use App\Support\Invoicing\Canonical\CanonicalInvoice;
use App\Support\Invoicing\Canonical\CanonicalInvoiceLine;
use App\Support\Invoicing\JurisdictionRules;
use DateTimeInterface;
use XMLWriter;

/**
 * Stormware Pohoda XML import (dataPack 2.0, agenda "inv:invoice").
 *
 * Issued documents come from CanonicalInvoice (lines + VAT breakdown),
 * received ones from ReceivedExpenseItem (header + total only - expenses
 * carry no line items). Amounts of documents in the company's default
 * currency go into <homeCurrency>; other currencies into <foreignCurrency>
 * with an unknown exchange rate (rate 1, flagged in the note) because
 * Satflux does not store one - the accountant fixes it on import.
 *
 * Schema references: http://www.stormware.cz/schema/version_2/{data,invoice,type}.xsd
 */
class PohodaXmlWriter
{
    private const NS_DATA = 'http://www.stormware.cz/schema/version_2/data.xsd';

    private const NS_INVOICE = 'http://www.stormware.cz/schema/version_2/invoice.xsd';

    private const NS_TYPE = 'http://www.stormware.cz/schema/version_2/type.xsd';

    private const VERSION = '2.0';

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  list<CanonicalInvoice>  $issued
     * @param  list<ReceivedExpenseItem>  $received
     */
    public function write(Company $company, array $issued, array $received, string $packId = 'satflux'): string
    {
        $this->warnings = [];
        $mapper = new PohodaVatRateMapper($company->jurisdiction ?? CompanyJurisdiction::EuOther);
        $homeCurrency = strtoupper((string) ($company->default_currency ?: 'EUR'));

        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElementNs('dat', 'dataPack', self::NS_DATA);
        $writer->writeAttribute('xmlns:inv', self::NS_INVOICE);
        $writer->writeAttribute('xmlns:typ', self::NS_TYPE);
        $writer->writeAttribute('version', self::VERSION);
        $writer->writeAttribute('id', $this->safeId($packId));
        $writer->writeAttribute('ico', $this->digits((string) $company->registration_number));
        $writer->writeAttribute('application', 'Satflux');
        $writer->writeAttribute('note', 'Export pre uctovnika - satflux.io');

        $index = 0;
        foreach ($issued as $canonical) {
            $this->issuedItem($writer, $canonical, $mapper, $homeCurrency, ++$index);
        }
        foreach ($received as $expense) {
            $this->receivedItem($writer, $expense, $homeCurrency, ++$index);
        }

        $writer->endElement(); // dataPack
        $writer->endDocument();

        $jurisdiction = JurisdictionRules::normalizeValue($company->jurisdiction);
        foreach ($mapper->unmapped() as $rate) {
            $this->warnings[] = "VAT rate {$rate}% has no Pohoda bucket for {$jurisdiction} - exported as 'none', check in Pohoda.";
        }

        return $writer->outputMemory();
    }

    /**
     * Human-readable caveats collected during the last write() (unknown VAT
     * rates, foreign currencies without a rate) - surfaced in the manifest.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    protected function issuedItem(
        XMLWriter $writer,
        CanonicalInvoice $canonical,
        PohodaVatRateMapper $mapper,
        string $homeCurrency,
        int $index,
    ): void {
        $document = $canonical->document;
        if ($document === null) {
            return;
        }

        $number = (string) ($document->number ?: $document->id);
        $vatApplicable = $canonical->vatApplicable();
        $currency = strtoupper($canonical->currency);
        $foreign = $currency !== $homeCurrency;

        $writer->startElementNs('dat', 'dataPackItem', null);
        $writer->writeAttribute('version', self::VERSION);
        $writer->writeAttribute('id', $this->safeId("issued-{$index}-{$number}"));
        $writer->startElementNs('inv', 'invoice', null);
        $writer->writeAttribute('version', self::VERSION);

        // --- header ---------------------------------------------------
        $writer->startElementNs('inv', 'invoiceHeader', null);
        $this->el($writer, 'inv', 'invoiceType', $this->issuedType($document->type));
        $writer->startElementNs('inv', 'number', null);
        $this->el($writer, 'typ', 'numberRequested', $number);
        $writer->endElement();
        $this->el($writer, 'inv', 'symVar', $this->digits((string) ($document->variable_symbol ?: $number)) ?: null);
        $this->el($writer, 'inv', 'date', $this->date($document->issue_date));
        $this->el($writer, 'inv', 'dateTax', $this->date($document->delivery_date ?? $document->issue_date));
        $this->el($writer, 'inv', 'dateAccounting', $this->date($document->issue_date));
        $this->el($writer, 'inv', 'dateDue', $this->date($document->due_date));
        $this->el($writer, 'inv', 'text', $this->trim((string) ($document->title ?: $this->typeText($document->type)), 240));
        $this->partner($writer, $canonical->contact);
        $writer->startElementNs('inv', 'paymentType', null);
        $this->el($writer, 'typ', 'paymentType', $document->payment_btc_enabled && ! $document->payment_bank_enabled ? 'cash' : 'draft');
        $writer->endElement();
        $this->el($writer, 'inv', 'symConst', $this->digits((string) $document->constant_symbol) ?: null);
        $noteParts = array_filter([
            $foreign ? "Cudzia mena {$currency}: kurz nie je znamy, doplnte v Pohode." : null,
            $canonical->discountPercent > 0 ? "Zlava na doklad {$this->num($canonical->discountPercent)} % (uz zapocitana v sumach)." : null,
        ]);
        $this->el($writer, 'inv', 'note', $noteParts !== [] ? implode(' ', $noteParts) : null);
        $this->el($writer, 'inv', 'intNote', $this->trim((string) ($document->internal_note ?? ''), 240) ?: null);
        $writer->endElement(); // invoiceHeader

        // --- detail ---------------------------------------------------
        $writer->startElementNs('inv', 'invoiceDetail', null);
        foreach ($canonical->lines as $line) {
            $this->lineItem($writer, $line, $mapper, $vatApplicable, $foreign, $currency);
        }
        $writer->endElement(); // invoiceDetail

        // --- summary --------------------------------------------------
        $writer->startElementNs('inv', 'invoiceSummary', null);
        $this->el($writer, 'inv', 'roundingDocument', 'none');
        $buckets = $this->summaryBuckets($canonical, $mapper, $vatApplicable);
        $writer->startElementNs('inv', 'homeCurrency', null);
        if ($foreign) {
            // Pohoda requires the home block even for foreign documents; the
            // real amounts live in foreignCurrency below (rate unknown).
            $this->el($writer, 'typ', 'priceNone', '0');
            $writer->startElementNs('typ', 'round', null);
            $this->el($writer, 'typ', 'priceRound', '0');
            $writer->endElement();
        } else {
            $this->el($writer, 'typ', 'priceNone', $this->money($buckets['none']['net']));
            $this->el($writer, 'typ', 'priceLow', $this->money($buckets['low']['net']));
            $this->el($writer, 'typ', 'priceLowVAT', $this->money($buckets['low']['vat']));
            $this->el($writer, 'typ', 'priceLowSum', $this->money($buckets['low']['net'] + $buckets['low']['vat']));
            $this->el($writer, 'typ', 'priceHigh', $this->money($buckets['high']['net']));
            $this->el($writer, 'typ', 'priceHighVAT', $this->money($buckets['high']['vat']));
            $this->el($writer, 'typ', 'priceHighSum', $this->money($buckets['high']['net'] + $buckets['high']['vat']));
            $this->el($writer, 'typ', 'price3', $this->money($buckets['third']['net']));
            $this->el($writer, 'typ', 'price3VAT', $this->money($buckets['third']['vat']));
            $this->el($writer, 'typ', 'price3Sum', $this->money($buckets['third']['net'] + $buckets['third']['vat']));
            $writer->startElementNs('typ', 'round', null);
            $this->el($writer, 'typ', 'priceRound', '0');
            $writer->endElement();
        }
        $writer->endElement(); // homeCurrency
        if ($foreign) {
            $this->warnings[] = "Document {$number} is in {$currency}; exchange rate unknown - exported with rate 1, adjust in Pohoda.";
            $writer->startElementNs('inv', 'foreignCurrency', null);
            $writer->startElementNs('typ', 'currency', null);
            $this->el($writer, 'typ', 'ids', $currency);
            $writer->endElement();
            $this->el($writer, 'typ', 'rate', '1');
            $this->el($writer, 'typ', 'amount', '1');
            $this->el($writer, 'typ', 'priceSum', $canonical->total);
            $writer->endElement(); // foreignCurrency
        }
        $writer->endElement(); // invoiceSummary

        $writer->endElement(); // invoice
        $writer->endElement(); // dataPackItem
    }

    protected function receivedItem(XMLWriter $writer, ReceivedExpenseItem $expense, string $homeCurrency, int $index): void
    {
        $currency = strtoupper($expense->currency);
        $foreign = $currency !== $homeCurrency;

        $writer->startElementNs('dat', 'dataPackItem', null);
        $writer->writeAttribute('version', self::VERSION);
        $writer->writeAttribute('id', $this->safeId("received-{$index}-{$expense->internalNumber}"));
        $writer->startElementNs('inv', 'invoice', null);
        $writer->writeAttribute('version', self::VERSION);

        $writer->startElementNs('inv', 'invoiceHeader', null);
        $this->el($writer, 'inv', 'invoiceType', 'receivedInvoice');
        $writer->startElementNs('inv', 'number', null);
        $this->el($writer, 'typ', 'numberRequested', $expense->internalNumber);
        $writer->endElement();
        $this->el($writer, 'inv', 'symVar', $this->digits((string) ($expense->variableSymbol ?? '')) ?: null);
        $this->el($writer, 'inv', 'originalDocument', $expense->externalNumber !== null ? $this->trim($expense->externalNumber, 32) : null);
        $this->el($writer, 'inv', 'date', $this->date($expense->issueDate));
        $this->el($writer, 'inv', 'dateTax', $this->date($expense->deliveryDate ?? $expense->issueDate));
        $this->el($writer, 'inv', 'dateAccounting', $this->date($expense->issueDate));
        $this->el($writer, 'inv', 'dateDue', $this->date($expense->dueDate));
        $this->el($writer, 'inv', 'text', $this->trim($expense->supplierName ?? $expense->externalNumber ?? 'Prijata faktura', 240));
        $writer->startElementNs('inv', 'partnerIdentity', null);
        $writer->startElementNs('typ', 'address', null);
        $this->el($writer, 'typ', 'company', $this->trim($expense->supplierName ?? '', 96) ?: null);
        $this->el($writer, 'typ', 'ico', $this->digits((string) ($expense->supplierRegistrationNumber ?? '')) ?: null);
        $this->el($writer, 'typ', 'dic', $expense->supplierTaxId !== null ? $this->trim($expense->supplierTaxId, 15) : null);
        $this->el($writer, 'typ', 'icDph', $expense->supplierVatId !== null ? $this->trim($expense->supplierVatId, 15) : null);
        $writer->endElement();
        $writer->endElement(); // partnerIdentity
        $writer->startElementNs('inv', 'paymentType', null);
        $this->el($writer, 'typ', 'paymentType', 'draft');
        $writer->endElement();
        $this->el($writer, 'inv', 'symConst', $this->digits((string) ($expense->constantSymbol ?? '')) ?: null);
        $noteParts = array_filter([
            'Naklad bez rozpisu poloziek - suma bez rozdelenia DPH, doplnte v Pohode.',
            $foreign ? "Cudzia mena {$currency}: kurz nie je znamy, doplnte v Pohode." : null,
        ]);
        $this->el($writer, 'inv', 'note', implode(' ', $noteParts));
        $this->el($writer, 'inv', 'intNote', $expense->note !== null ? $this->trim($expense->note, 240) : null);
        $writer->endElement(); // invoiceHeader

        $writer->startElementNs('inv', 'invoiceSummary', null);
        $this->el($writer, 'inv', 'roundingDocument', 'none');
        $writer->startElementNs('inv', 'homeCurrency', null);
        // No VAT breakdown is recorded for expenses: the whole amount goes to
        // the "none" bucket and the accountant splits it on import.
        $this->el($writer, 'typ', 'priceNone', $foreign ? '0' : $expense->total);
        $writer->startElementNs('typ', 'round', null);
        $this->el($writer, 'typ', 'priceRound', '0');
        $writer->endElement();
        $writer->endElement(); // homeCurrency
        if ($foreign) {
            $this->warnings[] = "Expense {$expense->internalNumber} is in {$currency}; exchange rate unknown - exported with rate 1, adjust in Pohoda.";
            $writer->startElementNs('inv', 'foreignCurrency', null);
            $writer->startElementNs('typ', 'currency', null);
            $this->el($writer, 'typ', 'ids', $currency);
            $writer->endElement();
            $this->el($writer, 'typ', 'rate', '1');
            $this->el($writer, 'typ', 'amount', '1');
            $this->el($writer, 'typ', 'priceSum', $expense->total);
            $writer->endElement();
        }
        $writer->endElement(); // invoiceSummary

        $writer->endElement(); // invoice
        $writer->endElement(); // dataPackItem
    }

    protected function lineItem(
        XMLWriter $writer,
        CanonicalInvoiceLine $line,
        PohodaVatRateMapper $mapper,
        bool $vatApplicable,
        bool $foreign,
        string $currency,
    ): void {
        $writer->startElementNs('inv', 'invoiceItem', null);
        $text = $line->name;
        if ($line->description !== null && trim($line->description) !== '') {
            $text .= ' - '.$line->description;
        }
        $this->el($writer, 'inv', 'text', $this->trim($text, 90));
        $this->el($writer, 'inv', 'quantity', $this->num($line->quantity));
        $this->el($writer, 'inv', 'unit', $line->unit !== null ? $this->trim($line->unit, 10) : null);
        $this->el($writer, 'inv', 'payVAT', 'false');
        $this->el($writer, 'inv', 'rateVAT', $vatApplicable ? $mapper->bucket($line->taxRate) : PohodaVatRateMapper::NONE);
        if ($line->lineDiscountPercent > 0) {
            $this->el($writer, 'inv', 'discountPercentage', $this->num($line->lineDiscountPercent));
        }
        $writer->startElementNs('inv', $foreign ? 'foreignCurrency' : 'homeCurrency', null);
        $this->el($writer, 'typ', 'unitPrice', $this->money($line->unitPrice));
        $this->el($writer, 'typ', 'price', $line->netAmount);
        $this->el($writer, 'typ', 'priceVAT', $line->taxAmount);
        $this->el($writer, 'typ', 'priceSum', $line->grossAmount);
        $writer->endElement();
        $writer->endElement(); // invoiceItem
    }

    protected function partner(XMLWriter $writer, ?CompanyContact $contact): void
    {
        if ($contact === null) {
            return;
        }

        $writer->startElementNs('inv', 'partnerIdentity', null);
        $writer->startElementNs('typ', 'address', null);
        $this->el($writer, 'typ', 'company', $this->trim((string) $contact->name, 96) ?: null);
        $this->el($writer, 'typ', 'city', $this->trim((string) ($contact->city ?? ''), 45) ?: null);
        $this->el($writer, 'typ', 'street', $this->trim((string) ($contact->street ?? ''), 64) ?: null);
        $this->el($writer, 'typ', 'zip', $this->trim((string) ($contact->postal_code ?? ''), 15) ?: null);
        $this->el($writer, 'typ', 'ico', $this->digits((string) ($contact->registration_number ?? '')) ?: null);
        $this->el($writer, 'typ', 'dic', $this->trim((string) ($contact->tax_id ?? ''), 15) ?: null);
        $this->el($writer, 'typ', 'icDph', $this->trim((string) ($contact->vat_id ?? ''), 15) ?: null);
        $country = strtoupper(trim((string) ($contact->country ?? '')));
        if ($country !== '') {
            $writer->startElementNs('typ', 'country', null);
            $this->el($writer, 'typ', 'ids', $country);
            $writer->endElement();
        }
        $this->el($writer, 'typ', 'email', $this->trim((string) ($contact->email ?? ''), 98) ?: null);
        $writer->endElement(); // address
        $writer->endElement(); // partnerIdentity
    }

    /**
     * @return array<string, array{net: float, vat: float}>
     */
    protected function summaryBuckets(CanonicalInvoice $canonical, PohodaVatRateMapper $mapper, bool $vatApplicable): array
    {
        $buckets = [
            PohodaVatRateMapper::NONE => ['net' => 0.0, 'vat' => 0.0],
            PohodaVatRateMapper::LOW => ['net' => 0.0, 'vat' => 0.0],
            PohodaVatRateMapper::HIGH => ['net' => 0.0, 'vat' => 0.0],
            PohodaVatRateMapper::THIRD => ['net' => 0.0, 'vat' => 0.0],
        ];

        if (! $vatApplicable) {
            $buckets[PohodaVatRateMapper::NONE]['net'] = (float) $canonical->total;

            return $buckets;
        }

        $taxedNet = 0.0;
        foreach ($canonical->taxBreakdown as $row) {
            $bucket = $mapper->bucket($row->ratePercent);
            $buckets[$bucket]['net'] += (float) $row->taxableAmount;
            $buckets[$bucket]['vat'] += (float) $row->taxAmount;
            $taxedNet += (float) $row->taxableAmount;
        }

        // Zero-rated lines never appear in the breakdown: whatever part of the
        // subtotal is not covered by a taxed bucket is VAT-free.
        $remainder = round((float) $canonical->subtotal - $taxedNet, 2);
        if ($remainder > 0) {
            $buckets[PohodaVatRateMapper::NONE]['net'] += $remainder;
        }

        return $buckets;
    }

    protected function issuedType(BusinessDocumentType $type): string
    {
        return match ($type) {
            BusinessDocumentType::CreditNote => 'issuedCreditNotice',
            BusinessDocumentType::Proforma => 'issuedAdvanceInvoice',
            default => 'issuedInvoice',
        };
    }

    protected function typeText(BusinessDocumentType $type): string
    {
        return match ($type) {
            BusinessDocumentType::CreditNote => 'Dobropis',
            BusinessDocumentType::Proforma => 'Zalohova faktura',
            default => 'Faktura',
        };
    }

    protected function el(XMLWriter $writer, string $prefix, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $writer->startElementNs($prefix, $name, null);
        $writer->text($value);
        $writer->endElement();
    }

    protected function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return null;
    }

    protected function money(float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    protected function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    protected function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    protected function trim(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    protected function safeId(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_\-.]+/', '-', $value) ?? 'satflux';

        return mb_substr(trim($value, '-'), 0, 60) ?: 'satflux';
    }
}
