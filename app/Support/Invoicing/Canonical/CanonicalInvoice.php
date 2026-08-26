<?php

namespace App\Support\Invoicing\Canonical;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Support\Invoicing\CompanyVatPolicy;

/**
 * Canonical invoice snapshot used by PDF, ISDOC, persistence, and exports.
 */
final class CanonicalInvoice
{
    /**
     * @param  list<CanonicalInvoiceLine>  $lines
     * @param  list<CanonicalTaxBreakdownRow>  $taxBreakdown
     */
    public function __construct(
        public readonly Company $company,
        public readonly ?CompanyContact $contact,
        public readonly ?BusinessDocument $document,
        public readonly string $subtotal,
        public readonly string $taxTotal,
        public readonly string $total,
        public readonly float $discountPercent,
        public readonly string $amountDue,
        public readonly string $roundingMethod,
        public readonly array $lines,
        public readonly array $taxBreakdown,
        public readonly string $currency,
    ) {}

    public function vatApplicable(): bool
    {
        return app(CompanyVatPolicy::class)
            ->vatApplicableForIsdoc($this->company, $this->contact);
    }
}
