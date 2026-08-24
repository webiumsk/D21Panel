<?php

namespace App\Services\Invoicing\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;

/** What the accountant package should contain. */
final class AccountantPackageOptions
{
    public const FORMAT_POHODA = 'pohoda';

    public const FORMAT_CSV = 'csv';

    /**
     * @param  list<string>  $formats
     */
    public function __construct(
        public readonly array $formats = [self::FORMAT_POHODA, self::FORMAT_CSV],
        public readonly bool $includePdf = true,
        public readonly bool $includeIsdoc = true,
        public readonly bool $includeUbl = false,
        public readonly bool $includeExpenseAttachments = true,
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
    ) {
        foreach ($this->formats as $format) {
            if (! in_array($format, [self::FORMAT_POHODA, self::FORMAT_CSV], true)) {
                throw new InvalidArgumentException("Unknown accountant export format: {$format}");
            }
        }

        if ($this->formats === [] && ! $this->includePdf && ! $this->includeIsdoc && ! $this->includeUbl && ! $this->includeExpenseAttachments) {
            throw new InvalidArgumentException('The accountant package would be empty - pick at least one format or content type.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $date = static function (mixed $value): ?DateTimeImmutable {
            if ($value === null || $value === '') {
                return null;
            }

            return new DateTimeImmutable((string) $value);
        };

        return new self(
            formats: array_values(array_map('strval', (array) ($input['formats'] ?? [self::FORMAT_POHODA, self::FORMAT_CSV]))),
            includePdf: (bool) ($input['include_pdf'] ?? true),
            includeIsdoc: (bool) ($input['include_isdoc'] ?? true),
            includeUbl: (bool) ($input['include_ubl'] ?? false),
            includeExpenseAttachments: (bool) ($input['include_expense_attachments'] ?? true),
            from: $date($input['from'] ?? null),
            to: $date($input['to'] ?? null),
        );
    }

    public function wantsPohoda(): bool
    {
        return in_array(self::FORMAT_POHODA, $this->formats, true);
    }

    public function wantsCsv(): bool
    {
        return in_array(self::FORMAT_CSV, $this->formats, true);
    }
}
