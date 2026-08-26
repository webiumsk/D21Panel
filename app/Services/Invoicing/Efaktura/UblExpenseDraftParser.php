<?php

namespace App\Services\Invoicing\Efaktura;

use App\Services\Compliance\XmlParser;
use Carbon\Carbon;

class UblExpenseDraftParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $ublXml): array
    {
        $root = XmlParser::loadString($ublXml, 'UBL expense');
        $root->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $root->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $invoiceNumber = $this->xpathString($root, '//cbc:ID');
        $issueDate = $this->parseDate($this->xpathString($root, '//cbc:IssueDate'));
        $dueDate = $this->parseDate($this->xpathString($root, '//cbc:DueDate'));
        $currency = $this->xpathString($root, '//cbc:DocumentCurrencyCode') ?: 'EUR';
        $supplierName = $this->xpathString($root, '//cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name');
        $total = $this->parseAmount(
            $this->xpathString($root, '//cac:LegalMonetaryTotal/cbc:PayableAmount')
            ?: $this->xpathString($root, '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')
            ?: '0',
        );
        $paymentId = $this->xpathString($root, '//cbc:BuyerReference');
        $typeCode = $this->xpathString($root, '//cbc:InvoiceTypeCode')
            ?: $this->xpathString($root, '//cbc:CreditNoteTypeCode');
        // 389 = self-billed invoice, 261 = self-billed credit note (UNTDID 1001).
        // A self-billed document received by the SUPPLIER is their own sale
        // (revenue), not an expense - the client must not file it as a cost.
        $selfBilled = in_array($typeCode, ['389', '261'], true);

        $draft = [
            'external_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            'title' => $supplierName !== '' ? $supplierName : 'Prijatá e-faktúra',
            'variable_symbol' => $paymentId !== '' ? preg_replace('/\D/', '', $paymentId) : null,
            'issue_date' => $issueDate ?? now()->toDateString(),
            'delivery_date' => $issueDate,
            'due_date' => $dueDate,
            'total' => $total,
            'currency' => $currency,
            'document_type_code' => $typeCode !== '' ? $typeCode : null,
            'self_billed' => $selfBilled,
            'internal_note' => 'Importované z Peppol (SAPI-SK).',
        ];

        // A self-billed document is booked by the SUPPLIER as an issued document
        // (D3.2): capture the counterparty (the UBL customer, who created it) and
        // the lines so the client can reconstruct the invoice, not just a total.
        if ($selfBilled) {
            $draft['customer'] = $this->parseCustomerParty($root);
            $draft['lines'] = $this->parseLines($root);
        }

        return $draft;
    }

    /**
     * @return array<string, string|null>
     */
    protected function parseCustomerParty(\SimpleXMLElement $root): array
    {
        $base = '//cac:AccountingCustomerParty/cac:Party';
        $nullable = fn (string $q): ?string => ($v = $this->xpathString($root, $q)) !== '' ? $v : null;

        return [
            'name' => $nullable($base.'/cac:PartyName/cbc:Name'),
            'legal_name' => $nullable($base.'/cac:PartyLegalEntity/cbc:RegistrationName'),
            'registration_number' => $nullable($base.'/cac:PartyLegalEntity/cbc:CompanyID'),
            'vat_number' => $nullable($base.'/cac:PartyTaxScheme/cbc:CompanyID'),
            'street' => $nullable($base.'/cac:PostalAddress/cbc:StreetName'),
            'city' => $nullable($base.'/cac:PostalAddress/cbc:CityName'),
            'postal_code' => $nullable($base.'/cac:PostalAddress/cbc:PostalZone'),
            'country' => $nullable($base.'/cac:PostalAddress/cac:Country/cbc:IdentificationCode'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseLines(\SimpleXMLElement $root): array
    {
        $nodes = $root->xpath('//cac:InvoiceLine | //cac:CreditNoteLine');
        if ($nodes === false || $nodes === []) {
            return [];
        }

        $lines = [];
        foreach ($nodes as $node) {
            $node->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $node->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

            $qtyNode = $node->xpath('cbc:InvoicedQuantity | cbc:CreditedQuantity');
            $qty = ($qtyNode !== false && $qtyNode !== []) ? $qtyNode[0] : null;

            $lines[] = [
                'name' => $this->xpathString($node, 'cac:Item/cbc:Name') ?: 'Položka',
                'description' => $this->nullableString($node, 'cac:Item/cbc:Description'),
                'quantity' => $this->parseAmount($qty !== null ? (string) $qty : '1') ?: '1',
                'unit' => $qty !== null ? trim((string) ($qty['unitCode'] ?? '')) : '',
                'unit_price' => $this->unitPrice($node),
                'tax_rate' => $this->parseAmount($this->xpathString($node, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent') ?: '0'),
                'line_total' => $this->parseAmount($this->xpathString($node, 'cbc:LineExtensionAmount') ?: '0'),
            ];
        }

        return $lines;
    }

    /**
     * BT-146 PriceAmount is the net price for BT-149 BaseQuantity units, so the
     * per-unit price is PriceAmount / BaseQuantity. A missing base quantity
     * means 1; a zero (invalid) base quantity falls back to the raw amount
     * rather than dividing by zero.
     */
    protected function unitPrice(\SimpleXMLElement $node): string
    {
        $priceAmount = $this->parseAmount($this->xpathString($node, 'cac:Price/cbc:PriceAmount') ?: '0');
        $baseRaw = $this->xpathString($node, 'cac:Price/cbc:BaseQuantity');
        $baseQuantity = $baseRaw !== '' ? $this->parseAmount($baseRaw) : '1';

        if ((float) $baseQuantity <= 0.0) {
            return $priceAmount;
        }

        return bcdiv($priceAmount, $baseQuantity, 2);
    }

    protected function nullableString(\SimpleXMLElement $root, string $query): ?string
    {
        $value = $this->xpathString($root, $query);

        return $value !== '' ? $value : null;
    }

    protected function xpathString(\SimpleXMLElement $root, string $query): string
    {
        $nodes = $root->xpath($query);
        if ($nodes === false || $nodes === []) {
            return '';
        }

        return trim((string) $nodes[0]);
    }

    protected function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseAmount(string $value): string
    {
        $normalized = str_replace(',', '.', trim($value));
        if ($normalized === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            return '0.00';
        }

        return bcadd($normalized, '0', 2);
    }
}
