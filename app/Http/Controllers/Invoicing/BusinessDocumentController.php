<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\BulkBusinessDocumentRequest;
use App\Http\Requests\Invoicing\SendBusinessDocumentEmailRequest;
use App\Http\Requests\Invoicing\StoreBusinessDocumentRequest;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyStockItem;
use App\Models\CompanyWarehouse;
use App\Models\Store;
use App\Services\Invoicing\BusinessDocumentBtcPayService;
use App\Services\Invoicing\BusinessDocumentBulkService;
use App\Services\Invoicing\BusinessDocumentCreditNoteService;
use App\Services\Invoicing\BusinessDocumentDuplicateService;
use App\Services\Invoicing\BusinessDocumentEmailService;
use App\Services\Invoicing\BusinessDocumentFromProformaService;
use App\Services\Invoicing\BusinessDocumentFromQuoteService;
use App\Services\Invoicing\BusinessDocumentIsdocService;
use App\Services\Invoicing\BusinessDocumentIssueService;
use App\Services\Invoicing\BusinessDocumentListPresenter;
use App\Services\Invoicing\BusinessDocumentMarkPaidService;
use App\Services\Invoicing\BusinessDocumentPaymentTokenService;
use App\Services\Invoicing\BusinessDocumentPdfService;
use App\Services\Invoicing\BusinessDocumentQuoteService;
use App\Services\Invoicing\BusinessDocumentUblService;
use App\Services\Invoicing\CanonicalInvoiceBuilder;
use App\Services\Invoicing\CompanyStockMovementService;
use App\Services\Invoicing\DocumentSequenceService;
use App\Services\Invoicing\DocumentTotalsCalculator;
use App\Support\Invoicing\CompanyAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class BusinessDocumentController extends Controller
{
    public function __construct(
        protected DocumentTotalsCalculator $totalsCalculator,
        protected BusinessDocumentIssueService $issueService,
        protected BusinessDocumentMarkPaidService $markPaidService,
        protected BusinessDocumentPaymentTokenService $paymentTokenService,
        protected BusinessDocumentBtcPayService $btcPayService,
        protected BusinessDocumentPdfService $pdfService,
        protected BusinessDocumentDuplicateService $duplicateService,
        protected BusinessDocumentBulkService $bulkService,
        protected DocumentSequenceService $sequenceService,
        protected BusinessDocumentEmailService $emailService,
        protected BusinessDocumentFromProformaService $fromProformaService,
        protected BusinessDocumentFromQuoteService $fromQuoteService,
        protected BusinessDocumentQuoteService $quoteService,
        protected BusinessDocumentCreditNoteService $creditNoteService,
        protected CanonicalInvoiceBuilder $canonicalBuilder,
        protected BusinessDocumentIsdocService $isdocService,
        protected BusinessDocumentUblService $ublService,
        protected CompanyStockMovementService $stockMovementService,
    ) {}

    /**
     * @return list<string>
     */
    protected function documentRelations(): array
    {
        return [
            'contact:id,name',
            'store:id,name',
            'sourceDocument:id,number,type,status',
            'finalInvoice:id,number,status,source_document_id,type',
            'bankMatch:id,business_document_id,bank_transaction_id,match_type,matched_at',
            'bankMatch.transaction:id,booked_at',
        ];
    }

    public function index(
        Request $request,
        Company $company,
        BusinessDocumentListPresenter $listPresenter,
    ): JsonResponse {
        $documents = $this->bulkService->filteredQuery($company, $request)
            ->select(BusinessDocumentListPresenter::listColumns())
            ->with(BusinessDocumentListPresenter::listRelations())
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($listPresenter->paginated($documents, $company));
    }

    public function bulk(BulkBusinessDocumentRequest $request, Company $company): JsonResponse|Response
    {
        $documents = $this->bulkService->resolveDocuments($company, $request);

        if ($documents->isEmpty()) {
            throw ValidationException::withMessages([
                'document_ids' => ['No invoices matched the selection.'],
            ]);
        }

        $action = $request->input('action');

        AuditLog::log('business_document.bulk_action', 'company', $company->id, [
            'action' => $action,
            'count' => $documents->count(),
        ]);

        return match ($action) {
            'mark_paid' => response()->json([
                'data' => $this->bulkService->markPaid($documents),
            ]),
            'delete' => response()->json([
                'data' => $this->bulkService->deleteDocuments($documents),
            ]),
            'cancel' => response()->json([
                'data' => $this->bulkService->cancelIssued($documents),
            ]),
            'pdf_zip' => $this->bulkService->downloadPdfZip($company, $documents),
            'pdf_merge' => $this->bulkService->downloadPdfMerged($company, $documents),
            'export_xlsx' => $this->bulkService->downloadXlsx($company, $documents),
            default => abort(400),
        };
    }

    public function store(StoreBusinessDocumentRequest $request, Company $company): JsonResponse
    {
        $type = $request->enum('type', BusinessDocumentType::class);

        if (! $type->isMvpEnabled()) {
            throw ValidationException::withMessages([
                'type' => ['This document type is not available yet.'],
            ]);
        }

        $this->assertContactBelongsToCompany($request->input('company_contact_id'), $company);
        $this->assertStoreBelongsToCompany($request->input('store_id'), $company);
        $this->assertStockItemsBelongToCompany($request->input('lines', []), $company);
        $this->assertWarehousesBelongToCompany($request->input('lines', []), $company);

        $isQuote = $type === BusinessDocumentType::Quote;

        $document = new BusinessDocument([
            'company_id' => $company->id,
            'company_contact_id' => $request->input('company_contact_id'),
            'store_id' => $this->resolveStoreId($company, $request),
            'type' => $type,
            'status' => BusinessDocumentStatus::Draft,
            'title' => $request->input('title'),
            'variable_symbol' => $request->input('variable_symbol'),
            'constant_symbol' => $request->input('constant_symbol')
                ?? CompanyAppSettings::from($company->app_settings)->get('default_constant_symbol'),
            'specific_symbol' => $request->input('specific_symbol'),
            'issue_date' => $request->input('issue_date'),
            'delivery_date' => $request->input('delivery_date') ?? $this->defaultDeliveryDate($request, $company),
            'due_date' => $request->input('due_date') ?? $this->defaultDueDate($request, $company),
            'currency' => $request->input('currency', $company->default_currency),
            'note_above_lines' => $request->input('note_above_lines'),
            'note_footer' => $request->input('note_footer') ?? $company->legal_footer_note,
            'internal_note' => $request->input('internal_note'),
            'pdf_locale' => $request->input('pdf_locale'),
            'pdf_show_signature' => $request->boolean('pdf_show_signature', true),
            'pdf_show_payment_info' => $request->boolean('pdf_show_payment_info', ! $isQuote),
            'tags' => $request->input('tags'),
            'payment_btc_enabled' => $isQuote ? false : $request->boolean('payment_btc_enabled'),
            'payment_bank_enabled' => $isQuote ? false : $request->boolean('payment_bank_enabled', true),
        ]);

        $document->setRelation('company', $company);
        $lines = $request->input('lines', []);
        $this->totalsCalculator->applyToDocument(
            $document,
            $lines,
            (float) $request->input('discount_percent', 0)
        );

        $document->save();
        $this->syncLines($document, $lines);

        return response()->json([
            'data' => $document->fresh(['lines', 'contact', 'store']),
        ], 201);
    }

    public function show(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        if (
            $businessDocument->payment_btc_enabled
            && $businessDocument->status === BusinessDocumentStatus::Issued
        ) {
            $this->btcPayService->syncPaidFromBtcpayIfSettled($businessDocument);
            $businessDocument->refresh();
        }

        return response()->json([
            'data' => $businessDocument->load(array_merge(['lines', 'company'], $this->documentRelations())),
        ]);
    }

    public function update(StoreBusinessDocumentRequest $request, Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        if (! $businessDocument->canUpdate($company)) {
            throw ValidationException::withMessages([
                'status' => ['This document cannot be edited in its current status.'],
            ]);
        }

        $this->assertContactBelongsToCompany($request->input('company_contact_id'), $company);
        $this->assertStoreBelongsToCompany($request->input('store_id'), $company);
        $this->assertStockItemsBelongToCompany($request->input('lines', []), $company);
        $this->assertWarehousesBelongToCompany($request->input('lines', []), $company);

        $lines = $request->input('lines', []);
        $previousTotal = 0.0;
        $previousStoreId = null;
        $previousPaymentBtc = false;

        $businessDocument = DB::transaction(function () use (
            $businessDocument,
            $company,
            $request,
            $lines,
            &$previousTotal,
            &$previousStoreId,
            &$previousPaymentBtc,
        ): BusinessDocument {
            $locked = BusinessDocument::query()
                ->whereKey($businessDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDocumentCompany($locked, $company);

            if (! $locked->canUpdate($company)) {
                throw ValidationException::withMessages([
                    'status' => ['This document cannot be edited in its current status.'],
                ]);
            }

            $previousTotal = (float) $locked->total;
            $previousStoreId = $locked->store_id;
            $previousPaymentBtc = $locked->payment_btc_enabled;

            $locked->fill(array_merge($request->only([
                'company_contact_id',
                'title',
                'variable_symbol',
                'constant_symbol',
                'specific_symbol',
                'issue_date',
                'delivery_date',
                'due_date',
                'currency',
                'note_above_lines',
                'note_footer',
                'internal_note',
                'pdf_locale',
                'pdf_show_signature',
                'pdf_show_payment_info',
                'tags',
                'payment_btc_enabled',
                'payment_bank_enabled',
            ]), [
                'store_id' => $this->resolveStoreId($company, $request) ?? $locked->store_id,
            ]));

            if ($locked->type === BusinessDocumentType::Quote) {
                $locked->pdf_show_payment_info = false;
                $locked->payment_btc_enabled = false;
                $locked->payment_bank_enabled = false;
            }

            $locked->setRelation('company', $company);
            $this->totalsCalculator->applyToDocument(
                $locked,
                $lines,
                (float) $request->input('discount_percent', $locked->discount_percent)
            );

            $locked->save();
            $locked->lines()->delete();
            $this->syncLines($locked, $lines);

            if ($locked->status === BusinessDocumentStatus::Issued) {
                // Editing an issued document replaces its lines - stock must
                // follow atomically with the persisted line replacement.
                $this->stockMovementService->rebuildDocumentIssue($locked->fresh(['lines', 'company']));
            }

            return $locked->fresh(['lines', 'contact', 'store']);
        });

        if ($businessDocument->status === BusinessDocumentStatus::Issued) {
            if (! $businessDocument->payment_btc_enabled || ! $businessDocument->store_id) {
                $businessDocument->payment_token = null;
                $businessDocument->btcpay_invoice_id = null;
                $businessDocument->btcpay_checkout_link = null;
                $businessDocument->btcpay_checkout_created_at = null;
                $businessDocument->save();
            } else {
                $this->paymentTokenService->assignIfNeeded($businessDocument);
                if ($this->btcPayService->shouldRefreshAfterUpdate(
                    $businessDocument,
                    $previousTotal,
                    $previousStoreId,
                    $previousPaymentBtc
                )) {
                    try {
                        $this->btcPayService->syncForDocument($businessDocument, forceRefresh: true);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                if ($businessDocument->isDirty()) {
                    $businessDocument->save();
                }
            }
        }

        return response()->json([
            'data' => $businessDocument->fresh(['lines', 'contact', 'store']),
        ]);
    }

    public function issue(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);
        $businessDocument->load(['company', 'lines', 'store']);

        $issued = $this->issueService->issue($businessDocument);

        return response()->json(['data' => $issued]);
    }

    public function cancel(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        if ($businessDocument->status === BusinessDocumentStatus::Cancelled) {
            return response()->json(['data' => $businessDocument]);
        }

        if (! $businessDocument->canCancel()) {
            throw ValidationException::withMessages([
                'status' => ['This document cannot be cancelled in its current status.'],
            ]);
        }

        DB::transaction(function () use ($businessDocument) {
            $locked = BusinessDocument::query()
                ->whereKey($businessDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === BusinessDocumentStatus::Cancelled) {
                return;
            }

            if (! $locked->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => ['This document cannot be cancelled in its current status.'],
                ]);
            }

            // Paid documents had their stock applied at issue time too -
            // cancelling either state must return the goods (Cursor PR #67).
            $shouldReverseStock = in_array($locked->status, [
                BusinessDocumentStatus::Issued,
                BusinessDocumentStatus::Issued->value,
                BusinessDocumentStatus::Paid,
                BusinessDocumentStatus::Paid->value,
            ], true);

            $locked->update([
                'status' => BusinessDocumentStatus::Cancelled,
                'paid_at' => null,
                'amount_paid' => null,
            ]);

            if ($shouldReverseStock) {
                $this->stockMovementService->reverseDocumentCancel($locked->fresh(['lines']));
            }
        });

        AuditLog::log('business_document.cancelled', 'business_document', $businessDocument->id, [
            'company_id' => $company->id,
            'number' => $businessDocument->number,
        ], request()->user()?->id);

        return response()->json(['data' => $businessDocument->fresh()]);
    }

    public function pdf(Company $company, BusinessDocument $businessDocument)
    {
        $this->assertDocumentCompany($businessDocument, $company);

        if ($businessDocument->status === BusinessDocumentStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Issue the document before downloading PDF.'],
            ]);
        }

        return $this->pdfService->download($businessDocument);
    }

    public function isdoc(Company $company, BusinessDocument $businessDocument): Response
    {
        $this->assertDocumentCompany($businessDocument, $company);

        if ($businessDocument->status === BusinessDocumentStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Issue the document before downloading ISDOC.'],
            ]);
        }

        if (! $this->isdocService->supports($businessDocument)) {
            throw ValidationException::withMessages([
                'document' => ['ISDOC export is not available for this document.'],
            ]);
        }

        $xml = $this->isdocService->xml($businessDocument);
        $filename = ($businessDocument->number ?: $businessDocument->id).'.isdoc';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function ubl(Company $company, BusinessDocument $businessDocument): Response
    {
        $this->assertDocumentCompany($businessDocument, $company);

        if ($businessDocument->status === BusinessDocumentStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Issue the document before downloading UBL.'],
            ]);
        }

        if (! $this->ublService->supports($businessDocument)) {
            throw ValidationException::withMessages([
                'document' => ['UBL export is not available for this document.'],
            ]);
        }

        $xml = $this->ublService->xml($businessDocument);
        $filename = ($businessDocument->number ?: $businessDocument->id).'.xml';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function markPaid(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        $document = $this->markPaidService->markPaid(
            $businessDocument,
            null,
            null,
            'manual',
            request()->user()?->id,
        );

        return response()->json(['data' => $document]);
    }

    public function unmarkPaid(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        $document = $this->markPaidService->unmarkPaid($businessDocument, request()->user()?->id);

        return response()->json(['data' => $document]);
    }

    public function emailPreview(Company $company, BusinessDocument $businessDocument, Request $request): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        return response()->json([
            'data' => $this->emailService->preview($company, $businessDocument, $request->user()),
        ]);
    }

    public function sendEmail(
        SendBusinessDocumentEmailRequest $request,
        Company $company,
        BusinessDocument $businessDocument,
    ): JsonResponse {
        $this->assertDocumentCompany($businessDocument, $company);

        try {
            $result = $this->emailService->send(
                $company,
                $businessDocument,
                $request->user(),
                $request->input('to', []),
                $request->input('cc', []),
                $request->input('bcc', []),
                $request->input('subject'),
                $request->input('body'),
            );
        } catch (TransportExceptionInterface $e) {
            return response()->json(['message' => 'Email could not be sent: '.$e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Email sent.',
            'data' => $result,
        ]);
    }

    public function history(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        $logs = AuditLog::query()
            ->where('target_type', 'business_document')
            ->where('target_id', $businessDocument->id)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'created_at' => $log->created_at?->toIso8601String(),
                'user' => $log->user ? [
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'metadata' => $log->metadata,
            ]);

        return response()->json(['data' => $logs]);
    }

    public function createFinalInvoice(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        $invoice = $this->fromProformaService->createFinalInvoice($company, $businessDocument);

        return response()->json(['data' => $invoice], 201);
    }

    public function approveQuote(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        $quote = $this->quoteService->approve($company, $businessDocument);

        return response()->json(['data' => $quote]);
    }

    public function rejectQuote(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        $quote = $this->quoteService->reject($company, $businessDocument);

        return response()->json(['data' => $quote]);
    }

    public function createInvoiceFromQuote(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        $invoice = $this->fromQuoteService->createInvoiceFromQuote($company, $businessDocument);

        return response()->json(['data' => $invoice], 201);
    }

    public function createCreditNoteFromInvoice(Request $request, Company $company): JsonResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'uuid'],
        ]);

        $invoice = BusinessDocument::query()
            ->where('company_id', $company->id)
            ->where('id', $request->input('invoice_id'))
            ->firstOrFail();

        $creditNote = $this->creditNoteService->createFromInvoice($company, $invoice);

        return response()->json(['data' => $creditNote], 201);
    }

    public function duplicate(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);
        $businessDocument->load(['lines', 'contact']);

        $copy = $this->duplicateService->duplicate($company, $businessDocument);

        AuditLog::log('business_document.duplicated', 'business_document', $copy->id, [
            'source_id' => $businessDocument->id,
            'company_id' => $company->id,
        ]);

        return response()->json(['data' => $copy], 201);
    }

    public function destroy(Company $company, BusinessDocument $businessDocument): JsonResponse
    {
        $this->assertDocumentCompany($businessDocument, $company);

        if (! $businessDocument->canDelete($company)) {
            throw ValidationException::withMessages([
                'status' => ['This document cannot be deleted. Cancel it first, or delete only the latest invoice without bank matches or linked documents.'],
            ]);
        }

        $id = $businessDocument->id;
        $number = $businessDocument->number;
        $documentType = $businessDocument->type->value;

        // Lock + re-check + stock reversal in one transaction: deleting an
        // issued document used to leave its stock deducted forever
        // (Cursor PR #67).
        DB::transaction(function () use ($businessDocument, $company) {
            $locked = BusinessDocument::query()
                ->whereKey($businessDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canDelete($company)) {
                throw ValidationException::withMessages([
                    'status' => ['This document cannot be deleted. Cancel it first, or delete only the latest invoice without bank matches or linked documents.'],
                ]);
            }

            $this->stockMovementService->reverseDocumentCancel($locked->fresh(['lines']));
            $locked->lines()->delete();
            $locked->delete();
        });

        $this->sequenceService->syncSeriesAfterDocumentChange($company, $documentType);

        AuditLog::log('business_document.deleted', 'business_document', $id, [
            'company_id' => $company->id,
            'number' => $number,
        ], request()->user()?->id);

        return response()->json(['message' => 'Invoice deleted']);
    }

    protected function syncLines(BusinessDocument $document, array $lines): void
    {
        $company = $document->company;

        foreach ($lines as $index => $line) {
            $amounts = $this->canonicalBuilder->computeLineAmounts($company, $line, $document->resolvedBuyer());

            BusinessDocumentLine::create([
                'business_document_id' => $document->id,
                'company_stock_item_id' => $line['company_stock_item_id'] ?? null,
                'company_warehouse_id' => $line['company_warehouse_id'] ?? null,
                'sort_order' => $index,
                'name' => $line['name'],
                'description' => $line['description'] ?? null,
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit' => $line['unit'] ?? 'ks.',
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'line_discount_percent' => (float) ($line['line_discount_percent'] ?? 0),
                'tax_rate' => $amounts['tax_rate'],
                'line_total' => number_format($amounts['gross'], 2, '.', ''),
            ]);
        }
    }

    protected function assertDocumentCompany(BusinessDocument $document, Company $company): void
    {
        if ($document->company_id !== $company->id) {
            abort(404);
        }
    }

    protected function assertContactBelongsToCompany(?string $contactId, Company $company): void
    {
        if (! $contactId) {
            return;
        }

        if (! CompanyContact::where('id', $contactId)->where('company_id', $company->id)->exists()) {
            throw ValidationException::withMessages([
                'company_contact_id' => ['Invalid contact for this company.'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function assertStockItemsBelongToCompany(array $lines, Company $company): void
    {
        $ids = collect($lines)
            ->pluck('company_stock_item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $validCount = CompanyStockItem::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->count();

        if ($validCount !== count($ids)) {
            throw ValidationException::withMessages([
                'lines' => ['One or more stock items are invalid for this company.'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function assertWarehousesBelongToCompany(array $lines, Company $company): void
    {
        $ids = collect($lines)
            ->pluck('company_warehouse_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $validCount = CompanyWarehouse::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->count();

        if ($validCount !== count($ids)) {
            throw ValidationException::withMessages([
                'lines' => ['One or more warehouses are invalid for this company.'],
            ]);
        }
    }

    protected function assertStoreBelongsToCompany(?string $storeId, Company $company): void
    {
        if (! $storeId) {
            return;
        }

        if (! Store::where('id', $storeId)->where('company_id', $company->id)->where('user_id', $company->user_id)->exists()) {
            throw ValidationException::withMessages([
                'store_id' => ['Store must be linked to this company.'],
            ]);
        }
    }

    protected function defaultDueDate(StoreBusinessDocumentRequest $request, Company $company): ?string
    {
        $settings = CompanyAppSettings::from($company->app_settings);
        $fallbackDays = $settings->int('default_invoice_payment_terms_days', 14);

        $contactId = $request->input('company_contact_id');
        if (! $contactId) {
            $issue = $request->input('issue_date') ?? now()->toDateString();

            return Carbon::parse($issue)->addDays($fallbackDays)->toDateString();
        }

        $contact = CompanyContact::find($contactId);
        $days = $contact?->default_payment_terms_days ?? $fallbackDays;
        $issue = $request->input('issue_date') ?? now()->toDateString();

        return Carbon::parse($issue)->addDays($days)->toDateString();
    }

    protected function defaultDeliveryDate(StoreBusinessDocumentRequest $request, Company $company): ?string
    {
        $mode = CompanyAppSettings::from($company->app_settings)->get('default_delivery_date_mode', 'empty');
        $issue = $request->input('issue_date') ?? now()->toDateString();

        return match ($mode) {
            'issue_date' => $issue,
            'due_date' => $request->input('due_date')
                ?? $this->defaultDueDate($request, $company),
            default => null,
        };
    }

    protected function resolveStoreId(Company $company, StoreBusinessDocumentRequest $request): ?string
    {
        $storeId = $request->input('store_id');
        if ($storeId) {
            return $storeId;
        }

        return Store::query()
            ->where('company_id', $company->id)
            ->where('user_id', $company->user_id)
            ->orderBy('name')
            ->value('id');
    }
}
