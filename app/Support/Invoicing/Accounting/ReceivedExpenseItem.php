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
     * Transient payload from the local-first bridge (already validated by
     * EphemeralAccountantExportRequest); attachments arrive base64-encoded.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $date = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $attachments = [];
        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            $bytes = base64_decode((string) ($attachment['content_base64'] ?? ''), true);
            if ($bytes === false) {
                continue;
            }
            $attachments[] = new ReceivedExpenseAttachment(
                filename: (string) ($attachment['filename'] ?? 'attachment'),
                mime: (string) ($attachment['mime'] ?? 'application/octet-stream'),
                bytes: $bytes,
            );
        }

        return new self(
            internalNumber: (string) $payload['internal_number'],
            externalNumber: $text($payload['external_number'] ?? null),
            supplierName: $text($payload['supplier_name'] ?? null),
            variableSymbol: $text($payload['variable_symbol'] ?? null),
            constantSymbol: $text($payload['constant_symbol'] ?? null),
            issueDate: $date($payload['issue_date'] ?? null),
            deliveryDate: $date($payload['delivery_date'] ?? null),
            dueDate: $date($payload['due_date'] ?? null),
            paidAt: $date($payload['paid_at'] ?? null),
            total: number_format((float) ($payload['total'] ?? 0), 2, '.', ''),
            currency: strtoupper((string) ($payload['currency'] ?? 'EUR')),
            status: BusinessExpenseStatus::from((string) ($payload['status'] ?? BusinessExpenseStatus::Recorded->value)),
            note: $text($payload['note'] ?? null),
            supplierRegistrationNumber: $text($payload['supplier_registration_number'] ?? null),
            supplierTaxId: $text($payload['supplier_tax_id'] ?? null),
            supplierVatId: $text($payload['supplier_vat_id'] ?? null),
            attachments: $attachments,
        );
    }

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
