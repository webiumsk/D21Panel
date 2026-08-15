<?php

namespace App\Models;

use App\Enums\RegWatchSourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Official source RegWatch monitors for legislative changes (Slov-Lex,
 * e-Sbirka, tax administrations...). The monitoring cron reads these and
 * writes detections into regwatch_changes only - never into regwatch_rules
 * (docs/LEGAL.md).
 *
 * @property string $id
 * @property string $jurisdiction_id
 * @property string $slug
 * @property string $name
 * @property string $url
 * @property RegWatchSourceType $type
 * @property bool $active
 * @property Carbon|null $last_checked_at
 * @property string|null $last_snapshot_hash
 * @property-read RegWatchJurisdiction|null $jurisdiction
 * @property-read int|null $new_changes_count
 */
class RegWatchSource extends Model
{
    use HasUuids;

    protected $table = 'regwatch_sources';

    protected $fillable = [
        'jurisdiction_id',
        'slug',
        'name',
        'url',
        'type',
        'active',
        'last_checked_at',
        'last_snapshot_hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => RegWatchSourceType::class,
            'active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(RegWatchJurisdiction::class, 'jurisdiction_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(RegWatchRule::class, 'source_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(RegWatchChange::class, 'source_id');
    }
}
