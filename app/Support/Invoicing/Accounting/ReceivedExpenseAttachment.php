<?php

namespace App\Support\Invoicing\Accounting;

/** One binary attachment of a received (supplier) document, already loaded into memory. */
final class ReceivedExpenseAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mime,
        public readonly string $bytes,
    ) {}
}
