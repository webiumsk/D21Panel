<?php

namespace App\Models;

use App\Enums\CompanyMemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMember extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'invited_by',
        'accepted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyMemberRole::class,
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Accepted and not revoked.
     *
     * @param  Builder<CompanyMember>  $query
     * @return Builder<CompanyMember>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('accepted_at')->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->accepted_at !== null && $this->revoked_at === null;
    }
}
