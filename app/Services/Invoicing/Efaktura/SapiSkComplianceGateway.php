<?php

namespace App\Services\Invoicing\Efaktura;

use App\Contracts\Invoicing\ComplianceSubmissionGateway;
use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Enums\ComplianceProvider;
use App\Enums\ComplianceSubmissionStatus;
use App\Models\BusinessDocument;
use App\Services\Invoicing\BusinessDocumentUblService;
use App\Support\Invoicing\CompanyEfakturaEligibility;
use App\Support\Invoicing\CompanyEfakturaSettings;
use App\Support\Invoicing\ComplianceSubmissionResult;
use App\Support\Invoicing\Efaktura\PeppolParticipantId;
use App\Support\Invoicing\Efaktura\SapiSkDocumentStatusMapper;
use App\Support\Invoicing\Efaktura\SapiSkSendPayload;
use App\Support\Invoicing\EuStructuredDocumentExport;
use App\Support\Invoicing\SkUblProfile;
use Illuminate\Http\Client\RequestException;

class SapiSkComplianceGateway implements ComplianceSubmissionGateway
{
    public function __construct(
        protected SapiSkClient $client,
        protected BusinessDocumentUblService $ublService,
    ) {}

    public function provider(): string
    {
        return ComplianceProvider::Peppol->value;
    }

    public function supports(BusinessDocument $document): bool
    {
        if (! config('efaktura.enabled')) {
            return false;
        }

        if (! EuStructuredDocumentExport::supports($document)) {
            return false;
        }

        if ($document->status !== BusinessDocumentStatus::Issued) {
            return false;
        }

        if (! in_array($document->type, [BusinessDocumentType::Invoice, BusinessDocumentType::CreditNote], true)) {
            return false;
        }

        $document->loadMissing(['company', 'contact']);
        $company = $document->company;
        $buyer = $document->resolvedBuyer();

        if (! app(CompanyEfakturaEligibility::class)->supportsCompany($company)) {
            return false;
        }

        $settings = CompanyEfakturaSettings::fromCompany($company);
        if (! $settings->configured()) {
            return false;
        }

        if ($buyer === null) {
            return false;
        }

        $contactCountry = trim((string) ($buyer->country ?? ''));
        if ($contactCountry === '') {
            return false;
        }

        return SkUblProfile::countryCode($buyer) === 'SK';
    }

    public function submit(BusinessDocument $document): ComplianceSubmissionResult
    {
        if (! $this->supports($document)) {
            return new ComplianceSubmissionResult(
                status: ComplianceSubmissionStatus::Skipped,
                message: 'Document is not eligible for SAPI-SK submission.',
            );
        }

        $document->loadMissing(['company', 'contact', 'lines']);
        $buyer = $document->resolvedBuyer();
        $settings = CompanyEfakturaSettings::fromCompany($document->company);
        $senderParticipantId = (string) $settings->peppolParticipantId();
        $receiverParticipantId = $buyer !== null ? PeppolParticipantId::fromContact($buyer) : null;

        if ($receiverParticipantId === null) {
            return new ComplianceSubmissionResult(
                status: ComplianceSubmissionStatus::Failed,
                message: 'Recipient Peppol participant ID is missing (IČO, DIČ, or peppol_participant_id on contact).',
            );
        }

        try {
            $ubl = $this->ublService->xml($document, auditDownload: false);
            $baseUrl = (string) $settings->sapiBaseUrl();
            $token = $this->client->accessToken(
                (string) $settings->sapiClientId(),
                (string) $settings->sapiClientSecret(),
                $baseUrl,
            );

            $response = $this->client->sendDocument(
                $token,
                $senderParticipantId,
                SapiSkSendPayload::build($document, $senderParticipantId, $receiverParticipantId, $ubl),
                $document->id,
                $baseUrl,
            );

            $externalId = (string) (
                $response['id']
                ?? $response['document_id']
                ?? $response['message_id']
                ?? $response['providerDocumentId']
                ?? ''
            );

            $status = SapiSkDocumentStatusMapper::fromProviderPayload($response)
                ?? ComplianceSubmissionStatus::Submitted;

            return new ComplianceSubmissionResult(
                status: $status,
                externalId: $externalId !== '' ? $externalId : null,
                responsePayload: $response,
            );
        } catch (RequestException $exception) {
            if ($this->client->isRecipientNotFoundError($exception)) {
                return new ComplianceSubmissionResult(
                    status: ComplianceSubmissionStatus::Failed,
                    message: 'Recipient is not registered in the Peppol network.',
                    responsePayload: $exception->response?->json(),
                );
            }

            report($exception);

            return new ComplianceSubmissionResult(
                status: ComplianceSubmissionStatus::Failed,
                message: $exception->getMessage(),
            );
        } catch (\Throwable $e) {
            report($e);

            return new ComplianceSubmissionResult(
                status: ComplianceSubmissionStatus::Failed,
                message: $e->getMessage(),
            );
        }
    }
}
