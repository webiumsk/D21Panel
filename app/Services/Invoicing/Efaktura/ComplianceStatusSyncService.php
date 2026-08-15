<?php

namespace App\Services\Invoicing\Efaktura;

use App\Enums\ComplianceSubmissionStatus;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentCompliance;
use App\Models\EfakturaCpdsProvider;
use App\Support\Invoicing\CompanyEfakturaSettings;
use App\Support\Invoicing\Efaktura\SapiSkDocumentStatusMapper;
use Illuminate\Http\Client\RequestException;

class ComplianceStatusSyncService
{
    public function __construct(
        protected SapiSkClient $client,
    ) {}

    /**
     * @return list<BusinessDocumentCompliance>
     */
    public function refreshDocument(BusinessDocument $document): array
    {
        $rows = BusinessDocumentCompliance::query()
            ->where('business_document_id', $document->id)
            ->whereIn('status', [
                ComplianceSubmissionStatus::Submitted,
                ComplianceSubmissionStatus::Pending,
            ])
            ->get();

        $updated = [];
        foreach ($rows as $row) {
            $fresh = $this->refreshRow($row);
            if ($fresh !== null) {
                $updated[] = $fresh;
            }
        }

        return $updated;
    }

    /**
     * @return array{checked: int, updated: int}
     */
    public function syncStaleSubmissions(): array
    {
        $stats = ['checked' => 0, 'updated' => 0];

        if (! config('efaktura.enabled')) {
            return $stats;
        }

        $globalDetailPath = (string) config('efaktura.providers.sapi_sk.send_detail_path', '');
        if ($globalDetailPath === '' && ! EfakturaCpdsProvider::anyActiveDetailPath()) {
            return $stats;
        }

        BusinessDocumentCompliance::query()
            ->where('status', ComplianceSubmissionStatus::Submitted)
            ->whereNotNull('external_id')
            ->where('submitted_at', '<', now()->subMinutes(5))
            ->orderBy('submitted_at')
            ->limit(100)
            ->each(function (BusinessDocumentCompliance $row) use (&$stats) {
                $stats['checked']++;
                $fresh = $this->refreshRow($row);
                if ($fresh !== null && $fresh->status !== ComplianceSubmissionStatus::Submitted) {
                    $stats['updated']++;
                }
            });

        return $stats;
    }

    public function refreshRow(BusinessDocumentCompliance $row): ?BusinessDocumentCompliance
    {
        $payload = $row->response_payload;
        $mapped = SapiSkDocumentStatusMapper::fromProviderPayload(is_array($payload) ? $payload : null);

        $remotePayload = $this->fetchRemotePayload($row);
        if ($remotePayload !== null) {
            $payload = array_merge(is_array($payload) ? $payload : [], $remotePayload);
            $remoteMapped = SapiSkDocumentStatusMapper::fromProviderPayload($remotePayload);
            $mapped = $remoteMapped ?? $mapped;
        }

        if ($mapped === null || $mapped === $row->status) {
            if ($remotePayload !== null) {
                $row->update(['response_payload' => $payload]);
            }

            return null;
        }

        $row->update([
            'status' => $mapped,
            'response_payload' => $payload,
            'resolved_at' => in_array($mapped, [
                ComplianceSubmissionStatus::Approved,
                ComplianceSubmissionStatus::Rejected,
                ComplianceSubmissionStatus::Failed,
                ComplianceSubmissionStatus::Skipped,
            ], true) ? now() : $row->resolved_at,
        ]);

        return $row->fresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchRemotePayload(BusinessDocumentCompliance $row): ?array
    {
        if ($row->external_id === null || $row->external_id === '') {
            return null;
        }

        $document = $row->document()->with('company')->first();
        if ($document === null) {
            return null;
        }

        $settings = CompanyEfakturaSettings::fromCompany($document->company);
        if (! $settings->configured()) {
            return null;
        }

        // Preset detail path (per CPDS) or the global fallback - none means
        // this company's postman exposes no status endpoint.
        if ($this->client->sentDocumentDetailPathTemplate($settings->sapiBaseUrl()) === null) {
            return null;
        }

        try {
            $baseUrl = (string) $settings->sapiBaseUrl();
            $token = $this->client->accessToken(
                (string) $settings->sapiClientId(),
                (string) $settings->sapiClientSecret(),
                $baseUrl,
            );

            return $this->client->sentDocument(
                $token,
                (string) $settings->peppolParticipantId(),
                (string) $row->external_id,
                $baseUrl,
            );
        } catch (RequestException $exception) {
            if (in_array($exception->response?->status(), [404, 405, 501], true)) {
                return null;
            }

            report($exception);

            return null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
