<?php

namespace App\Support\Invoicing;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\CompanyContact;

/**
 * VAT display and calculation rules for EU companies, including §7 / §7a partial payers.
 *
 * Partial (§7a) payers distinguish the counterparty in three tiers: domestic
 * and non-EU supplies show no VAT at all; EU (non-domestic) supplies show a
 * 0% VAT summary plus the reverse-charge note.
 */
final class CompanyVatPolicy
{
    public const PARTIAL_REVERSE_CHARGE_NOTE = 'The supply of goods is exempt. The supply of services is subject to the reverse charge procedure.';

    /**
     * German statutory clause wording (operator-supplied, 2026-07-22). These
     * are legal texts mandated for DE invoices - they stay German regardless
     * of the PDF locale and are never translated.
     */
    public const DE_KLEINUNTERNEHMER_NOTE = 'Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung gem. § 19 UStG.';

    public const DE_REVERSE_CHARGE_NOTE = 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge).';

    /** Default export clause (services); goods sellers switch via the export_goods app setting. */
    public const DE_EXPORT_SERVICES_NOTE = 'Nicht im Inland steuerbare Leistung.';

    public const DE_EXPORT_GOODS_NOTE = 'Steuerfreie Ausfuhrlieferung.';

    /**
     * EU VAT area member codes - mirror of resources/js/config/euVatCountries.ts
     * ('EL' is the VIES alias for Greece).
     *
     * @var list<string>
     */
    public const EU_VAT_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SE', 'SI', 'SK',
    ];

    public function vatStatus(Company $company): string
    {
        $status = (string) ($company->vat_status ?? '');

        if ($status === 'payer' || $status === 'partial') {
            return $status;
        }

        if ($status === 'none' || $status === '') {
            return $company->vat_payer ? 'payer' : 'none';
        }

        return $company->vat_payer ? 'payer' : 'none';
    }

    public function isPartialPayer(Company $company): bool
    {
        return $this->vatStatus($company) === 'partial';
    }

    public function isFullPayer(Company $company): bool
    {
        return $this->vatStatus($company) === 'payer';
    }

    /**
     * Seller country fallback for companies with no country set - derived
     * from the single-country jurisdictions only. Multi-country buckets
     * (eu_other, offshore, asia) stay empty, and an empty seller compares
     * as domestic - the safe default that never triggers reverse charge.
     *
     * @var array<string, string>
     */
    private const JURISDICTION_COUNTRY = [
        'eu_sk' => 'SK',
        'eu_cz' => 'CZ',
        'eu_de' => 'DE',
        'eu_at' => 'AT',
        'ch' => 'CH',
        'us' => 'US',
        'uk' => 'GB',
    ];

    protected function sellerCountry(Company $company): string
    {
        $country = $this->normalizeCountryCode((string) $company->country);
        if ($country !== '') {
            return $country;
        }

        return self::JURISDICTION_COUNTRY[JurisdictionRules::normalizeValue($company->jurisdiction)] ?? '';
    }

    public function isDomesticSupply(Company $company, ?CompanyContact $contact): bool
    {
        if ($contact === null) {
            return true;
        }

        $seller = $this->sellerCountry($company);
        $buyer = $this->normalizeCountryCode((string) $contact->country);

        if ($seller === '' || $buyer === '') {
            return true;
        }

        return $seller === $buyer;
    }

    public function isForeignSupply(Company $company, ?CompanyContact $contact): bool
    {
        return ! $this->isDomesticSupply($company, $contact);
    }

    /**
     * Counterparty tier for VAT display: 'domestic' (same country or empty),
     * 'eu' (EU member other than the supplier's country) or 'non_eu'.
     * A country that does not normalize to a known ISO2 code falls to
     * 'non_eu' - it is never treated as an EU reverse-charge case.
     *
     * @return 'domestic'|'eu'|'non_eu'
     */
    public function supplyRegion(Company $company, ?CompanyContact $contact): string
    {
        if ($this->isDomesticSupply($company, $contact)) {
            return 'domestic';
        }

        $buyer = $this->normalizeCountryCode((string) $contact?->country);
        if (strlen($buyer) === 2 && in_array($buyer, self::EU_VAT_COUNTRIES, true)) {
            return 'eu';
        }

        return 'non_eu';
    }

    /**
     * §4 full payer invoicing a VAT-registered business in another EU state:
     * the tax liability transfers to the buyer (reverse charge / exemption),
     * so no VAT is charged and the invoice carries the note. Requires the
     * counterparty's IC DPH (vat_id) - B2C EU supplies keep normal VAT.
     */
    public function euB2bReverseCharge(Company $company, ?CompanyContact $contact): bool
    {
        return $this->isFullPayer($company)
            && $this->supplyRegion($company, $contact) === 'eu'
            && trim((string) $contact?->vat_id) !== '';
    }

    public function isDeCompany(Company $company): bool
    {
        return JurisdictionRules::normalizeValue($company->jurisdiction) === 'eu_de';
    }

    /**
     * DE export exemption (operator rule): a German payer invoicing a non-EU
     * counterparty charges no VAT and carries the export clause instead.
     */
    public function exportExemptionApplies(Company $company, ?CompanyContact $contact): bool
    {
        return $this->isDeCompany($company)
            && $this->isFullPayer($company)
            && $this->supplyRegion($company, $contact) === 'non_eu';
    }

    public function calculatesVatAmounts(Company $company, ?CompanyContact $contact = null): bool
    {
        if ($company->jurisdiction === CompanyJurisdiction::Us) {
            return true;
        }

        return $this->isFullPayer($company)
            && ! $this->euB2bReverseCharge($company, $contact)
            && ! $this->exportExemptionApplies($company, $contact);
    }

    public function showsVatRateColumn(Company $company, ?CompanyContact $contact = null): bool
    {
        if ($company->jurisdiction === CompanyJurisdiction::Us) {
            return true;
        }

        if ($this->isFullPayer($company)) {
            return true;
        }

        // §7a: the VAT rate column only appears on EU reverse-charge
        // invoices (rate 0); domestic and non-EU supplies show no VAT.
        return $this->isPartialPayer($company) && $this->supplyRegion($company, $contact) === 'eu';
    }

    public function showsVatBreakdown(Company $company, ?CompanyContact $contact = null): bool
    {
        if ($company->jurisdiction === CompanyJurisdiction::Us) {
            return false;
        }

        if ($this->isFullPayer($company)) {
            return true;
        }

        // §7a EU supply: show the summary with VAT 0 next to the
        // reverse-charge note.
        return $this->isPartialPayer($company) && $this->supplyRegion($company, $contact) === 'eu';
    }

    public function defaultTaxRate(Company $company, ?CompanyContact $contact = null): float
    {
        if ($company->jurisdiction === CompanyJurisdiction::Us) {
            return (float) ($company->vat_rate_default ?? 0);
        }

        if ($this->isFullPayer($company)) {
            return (float) ($company->vat_rate_default ?? 0);
        }

        return 0.0;
    }

    public function resolveLineTaxRate(Company $company, ?CompanyContact $contact, ?float $requestedRate = null): float
    {
        if (! $this->calculatesVatAmounts($company, $contact)) {
            return 0.0;
        }

        return $requestedRate ?? $this->defaultTaxRate($company, $contact);
    }

    public function vatApplicableForIsdoc(Company $company, ?CompanyContact $contact = null): bool
    {
        return $this->isFullPayer($company);
    }

    /**
     * EN 16931 tax scenario of a whole document (shared by the UBL/XRechnung
     * and ZUGFeRD exports): null = normal S/Z categories; otherwise the
     * category code plus the statutory exemption reason. AE = reverse
     * charge (statutory or the manual reverse_charge toggle - same rule as
     * reverseChargeNote), E = exempt (§19 Kleinunternehmer), G = export of
     * goods outside the EU (export_goods app setting), O = services outside
     * the scope of German VAT.
     *
     * @return array{category: string, reason: string|null}|null
     */
    public function enTaxScenario(
        Company $company,
        ?CompanyContact $contact,
        CompanyAppSettings $settings,
    ): ?array {
        if ($this->isDeCompany($company) && $this->vatStatus($company) === 'none') {
            return ['category' => 'E', 'reason' => self::DE_KLEINUNTERNEHMER_NOTE];
        }

        if ($this->reverseChargeNote($company, $contact, $settings) !== null) {
            return [
                'category' => 'AE',
                'reason' => $this->taxClause($company, $contact, $settings),
            ];
        }

        if ($this->exportExemptionApplies($company, $contact)) {
            return [
                'category' => $settings->bool('export_goods') ? 'G' : 'O',
                'reason' => $this->taxClause($company, $contact, $settings),
            ];
        }

        return null;
    }

    /**
     * The statutory tax clause for the invoice, in precedence order:
     * DE Kleinunternehmer (§19 UStG) for German non-payers, the
     * reverse-charge note (German statutory wording for DE companies),
     * then the DE export clause for non-EU supplies. Returns null when no
     * clause applies.
     */
    public function taxClause(
        Company $company,
        ?CompanyContact $contact,
        CompanyAppSettings $settings,
    ): ?string {
        if ($this->isDeCompany($company) && $this->vatStatus($company) === 'none') {
            return self::DE_KLEINUNTERNEHMER_NOTE;
        }

        $reverseCharge = $this->reverseChargeNote($company, $contact, $settings);
        if ($reverseCharge !== null) {
            if ($this->isDeCompany($company)) {
                // The German statutory wording is mandatory and always stays;
                // a custom company note is appended, never a replacement.
                $custom = $settings->bool('reverse_charge') ? trim((string) $settings->get('reverse_charge_note')) : '';

                return $custom !== ''
                    ? self::DE_REVERSE_CHARGE_NOTE.' '.$custom
                    : self::DE_REVERSE_CHARGE_NOTE;
            }

            return $reverseCharge;
        }

        if ($this->exportExemptionApplies($company, $contact)) {
            $custom = trim((string) $settings->get('export_note'));
            if ($custom !== '') {
                return $custom;
            }

            return $settings->bool('export_goods')
                ? self::DE_EXPORT_GOODS_NOTE
                : self::DE_EXPORT_SERVICES_NOTE;
        }

        return null;
    }

    public function reverseChargeNote(
        Company $company,
        ?CompanyContact $contact,
        CompanyAppSettings $settings,
    ): ?string {
        $applies = ($this->isPartialPayer($company) && $this->supplyRegion($company, $contact) === 'eu')
            || $this->euB2bReverseCharge($company, $contact);

        if ($applies) {
            // A custom note from the company app settings wins over the
            // statutory default wording.
            $custom = $settings->bool('reverse_charge') ? trim((string) $settings->get('reverse_charge_note')) : '';

            return $custom !== '' ? $custom : __(self::PARTIAL_REVERSE_CHARGE_NOTE);
        }

        if ($settings->bool('reverse_charge') && $contact && trim((string) $contact->vat_id) !== '') {
            return (string) ($settings->get('reverse_charge_note')
                ?: __('Reverse charge - VAT to be accounted for by the recipient.'));
        }

        return null;
    }

    protected function normalizeCountryCode(string $country): string
    {
        $code = strtoupper(trim($country));

        // Canonicalize the Greek VIES alias so 'EL' vs 'GR' never
        // misclassifies a domestic supply as cross-border.
        return $code === 'EL' ? 'GR' : $code;
    }
}
