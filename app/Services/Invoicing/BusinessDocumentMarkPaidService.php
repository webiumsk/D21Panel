<?php

namespace App\Services\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\BusinessDocument;
use App\Services\Integrations\WooCommerceWebhookNotifier;
use Illuminate\Validation\ValidationException;

class BusinessDocumentMarkPaidService
{
    public function __construct(
        protected BusinessDocumentIssueService $issueService,
        protected BusinessDocumentPaymentTokenService $paymentTokenService,
    ) {}

    /**
     * Mark document paid (issues draft first). Supports partial bank amount.
     */
    public function markPaid(
        BusinessDocument $document,
        ?float $amountPaid = null,
        ?BankTransaction $bankTransaction = null,
        string $source = 'manual',
        ?int $userId = null,
    ): BusinessDocument {
        if ($document->status === BusinessDocumentStatus::Draft) {
            $document = $this->issueService->issue($document);
        }

        if ($document->status === BusinessDocumentStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['Cancelled documents cannot be marked as paid.'],
            ]);
        }

        if ($document->status === BusinessDocumentStatus::Paid) {
            return $document->fresh(['contact', 'store']);
        }

        if ($document->status !== BusinessDocumentStatus::Issued) {
            throw ValidationException::withMessages([
                'status' => ['Only issued documents can be marked as paid.'],
            ]);
        }

        $paidAmount = $amountPaid ?? (float) $document->total;
        $total = (float) $document->total;
        $fullyPaid = abs($paidAmount - $total) <= (float) config('bank_import.amount_tolerance', 0.01);

        $document->update([
            'status' => $fullyPaid ? BusinessDocumentStatus::Paid : BusinessDocumentStatus::Issued,
            'paid_at' => $fullyPaid ? now() : $document->paid_at,
            'amount_paid' => round($paidAmount, 2),
        ]);

        if ($fullyPaid) {
            $this->paymentTokenService->revokeAfterPaid($document->fresh());
            app(WooCommerceWebhookNotifier::class)
                ->notifyDocumentPaid($document->fresh());
        }

        $metadata = [
            'company_id' => $document->company_id,
            'number' => $document->number,
            'source' => $source,
            'amount_paid' => $paidAmount,
        ];
        if ($bankTransaction) {
            $metadata['bank_transaction_id'] = $bankTransaction->id;
        }

        AuditLog::log(
            'business_document.marked_paid',
            'business_document',
            $document->id,
            $metadata,
            $userId,
        );

        return $document->fresh(['contact', 'store']);
    }

    public function unmarkPaid(BusinessDocument $document, ?int $userId = null): BusinessDocument
    {
        if ($document->status !== BusinessDocumentStatus::Paid) {
            return $document->fresh(['contact', 'store']);
        }

        $document->update([
            'status' => BusinessDocumentStatus::Issued,
            'paid_at' => null,
            'amount_paid' => null,
        ]);

        AuditLog::log('business_document.unmarked_paid', 'business_document', $document->id, [
            'company_id' => $document->company_id,
            'number' => $document->number,
        ], $userId);

        return $document->fresh(['contact', 'store']);
    }
}
