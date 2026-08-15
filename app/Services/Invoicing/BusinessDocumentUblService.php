<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentType;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Support\Invoicing\Canonical\CanonicalInvoice;
use App\Support\Invoicing\Canonical\CanonicalInvoiceLine;
use App\Support\Invoicing\Canonical\CanonicalTaxBreakdownRow;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyVatPolicy;
use App\Support\Invoicing\DeXRechnungProfile;
use App\Support\Invoicing\EuStructuredDocumentExport;
use App\Support\Invoicing\SkUblProfile;
use XMLWriter;

/**
 * Minimal UBL 2.1 / EN 16931 invoice export from the canonical invoice snapshot.
 */
class BusinessDocumentUblService
{
    /** German companies export the XRechnung CIUS instead of plain Peppol BIS. */
    protected bool $xrechnung = false;

    /**
     * EN 16931 tax scenario of the whole invoice, resolved from the shared
     * VAT policy: null = normal S/Z behavior; otherwise every category on
     * the document carries this code and (on subtotals) the statutory
     * exemption reason. AE = reverse charge, E = exempt (§19
     * Kleinunternehmer), G = export outside the EU (goods), O = outside the
     * scope of VAT (exported services).
     *
     * @var array{category: string, reason: string|null}|null
     */
    protected ?array $taxScenario = null;

    public function __construct(
        protected CanonicalInvoiceBuilder $canonicalBuilder,
        protected CompanyVatPolicy $vatPolicy,
    ) {}

    public function supports(BusinessDocument $document): bool
    {
        return EuStructuredDocumentExport::supports($document);
    }

    public function xml(BusinessDocument $document, bool $auditDownload = true): string
    {
        $canonical = $this->canonicalBuilder->fromDocument($document);
        $document = $canonical->document ?? $document;

        $this->xrechnung = DeXRechnungProfile::appliesTo($canonical->company);
        $this->taxScenario = $this->resolveTaxScenario($canonical);
        $isCreditNote = $document->type === BusinessDocumentType::CreditNote;

        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');

        $rootNs = $isCreditNote
            ? 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2'
            : 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
        $rootLocal = $isCreditNote ? 'CreditNote' : 'Invoice';

        $writer->startElementNs(null, $rootLocal, $rootNs);
        $writer->writeAttributeNs('xmlns', 'cac', null, 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $writer->writeAttributeNs('xmlns', 'cbc', null, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $this->element($writer, 'cbc', 'CustomizationID', $this->xrechnung
            ? DeXRechnungProfile::CUSTOMIZATION_ID
            : 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $this->element($writer, 'cbc', 'ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->element($writer, 'cbc', 'ID', (string) $document->number);
        $this->element($writer, 'cbc', 'IssueDate', $document->issue_date?->format('Y-m-d') ?? now()->format('Y-m-d'));
        $this->element($writer, 'cbc', 'DueDate', $document->due_date?->format('Y-m-d'));
        $this->element($writer, 'cbc', 'DocumentCurrencyCode', $canonical->currency);
        // BT-10 is mandatory in XRechnung (BR-DE-15); Peppol keeps the
        // optional variable-symbol behavior.
        $this->element($writer, 'cbc', 'BuyerReference', $this->xrechnung
            ? DeXRechnungProfile::buyerReference($document)
            : $document->variable_symbol);
        if ($isCreditNote) {
            $this->element($writer, 'cbc', 'CreditNoteTypeCode', $this->invoiceTypeCode($document->type));
        } else {
            $this->element($writer, 'cbc', 'InvoiceTypeCode', $this->invoiceTypeCode($document->type));
        }

        if ($document->note_footer) {
            $this->element($writer, 'cbc', 'Note', $document->note_footer);
        }

        $this->party($writer, 'AccountingSupplierParty', $canonical->company);
        if ($canonical->contact) {
            $this->party($writer, 'AccountingCustomerParty', $canonical->contact);
        }

        if (! $isCreditNote) {
            $this->paymentMeans($writer, $document, $canonical->company);
        }

        $this->taxTotal($writer, $canonical);
        $this->legalMonetaryTotal($writer, $canonical);

        foreach ($canonical->lines as $index => $line) {
            $this->invoiceLine($writer, $line, $index + 1, $canonical, $isCreditNote);
        }

        $writer->endElement();
        $writer->endDocument();

        $xml = $writer->outputMemory();

        if ($auditDownload) {
            AuditLog::log('business_document.ubl_downloaded', 'business_document', $document->id, [
                'company_id' => $document->company_id,
                'number' => $document->number,
                'format' => 'ubl',
            ]);
        }

        return $xml;
    }

    /**
     * @return array{category: string, reason: string|null}|null
     */
    protected function resolveTaxScenario(CanonicalInvoice $canonical): ?array
    {
        // getAttribute keeps the type mixed - the app_settings PHPDoc says
        // string while the cast yields an array (pre-existing looseness).
        $rawSettings = $canonical->company->getAttribute('app_settings');

        return $this->vatPolicy->enTaxScenario(
            $canonical->company,
            $canonical->contact,
            CompanyAppSettings::from(is_array($rawSettings) ? $rawSettings : null),
        );
    }

    protected function invoiceTypeCode(BusinessDocumentType $type): string
    {
        return match ($type) {
            BusinessDocumentType::CreditNote => '381',
            BusinessDocumentType::Proforma => '325',
            default => '380',
        };
    }

    protected function taxTotal(XMLWriter $writer, CanonicalInvoice $canonical): void
    {
        $writer->startElementNs('cac', 'TaxTotal', null);
        $this->amountElement($writer, 'cbc', 'TaxAmount', $canonical->taxTotal, $canonical->currency);

        foreach ($canonical->taxBreakdown as $row) {
            $this->taxSubtotal($writer, $row, $canonical->currency);
        }

        if ($canonical->taxBreakdown === [] && (float) $canonical->taxTotal <= 0) {
            $this->taxSubtotalZero($writer, $canonical);
        }

        $writer->endElement();
    }

    protected function taxSubtotal(XMLWriter $writer, CanonicalTaxBreakdownRow $row, string $currency): void
    {
        $writer->startElementNs('cac', 'TaxSubtotal', null);
        $this->amountElement($writer, 'cbc', 'TaxableAmount', $row->taxableAmount, $currency);
        $this->amountElement($writer, 'cbc', 'TaxAmount', $row->taxAmount, $currency);
        $writer->startElementNs('cac', 'TaxCategory', null);
        $this->element($writer, 'cbc', 'ID', $this->taxScenario['category'] ?? 'S');
        $this->element($writer, 'cbc', 'Percent', $this->formatRate($row->ratePercent));
        $this->element($writer, 'cbc', 'TaxExemptionReason', $this->taxScenario['reason'] ?? null);
        $writer->startElementNs('cac', 'TaxScheme', null);
        $this->element($writer, 'cbc', 'ID', 'VAT');
        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
    }

    protected function taxSubtotalZero(XMLWriter $writer, CanonicalInvoice $canonical): void
    {
        $writer->startElementNs('cac', 'TaxSubtotal', null);
        $this->amountElement($writer, 'cbc', 'TaxableAmount', $canonical->subtotal, $canonical->currency);
        $this->amountElement($writer, 'cbc', 'TaxAmount', '0.00', $canonical->currency);
        $writer->startElementNs('cac', 'TaxCategory', null);
        $this->element($writer, 'cbc', 'ID', $this->taxScenario['category'] ?? 'Z');
        $this->element($writer, 'cbc', 'Percent', '0');
        $this->element($writer, 'cbc', 'TaxExemptionReason', $this->taxScenario['reason'] ?? null);
        $writer->startElementNs('cac', 'TaxScheme', null);
        $this->element($writer, 'cbc', 'ID', 'VAT');
        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
    }

    protected function legalMonetaryTotal(XMLWriter $writer, CanonicalInvoice $canonical): void
    {
        $writer->startElementNs('cac', 'LegalMonetaryTotal', null);
        $this->amountElement($writer, 'cbc', 'LineExtensionAmount', $canonical->subtotal, $canonical->currency);
        $this->amountElement($writer, 'cbc', 'TaxExclusiveAmount', $canonical->subtotal, $canonical->currency);
        $this->amountElement($writer, 'cbc', 'TaxInclusiveAmount', $canonical->total, $canonical->currency);
        $this->amountElement($writer, 'cbc', 'PayableAmount', $canonical->amountDue, $canonical->currency);
        $writer->endElement();
    }

    protected function invoiceLine(
        XMLWriter $writer,
        CanonicalInvoiceLine $line,
        int $lineId,
        CanonicalInvoice $canonical,
        bool $isCreditNote,
    ): void {
        $tag = $isCreditNote ? 'CreditNoteLine' : 'InvoiceLine';

        $writer->startElementNs('cac', $tag, null);
        $this->element($writer, 'cbc', 'ID', (string) $lineId);
        $this->quantityElement($writer, $line, $isCreditNote);
        $this->amountElement($writer, 'cbc', 'LineExtensionAmount', $line->netAmount, $canonical->currency);

        $writer->startElementNs('cac', 'Item', null);
        $this->element($writer, 'cbc', 'Name', $line->name);
        if ($line->description) {
            $this->element($writer, 'cbc', 'Description', $line->description);
        }
        $writer->startElementNs('cac', 'ClassifiedTaxCategory', null);
        $this->element($writer, 'cbc', 'ID', $this->taxScenario['category'] ?? ((float) $line->taxRate > 0 ? 'S' : 'Z'));
        $this->element($writer, 'cbc', 'Percent', $this->formatRate($line->taxRate));
        $writer->startElementNs('cac', 'TaxScheme', null);
        $this->element($writer, 'cbc', 'ID', 'VAT');
        $writer->endElement();
        $writer->endElement();
        $writer->endElement();

        $writer->startElementNs('cac', 'Price', null);
        $this->amountElement($writer, 'cbc', 'PriceAmount', $this->formatMoney($line->unitPrice), $canonical->currency);
        $writer->endElement();

        $writer->endElement();
    }

    protected function paymentMeans(XMLWriter $writer, BusinessDocument $document, Company $company): void
    {
        $iban = preg_replace('/\s+/', '', (string) ($company->iban ?? ''));
        if ($iban === '') {
            return;
        }

        $writer->startElementNs('cac', 'PaymentMeans', null);
        // 58 = SEPA credit transfer (XRechnung/BR-DE-23 expectation for IBAN).
        $this->element($writer, 'cbc', 'PaymentMeansCode', $this->xrechnung ? '58' : '30');
        if ($document->variable_symbol) {
            $this->element($writer, 'cbc', 'PaymentID', $document->variable_symbol);
        }
        $writer->startElementNs('cac', 'PayeeFinancialAccount', null);
        $this->element($writer, 'cbc', 'ID', $iban);
        if ($company->bic) {
            $writer->startElementNs('cac', 'FinancialInstitutionBranch', null);
            $this->element($writer, 'cbc', 'ID', $company->bic);
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endElement();
    }

    protected function party(XMLWriter $writer, string $wrapper, Company|CompanyContact $entity): void
    {
        $writer->startElementNs('cac', $wrapper, null);
        $writer->startElementNs('cac', 'Party', null);

        // XRechnung parties carry electronic addresses (e-mail with the EM
        // scheme) - German registers are not Peppol participant ids.
        $endpoint = $this->xrechnung
            ? DeXRechnungProfile::electronicAddress($entity)
            : SkUblProfile::resolveEndpoint($entity);
        if ($endpoint !== null) {
            $writer->startElementNs('cbc', 'EndpointID', null);
            $writer->writeAttribute('schemeID', $endpoint['scheme']);
            $writer->text($endpoint['id']);
            $writer->endElement();
        }

        $writer->startElementNs('cac', 'PartyName', null);
        $this->element($writer, 'cbc', 'Name', SkUblProfile::partyDisplayName($entity));
        $writer->endElement();

        $writer->startElementNs('cac', 'PostalAddress', null);
        $this->element($writer, 'cbc', 'StreetName', $entity->street);
        $this->element($writer, 'cbc', 'CityName', $entity->city);
        $this->element($writer, 'cbc', 'PostalZone', $entity->postal_code);
        $writer->startElementNs('cac', 'Country', null);
        $this->element($writer, 'cbc', 'IdentificationCode', SkUblProfile::countryCode($entity));
        $writer->endElement();
        $writer->endElement();

        // UBL schema order inside cac:Party: PartyTaxScheme precedes
        // PartyLegalEntity, Contact comes last.
        $vat = $entity instanceof Company ? $entity->vat_number : $entity->vat_id;
        if ($vat) {
            $writer->startElementNs('cac', 'PartyTaxScheme', null);
            $this->element($writer, 'cbc', 'CompanyID', $vat);
            $writer->startElementNs('cac', 'TaxScheme', null);
            $this->element($writer, 'cbc', 'ID', 'VAT');
            $writer->endElement();
            $writer->endElement();
        }

        $legalEntity = $this->xrechnung
            ? DeXRechnungProfile::legalEntityId($entity)
            : SkUblProfile::resolveLegalEntityId($entity);
        if ($legalEntity !== null) {
            $writer->startElementNs('cac', 'PartyLegalEntity', null);
            $this->element($writer, 'cbc', 'RegistrationName', SkUblProfile::partyDisplayName($entity));
            $writer->startElementNs('cbc', 'CompanyID', null);
            if ($legalEntity['scheme'] !== null) {
                $writer->writeAttribute('schemeID', $legalEntity['scheme']);
            }
            $writer->text($legalEntity['id']);
            $writer->endElement();
            $writer->endElement();
        }

        // BG-6 seller contact - required by XRechnung (BR-DE-2/5/6).
        if ($this->xrechnung && $wrapper === 'AccountingSupplierParty' && $entity instanceof Company) {
            $contactName = trim((string) ($entity->issuer_name ?: $entity->legal_name));
            $phone = trim((string) $entity->issuer_phone);
            $email = trim((string) $entity->issuer_email);
            if ($contactName !== '' || $phone !== '' || $email !== '') {
                $writer->startElementNs('cac', 'Contact', null);
                $this->element($writer, 'cbc', 'Name', $contactName);
                $this->element($writer, 'cbc', 'Telephone', $phone);
                $this->element($writer, 'cbc', 'ElectronicMail', $email);
                $writer->endElement();
            }
        }

        $writer->endElement();
        $writer->endElement();
    }

    protected function quantityElement(XMLWriter $writer, CanonicalInvoiceLine $line, bool $isCreditNote = false): void
    {
        $tag = $isCreditNote ? 'CreditedQuantity' : 'InvoicedQuantity';
        $writer->startElementNs('cbc', $tag, null);
        $unitCode = SkUblProfile::resolveUnitCode($line->unit);
        if ($unitCode !== null) {
            $writer->writeAttribute('unitCode', $unitCode);
        }
        $writer->text($this->formatQty($line->quantity));
        $writer->endElement();
    }

    protected function amountElement(XMLWriter $writer, string $prefix, string $name, string|float $amount, string $currency): void
    {
        $writer->startElementNs($prefix, $name, null);
        $writer->writeAttribute('currencyID', $currency);
        $writer->text(is_string($amount) ? $amount : $this->formatMoney($amount));
        $writer->endElement();
    }

    protected function element(XMLWriter $writer, string $prefix, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $writer->startElementNs($prefix, $name, null);
        $writer->text($value);
        $writer->endElement();
    }

    protected function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    protected function formatQty(float $qty): string
    {
        $s = number_format($qty, 4, '.', '');

        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    protected function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
