<?php

namespace App\Support\Invoicing\Accounting;

use App\Enums\CompanyJurisdiction;
use App\Support\Invoicing\JurisdictionRules;

/**
 * Maps a numeric VAT rate onto Pohoda's rate buckets (high / low / third /
 * none) using the jurisdiction's current statutory rate list: the highest
 * non-zero rate is "high", the next one "low", a third reduced rate
 * "third". Rates outside the list (historic rates on old documents, foreign
 * VAT) map to "none" and are reported through unmapped() so the caller can
 * flag them - Pohoda has no bucket for them.
 */
final class PohodaVatRateMapper
{
    public const HIGH = 'high';

    public const LOW = 'low';

    public const THIRD = 'third';

    public const NONE = 'none';

    /** @var array<string, string> */
    private array $buckets;

    /** @var array<string, true> */
    private array $unmapped = [];

    public function __construct(CompanyJurisdiction $jurisdiction)
    {
        $rates = array_values(array_filter(
            JurisdictionRules::for($jurisdiction)['vat_rates'],
            static fn (float $rate): bool => $rate > 0,
        ));
        rsort($rates, SORT_NUMERIC);

        $this->buckets = [];
        foreach ([self::HIGH, self::LOW, self::THIRD] as $index => $bucket) {
            if (isset($rates[$index])) {
                $this->buckets[self::key($rates[$index])] = $bucket;
            }
        }
    }

    public function bucket(float $rate): string
    {
        if ($rate <= 0) {
            return self::NONE;
        }

        $bucket = $this->buckets[self::key($rate)] ?? null;
        if ($bucket === null) {
            $this->unmapped[self::key($rate)] = true;

            return self::NONE;
        }

        return $bucket;
    }

    /**
     * Rates that had to fall back to "none" so far.
     *
     * @return list<string>
     */
    public function unmapped(): array
    {
        return array_keys($this->unmapped);
    }

    private static function key(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }
}
