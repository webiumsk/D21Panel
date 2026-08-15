<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Admin-maintained preset of a certified Slovak digital postman (CPDS) for
 * the e-faktura settings form: name + verified SAPI-SK base URL. Presets are
 * entered by the operator only after verifying the provider's real endpoint
 * (RegWatch rule - no unverified URLs). Preset hosts are implicitly trusted
 * by the SapiSkClient SSRF allowlist.
 *
 * @property string $id
 * @property string $name
 * @property string $base_url
 * @property string|null $send_detail_path
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EfakturaCpdsProvider extends Model
{
    use HasUuids;

    protected $table = 'efaktura_cpds_providers';

    protected $fillable = [
        'name',
        'base_url',
        'send_detail_path',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Public payload for the merchant settings form (via GET /api/config).
     *
     * @return list<array{id: string, name: string, base_url: string}>
     */
    public static function activePresets(): array
    {
        try {
            return self::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (self $preset): array => [
                    'id' => $preset->id,
                    'name' => $preset->name,
                    'base_url' => rtrim($preset->base_url, '/'),
                ])
                ->all();
        } catch (\Throwable) {
            // Missing table (fresh install before migrate) must never break
            // config or sending - presets are an additive convenience.
            return [];
        }
    }

    /**
     * Lowercased hosts of active presets - merged into the SAPI SSRF
     * allowlist so a preset-selected merchant always passes the host check.
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        try {
            return self::query()
                ->where('active', true)
                ->pluck('base_url')
                ->map(fn ($url): string => strtolower((string) parse_url(rtrim((string) $url, '/'), PHP_URL_HOST)))
                ->filter(fn (string $host): bool => $host !== '')
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Preset detail-path override for the CPDS matching the given base URL
     * host. The admin controller enforces one active preset per host, so the
     * match is unique; the sort_order/name ordering keeps resolution
     * deterministic even for legacy duplicate rows.
     */
    public static function detailPathForBaseUrl(?string $baseUrl): ?string
    {
        $host = strtolower((string) parse_url(rtrim((string) $baseUrl, '/'), PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        try {
            $match = self::query()
                ->where('active', true)
                ->whereNotNull('send_detail_path')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->first(fn (self $preset): bool => strtolower((string) parse_url(rtrim($preset->base_url, '/'), PHP_URL_HOST)) === $host);

            $path = $match?->send_detail_path;

            return is_string($path) && trim($path) !== '' ? trim($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Whether any active preset carries a sent-document detail path (scheduler gate). */
    public static function anyActiveDetailPath(): bool
    {
        try {
            return self::query()
                ->where('active', true)
                ->whereNotNull('send_detail_path')
                ->where('send_detail_path', '!=', '')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
