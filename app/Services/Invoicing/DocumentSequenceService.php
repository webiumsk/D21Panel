<?php

namespace App\Services\Invoicing;

use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\BusinessExpense;
use App\Models\Company;
use App\Models\CompanyDocumentSequence;
use App\Models\DocumentNumberReservation;
use App\Support\LandingCopy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentSequenceService
{
    public function __construct(
        protected DocumentNumberFormatter $formatter,
    ) {}

    public function nextNumber(Company $company, string $documentType, ?int $localHighCounter = null): string
    {
        return DB::transaction(function () use ($company, $documentType, $localHighCounter) {
            $series = $this->resolveSeriesForIssue($company, $documentType);

            $series = CompanyDocumentSequence::query()
                ->where('id', $series->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->syncPeriod($series);
            $this->ensureCounterSynced($series);
            $this->applyLocalHighCounter($series, $localHighCounter);

            $series->last_number = (int) $series->last_number + 1;
            $series->save();

            return $this->formatter->format(
                $series->format,
                (int) $series->last_number,
            );
        });
    }

    /**
     * Atomically reserves the next number for an issue attempt (audit F3).
     *
     * Idempotent per (company, document type, issue_request_id): a retried
     * request returns the existing reservation - including its number - in
     * whatever status it currently has, so a client can recover an
     * interrupted issue without burning another number.
     */
    public function reserveNumberForIssue(
        Company $company,
        string $documentType,
        string $issueRequestId,
        ?int $localHighCounter = null,
        ?int $reservedByUserId = null,
    ): DocumentNumberReservation {
        return DB::transaction(function () use ($company, $documentType, $issueRequestId, $localHighCounter, $reservedByUserId) {
            $existing = DocumentNumberReservation::query()
                ->where('company_id', $company->id)
                ->where('document_type', $documentType)
                ->where('issue_request_id', $issueRequestId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $series = $this->resolveSeriesForIssue($company, $documentType);
            $series = CompanyDocumentSequence::query()
                ->where('id', $series->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->syncPeriod($series);
            $this->ensureCounterSynced($series);
            $this->applyLocalHighCounter($series, $localHighCounter);
            // ensureCounterSynced derives the counter from SERVER documents,
            // which local-first clients do not create - never hand out a
            // number at or below an existing reservation for this period.
            // Voided reservations count too: numbers are never recycled.
            $this->applyReservedCounterFloor($series);

            $series->last_number = (int) $series->last_number + 1;
            $series->save();

            $counter = (int) $series->last_number;

            return DocumentNumberReservation::create([
                'company_id' => $company->id,
                'document_type' => $documentType,
                'company_document_sequence_id' => $series->id,
                'issue_request_id' => $issueRequestId,
                'period_key' => $series->period_key,
                'counter' => $counter,
                'number' => $this->formatter->format($series->format, $counter),
                'status' => DocumentNumberReservation::STATUS_RESERVED,
                'reserved_by_user_id' => $reservedByUserId,
            ]);
        });
    }

    /**
     * Marks a reservation confirmed once the client has persisted the issued
     * snapshot. Stores only an opaque hash + format version - never content.
     * Idempotent for already confirmed reservations; refuses voided ones.
     */
    public function confirmReservation(
        Company $company,
        string $documentType,
        string $issueRequestId,
        ?string $snapshotHash = null,
        ?string $snapshotFormatVersion = null,
    ): DocumentNumberReservation {
        return DB::transaction(function () use ($company, $documentType, $issueRequestId, $snapshotHash, $snapshotFormatVersion) {
            $reservation = $this->lockedReservation($company, $documentType, $issueRequestId);

            if ($reservation->status === DocumentNumberReservation::STATUS_VOIDED) {
                throw ValidationException::withMessages([
                    'issue_request_id' => ['Reservation was voided and cannot be confirmed.'],
                ]);
            }

            if ($reservation->status !== DocumentNumberReservation::STATUS_CONFIRMED) {
                $reservation->status = DocumentNumberReservation::STATUS_CONFIRMED;
                $reservation->confirmed_hash = $snapshotHash;
                $reservation->confirmed_format_version = $snapshotFormatVersion;
                $reservation->save();
            }

            return $reservation;
        });
    }

    /**
     * Voids an unconfirmed reservation (client abandoned the issue). The
     * number is NOT recycled - the sequence keeps its gap. Idempotent for
     * already voided reservations; refuses confirmed ones.
     */
    public function voidReservation(
        Company $company,
        string $documentType,
        string $issueRequestId,
    ): DocumentNumberReservation {
        return DB::transaction(function () use ($company, $documentType, $issueRequestId) {
            $reservation = $this->lockedReservation($company, $documentType, $issueRequestId);

            if ($reservation->status === DocumentNumberReservation::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'issue_request_id' => ['Reservation was confirmed and cannot be voided.'],
                ]);
            }

            if ($reservation->status !== DocumentNumberReservation::STATUS_VOIDED) {
                $reservation->status = DocumentNumberReservation::STATUS_VOIDED;
                $reservation->save();
            }

            return $reservation;
        });
    }

    /**
     * Releases the reservation holding a deleted invoice's number so the
     * sequence stays GAPLESS (P3 numbering rework, user requirement: deleted
     * numbers must be reissued). Only the HIGHEST counter of the series
     * period can be released - deleting older invoices is refused upstream,
     * and this guard makes the rule race-safe. Chained deletes (newest
     * first) release one number at a time.
     *
     * Returns released=false with reason "not_found" when no reservation
     * holds the number (pre-allocator documents) - a harmless no-op.
     *
     * @return array{released: bool, reason?: string}
     */
    public function releaseReservationByNumber(
        Company $company,
        string $documentType,
        string $number,
    ): array {
        return DB::transaction(function () use ($company, $documentType, $number) {
            $series = $this->resolveSeriesForIssue($company, $documentType);
            $series = CompanyDocumentSequence::query()
                ->where('id', $series->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->syncPeriod($series);

            $scoped = DocumentNumberReservation::query()
                ->where('company_document_sequence_id', $series->id)
                ->when(
                    $series->period_key === null,
                    fn ($query) => $query->whereNull('period_key'),
                    fn ($query) => $query->where('period_key', $series->period_key),
                );

            $reservation = (clone $scoped)
                ->where('number', $number)
                ->lockForUpdate()
                ->first();
            if (! $reservation) {
                return ['released' => false, 'reason' => 'not_found'];
            }

            $maxCounter = (int) (clone $scoped)->max('counter');
            if ((int) $reservation->counter !== $maxCounter) {
                throw ValidationException::withMessages([
                    'number' => ['Only the most recent number of the series can be released.'],
                ]);
            }

            $reservation->delete();

            $remainingMax = (int) (clone $scoped)->max('counter');
            if ((int) $series->last_number > $remainingMax) {
                // The reserve path re-derives the counter from documents,
                // the local high counter and the remaining reservation floor
                // - lowering here makes previews honest immediately.
                $series->last_number = $remainingMax;
                $series->save();
            }

            AuditLog::log('company.document_number_released', 'company', $company->id, [
                'document_type' => $documentType,
                'number' => $number,
                'counter' => (int) $reservation->counter,
            ]);

            return ['released' => true];
        });
    }

    public function findReservation(
        Company $company,
        string $documentType,
        string $issueRequestId,
    ): ?DocumentNumberReservation {
        return DocumentNumberReservation::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('issue_request_id', $issueRequestId)
            ->first();
    }

    /**
     * Raises the series counter to the highest counter ever reserved for the
     * current period. Safe against races: every reservation for a series runs
     * under the same lockForUpdate on the series row.
     */
    protected function applyReservedCounterFloor(CompanyDocumentSequence $series): void
    {
        $reservedMax = (int) DocumentNumberReservation::query()
            ->where('company_document_sequence_id', $series->id)
            ->when(
                $series->period_key === null,
                fn ($query) => $query->whereNull('period_key'),
                fn ($query) => $query->where('period_key', $series->period_key),
            )
            ->max('counter');

        if ($reservedMax > (int) $series->last_number) {
            $series->last_number = $reservedMax;
            $series->save();
        }
    }

    protected function lockedReservation(
        Company $company,
        string $documentType,
        string $issueRequestId,
    ): DocumentNumberReservation {
        $reservation = DocumentNumberReservation::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('issue_request_id', $issueRequestId)
            ->lockForUpdate()
            ->first();

        if (! $reservation) {
            throw ValidationException::withMessages([
                'issue_request_id' => ['No reservation found for this issue request.'],
            ]);
        }

        return $reservation;
    }

    public function lastIssuedCounter(Company $company, string $documentType): int
    {
        $series = CompanyDocumentSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->first();

        return $series ? (int) $series->last_number : 0;
    }

    public function previewNextCounter(Company $company, string $documentType, ?int $localHighCounter = null): int
    {
        $series = CompanyDocumentSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->first();

        if (! $series) {
            $this->seedDefaultsForCompany($company);

            $series = CompanyDocumentSequence::query()
                ->where('company_id', $company->id)
                ->where('document_type', $documentType)
                ->where('is_default', true)
                ->first();
        }

        if (! $series) {
            throw ValidationException::withMessages([
                'number_series' => ['No default number series found for this document type.'],
            ]);
        }

        $this->ensureCounterSynced($series);
        $this->applyLocalHighCounter($series, $localHighCounter);

        return $this->effectiveLastNumber($series->fresh()) + 1;
    }

    public function previewNext(CompanyDocumentSequence $series, ?int $counterOverride = null): string
    {
        $counter = $counterOverride ?? ($this->effectiveLastNumber($series) + 1);

        return $this->formatter->format($series->format, $counter);
    }

    public function previewNextNumber(Company $company, string $documentType, ?int $localHighCounter = null): string
    {
        $series = CompanyDocumentSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->first();

        if (! $series) {
            $this->seedDefaultsForCompany($company);

            $series = CompanyDocumentSequence::query()
                ->where('company_id', $company->id)
                ->where('document_type', $documentType)
                ->where('is_default', true)
                ->first();
        }

        if (! $series) {
            throw ValidationException::withMessages([
                'number_series' => ['No default number series found for this document type.'],
            ]);
        }

        $this->ensureCounterSynced($series);
        $this->applyLocalHighCounter($series, $localHighCounter);

        return $this->previewNext($series->fresh());
    }

    /**
     * Align server counter with the highest issued number known to local-first clients (Evolu).
     */
    protected function applyLocalHighCounter(CompanyDocumentSequence $series, ?int $localHighCounter): void
    {
        if ($localHighCounter === null || $localHighCounter < 0) {
            return;
        }

        if ($localHighCounter > (int) $series->last_number) {
            $series->last_number = $localHighCounter;
            $series->save();
        }
    }

    protected function ensureCounterSynced(CompanyDocumentSequence $series): void
    {
        $this->syncPeriod($series);

        $fromDocuments = $series->document_type === 'expense'
            ? $this->highestUsedExpenseCounter($series->company_id, $series->format)
            : $this->highestUsedCounter(
                $series->company_id,
                $series->document_type,
                $series->format,
            );

        if ($fromDocuments !== (int) $series->last_number) {
            $series->last_number = $fromDocuments;
            $series->save();
        }
    }

    /**
     * Recalculate default series counter after a document is deleted.
     */
    public function syncSeriesAfterDocumentChange(Company $company, string $documentType): void
    {
        $series = CompanyDocumentSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->first();

        if ($series) {
            $this->ensureCounterSynced($series);
        }
    }

    protected function highestUsedCounter(string $companyId, string $documentType, string $format): int
    {
        $digitLen = $this->counterDigitsInFormat($format);
        $max = 0;

        BusinessDocument::query()
            ->where('company_id', $companyId)
            ->where('type', $documentType)
            ->whereNotNull('number')
            ->pluck('number')
            ->each(function (string $number) use ($digitLen, &$max) {
                if (strlen($number) < $digitLen) {
                    return;
                }

                $suffix = substr($number, -$digitLen);
                if (ctype_digit($suffix)) {
                    $max = max($max, (int) $suffix);
                }
            });

        return $max;
    }

    protected function highestUsedExpenseCounter(string $companyId, string $format): int
    {
        $digitLen = $this->counterDigitsInFormat($format);
        $max = 0;

        BusinessExpense::query()
            ->where('company_id', $companyId)
            ->pluck('internal_number')
            ->each(function (string $number) use ($digitLen, &$max) {
                if (strlen($number) < $digitLen) {
                    return;
                }

                $suffix = substr($number, -$digitLen);
                if (ctype_digit($suffix)) {
                    $max = max($max, (int) $suffix);
                }
            });

        return $max;
    }

    protected function counterDigitsInFormat(string $format): int
    {
        $format = strtoupper(trim($format));

        if (preg_match('/N{2,}$/', $format, $matches)) {
            return strlen($matches[0]);
        }

        if (preg_match('/C+$/', $format, $matches)) {
            return strlen($matches[0]);
        }

        if (preg_match_all('/N{2,}/', $format, $matches)) {
            $max = 0;
            foreach ($matches[0] as $run) {
                $max = max($max, strlen($run));
            }
            if ($max > 0) {
                return $max;
            }
        }

        return max(1, substr_count($format, 'C'));
    }

    protected function effectiveLastNumber(CompanyDocumentSequence $series): int
    {
        $periodKey = $this->currentPeriodKey($series->reset_period);

        if ($series->period_key !== $periodKey) {
            return 0;
        }

        return (int) $series->last_number;
    }

    public function currentPeriodKey(string $resetPeriod): string
    {
        return match ($resetPeriod) {
            'monthly' => now()->format('Y-m'),
            'never' => 'all',
            default => now()->format('Y'),
        };
    }

    /**
     * Default number series seeded for new companies.
     * Format tokens: Y = year, M = month, N = counter (run of 2+). Legacy R/C still work.
     * Single Y or N are literal characters. Literal prefix letters must not use token runs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function defaultSeriesDefinitions(?string $locale = null): array
    {
        return array_map(function (array $def) use ($locale) {
            return [
                'document_type' => $def['document_type'],
                'name' => LandingCopy::get($def['name_key'], $locale),
                'format' => $def['format'],
                'is_default' => true,
            ];
        }, $this->defaultSeriesFormatDefinitions());
    }

    /**
     * @return array<int, array{document_type: string, name_key: string, format: string}>
     */
    protected function defaultSeriesFormatDefinitions(): array
    {
        return [
            ['document_type' => 'invoice', 'name_key' => 'invoicing.series_default_name_invoice', 'format' => 'INVYYYYNNNN'],
            ['document_type' => 'credit_note', 'name_key' => 'invoicing.series_default_name_credit_note', 'format' => 'CNYYYYNNNN'],
            ['document_type' => 'proforma', 'name_key' => 'invoicing.series_default_name_proforma', 'format' => 'PFYYYYNNNN'],
            ['document_type' => 'delivery_note', 'name_key' => 'invoicing.series_default_name_delivery_note', 'format' => 'DELYYYYNNNN'],
            ['document_type' => 'quote', 'name_key' => 'invoicing.series_default_name_quote', 'format' => 'QTYYYYNNNN'],
            ['document_type' => 'order_received', 'name_key' => 'invoicing.series_default_name_order_received', 'format' => 'POYYYYNNNN'],
            ['document_type' => 'order_issued', 'name_key' => 'invoicing.series_default_name_order_issued', 'format' => 'SOYYYYNNNN'],
            ['document_type' => 'expense', 'name_key' => 'invoicing.series_default_name_expense', 'format' => 'EXPYYYYNNNN'],
        ];
    }

    public function seedDefaultsForCompany(Company $company, ?string $locale = null): void
    {
        foreach ($this->defaultSeriesDefinitions($locale) as $def) {
            $exists = CompanyDocumentSequence::query()
                ->where('company_id', $company->id)
                ->where('document_type', $def['document_type'])
                ->where('is_default', true)
                ->exists();

            if ($exists) {
                continue;
            }

            CompanyDocumentSequence::create([
                'company_id' => $company->id,
                'document_type' => $def['document_type'],
                'name' => $def['name'],
                'format' => $def['format'],
                'reset_period' => 'yearly',
                'is_default' => $def['is_default'],
                'period_key' => $this->currentPeriodKey('yearly'),
                'last_number' => 0,
            ]);
        }
    }

    protected function resolveSeriesForIssue(Company $company, string $documentType): CompanyDocumentSequence
    {
        $series = CompanyDocumentSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->first();

        if ($series) {
            return $series;
        }

        $this->seedDefaultsForCompany($company);

        $series = CompanyDocumentSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->first();

        if (! $series) {
            throw ValidationException::withMessages([
                'number_series' => ['No default number series found for this document type.'],
            ]);
        }

        return $series;
    }

    protected function syncPeriod(CompanyDocumentSequence $series): void
    {
        $key = $this->currentPeriodKey($series->reset_period);
        if ($series->period_key !== $key) {
            $series->period_key = $key;
            $series->last_number = 0;
        }
    }
}
