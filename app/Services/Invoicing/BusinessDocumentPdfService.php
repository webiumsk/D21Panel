<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Store;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyVatPolicy;
use App\Support\Invoicing\JurisdictionRules;
use App\Support\Invoicing\QrPngRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\Response;

class BusinessDocumentPdfService
{
    public function __construct(
        protected BankQrGenerator $bankQrGenerator,
        protected BusinessDocumentPaymentTokenService $paymentTokenService,
        protected BusinessDocumentBtcPayService $btcPayService,
        protected CompanyBrandingService $brandingService,
        protected CompanyPdfFilenameBuilder $pdfFilenameBuilder,
        protected BusinessDocumentIsdocService $isdocService,
        protected BusinessDocumentZugferdService $zugferdService,
        protected CanonicalInvoiceBuilder $canonicalBuilder,
        protected CompanyVatPolicy $vatPolicy,
    ) {}

    public function download(BusinessDocument $document): Response
    {
        $filename = $this->pdfFilenameBuilder->build($document);

        AuditLog::log('business_document.pdf_downloaded', 'business_document', $document->id, [
            'company_id' => $document->company_id,
            'number' => $document->number,
        ]);

        return response($this->renderBinary($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function renderBinary(BusinessDocument $document): string
    {
        $document->loadMissing(['company', 'contact', 'lines', 'store']);
        $viewData = $this->viewDataForDocument($document);
        $view = $document->company->jurisdiction === CompanyJurisdiction::Us
            ? 'pdf.business-invoice-us'
            : 'pdf.business-invoice-eu';

        $visualPath = $this->tempPdfPath('pdf-'.uniqid().'.pdf');

        Pdf::view($view, $viewData)->save($visualPath);

        try {
            // DE companies get the ZUGFeRD hybrid (factur-x.xml, also for
            // ephemeral local-first documents); ISDOC stays the SK/CZ embed.
            if ($this->zugferdService->supportsEmbedInPdf($document)) {
                $zugferdPath = $this->tempPdfPath('pdf-zugferd-'.bin2hex(random_bytes(8)).'.pdf');
                try {
                    $this->zugferdService->embedInPdf($visualPath, $document, $zugferdPath);

                    $binary = file_get_contents($zugferdPath);
                    if ($binary === false) {
                        // An unreadable hybrid must fail loudly - an empty 200
                        // body would look like a served (broken) invoice.
                        throw new \RuntimeException('Could not read generated ZUGFeRD PDF.');
                    }

                    return $binary;
                } finally {
                    @unlink($zugferdPath);
                }
            }

            if ($document->exists && $this->isdocService->supportsEmbedInPdf($document)) {
                $isdocPath = $this->tempPdfPath('pdf-isdoc-'.uniqid().'.pdf');
                try {
                    $this->isdocService->embedIsdocInPdf($visualPath, $document, $isdocPath);

                    return file_get_contents($isdocPath) ?: '';
                } finally {
                    @unlink($isdocPath);
                }
            }

            return file_get_contents($visualPath) ?: '';
        } finally {
            @unlink($visualPath);
        }
    }

    /**
     * @param  Collection<int, BusinessDocument>  $documents
     */
    public function renderMergedBinary(Collection $documents): string
    {
        $pages = [];
        foreach ($documents as $document) {
            $document->loadMissing(['company', 'contact', 'lines', 'store']);
            $pages[] = $this->viewDataForDocument($document);
        }

        $path = $this->tempPdfPath('pdf-merge-'.uniqid().'.pdf');

        Pdf::view('pdf.business-invoices-merged', ['pages' => $pages])->save($path);
        $binary = file_get_contents($path);
        @unlink($path);

        return $binary ?: '';
    }

    /**
     * ZIP archive with one PDF per document (invoice-{number|id}.pdf).
     *
     * @param  Collection<int, BusinessDocument>  $documents
     */
    public function renderZipBinary(Collection $documents): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'invoices-');
        if ($zipPath === false) {
            throw new \RuntimeException('Could not create ZIP archive.');
        }

        try {
            $zip = new \ZipArchive;
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create ZIP archive.');
            }

            foreach ($documents as $document) {
                $pdf = $this->renderBinary($document);
                $name = 'invoice-'.$this->safeZipEntrySegment((string) ($document->number ?: $document->id)).'.pdf';
                if ($zip->addFromString($name, $pdf) === false) {
                    throw new \RuntimeException('Could not add PDF to ZIP archive.');
                }
            }

            if ($zip->close() !== true) {
                throw new \RuntimeException('Could not finalize ZIP archive.');
            }

            $binary = file_get_contents($zipPath);
            if ($binary === false) {
                throw new \RuntimeException('Could not read generated ZIP archive.');
            }

            return $binary;
        } finally {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * Document numbers come from user payloads (ephemeral flow) - reduce them
     * to a basename-safe segment so ZIP entries cannot carry path separators
     * (same character class as CompanyPdfFilenameBuilder).
     */
    protected function safeZipEntrySegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $value) ?? '';
        $segment = trim($segment, '._-');

        return $segment !== '' ? $segment : 'document';
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewDataForDocument(BusinessDocument $document): array
    {
        $company = $document->company;
        $bankQr = null;
        $bankQrStandard = null;
        if ($company instanceof Company
            && $document->payment_bank_enabled
            && $document->type !== BusinessDocumentType::Quote
        ) {
            $bankQr = $this->bankQrGenerator->generateQrDataUri($company, $document);
            // The caption under the QR must name what the code actually is
            // (PAY by square / EPC / Swiss QR) - payers recognize the brand.
            $bankQrStandard = $bankQr !== null
                ? $this->bankQrGenerator->selectStandard($company, $document)
                : null;
        }

        $btcPayQr = null;
        $btcPayUrl = null;
        if ($document->payment_btc_enabled && $document->type !== BusinessDocumentType::Quote) {
            $qrTarget = $this->resolveBtcPayQrTarget($document);
            if ($qrTarget) {
                $btcPayUrl = $qrTarget;
                $btcPayQr = QrPngRenderer::dataUri($qrTarget, 180);
            }
        }

        app()->setLocale($document->pdf_locale ?: 'sk');

        $canonical = $this->canonicalBuilder->fromDocument($document);
        $settings = CompanyAppSettings::from($company->app_settings);
        $contact = $document->resolvedBuyer();
        $reverseChargeNote = $this->vatPolicy->taxClause($company, $contact, $settings);

        $jurisdiction = $company->jurisdiction;
        $isUs = $jurisdiction === CompanyJurisdiction::Us;
        $isDe = $this->vatPolicy->isDeCompany($company);

        // sk/cs/en label localization comes from pdf_locale translations;
        // jurisdictions whose statutory tax terms a language file cannot
        // distinguish (DE USt-IdNr. vs AT UID-Nr. vs CH MWST - all German)
        // override the labels from JurisdictionRules.
        $rules = JurisdictionRules::for($jurisdiction);
        $vatLabel = $rules['pdf_label_override'] ? $rules['vat_name'] : __('VAT');
        $taxIdLabel = $rules['pdf_label_override'] ? $rules['tax_id_label'] : __('VAT ID');

        return [
            'document' => $document,
            'company' => $company,
            'contact' => $contact,
            'lines' => $document->lines,
            'vatLabel' => $vatLabel,
            'taxIdLabel' => $taxIdLabel,
            'taxBreakdown' => $canonical->taxBreakdown,
            'showVatColumn' => $this->vatPolicy->showsVatRateColumn($company, $contact),
            'showVatBreakdown' => $this->vatPolicy->showsVatBreakdown($company, $contact),
            'showSalesTaxColumn' => $isUs && (float) $canonical->taxTotal > 0,
            'isUs' => $isUs,
            // DE mandatory-field rendering: always-shown Leistungsdatum and
            // the German Steuernummer label.
            'isDe' => $isDe,
            'taxNumberLabel' => $isDe ? 'Steuernummer' : null,
            'reverseChargeNote' => $reverseChargeNote,
            'bankQr' => $bankQr,
            'bankQrStandard' => $bankQrStandard,
            'btcPayQr' => $btcPayQr,
            'btcPayUrl' => $btcPayUrl,
            'logoDataUri' => $this->brandingService->resolveBrandingDataUri(
                $company->getAttribute('ephemeral_logo_url'),
                $company->logo_path,
            ),
            'signatureStampDataUri' => $this->brandingService->resolveBrandingDataUri(
                $company->getAttribute('ephemeral_signature_stamp_url'),
                $company->signature_stamp_path,
            ),
        ];
    }

    protected function tempPdfPath(string $filename): string
    {
        $candidates = [
            Storage::disk('local')->path('temp'),
            storage_path('app/temp'),
            sys_get_temp_dir().'/satflux-pdf',
        ];

        foreach ($candidates as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                continue;
            }
            if (is_writable($dir)) {
                return rtrim($dir, '/').'/'.$filename;
            }
        }

        return sys_get_temp_dir().'/'.$filename;
    }

    protected function resolveBtcPayQrTarget(BusinessDocument $document): ?string
    {
        if (! $document->payment_btc_enabled || ! $document->store_id) {
            return null;
        }

        if (! $document->exists) {
            if ($document->btcpay_checkout_link) {
                return $document->btcpay_checkout_link;
            }

            $document->loadMissing(['store.user']);
            $store = $document->store;
            if (! $store instanceof Store) {
                return null;
            }

            try {
                $evoluDocumentId = $document->getAttribute('ephemeral_evolu_document_id');

                // Shares the checkout dedupe: paid documents render without a
                // payment QR, existing checkouts are reused, and a mint is
                // registered - rendering a PDF must never leave stray BTCPay
                // invoices behind (production 2026-07-15).
                return $this->btcPayService->qrCheckoutLinkForEphemeralRender(
                    $document,
                    $store,
                    is_string($evoluDocumentId) && $evoluDocumentId !== '' ? $evoluDocumentId : null,
                );
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        }

        $document->loadMissing(['store']);
        if (! $document->btcpay_checkout_link) {
            try {
                $this->btcPayService->syncForDocument($document, forceRefresh: true);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Persisted documents encode the lazy pay link (/pay/i/…), not the BTCPay checkout URL.
        $this->paymentTokenService->ensureForDocument($document);

        return $this->paymentTokenService->payUrl($document);
    }
}
