<?php

namespace App\Models;

use App\Enums\RegWatchChangeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Detected legislative change on a monitored source - the only table the
 * RegWatch monitoring cron writes to (status 'new'). Human-in-the-loop
 * review moves it new -> reviewed -> applied/dismissed before any edit
 * reaches regwatch_rules (docs/LEGAL.md).
 *
 * @property string $id
 * @property string $source_id
 * @property string|null $rule_id
 * @property RegWatchChangeStatus $status
 * @property string|null $summary
 * @property string|null $diff
 * @property array<string, mixed>|null $classification_json
 * @property Carbon $detected_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property-read RegWatchSource|null $source
 * @property-read RegWatchRule|null $rule
 * @property-read User|null $reviewer
 */
class RegWatchChange extends Model
{
    use HasUuids;

    protected $table = 'regwatch_changes';

    protected $fillable = [
        'source_id',
        'rule_id',
        'status',
        'summary',
        'diff',
        'classification_json',
        'detected_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => RegWatchChangeStatus::class,
            'classification_json' => 'array',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(RegWatchSource::class, 'source_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RegWatchRule::class, 'rule_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
