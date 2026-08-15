<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\BusinessDocument;
use App\Models\IntegrationDocumentInbox;
use App\Models\StoreIntegration;
use App\Services\Integrations\WooCommerceDocumentService;
use App\Services\Invoicing\BusinessDocumentPdfService;
use App\Services\Invoicing\CompanyPdfFilenameBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WooCommerceIntegrationController extends Controller
{
    public function __construct(
        protected WooCommerceDocumentService $documentService,
    ) {}

    public function connection(Request $request): JsonResponse
    {
        /** @var StoreIntegration $integration */
        $integration = $request->attributes->get('store_integration');

        return response()->json([
            'data' => $this->documentService->connectionInfo($integration),
        ]);
    }

    public function upsertContact(Request $request): JsonResponse
    {
        /** @var StoreIntegration $integration */
        $integration = $request->attributes->get('store_integration');
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'ico' => ['nullable', 'string', 'max:32'],
            'dic' => ['nullable', 'string', 'max:32'],
            'ic_dph' => ['nullable', 'string', 'max:32'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'zip' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:2'],
        ]);

        $contact = $this->documentService->upsertContact($integration, $validated);

        return response()->json(['data' => $contact], 201);
    }

    public function createDocument(Request $request): JsonResponse
    {
        /** @var StoreIntegration $integration */
        $integration = $request->attributes->get('store_integration');
        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:invoice,proforma'],
            'woocommerce_order_id' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'buyer' => ['sometimes', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric'],
            'lines.*.tax_rate' => ['sometimes', 'numeric'],
            // Empty strings from the WP plugin arrive as null
            // (convertEmptyStringsToNull) - optional strings must be nullable.
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_paid' => ['sometimes', 'boolean'],
            'paid_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'order_total' => ['sometimes', 'numeric'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0'],
            'btcpay_invoice_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'source_evolu_document_id' => ['sometimes', 'nullable', 'uuid'],
            'source_document_number' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $result = $this->documentService->createDocument($integration, $validated);

        if ($result instanceof IntegrationDocumentInbox) {
            return response()->json([
                'data' => $this->documentService->serializeInboxEntryWithDiagnostics($integration, $result),
            ], 201);
        }

        return response()->json([
            'data' => $this->documentService->serializeDocument($result),
        ], 201);
    }

    public function issueDocument(Request $request, string $documentId): JsonResponse
    {
        /** @var StoreIntegration $integration */
        $integration = $request->attributes->get('store_integration');

        $inbox = IntegrationDocumentInbox::query()
            ->where('id', $documentId)
            ->where('store_integration_id', $integration->id)
            ->first();
        if ($inbox) {
            $entry = $this->documentService->issueInboxEntry($integration, $inbox);

            return response()->json([
                'data' => $this->documentService->serializeIssuedInboxEntry($entry),
            ]);
        }

        $document = BusinessDocument::findOrFail($documentId);
        $company = $integration->company ?? $integration->store->company;
        if (! $company || $document->company_id !== $company->id) {
            abort(404);
        }
        $document = $this->documentService->issueDocument($integration, $document);

        return response()->json([
            'data' => $this->documentService->serializeDocument($document),
        ]);
    }

    /**
     * PDF of an auto-issued inbox document (WC email attachment path).
     * Accepts the inbox row id or the evolu document id.
     */
    public function documentPdf(
        Request $request,
        string $documentId,
        BusinessDocumentPdfService $pdfService,
        CompanyPdfFilenameBuilder $filenameBuilder,
    ): Response {
        /** @var StoreIntegration $integration */
        $integration = $request->attributes->get('store_integration');

        $inbox = IntegrationDocumentInbox::query()
            ->where('store_integration_id', $integration->id)
            ->where(function ($query) use ($documentId) {
                $query->where('id', $documentId)
                    ->orWhere('evolu_document_id', $documentId);
            })
            ->first();
        if (! $inbox) {
            abort(404);
        }

        $pdf = $this->documentService->renderInboxPdf($integration, $inbox, $pdfService, $filenameBuilder);

        return response($pdf['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf['filename'].'"',
        ]);
    }

    public function showDocument(Request $request, string $documentId): JsonResponse
    {
        /** @var StoreIntegration $integration */
        $integration = $request->attributes->get('store_integration');

        $inbox = IntegrationDocumentInbox::query()
            ->where('id', $documentId)
            ->where('store_integration_id', $integration->id)
            ->first();
        if ($inbox) {
            return response()->json([
                'data' => $this->documentService->serializeIssuedInboxEntry($inbox),
            ]);
        }

        $document = BusinessDocument::findOrFail($documentId);
        $company = $integration->company ?? $integration->store->company;
        if (! $company || $document->company_id !== $company->id) {
            abort(404);
        }

        return response()->json([
            'data' => $this->documentService->serializeDocument($document),
        ]);
    }
}
