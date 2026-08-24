<?php

namespace App\Services\Invoicing\Accounting;

use App\Enums\BusinessDocumentStatus;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Services\Invoicing\BusinessDocumentIsdocService;
use App\Services\Invoicing\BusinessDocumentPdfService;
use App\Services\Invoicing\BusinessDocumentUblService;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use App\Support\Invoicing\Accounting\ReceivedExpenseItem;
use App\Support\Invoicing\Canonical\CanonicalInvoice;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Assembles the "balík pre účtovníka" ZIP for one company and period:
 *
 *   manifest.txt
 *   pohoda/invoices.xml            (Pohoda dataPack, issued + received)
 *   csv/issued.csv, received.csv, vat_summary.csv
 *   issued/{number}.pdf|.isdoc|.ubl.xml
 *   received/{internal_number}/{attachment}
 *
 * Callers select the documents (period, status) and load expense
 * attachments into memory; nothing is persisted here, so the same builder
 * serves server-mode companies (from the DB) and the local-first ephemeral
 * bridge (from a transient payload).
 */
class AccountantPackageBuilder
{
    public function __construct(
        protected CanonicalInvoiceBuilder $canonicalBuilder,
        protected BusinessDocumentPdfService $pdfService,
        protected BusinessDocumentIsdocService $isdocService,
        protected BusinessDocumentUblService $ublService,
        protected PohodaXmlWriter $pohodaWriter,
        protected AccountingCsvWriter $csvWriter,
    ) {}

    /**
     * @param  iterable<BusinessDocument>  $documents
     * @param  list<ReceivedExpenseItem>  $expenses
     * @return string ZIP binary
     */
    public function build(Company $company, iterable $documents, array $expenses, AccountantPackageOptions $options): string
    {
        $issued = [];
        $models = [];
        foreach ($documents as $document) {
            if ($document->status === BusinessDocumentStatus::Draft) {
                continue; // drafts are not accounting documents
            }
            $document->setRelation('company', $company);
            $document->loadMissing(['contact', 'lines']);
            $issued[] = $this->canonicalBuilder->fromDocument($document);
            $models[] = $document;
        }

        if ($issued === [] && $expenses === []) {
            throw new InvalidArgumentException('Nothing to export for the selected period.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'accountant-');
        if ($zipPath === false) {
            throw new RuntimeException('Could not create ZIP archive.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create ZIP archive.');
            }

            $notes = [];
            $skipped = [];

            if ($options->wantsPohoda()) {
                $xml = $this->pohodaWriter->write($company, $issued, $expenses, 'satflux-'.$this->periodSlug($options));
                $this->add($zip, 'pohoda/invoices.xml', $xml);
                $notes = array_merge($notes, $this->pohodaWriter->warnings());
            }

            if ($options->wantsCsv()) {
                $this->add($zip, 'csv/issued.csv', $this->csvWriter->issued($issued));
                $this->add($zip, 'csv/received.csv', $this->csvWriter->received($expenses));
                $this->add($zip, 'csv/vat_summary.csv', $this->csvWriter->vatSummary($issued));
            }

            $usedNames = [];
            foreach ($models as $document) {
                $base = $this->uniqueName($usedNames, 'issued/'.$this->safeSegment((string) ($document->number ?: $document->id)));
                if ($options->includePdf) {
                    $this->add($zip, "{$base}.pdf", $this->pdfService->renderBinary($document));
                }
                if ($options->includeIsdoc) {
                    if ($this->isdocService->supports($document)) {
                        $this->add($zip, "{$base}.isdoc", $this->isdocService->xml($document, auditDownload: false));
                    } else {
                        $skipped[] = "ISDOC skipped for {$document->number}: unsupported document type or jurisdiction.";
                    }
                }
                if ($options->includeUbl) {
                    if ($this->ublService->supports($document)) {
                        $this->add($zip, "{$base}.ubl.xml", $this->ublService->xml($document, auditDownload: false));
                    } else {
                        $skipped[] = "UBL skipped for {$document->number}: unsupported document type or jurisdiction.";
                    }
                }
            }

            $attachmentCount = 0;
            if ($options->includeExpenseAttachments) {
                $usedDirs = [];
                foreach ($expenses as $expense) {
                    if ($expense->attachments === []) {
                        continue;
                    }
                    $dir = $this->uniqueName($usedDirs, 'received/'.$this->safeSegment($expense->internalNumber));
                    $usedFiles = [];
                    foreach ($expense->attachments as $attachment) {
                        $name = $this->uniqueName($usedFiles, $dir.'/'.$this->safeFilename($attachment->filename));
                        $this->add($zip, $name, $attachment->bytes);
                        $attachmentCount++;
                    }
                }
            }

            $this->add($zip, 'manifest.txt', $this->manifest($company, $options, $issued, $expenses, $attachmentCount, array_merge($notes, $skipped)));

            if ($zip->close() !== true) {
                throw new RuntimeException('Could not finalize ZIP archive.');
            }

            $binary = file_get_contents($zipPath);
            if ($binary === false) {
                throw new RuntimeException('Could not read generated ZIP archive.');
            }

            return $binary;
        } finally {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * @param  list<CanonicalInvoice>  $issued
     * @param  list<ReceivedExpenseItem>  $expenses
     * @param  list<string>  $notes
     */
    protected function manifest(
        Company $company,
        AccountantPackageOptions $options,
        array $issued,
        array $expenses,
        int $attachmentCount,
        array $notes,
    ): string {
        $lines = [
            'Satflux - export pre uctovnika',
            'Firma: '.$company->displayName().' (ICO '.($company->registration_number ?: '-').')',
            'Obdobie: '.($options->from?->format('Y-m-d') ?? '-').' az '.($options->to?->format('Y-m-d') ?? '-'),
            'Vystavene doklady: '.count($issued),
            'Prijate doklady (naklady): '.count($expenses),
            'Prilohy nakladov: '.$attachmentCount,
            'Formaty: '.implode(', ', $options->formats ?: ['-']),
            '',
            'Obsah:',
            '  pohoda/invoices.xml   - import do Pohody (Soubor > Datova komunikace > XML import), vystavene aj prijate faktury',
            '  csv/*.csv             - univerzalne tabulky (Excel, Money S3, iDoklad)',
            '  issued/*.isdoc        - ISDOC per vystaveny doklad (KROS Omega / Alfa+ importuju ISDOC, Pohoda XML neprijmu)',
            '  issued/*.pdf          - vizualna faktura',
            '  received/<cislo>/     - prilohy prijatych dokladov (PDF, obrazky, UBL e-faktury)',
            '',
            'Upozornenia:',
            '  - Prijate doklady nemaju rozpis poloziek ani DPH - v Pohode idu do sadzby "none", rozdelte pri importe.',
            '  - Doklady v cudzej mene su exportovane s kurzom 1 (Satflux kurz neuklada) - upravte v Pohode.',
        ];
        foreach ($notes as $note) {
            $lines[] = '  - '.$note;
        }

        return implode("\r\n", $lines)."\r\n";
    }

    protected function add(ZipArchive $zip, string $name, string $content): void
    {
        if ($zip->addFromString($name, $content) === false) {
            throw new RuntimeException("Could not add {$name} to ZIP archive.");
        }
    }

    /**
     * @param  array<string, true>  $used
     */
    protected function uniqueName(array &$used, string $name): string
    {
        $candidate = $name;
        $suffix = 2;
        while (isset($used[$candidate])) {
            $candidate = "{$name}-{$suffix}";
            $suffix++;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    protected function safeSegment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $value) ?? '';

        return trim($value, '._-') ?: 'document';
    }

    protected function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $filename) ?? '';
        $filename = trim($filename, '._-');

        return $filename !== '' ? mb_substr($filename, 0, 120) : 'attachment';
    }

    protected function periodSlug(AccountantPackageOptions $options): string
    {
        return ($options->from?->format('Ymd') ?? 'all').'-'.($options->to?->format('Ymd') ?? 'all');
    }
}
