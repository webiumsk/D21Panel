<?php

namespace App\Services\Invoicing\Efaktura;

use App\Enums\CompanyJurisdiction;
use App\Models\AuditLog;
use App\Models\BusinessExpense;
use App\Models\Company;
use App\Models\EfakturaInboundReceipt;
use App\Services\Invoicing\BusinessExpenseService;
use App\Support\Invoicing\CompanyEfakturaEligibility;
use App\Support\Invoicing\CompanyEfakturaSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EfakturaInboundService
{
    public function __construct(
        protected SapiSkClient $client,
        protected UblExpenseDraftParser $parser,
        protected BusinessExpenseService $expenseService,
        protected EfakturaInboundInboxService $inboxService,
    ) {}

    /**
     * @return array{imported: int, acknowledged: int, skipped: int, failed: int}
     */
    public function pollAll(): array
    {
        $stats = ['imported' => 0, 'acknowledged' => 0, 'skipped' => 0, 'failed' => 0];

        if (! config('efaktura.enabled')) {
            return $stats;
        }

        $eligibility = app(CompanyEfakturaEligibility::class);

        Company::query()
            ->where('jurisdiction', CompanyJurisdiction::EuSk)
            ->orderBy('id')
            ->chunkById(50, function ($companies) use (&$stats, $eligibility) {
                foreach ($companies as $company) {
                    if (! $eligibility->supportsInbound($company)) {
                        continue;
                    }
                    $companyStats = $this->pollCompany($company);
                    foreach ($companyStats as $key => $value) {
                        $stats[$key] += $value;
                    }
                }
            });

        return $stats;
    }

    /**
     * @return array{imported: int, acknowledged: int, skipped: int, failed: int}
     */
    public function pollCompany(Company $company): array
    {
        $stats = ['imported' => 0, 'acknowledged' => 0, 'skipped' => 0, 'failed' => 0];
        if (! app(CompanyEfakturaEligibility::class)->supportsInbound($company)) {
            return $stats;
        }

        $settings = CompanyEfakturaSettings::fromCompany($company);

        if (! $settings->inboundEnabled() || ! $settings->configured()) {
            return $stats;
        }

        $participantId = (string) $settings->peppolParticipantId();

        try {
            $baseUrl = (string) $settings->sapiBaseUrl();
            $token = $this->client->accessToken(
                (string) $settings->sapiClientId(),
                (string) $settings->sapiClientSecret(),
                $baseUrl,
            );

            $listing = $this->client->listReceivedDocuments($token, $participantId, baseUrl: $baseUrl);
            $documents = $listing['documents'] ?? $listing['items'] ?? [];

            foreach ($documents as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $externalId = (string) ($item['providerDocumentId'] ?? $item['documentId'] ?? $item['id'] ?? '');
                if ($externalId === '') {
                    $stats['failed']++;

                    continue;
                }

                $existingReceipt = EfakturaInboundReceipt::query()
                    ->where('company_id', $company->id)
                    ->where('external_document_id', $externalId)
                    ->first();

                if ($existingReceipt !== null && $existingReceipt->acknowledged_at !== null) {
                    $stats['skipped']++;

                    continue;
                }

                if ($existingReceipt !== null) {
                    try {
                        if (! $this->receiptHasContent($existingReceipt)) {
                            $detail = $this->client->receivedDocument($token, $participantId, $externalId, $baseUrl);
                            $ubl = (string) ($detail['payload'] ?? $detail['ubl'] ?? '');
                            if ($ubl === '') {
                                $stats['failed']++;

                                continue;
                            }

                            $existingReceipt = $this->importInboundDocument($company, $externalId, $ubl, $detail, $existingReceipt);
                            $stats['imported']++;
                        }

                        $this->client->acknowledgeReceived($token, $participantId, $externalId, $baseUrl);
                        $existingReceipt->update([
                            'status' => 'acknowledged',
                            'acknowledged_at' => now(),
                        ]);
                        $stats['acknowledged']++;
                    } catch (\Throwable $e) {
                        report($e);
                        $stats['failed']++;
                        Log::warning('efaktura inbound acknowledge retry failed', [
                            'company_id' => $company->id,
                            'external_document_id' => $externalId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    continue;
                }

                try {
                    $detail = $this->client->receivedDocument($token, $participantId, $externalId, $baseUrl);
                    $ubl = (string) ($detail['payload'] ?? $detail['ubl'] ?? '');
                    if ($ubl === '') {
                        $stats['failed']++;

                        continue;
                    }

                    $receipt = $this->importInboundDocument($company, $externalId, $ubl, $detail);

                    $this->client->acknowledgeReceived($token, $participantId, $externalId, $baseUrl);
                    $receipt->update([
                        'status' => 'acknowledged',
                        'acknowledged_at' => now(),
                    ]);

                    AuditLog::log(
                        $receipt->business_expense_id !== null
                            ? 'business_expense.efaktura_inbound_imported'
                            : 'company.efaktura_inbound_inboxed',
                        $receipt->business_expense_id !== null ? 'business_expense' : 'company',
                        $receipt->business_expense_id ?? $company->id,
                        [
                            'company_id' => $company->id,
                            'external_document_id' => $externalId,
                            'receipt_id' => $receipt->id,
                        ],
                    );

                    $stats['imported']++;
                    $stats['acknowledged']++;
                } catch (\Throwable $e) {
                    report($e);
                    $stats['failed']++;
                    Log::warning('efaktura inbound import failed', [
                        'company_id' => $company->id,
                        'external_document_id' => $externalId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $stats['failed']++;
        }

        $this->persistInboundPollMeta($company, $stats);

        return $stats;
    }

    /**
     * @param  array{imported: int, acknowledged: int, skipped: int, failed: int}  $stats
     */
    protected function persistInboundPollMeta(Company $company, array $stats): void
    {
        $current = is_array($company->app_settings) ? $company->app_settings : [];
        $company->update([
            'app_settings' => array_merge($current, [
                'efaktura_inbound_last_poll_at' => now()->toIso8601String(),
                'efaktura_inbound_last_poll_stats' => $stats,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    protected function importInboundDocument(
        Company $company,
        string $externalId,
        string $ubl,
        array $detail,
        ?EfakturaInboundReceipt $existingReceipt = null,
    ): EfakturaInboundReceipt {
        // Local-first companies keep their expenses in Evolu: park the parsed
        // draft + UBL as an inbox item for the client instead of creating a
        // server expense nobody would ever see.
        // The provider detail carries the UBL itself - never persist it in the
        // plain response_payload column (the inbox keeps it encrypted, the
        // server mode as a file).
        $detail = $this->detailWithoutDocumentBody($detail);

        if (! $company->usesServerInvoicing()) {
            $draft = $this->parser->parse($ubl);

            return $this->inboxService->storeAsInboxItem($company, $externalId, $ubl, $draft, $detail, $existingReceipt);
        }

        $attachment = null;

        try {
            return DB::transaction(function () use ($company, $externalId, $ubl, $detail, $existingReceipt, &$attachment) {
                $draft = $this->parser->parse($ubl);
                $expense = $this->expenseService->create($company, $draft);
                $attachment = $this->storeUblAttachment($expense, $ubl, $externalId);

                $attributes = [
                    'company_id' => $company->id,
                    'external_document_id' => $externalId,
                    'business_expense_id' => $expense->id,
                    'status' => 'imported',
                    'attachment_disk' => $attachment['disk'],
                    'attachment_path' => $attachment['path'],
                    'acknowledged_at' => null,
                    'response_payload' => $detail,
                ];

                if ($existingReceipt !== null) {
                    $existingReceipt->update($attributes);

                    return $existingReceipt->refresh();
                }

                return EfakturaInboundReceipt::query()->create($attributes);
            });
        } catch (\Throwable $e) {
            if (is_array($attachment)) {
                Storage::disk($attachment['disk'])->delete($attachment['path']);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    protected function detailWithoutDocumentBody(array $detail): array
    {
        unset($detail['payload'], $detail['ubl'], $detail['document'], $detail['xml']);

        return $detail;
    }

    /**
     * A receipt still "has content" when its server expense exists or when it
     * is an inbox item the client already handled (imported / dismissed).
     * Only a receipt with neither is re-fetched from the CPDS on retry.
     */
    protected function receiptHasContent(EfakturaInboundReceipt $receipt): bool
    {
        if ($receipt->business_expense_id !== null && $receipt->expense()->exists()) {
            return true;
        }

        // Inbox items always carry a stable Evolu id, whatever their inbox state.
        return $receipt->evolu_expense_id !== null;
    }

    /**
     * @return array{disk: string, path: string}
     */
    protected function storeUblAttachment(BusinessExpense $expense, string $ubl, string $externalId): array
    {
        $disk = 'local';
        $filename = 'efaktura-'.Str::slug($externalId).'.xml';
        $path = "companies/{$expense->company_id}/expenses/{$expense->id}/{$filename}";

        if (! Storage::disk($disk)->put($path, $ubl)) {
            throw new \RuntimeException("Failed to store inbound UBL attachment at [{$path}].");
        }

        $expense->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $filename,
            'mime' => 'application/xml',
            'size_bytes' => strlen($ubl),
        ]);

        $expense->update([
            'attachment_disk' => $disk,
            'attachment_path' => $path,
            'original_filename' => $filename,
            'attachment_mime' => 'application/xml',
        ]);

        return ['disk' => $disk, 'path' => $path];
    }
}
