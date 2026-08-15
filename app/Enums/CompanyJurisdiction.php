<?php

namespace App\Enums;

enum CompanyJurisdiction: string
{
    case EuSk = 'eu_sk';
    case EuCz = 'eu_cz';
    case EuDe = 'eu_de';
    case EuAt = 'eu_at';
    case EuOther = 'eu_other';
    case Ch = 'ch';
    case Us = 'us';
    case Uk = 'uk';
    case Offshore = 'offshore';
    case Asia = 'asia';

    /**
     * Jurisdictions with a complete market setup (VAT policy, clauses,
     * e-invoicing) - the only ones selectable for new companies; the rest
     * are visible but disabled until their market work lands. Mirror of
     * ENABLED_COMPANY_JURISDICTIONS in config/companyJurisdiction.ts.
     *
     * @return list<self>
     */
    public static function enabled(): array
    {
        return [self::EuSk, self::EuCz, self::EuDe, self::Us];
    }

    public function supportsPayBySquare(): bool
    {
        // PayBySquare is the Slovak QR standard - German/Austrian/Swiss
        // banking apps cannot read it (EPC QR / Swiss QR-bill are separate
        // follow-ups). EuOther keeps it for backward compatibility.
        return match ($this) {
            self::EuSk, self::EuCz, self::EuOther => true,
            default => false,
        };
    }

    public function isUs(): bool
    {
        return $this === self::Us;
    }

    public static function fromCountryCode(?string $country): self
    {
        $c = strtoupper(trim((string) $country));

        return match (true) {
            $c === 'SK' => self::EuSk,
            $c === 'CZ' => self::EuCz,
            $c === 'DE' => self::EuDe,
            $c === 'AT' => self::EuAt,
            // Liechtenstein is part of the Swiss VAT (MWST) area.
            $c === 'CH' || $c === 'LI' => self::Ch,
            $c === 'US' => self::Us,
            $c === 'GB' || $c === 'UK' => self::Uk,
            in_array($c, ['HK', 'SG', 'AE'], true) => self::Asia,
            in_array($c, ['GI', 'KY', 'PA', 'VG', 'BM', 'LU', 'MT', 'EE', 'LV', 'LT'], true) => self::Offshore,
            default => self::EuOther,
        };
    }
}
