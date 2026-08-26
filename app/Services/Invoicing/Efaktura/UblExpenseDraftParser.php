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

        return [
            'external_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            'title' => $supplierName !== '' ? $supplierName : 'Prijatá e-faktúra',
            'variable_symbol' => $paymentId !== '' ? preg_replace('/\D/', '', $paymentId) : null,
            'issue_date' => $issueDate ?? now()->toDateString(),
            'delivery_date' => $issueDate,
            'due_date' => $dueDate,
            'total' => $total,
            'currency' => $currency,
            'internal_note' => 'Importované z Peppol (SAPI-SK).',
        ];
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
