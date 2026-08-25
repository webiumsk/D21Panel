<?php

namespace App\Models;

use App\Enums\CompanyMemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A pending invite into a shared company (docs/COMPANY_SHARING.md, "C4").
 *
 * The plaintext token is shown once at creation; only its sha256 is stored.
 * `sealed_secret_json` is an opaque ECIES blob the server never decrypts.
 *
 * @property string $id
 * @property string $company_id
 * @property CompanyMemberRole $role
 * @property string $mode
 * @property string|null $invited_email
 * @property string|null $invitee_public_key
 * @property string|null $sealed_secret_json
 * @property string $token_hash
 * @property int|null $created_by
 * @property int|null $accepted_by
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Company|null $company
 * @property-read User|null $creator
 */
class CompanyInvite extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'role',
        'mode',
        'invited_email',
        'invitee_public_key',
        'sealed_secret_json',
        'token_hash',
        'created_by',
        'accepted_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /**
     * The sealed blob is opaque here and must never leak in serialization
     * except through the deliberate recipient-facing controller path.
     */
    protected $hidden = [
        'token_hash',
        'sealed_secret_json',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyMemberRole::class,
            'expires_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Generate an opaque one-time token; returns [plaintext, sha256]. */
    public static function mintToken(): array
    {
        $token = 'cinv_'.Str::random(48);

        return [$token, hash('sha256', $token)];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * Live invites: not accepted, not revoked, not expired.
     *
     * @param  Builder<CompanyInvite>  $query
     * @return Builder<CompanyInvite>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
