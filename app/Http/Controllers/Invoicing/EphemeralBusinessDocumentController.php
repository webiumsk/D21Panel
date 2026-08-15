<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\EphemeralBusinessDocumentBtcpayRequest;
use App\Http\Requests\Invoicing\EphemeralBusinessDocumentBulkRequest;
use App\Http\Requests\Invoicing\EphemeralBusinessDocumentEfakturaRequest;
use App\Http\Requests\Invoicing\EphemeralBusinessDocumentEmailRequest;
use App\Http\Requests\Invoicing\EphemeralBusinessDocumentPdfRequest;
use App\Http\Requests\Invoicing\EphemeralCompanyEmailSmtpTestRequest;
use App\Http\Requests\Invoicing\TestEfakturaConnectionRequest;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\EphemeralBtcpayCheckout;
use App\Models\Store;
use App\Models\User;
use App\Services\Invoicing\BusinessDocumentBtcPayService;
use App\Services\Invoicing\BusinessDocumentEmailService;
use App\Services\Invoicing\BusinessDocumentIsdocService;
use App\Services\Invoicing\BusinessDocumentPdfService;
use App\Services\Invoicing\BusinessDocumentUblService;
use App\Services\Invoicing\CompanyEmailSettingsService;
use App\Services\Invoicing\CompanyPdfFilenameBuilder;
use App\Services\Invoicing\Efaktura\EfakturaConnectionTester;
use App\Services\Invoicing\Efaktura\EphemeralEfakturaSubmissionService;
use App\Services\Invoicing\EphemeralBtcpayCheckoutService;
use App\Services\Invoicing\EphemeralDocumentFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EphemeralBusinessDocumentController extends Controller
{
    public function __construct(
        protected EphemeralDocumentFactory $factory,
        protected BusinessDocumentPdfService $pdfService,
        protected BusinessDocumentIsdocService $isdocService,
        protected BusinessDocumentUblService $ublService,
        protected BusinessDocumentBtcPayService $btcPayService,
        protected EphemeralBtcpayCheckoutService $ephemeralBtcpayCheckoutService,
        protected CompanyPdfFilenameBuilder $pdfFilenameBuilder,
        protected BusinessDocumentEmailService $emailService,
        protected EphemeralEfakturaSubmissionService $efakturaService,
        protected CompanyEmailSettingsService $emailSettingsService,
    ) {}

    public function pdf(EphemeralBusinessDocumentPdfRequest $request, Company $company): Response
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithPdf($request, $snapshotCompany, $company);
    }

    public function pdfWithoutCompany(EphemeralBusinessDocumentPdfRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithPdf($request, $snapshotCompany);
    }

    public function emailPreview(EphemeralBusinessDocumentPdfRequest $request, Company $company): JsonResponse
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithEmailPreview($request, $snapshotCompany, $company);
    }

    public function emailPreviewWithoutCompany(EphemeralBusinessDocumentPdfRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithEmailPreview($request, $snapshotCompany);
    }

    public function sendEmail(EphemeralBusinessDocumentEmailRequest $request, Company $company): JsonResponse
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithSendEmail($request, $snapshotCompany, $company);
    }

    public function sendEmailWithoutCompany(EphemeralBusinessDocumentEmailRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithSendEmail($request, $snapshotCompany);
    }

    public function testEmailSettingsSmtp(EphemeralCompanyEmailSmtpTestRequest $request, Company $company): JsonResponse
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithEmailSettingsSmtpTest($request, $snapshotCompany);
    }

    public function testEmailSettingsSmtpWithoutCompany(EphemeralCompanyEmailSmtpTestRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithEmailSettingsSmtpTest($request, $snapshotCompany);
    }

    public function isdoc(EphemeralBusinessDocumentPdfRequest $request, Company $company): Response
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithIsdoc($request, $snapshotCompany, $company);
    }

    public function isdocWithoutCompany(EphemeralBusinessDocumentPdfRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithIsdoc($request, $snapshotCompany);
    }

    public function ubl(EphemeralBusinessDocumentPdfRequest $request, Company $company): Response
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithUbl($request, $snapshotCompany, $company);
    }

    public function ublWithoutCompany(EphemeralBusinessDocumentPdfRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithUbl($request, $snapshotCompany);
    }

    public function btcpayCheckout(EphemeralBusinessDocumentBtcpayRequest $request, Company $company): JsonResponse
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithBtcpayCheckout($request, $snapshotCompany, $company);
    }

    public function btcpayCheckoutWithoutCompany(EphemeralBusinessDocumentBtcpayRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithBtcpayCheckout($request, $snapshotCompany);
    }

    /**
     * Read-only lookup: the still-payable checkout of a document, or null.
     * Viewing an invoice calls THIS - a BTCPay invoice is only ever minted
     * by an explicit user action (production 2026-07-14: every view of an
     * unpaid invoice created another "New" BTCPay invoice).
     */
    public function btcpayCheckoutExistingWithoutCompany(EphemeralBusinessDocumentBtcpayRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        $store = Store::query()
            ->where('id', $request->validated('store_id'))
            ->where('user_id', $user->id)
            ->with('user')
            ->firstOrFail();

        $document = $this->factory->document($snapshotCompany, $request->validated());

        return response()->json([
            'data' => $this->findReusableCheckout($user, $store, $document, $request->input('evolu_document_id')),
        ]);
    }

    public function efakturaBridge(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $bridgeCompany = $this->efakturaService->resolveBridgeCompany($user);

        return response()->json([
            'data' => [
                'configured' => $bridgeCompany !== null && config('efaktura.enabled'),
                'bridge_company_id' => $bridgeCompany?->id,
                'bridge_company_name' => $bridgeCompany?->legal_name ?: $bridgeCompany?->trade_name,
            ],
        ]);
    }

    public function efakturaStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'evolu_document_id' => ['required', 'string', 'max:64'],
        ]);

        $row = $this->efakturaService->latestForDocument($user, $validated['evolu_document_id']);

        return response()->json([
            'data' => $row ? [$row->toApiRow()] : [],
        ]);
    }

    /** Latest submission per Evolu document id for the invoice-list badge. */
    public function efakturaStatusBulk(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'evolu_document_ids' => ['required', 'array', 'max:100'],
            'evolu_document_ids.*' => ['string', 'max:64'],
        ]);

        $rows = $this->efakturaService->latestForDocuments($user, $validated['evolu_document_ids']);

        return response()->json([
            'data' => array_map(fn ($row) => $row->toApiRow(), $rows),
        ]);
    }

    /**
     * One-shot SAPI-SK credential check for local-first companies - the
     * credentials live only in the client's Evolu database, so all three
     * fields must arrive in the body. Success is not persisted here; the
     * client stamps efaktura_connection_tested_at into its local settings.
     */
    public function efakturaTestConnection(
        TestEfakturaConnectionRequest $request,
        EfakturaConnectionTester $tester,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! config('efaktura.enabled')) {
            throw ValidationException::withMessages([
                'efaktura' => ['E-faktura integration is disabled globally.'],
            ]);
        }

        $validated = $request->validated();
        $result = $tester->test(
            $validated['efaktura_sapi_base_url'] ?? null,
            $validated['efaktura_sapi_client_id'] ?? null,
            $validated['efaktura_sapi_client_secret'] ?? null,
        );

        return response()->json(['data' => array_merge($result, [
            'tested_at' => $result['ok'] ? Carbon::now()->toIso8601String() : null,
        ])]);
    }

    public function efakturaSend(EphemeralBusinessDocumentEfakturaRequest $request, Company $company): JsonResponse
    {
        $this->efakturaService->assertCompanyConfigured($company);
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithEfakturaSend($request, $snapshotCompany, $company, $company);
    }

    public function efakturaSendWithoutCompany(EphemeralBusinessDocumentEfakturaRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $snapshotCompany = $this->factory->resolveCompany(
            $user,
            (array) ($request->validated()['company'] ?? []),
        );

        $bridgeCompany = $this->efakturaService->assertBridgeCompany($user);

        return $this->respondWithEfakturaSend($request, $snapshotCompany, $bridgeCompany);
    }

    public function efakturaRefresh(Request $request, Company $company): JsonResponse
    {
        $this->efakturaService->assertCompanyConfigured($company);

        return $this->respondWithEfakturaRefresh($request);
    }

    public function efakturaRefreshWithoutCompany(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->efakturaService->assertBridgeCompany($user);

        return $this->respondWithEfakturaRefresh($request);
    }

    public function bulkPdfZipWithoutCompany(EphemeralBusinessDocumentBulkRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithBulkPdfZip($request, $snapshotCompany);
    }

    public function bulkPdfZip(EphemeralBusinessDocumentBulkRequest $request, Company $company): Response
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithBulkPdfZip($request, $snapshotCompany, $company);
    }

    public function bulkPdfMergeWithoutCompany(EphemeralBusinessDocumentBulkRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithBulkPdfMerge($request, $snapshotCompany);
    }

    public function bulkPdfMerge(EphemeralBusinessDocumentBulkRequest $request, Company $company): Response
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondWithBulkPdfMerge($request, $snapshotCompany, $company);
    }

    public function btcpayStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'evolu_document_id' => ['required', 'string', 'max:64'],
            'btcpay_invoice_id' => ['required', 'string', 'max:128'],
        ]);

        $checkout = $this->ephemeralBtcpayCheckoutService->findForUser(
            $user,
            $validated['evolu_document_id'],
            $validated['btcpay_invoice_id'],
        );

        if (! $checkout) {
            return response()->json(['message' => 'Checkout not found.'], 404);
        }

        return response()->json([
            'data' => $this->ephemeralBtcpayCheckoutService->statusPayload($checkout),
        ]);
    }

    protected function respondWithPdf(
        EphemeralBusinessDocumentPdfRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): Response {
        $document = $this->factory->document($snapshotCompany, $request->validated());
        $filename = $this->pdfFilenameBuilder->build($document);
        [$auditType, $auditId] = $this->auditTarget($request->user(), $auditCompany, $snapshotCompany);

        AuditLog::log('business_document.ephemeral_pdf_downloaded', $auditType, $auditId, [
            'document_type' => $document->type?->value,
            'line_count' => $document->lines->count(),
            'company_less' => $auditCompany === null,
        ], $request->user()?->id);

        return response($this->pdfService->renderBinary($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    protected function respondWithEmailPreview(
        EphemeralBusinessDocumentPdfRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): JsonResponse {
        $document = $this->factory->document($snapshotCompany, $request->validated());

        return response()->json([
            'data' => $this->emailService->previewEphemeral($snapshotCompany, $document, $request->user()),
        ]);
    }

    protected function respondWithSendEmail(
        EphemeralBusinessDocumentEmailRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): JsonResponse {
        $document = $this->factory->document($snapshotCompany, $request->validated());

        try {
            $result = $this->emailService->sendEphemeral(
                $snapshotCompany,
                $document,
                $request->user(),
                $request->input('to', []),
                $request->input('cc', []),
                $request->input('bcc', []),
                $request->input('subject'),
                $request->input('body'),
            );
        } catch (TransportExceptionInterface $e) {
            Log::warning('Ephemeral business document email failed', [
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Email could not be sent.'], 422);
        }

        return response()->json([
            'message' => 'Email sent.',
            'data' => $result,
        ]);
    }

    protected function respondWithEmailSettingsSmtpTest(
        EphemeralCompanyEmailSmtpTestRequest $request,
        Company $snapshotCompany,
    ): JsonResponse {
        try {
            $this->emailSettingsService->sendSmtpTest($snapshotCompany, $request->validated('to'));
        } catch (TransportExceptionInterface $e) {
            Log::warning('Ephemeral company SMTP test failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'SMTP connection failed: '.$e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Test email sent.']);
    }

    protected function respondWithIsdoc(
        EphemeralBusinessDocumentPdfRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): Response {
        $document = $this->factory->document($snapshotCompany, $request->validated());
        $this->assertStructuredExportAllowed($document);

        $filename = ($document->number ?: 'document').'.isdoc';
        [$auditType, $auditId] = $this->auditTarget($request->user(), $auditCompany, $snapshotCompany);

        AuditLog::log('business_document.ephemeral_isdoc_downloaded', $auditType, $auditId, [
            'document_type' => $document->type?->value,
            'line_count' => $document->lines->count(),
            'company_less' => $auditCompany === null,
        ], $request->user()?->id);

        return response($this->isdocService->xml($document, auditDownload: false), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    protected function respondWithUbl(
        EphemeralBusinessDocumentPdfRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): Response {
        $document = $this->factory->document($snapshotCompany, $request->validated());
        $this->assertStructuredExportAllowed($document);

        $filename = ($document->number ?: 'document').'.xml';
        [$auditType, $auditId] = $this->auditTarget($request->user(), $auditCompany, $snapshotCompany);

        AuditLog::log('business_document.ephemeral_ubl_downloaded', $auditType, $auditId, [
            'document_type' => $document->type?->value,
            'line_count' => $document->lines->count(),
            'company_less' => $auditCompany === null,
        ], $request->user()?->id);

        return response($this->ublService->xml($document, auditDownload: false), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Existing checkout of a document, shared by the read-only lookup and
     * the create flow's dedupe:
     *  - an already PAID checkout (row, or a pending row whose BTCPay
     *    invoice settled meanwhile) returns a paid payload - it must never
     *    be replaced by a fresh invoice (PR #144: creating one for an
     *    already-paid document duplicated invoices in BTCPay),
     *  - a still-payable checkout with matching amount and currency is
     *    reused,
     *  - an UNKNOWN BTCPay state fails safe: with $failClosed the caller
     *    must not mint a replacement blindly (the existing invoice could be
     *    paid) - a 422 asks the user to retry; without it (read-only view)
     *    null simply shows no link,
     *  - anything else returns null (caller may create a fresh one).
     *
     * @return array{checkout_link: string|null, btcpay_invoice_id: string, status?: string, paid_at?: string|null}|null
     */
    protected function findReusableCheckout(
        User $user,
        Store $store,
        BusinessDocument $document,
        mixed $evoluDocumentId,
        bool $failClosed = false,
    ): ?array {
        if (! is_string($evoluDocumentId) || $evoluDocumentId === '') {
            return null;
        }

        $paid = $this->ephemeralBtcpayCheckoutService->findLatestPaid($user, $store, $evoluDocumentId);
        if ($paid) {
            return $this->paidEphemeralCheckoutPayload($paid);
        }

        $pending = $this->ephemeralBtcpayCheckoutService->findLatestPending($user, $store, $evoluDocumentId);
        if (! $pending) {
            return null;
        }

        $state = $this->btcPayService->ephemeralCheckoutState($store, $pending->btcpay_invoice_id);
        if ($state['state'] === 'unknown') {
            if ($failClosed) {
                throw ValidationException::withMessages([
                    'btcpay' => ['BTCPay did not confirm the state of the existing checkout. Try again in a moment.'],
                ]);
            }

            return null;
        }

        if ($state['state'] === 'paid') {
            return $this->paidEphemeralCheckoutPayload(
                $this->ephemeralBtcpayCheckoutService->markPaid($pending),
            );
        }

        if (
            $state['state'] === 'payable'
            && abs((float) $pending->amount - (float) $document->total) < 0.005
            && strcasecmp((string) $pending->currency, (string) $document->currency) === 0
        ) {
            return [
                'checkout_link' => $state['checkout_link'],
                'btcpay_invoice_id' => $state['btcpay_invoice_id'],
            ];
        }

        return null;
    }

    /**
     * @return array{checkout_link: null, btcpay_invoice_id: string, status: string, paid_at: string|null}
     */
    protected function paidEphemeralCheckoutPayload(EphemeralBtcpayCheckout $checkout): array
    {
        return [
            'checkout_link' => null,
            'btcpay_invoice_id' => $checkout->btcpay_invoice_id,
            'status' => EphemeralBtcpayCheckout::STATUS_PAID,
            'paid_at' => $checkout->paid_at?->toIso8601String(),
        ];
    }

    protected function respondWithBtcpayCheckout(
        EphemeralBusinessDocumentBtcpayRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // store_id is in the request body, not the route; EnsureStoreOwnership cannot be applied here.
        $store = Store::query()
            ->where('id', $request->validated('store_id'))
            ->where('user_id', $user->id)
            ->with('user')
            ->firstOrFail();

        $document = $this->factory->document($snapshotCompany, $request->validated());
        $document->payment_btc_enabled = (bool) ($request->input('document.payment_btc_enabled', true));
        $document->store_id = $store->id;

        if ($document->status === BusinessDocumentStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Issue the document before creating a Bitcoin checkout.'],
            ]);
        }

        $evoluDocumentId = $request->input('evolu_document_id');

        // Reuse a still-payable checkout for the same document (same amount
        // and currency) - never mint a second BTCPay invoice for it. A PAID
        // checkout returns its paid payload instead of any new invoice;
        // an UNKNOWN BTCPay state fails closed (422) rather than minting.
        $reused = $this->findReusableCheckout($user, $store, $document, $evoluDocumentId, failClosed: true);
        if ($reused !== null) {
            if (! isset($reused['status'])) {
                [$auditType, $auditId] = $this->auditTarget($user, $auditCompany, $snapshotCompany);
                AuditLog::log('business_document.ephemeral_btcpay_checkout', $auditType, $auditId, [
                    'document_type' => (string) $request->input('document.type'),
                    'store_id' => $store->id,
                    'btcpay_invoice_id' => $reused['btcpay_invoice_id'],
                    'company_less' => $auditCompany === null,
                    'reused' => true,
                ], $user->id);
            }

            return response()->json(['data' => $reused]);
        }

        $result = $this->btcPayService->createEphemeralCheckout(
            $document,
            $store,
            $request->input('evolu_document_id'),
        );
        if (
            is_string($evoluDocumentId)
            && $evoluDocumentId !== ''
            && is_string($result['btcpay_invoice_id'] ?? null)
            && $result['btcpay_invoice_id'] !== ''
        ) {
            $this->ephemeralBtcpayCheckoutService->registerCheckout(
                $user,
                $store,
                $evoluDocumentId,
                $result['btcpay_invoice_id'],
                (float) $document->total,
                $document->currency,
            );
        }

        [$auditType, $auditId] = $this->auditTarget($user, $auditCompany, $snapshotCompany);

        AuditLog::log('business_document.ephemeral_btcpay_checkout', $auditType, $auditId, [
            'document_type' => $document->type?->value,
            'store_id' => $store->id,
            'btcpay_invoice_id' => $result['btcpay_invoice_id'],
            'company_less' => $auditCompany === null,
        ], $user->id);

        return response()->json(['data' => $result]);
    }

    protected function respondWithBulkPdfZip(
        EphemeralBusinessDocumentBulkRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): Response {
        $documents = $this->factory->documentsFromBulk($snapshotCompany, $request->validated());
        $issued = $documents->filter(fn (BusinessDocument $document) => $document->status !== BusinessDocumentStatus::Draft);

        if ($issued->isEmpty()) {
            throw ValidationException::withMessages([
                'documents' => ['No issued invoices selected for PDF export.'],
            ]);
        }

        $binary = $this->pdfService->renderZipBinary($issued);

        [$auditType, $auditId] = $this->auditTarget($request->user(), $auditCompany, $snapshotCompany);

        AuditLog::log('business_document.ephemeral_bulk_pdf_zip', $auditType, $auditId, [
            'count' => $issued->count(),
            'company_less' => $auditCompany === null,
        ], $request->user()?->id);

        return response($binary, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="invoices.zip"',
        ]);
    }

    protected function respondWithBulkPdfMerge(
        EphemeralBusinessDocumentBulkRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): Response {
        $documents = $this->factory->documentsFromBulk($snapshotCompany, $request->validated());
        $issued = $documents->filter(fn (BusinessDocument $document) => $document->status !== BusinessDocumentStatus::Draft);

        if ($issued->isEmpty()) {
            throw ValidationException::withMessages([
                'documents' => ['No issued invoices selected for PDF export.'],
            ]);
        }

        $pdf = $this->pdfService->renderMergedBinary($issued);

        [$auditType, $auditId] = $this->auditTarget($request->user(), $auditCompany, $snapshotCompany);

        AuditLog::log('business_document.ephemeral_bulk_pdf_merge', $auditType, $auditId, [
            'count' => $issued->count(),
            'company_less' => $auditCompany === null,
        ], $request->user()?->id);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoices-merged.pdf"',
        ]);
    }

    protected function respondWithEfakturaSend(
        EphemeralBusinessDocumentEfakturaRequest $request,
        Company $snapshotCompany,
        Company $bridgeCompany,
        ?Company $auditCompany = null,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $document = $this->factory->document($snapshotCompany, $request->validated());
        $evoluDocumentId = (string) $request->validated('evolu_document_id');

        $this->efakturaService->assertEphemeralDocumentEligible($document, $bridgeCompany);

        $result = $this->efakturaService->submit($user, $bridgeCompany, $document, $evoluDocumentId);
        $row = $this->efakturaService->latestForDocument($user, $evoluDocumentId);

        return response()->json([
            'data' => array_merge($row?->toApiRow() ?? [], [
                'status' => $result->status->value,
                'external_id' => $result->externalId,
                'message' => $result->message,
                'response_payload' => $result->responsePayload,
            ]),
        ]);
    }

    protected function respondWithEfakturaRefresh(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'evolu_document_id' => ['required', 'string', 'max:64'],
        ]);

        $row = $this->efakturaService->refresh($user, $validated['evolu_document_id']);

        return response()->json([
            'data' => $row ? [$row->toApiRow()] : [],
        ]);
    }

    protected function assertStructuredExportAllowed(BusinessDocument $document): void
    {
        if ($document->status === BusinessDocumentStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Issue the document before downloading structured export.'],
            ]);
        }

        if (! $this->isdocService->supports($document)) {
            throw ValidationException::withMessages([
                'document' => ['Structured export is not available for this document.'],
            ]);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function auditTarget(?User $user, ?Company $auditCompany, Company $snapshotCompany): array
    {
        $companyId = $auditCompany?->id ?? $snapshotCompany->id;
        if (is_string($companyId) && Str::isUuid($companyId)) {
            return ['company', $companyId];
        }

        return [null, null];
    }
}
