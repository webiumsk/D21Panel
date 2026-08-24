<?php

namespace App\Support\Invoicing;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;

/**
 * Slovak e-faktura eligibility, split by direction to mirror the statute
 * (zákon 385/2025): only full VAT payers must ISSUE e-invoices, but every
 * SK taxable entity - non-payers and §7/§7a partial payers included - must
 * be able to RECEIVE them.
 */
final class CompanyEfakturaEligibility
{
    public function __construct(
        protected CompanyVatPolicy $vatPolicy,
    ) {}

    /** Outbound (issuing): eu_sk full VAT payers only. */
    public function supportsCompany(Company $company): bool
    {
        if (! $this->isSlovak($company)) {
            return false;
        }

        return $this->vatPolicy->isFullPayer($company);
    }

    /** Alias of supportsCompany() - reads better next to supportsInbound(). */
    public function supportsOutbound(Company $company): bool
    {
        return $this->supportsCompany($company);
    }

    /** Inbound (receiving): every Slovak company regardless of VAT status. */
    public function supportsInbound(Company $company): bool
    {
        return $this->isSlovak($company);
    }

    /** Whether the company may hold any e-faktura settings at all. */
    public function supportsAny(Company $company): bool
    {
        return $this->supportsInbound($company) || $this->supportsOutbound($company);
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return list<string>
     */
    public function efakturaSettingKeys(array $incoming): array
    {
        return array_values(array_filter(array_keys($incoming), static fn (string $key): bool => str_starts_with($key, 'efaktura_')));
    }

    private function isSlovak(Company $company): bool
    {
        return $company->jurisdiction === CompanyJurisdiction::EuSk;
    }
}
