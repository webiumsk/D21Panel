<?php

namespace App\Support\Invoicing\Accounting;

use App\Enums\BusinessExpenseStatus;
use App\Models\BusinessExpense;
use DateTimeImmutable;

/**
 * Storage-agnostic view of a received supplier document (náklad) for the
 * accountant package: server-mode callers build it from BusinessExpense,
 * local-first callers from the transient Evolu payload.
 */
final class ReceivedExpenseItem
{
    /**
     * @param  list<ReceivedExpenseAttachment>  $attachments
     */
    public function __construct(
        public readonly string $internalNumber,
        public readonly ?string $externalNumber,
        public readonly ?string $supplierName,
        public readonly ?string $variableSymbol,
        public readonly ?string $constantSymbol,
        public readonly ?DateTimeImmutable $issueDate,
        public readonly ?DateTimeImmutable $deliveryDate,
        public readonly ?DateTimeImmutable $dueDate,
        public readonly ?DateTimeImmutable $paidAt,
        public readonly string $total,
        public readonly string $currency,
        public readonly BusinessExpenseStatus $status,
        public readonly ?string $note = null,
        public readonly ?string $supplierRegistrationNumber = null,
        public readonly ?string $supplierTaxId = null,
        public readonly ?string $supplierVatId = null,
        public readonly array $attachments = [],
    ) {}

    /**
     * @param  list<ReceivedExpenseAttachment>  $attachments
     */
    public static function fromModel(BusinessExpense $expense, array $attachments = []): self
    {
        $date = static fn (mixed $value): ?DateTimeImmutable => $value === null
            ? null
            : DateTimeImmutable::createFromInterface($value);

        return new self(
            internalNumber: (string) ($expense->internal_number ?: $expense->id),
            externalNumber: $expense->external_number ?: null,
            supplierName: $expense->title ?: null,
            variableSymbol: $expense->variable_symbol ?: null,
            constantSymbol: $expense->constant_symbol ?: null,
            issueDate: $date($expense->issue_date),
            deliveryDate: $date($expense->delivery_date),
            dueDate: $date($expense->due_date),
            paidAt: $date($expense->paid_at),
            total: number_format((float) $expense->total, 2, '.', ''),
            currency: (string) ($expense->currency ?: 'EUR'),
            status: $expense->status,
            note: $expense->internal_note ?: null,
            attachments: $attachments,
        );
    }
}
