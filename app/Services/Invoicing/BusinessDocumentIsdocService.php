<?php

namespace App\Services\Invoicing;

use Adawolfa\ISDOC;
use Adawolfa\ISDOC\Invoice as IsdocInvoice;
use Adawolfa\ISDOC\Manager;
use Adawolfa\ISDOC\Schema\Invoice as IsdocInvoiceDocument;
use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Support\Invoicing\Canonical\CanonicalInvoiceLine;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\EuStructuredDocumentExport;
use DateTimeImmutable;

class BusinessDocumentIsdocService
{
    public function __construct(
        protected CanonicalInvoiceBuilder $canonicalBuilder,
    ) {}

    public function supports(BusinessDocument $document): bool
    {
        return EuStructuredDocumentExport::supports($document);
    }

    public function supportsEmbedInPdf(BusinessDocument $document): bool
    {
        if (! $this->supports($document)) {
            return false;
        }

        $document->loadMissing('company');

        return CompanyAppSettings::from($document->company->app_settings)->bool('embed_isdoc_in_pdf');
    }

    /**
     * @throws ISDOC\WriterException
     */
    public function embedIsdocInPdf(string $visualPdfPath, BusinessDocument $document, string $outputPath): void
    {
        $invoice = $this->build($document);

        $supplements = new IsdocInvoiceDocument\SupplementsList;
        $pdfSupplement = IsdocInvoice\Supplement::fromPath($visualPdfPath, 'invoice-preview.pdf');
        $pdfSupplement->setPreview(true);
        $supplements->add($pdfSupplement);
        $invoice->setSupplementsList($supplements);

        Manager::create()->getWriter()->file($invoice, $outputPath, Manager::FORMAT_PDF);
    }

    public function build(BusinessDocument $document): IsdocInvoice
    {
        $canonical = $this->canonicalBuilder->fromDocument($document);
        $document = $canonical->document ?? $document;
        $document->loadMissing(['company', 'contact', 'lines']);

        $company = $canonical->company;
        $contact = $canonical->contact;
        $currency = $canonical->currency;
        $vatApplicable = $canonical->vatApplicable();

        $invoice = new IsdocInvoice(
            $document->number,
            $document->id,
            $this->date($document->issue_date),
            $vatApplicable,
            $currency,
            new IsdocInvoiceDocument\AccountingSupplierParty($this->party($company)),
        );

        $invoice->setDocumentType($this->documentType($document, $vatApplicable));
        $invoice->setIssuingSystem('satflux.io');
        $invoice->setVersion(IsdocInvoice::VERSION);

        if ($document->due_date) {
            $invoice->setTaxPointDate($this->date($document->due_date));
        }

        if ($contact) {
            $invoice->setAccountingCustomerParty(
                new IsdocInvoiceDocument\AccountingCustomerParty($this->party($contact, isCustomer: true))
            );
        }

        if ($document->note_footer) {
            $note = new IsdocInvoiceDocument\Note;
            $note->setContent($document->note_footer);
            $note->setLanguageID($this->languageId($document->pdf_locale));
            $invoice->setNote($note);
        }

        foreach ($canonical->lines as $index => $line) {
            $invoice->getInvoiceLines()->add($this->invoiceLineFromCanonical($line, (string) ($index + 1), $vatApplicable));
        }

        $invoice->setTaxTotal(new IsdocInvoiceDocument\TaxTotal($canonical->taxTotal));

        if ($vatApplicable && (float) $canonical->taxTotal > 0) {
            foreach ($canonical->taxBreakdown as $row) {
                $taxCategory = new IsdocInvoiceDocument\TaxCategory($this->dec($row->ratePercent));
                $taxCategory->setVatApplicable(true);

                $invoice->getTaxTotal()->add(
                    new IsdocInvoiceDocument\TaxSubTotal(
                        $row->taxableAmount,
                        $row->taxAmount,
                        $row->grossAmount,
                        '0',
                        '0',
                        '0',
                        $row->taxableAmount,
                        $row->taxAmount,
                        $row->grossAmount,
                        $taxCategory,
                    )
                );
            }
        }

        $monetary = $invoice->getLegalMonetaryTotal();
        $monetary->setTaxExclusiveAmount($canonical->subtotal);
        $monetary->setTaxInclusiveAmount($canonical->total);
        $monetary->setPayableAmount($canonical->amountDue);

        if ($document->payment_bank_enabled && $company->iban) {
            $invoice->setPaymentMeans($this->paymentMeans($document, $company));
        }

        return $invoice;
    }

    /**
     * @throws ISDOC\WriterException
     */
    public function xml(BusinessDocument $document, bool $auditDownload = true): string
    {
        $xml = Manager::create()->getWriter()->xml($this->build($document));

        if ($auditDownload) {
            AuditLog::log('business_document.isdoc_downloaded', 'business_document', $document->id, [
                'company_id' => $document->company_id,
                'number' => $document->number,
                'format' => 'isdoc',
            ]);
        }

        return $xml;
    }

    protected function documentType(BusinessDocument $document, bool $vatApplicable): int
    {
        return match ($document->type) {
            BusinessDocumentType::CreditNote => IsdocInvoiceDocument::DOCUMENT_TYPE_CREDIT_NOTE,
            BusinessDocumentType::Proforma => $vatApplicable
                ? IsdocInvoiceDocument::DOCUMENT_TYPE_ADVANCE_INVOICE_WITH_VAT
                : IsdocInvoiceDocument::DOCUMENT_TYPE_PROFORMA_INVOICE_NO_VAT,
            default => IsdocInvoiceDocument::DOCUMENT_TYPE_INVOICE,
        };
    }

    protected function invoiceLineFromCanonical(CanonicalInvoiceLine $line, string $id, bool $vatApplicable): IsdocInvoiceDocument\InvoiceLine
    {
        $taxRate = $vatApplicable ? $line->taxRate : 0.0;
        $unitPriceTaxInclusive = $line->quantity > 0
            ? (float) $line->grossAmount / $line->quantity
            : (float) $line->unitPrice * (1 + $taxRate / 100);

        $taxCategory = new IsdocInvoiceDocument\ClassifiedTaxCategory(
            $this->dec($taxRate),
            IsdocInvoiceDocument\ClassifiedTaxCategory::VAT_CALCULATION_METHOD_FROM_THE_TOP,
        );
        $taxCategory->setVatApplicable($vatApplicable && $taxRate > 0);

        $invoiceLine = new IsdocInvoiceDocument\InvoiceLine(
            $id,
            $line->netAmount,
            $line->grossAmount,
            $line->taxAmount,
            $this->dec($line->unitPrice),
            $this->dec($unitPriceTaxInclusive),
            $taxCategory,
        );

        $quantity = new IsdocInvoiceDocument\Quantity;
        $quantity->setContent($this->decQty($line->quantity));
        if ($line->unit) {
            $quantity->setUnitCode($line->unit);
        }
        $invoiceLine->setInvoicedQuantity($quantity);

        $item = new IsdocInvoiceDocument\Item;
        $description = trim($line->name.($line->description ? "\n".$line->description : ''));
        $item->setDescription($description !== '' ? $description : $line->name);
        $invoiceLine->setItem($item);

        return $invoiceLine;
    }

    protected function paymentMeans(BusinessDocument $document, Company $company): IsdocInvoiceDocument\PaymentMeans
    {
        $payable = max(0, (float) $document->total - (float) ($document->amount_paid ?? 0));

        $details = new IsdocInvoiceDocument\Details;
        $details->setDocumentID($document->number);
        $details->setIssueDate($this->date($document->issue_date));
        if ($document->due_date) {
            $details->setPaymentDueDate($this->date($document->due_date));
        }
        if ($company->bank_account) {
            $details->setId($company->bank_account);
        }
        if ($company->bank_code) {
            $details->setBankCode($company->bank_code);
        }
        if ($company->bank_name) {
            $details->setName($company->bank_name);
        }
        if ($company->iban) {
            $details->setIban(preg_replace('/\s+/', '', $company->iban));
        }
        if ($company->bic) {
            $details->setBic($company->bic);
        }
        if ($document->variable_symbol) {
            $details->setVariableSymbol($document->variable_symbol);
        }
        if ($document->constant_symbol) {
            $details->setConstantSymbol($document->constant_symbol);
        }
        if ($document->specific_symbol) {
            $details->setSpecificSymbol($document->specific_symbol);
        }

        $payment = new IsdocInvoiceDocument\Payment(
            $this->dec($payable),
            IsdocInvoiceDocument\Payment::PAYMENT_MEANS_CODE_CREDIT_TRANSFER,
        );
        $payment->setDetails($details);

        $means = new IsdocInvoiceDocument\PaymentMeans;
        $means->add($payment);

        return $means;
    }

    protected function party(Company|CompanyContact $entity, bool $isCustomer = false): IsdocInvoiceDocument\Party
    {
        $ico = $this->partyIdentifier($entity, $isCustomer);
        $country = $this->country($entity);

        $party = new IsdocInvoiceDocument\Party(
            new IsdocInvoiceDocument\PartyIdentification($ico),
            new IsdocInvoiceDocument\PartyName($entity->name ?? ($isCustomer ? 'Odberateľ' : 'Dodávateľ')),
            new IsdocInvoiceDocument\PostalAddress(
                $entity->street ?: '-',
                '1',
                $entity->city ?: '-',
                $entity->postal_code ?: '-',
                new IsdocInvoiceDocument\Country($country['code'], $country['name']),
            ),
        );

        if ($entity instanceof Company) {
            if ($entity->vat_number) {
                $scheme = new IsdocInvoiceDocument\PartyTaxScheme($entity->vat_number, 'VAT');
                $schemes = new IsdocInvoiceDocument\PartyTaxSchemes;
                $schemes->add($scheme);
                $party->setPartyTaxSchemes($schemes);
            }
            if ($entity->commercial_register) {
                $register = new IsdocInvoiceDocument\RegisterIdentification;
                $register->setPreformatted($entity->commercial_register);
                $party->setRegisterIdentification($register);
            }
            if ($entity->issuer_email || $entity->issuer_phone) {
                $contact = new IsdocInvoiceDocument\Contact;
                if ($entity->issuer_email) {
                    $contact->setElectronicMail($entity->issuer_email);
                }
                if ($entity->issuer_phone) {
                    $contact->setTelephone($entity->issuer_phone);
                }
                $party->setContact($contact);
            }
        } elseif ($entity->email || $entity->phone) {
            $contact = new IsdocInvoiceDocument\Contact;
            if ($entity->email) {
                $contact->setElectronicMail($entity->email);
            }
            if ($entity->phone) {
                $contact->setTelephone($entity->phone);
            }
            $party->setContact($contact);
        }

        return $party;
    }

    protected function partyIdentifier(Company|CompanyContact $entity, bool $isCustomer): string
    {
        $id = $entity->registration_number
            ?: ($entity instanceof CompanyContact ? $entity->tax_id : $entity->tax_id)
            ?: null;

        if ($id) {
            return preg_replace('/\s+/', '', $id);
        }

        return $isCustomer ? '0000000000' : '00000000';
    }

    /**
     * @return array{code: string, name: string}
     */
    protected function country(Company|CompanyContact $entity): array
    {
        $raw = strtoupper(trim((string) ($entity->country ?? '')));

        if ($raw === 'SK' || str_contains($raw, 'SLOV')) {
            return ['code' => 'SK', 'name' => 'Slovensko'];
        }
        if ($raw === 'CZ' || str_contains($raw, 'ČESK') || str_contains($raw, 'CESK')) {
            return ['code' => 'CZ', 'name' => 'Česká republika'];
        }
        if (strlen($raw) === 2) {
            return ['code' => $raw, 'name' => $raw];
        }

        if ($entity instanceof Company) {
            return match ($entity->jurisdiction) {
                CompanyJurisdiction::EuCz => ['code' => 'CZ', 'name' => 'Česká republika'],
                default => ['code' => 'SK', 'name' => 'Slovensko'],
            };
        }

        return ['code' => 'SK', 'name' => 'Slovensko'];
    }

    protected function languageId(?string $locale): ?string
    {
        return match ($locale) {
            'sk' => 'sk',
            'cs' => 'cs',
            'de' => 'de',
            'en' => 'en',
            default => 'sk',
        };
    }

    protected function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        return DateTimeImmutable::createFromFormat('Y-m-d', $value->format('Y-m-d'))
            ?: new DateTimeImmutable;
    }

    protected function dec(float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    protected function decQty(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }
}
