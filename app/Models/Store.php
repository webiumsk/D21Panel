<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $blink_alert_snoozed_until
 * @property Carbon|null $blink_alert_dismissed_at
 */
class Store extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'name',
        'default_currency',
        'timezone',
        'preferred_exchange',
        'wallet_type',
        'cashu_fallback_enabled',
        'cashu_fallback_address',
        'blink_alert_snoozed_until',
        'blink_alert_dismissed_at',
        'metadata',
        'auto_report_enabled',
        'auto_report_email',
        'auto_report_format',
        'webhook_secret',
        'btcpay_webhook_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * btcpay_store_id is exposed only explicitly (Pay Button - see CLAUDE.md),
     * never via toArray()/toJson().
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'webhook_secret',
        'btcpay_store_id',
        'btcpay_webhook_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'auto_report_enabled' => 'boolean',
            'cashu_fallback_enabled' => 'boolean',
            'blink_alert_snoozed_until' => 'datetime',
            'blink_alert_dismissed_at' => 'datetime',
            'webhook_secret' => 'encrypted', // Encrypt HMAC secret at rest (like User.btcpay_api_key)
        ];
    }

    /**
     * Get the user that owns the store.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the checklist items for the store.
     *
     * @return HasMany<StoreChecklist, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(StoreChecklist::class);
    }

    /**
     * Settlement ledger rows (synced from BTCPay invoice payments).
     *
     * @return HasMany<StoreSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(StoreSettlement::class);
    }

    /**
     * Get the exports for the store.
     */
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    /**
     * Get the apps for the store.
     */
    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    /**
     * Get the wallet connection for the store.
     *
     * @return HasOne<WalletConnection, $this>
     */
    public function walletConnection(): HasOne
    {
        return $this->hasOne(WalletConnection::class);
    }

    /**
     * Get the API keys for the store.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(StoreApiKey::class);
    }

    /**
     * Get the PoS terminals for the store.
     */
    public function posTerminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class, 'store_id');
    }

    /**
     * Get PoS orders for the store.
     */
    public function posOrders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'store_id');
    }

    /**
     * @return HasMany<StoreEmailRule, $this>
     */
    public function emailRules(): HasMany
    {
        return $this->hasMany(StoreEmailRule::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * Scope a query to only include stores for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
