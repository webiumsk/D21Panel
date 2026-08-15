<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentType;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Support\Invoicing\Canonical\CanonicalInvoice;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyVatPolicy;
use App\Support\Invoicing\DeXRechnungProfile;
use App\Support\Invoicing\EuStructuredDocumentExport;
use App\Support\Invoicing\SkUblProfile;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * ZUGFeRD 2.x hybrid invoices for German companies: the EN 16931 CII XML is
 * generated from the canonical snapshot (same VAT scenario resolution as the
 * UBL/XRechnung export) and embedded into the visual PDF as factur-x.xml
 * with the PDF/A-3 metadata the profile requires. Mandatory for DE B2B
 * receipt since 2025-01-01, for sending from 2027 (JurisdictionRules).
 */
class BusinessDocumentZugferdService
{
    public function __construct(
        protected CanonicalInvoiceBuilder $canonicalBuilder,
        protected CompanyVatPolicy $vatPolicy,
    ) {}

    public function supports(BusinessDocument $document): bool
    {
        $document->loadMissing('company');

        return EuStructuredDocumentExport::supports($document)
            && $document->company instanceof Company
            && $this->vatPolicy->isDeCompany($document->company);
    }

    /**
     * PDF embedding is on by default for DE companies and can be switched
     * off per company (embed_zugferd_in_pdf app setting). Unlike the ISDOC
     * embed this also applies to ephemeral (local-first) documents - the
     * hybrid PDF is the primary DE deliverable.
     */
    public function supportsEmbedInPdf(BusinessDocument $document): bool
    {
        if (! $this->supports($document)) {
            return false;
        }

        $rawSettings = $document->company?->getAttribute('app_settings');

        return CompanyAppSettings::from(is_array($rawSettings) ? $rawSettings : null)
            ->bool('embed_zugferd_in_pdf');
    }

    public function xml(BusinessDocument $document): string
    {
        return $this->builder($document)->getContent();
    }

    /** Embed the CII XML into the visual PDF (factur-x.xml, PDF/A-3). */
    public function embedInPdf(string $visualPdfPath, BusinessDocument $document, string $outputPath): void
    {
        ZugferdDocumentPdfBuilder::fromPdfFile($this->builder($document), $visualPdfPath)
            ->setAttachmentRelationshipTypeToAlternative()
            ->generateDocument()
            ->saveDocument($outputPath);
    }

    protected function builder(BusinessDocument $document): ZugferdDocumentBuilder
    {
        $canonical = $this->canonicalBuilder->fromDocument($document);
        $document = $canonical->document ?? $document;
        $company = $canonical->company;
        $contact = $canonical->contact;

        $rawSettings = $company->getAttribute('app_settings');
        $scenario = $this->vatPolicy->enTaxScenario(
            $company,
            $contact,
            CompanyAppSettings::from(is_array($rawSettings) ? $rawSettings : null),
        );

        $builder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        $builder->setDocumentInformation(
            (string) $document->number,
            $this->typeCode($document->type),
            $this->date($document->issue_date) ?? now(),
            $canonical->currency,
        );
        $builder->setDocumentBuyerReference(DeXRechnungProfile::buyerReference($document));

        $builder->setDocumentSeller((string) $company->legal_name);
        $builder->setDocumentSellerAddress(
            $company->street,
            null,
            null,
            $company->postal_code,
            $company->city,
            SkUblProfile::countryCode($company),
        );
        if ($company->vat_number) {
            $builder->addDocumentSellerTaxRegistration('VA', $company->vat_number);
        }
        if ($company->tax_id) {
            $builder->addDocumentSellerTaxRegistration('FC', $company->tax_id);
        }
        $sellerContact = trim((string) ($company->issuer_name ?: $company->legal_name));
        if ($sellerContact !== '' || $company->issuer_phone || $company->issuer_email) {
            $builder->setDocumentSellerContact(
                $sellerContact,
                null,
                $company->issuer_phone,
                null,
                $company->issuer_email,
            );
        }

        if ($contact) {
            $builder->setDocumentBuyer((string) $contact->name);
            $builder->setDocumentBuyerAddress(
                $contact->street,
                null,
                null,
                $contact->postal_code,
                $contact->city,
                SkUblProfile::countryCode($contact),
            );
            if ($contact->vat_id) {
                $builder->addDocumentBuyerTaxRegistration('VA', $contact->vat_id);
            }
        }

        $supplyDate = $this->date($document->delivery_date) ?? $this->date($document->issue_date);
        if ($supplyDate !== null) {
            $builder->setDocumentSupplyChainEvent($supplyDate);
        }

        $iban = preg_replace('/\s+/', '', (string) ($company->iban ?? ''));
        if ($iban !== '') {
            $builder->addDocumentPaymentMeanToCreditTransfer(
                $iban,
                (string) $company->legal_name,
                null,
                $company->bic,
                $document->variable_symbol,
            );
        }
        $dueDate = $this->date($document->due_date);
        if ($dueDate !== null) {
            $builder->addDocumentPaymentTerm(null, $dueDate);
        }

        $this->applyTaxes($builder, $canonical, $scenario);

        $builder->setDocumentSummation(
            (float) $canonical->total,
            (float) $canonical->amountDue,
            (float) $canonical->subtotal,
            0.0,
            0.0,
            (float) $canonical->subtotal,
            (float) $canonical->taxTotal,
        );

        foreach ($canonical->lines as $index => $line) {
            $builder->addNewPosition((string) ($index + 1));
            $builder->setDocumentPositionProductDetails($line->name, $line->description);
            $builder->setDocumentPositionNetPrice(round($line->unitPrice, 2));
            $builder->setDocumentPositionQuantity(
                $line->quantity,
                SkUblProfile::resolveUnitCode($line->unit) ?? 'C62',
            );
            $builder->addDocumentPositionTax(
                $scenario['category'] ?? ((float) $line->taxRate > 0 ? 'S' : 'Z'),
                'VAT',
                $scenario !== null ? 0.0 : (float) $line->taxRate,
            );
            $builder->setDocumentPositionLineSummation((float) $line->netAmount);
        }

        return $builder;
    }

    /**
     * @param  array{category: string, reason: string|null}|null  $scenario
     */
    protected function applyTaxes(
        ZugferdDocumentBuilder $builder,
        CanonicalInvoice $canonical,
        ?array $scenario,
    ): void {
        if ($scenario !== null || $canonical->taxBreakdown === []) {
            $builder->addDocumentTax(
                $scenario['category'] ?? 'Z',
                'VAT',
                (float) $canonical->subtotal,
                0.0,
                0.0,
                $scenario['reason'] ?? null,
            );

            return;
        }

        foreach ($canonical->taxBreakdown as $row) {
            $builder->addDocumentTax(
                'S',
                'VAT',
                (float) $row->taxableAmount,
                (float) $row->taxAmount,
                $row->ratePercent,
            );
        }
    }

    /**
     * The date PHPDocs on BusinessDocument say string while the casts yield
     * Carbon (pre-existing looseness) - normalize both shapes.
     */
    protected function date(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    protected function typeCode(BusinessDocumentType|string $type): string
    {
        $value = $type instanceof BusinessDocumentType ? $type->value : $type;

        return match ($value) {
            'credit_note' => '381',
            'proforma' => '325',
            default => '380',
        };
    }
}
