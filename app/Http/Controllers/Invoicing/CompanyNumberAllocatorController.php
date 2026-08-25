<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DocumentNumberReservation;
use App\Services\Invoicing\DocumentSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Server-side number allocator for local-first issuing (invoicing audit F3).
 *
 * Company-scoped on purpose - NOT bound to a BTCPay store link - so invoicing
 * numbering works for any bridge company. The server only ever sees counters,
 * an idempotency key and (on confirm) an opaque snapshot hash; document
 * content stays on the client.
 */
class CompanyNumberAllocatorController extends Controller
{
    protected const DOCUMENT_TYPES = [
        'invoice',
        'credit_note',
        'proforma',
        'delivery_note',
        'quote',
        'order_received',
    ];

    public function __construct(
        protected DocumentSequenceService $sequenceService,
    ) {}

    /**
     * Rules shared by every allocator endpoint: which sequence (document
     * type) and which issue attempt (idempotency key) is being addressed.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function baseRules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(self::DOCUMENT_TYPES)],
            'issue_request_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    public function reserve(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            ...$this->baseRules(),
            'local_high_counter' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
        ]);

        $reservation = $this->sequenceService->reserveNumberForIssue(
            $company,
            $validated['document_type'],
            $validated['issue_request_id'],
            $validated['local_high_counter'] ?? null,
            $request->user()?->id,
        );

        return response()->json(['data' => $this->reservationPayload($reservation)]);
    }

    public function confirm(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            ...$this->baseRules(),
            'snapshot_hash' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[A-Fa-f0-9]+$/'],
            'snapshot_format_version' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $reservation = $this->sequenceService->confirmReservation(
            $company,
            $validated['document_type'],
            $validated['issue_request_id'],
            $validated['snapshot_hash'] ?? null,
            $validated['snapshot_format_version'] ?? null,
        );

        return response()->json(['data' => $this->reservationPayload($reservation)]);
    }

    public function void(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate($this->baseRules());

        $reservation = $this->sequenceService->voidReservation(
            $company,
            $validated['document_type'],
            $validated['issue_request_id'],
        );

        return response()->json(['data' => $this->reservationPayload($reservation)]);
    }

    /**
     * Gapless numbering (P3): frees the number of a deleted invoice so the
     * sequence hands it out again. Addressed by NUMBER (the client has no
     * reservation key for imported/auto-issued documents); only the highest
     * number of the series period can be released.
     */
    public function release(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(self::DOCUMENT_TYPES)],
            'number' => ['required', 'string', 'min:1', 'max:64'],
        ]);

        $result = $this->sequenceService->releaseReservationByNumber(
            $company,
            $validated['document_type'],
            $validated['number'],
        );

        return response()->json(['data' => $result]);
    }

    /** Recovery of an interrupted issue: what happened to this issue request? */
    public function status(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate($this->baseRules());

        $reservation = $this->sequenceService->findReservation(
            $company,
            $validated['document_type'],
            $validated['issue_request_id'],
        );

        return response()->json([
            'data' => $reservation ? $this->reservationPayload($reservation) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function reservationPayload(DocumentNumberReservation $reservation): array
    {
        return [
            'issue_request_id' => $reservation->issue_request_id,
            'document_type' => $reservation->document_type,
            'number' => $reservation->number,
            'counter' => $reservation->counter,
            'status' => $reservation->status,
            'period_key' => $reservation->period_key,
        ];
    }
}
