<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySlotPurchase extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'user_id',
        'slots',
        'price_sats',
        'btcpay_invoice_id',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'slots' => 'integer',
            'price_sats' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
