<?php

namespace App\Models;

use App\Enums\RegWatchTopic;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * RegWatch rule - the source of truth for a tax/legal rule in a
 * jurisdiction. Edited ONLY by a human after reviewing the official source
 * (docs/LEGAL.md); the monitoring cron must never write here. A row with
 * verified_on = NULL is an unverified placeholder ("TODO: overiť z
 * oficiálneho zdroja") and must not be presented as fact.
 *
 * @property string $id
 * @property string $jurisdiction_id
 * @property string|null $source_id
 * @property string $slug
 * @property RegWatchTopic $topic
 * @property string $title
 * @property string $rule_text
 * @property string $source_url
 * @property Carbon|null $verified_on
 * @property Carbon|null $effective_from
 * @property-read RegWatchJurisdiction|null $jurisdiction
 * @property-read RegWatchSource|null $source
 */
class RegWatchRule extends Model
{
    use HasUuids;

    /** Seeded placeholder body - a rule with this text must stay unverified. */
    public const PLACEHOLDER_RULE_TEXT = 'TODO: overiť z oficiálneho zdroja';

    protected $table = 'regwatch_rules';

    protected $fillable = [
        'jurisdiction_id',
        'source_id',
        'slug',
        'topic',
        'title',
        'rule_text',
        'source_url',
        'verified_on',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'topic' => RegWatchTopic::class,
            'verified_on' => 'date',
            'effective_from' => 'date',
        ];
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(RegWatchJurisdiction::class, 'jurisdiction_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(RegWatchSource::class, 'source_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(RegWatchChange::class, 'rule_id');
    }
}
