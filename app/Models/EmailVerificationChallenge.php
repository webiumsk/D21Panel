<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A 6-digit code emailed to prove control of an address before a change is
 * applied. See EmailCodeChallengeService for the lifecycle.
 *
 * @property string $id
 * @property int $user_id
 * @property string $purpose
 * @property string $email
 * @property string $code_hash
 * @property array<string, mixed>|null $payload
 * @property int $attempts
 * @property int $send_count
 * @property Carbon|null $last_sent_at
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $superseded_at
 */
class EmailVerificationChallenge extends Model
{
    use HasUuids, Prunable;

    public const PURPOSE_GUEST_UPGRADE = 'guest_upgrade';

    public const PURPOSE_WALLET_CONNECTION_CHANGE = 'wallet_connection_change';

    public const PURPOSES = [
        self::PURPOSE_GUEST_UPGRADE,
        self::PURPOSE_WALLET_CONNECTION_CHANGE,
    ];

    protected $fillable = [
        'user_id',
        'purpose',
        'email',
        'code_hash',
        'payload',
        'attempts',
        'send_count',
        'last_sent_at',
        'expires_at',
        'verified_at',
        'consumed_at',
        'superseded_at',
    ];

    protected $hidden = ['code_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'send_count' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Neither consumed nor replaced by a newer challenge (may still be expired). */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->whereNull('superseded_at');
    }

    public function isLive(): bool
    {
        return $this->consumed_at === null && $this->superseded_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Finished rows are kept one day for support/audit correlation, then pruned (model:prune). */
    public function prunable(): Builder
    {
        $cutoff = now()->subDay();

        return static::query()
            ->where('expires_at', '<', $cutoff)
            ->orWhere('consumed_at', '<', $cutoff)
            ->orWhere('superseded_at', '<', $cutoff);
    }
}
