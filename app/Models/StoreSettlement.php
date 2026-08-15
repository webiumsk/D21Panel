<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One actual payment on a BTCPay invoice, as synced from Greenfield invoice payment-methods.
 *
 * The net settlement side of Lightning-via-Boltz payments is an ESTIMATE computed from the
 * public Boltz pair fee snapshot (see net_quality + estimate_basis) - the Boltz plugin exposes
 * no swap reports via API and BTCPay's Greenfield reports endpoint is disabled upstream.
 *
 * @property Carbon|null $paid_at
 * @property array<string, mixed>|null $flags
 * @property string|null $payment_status
 * @property string|null $settlement_asset
 * @property string $category
 */
class StoreSettlement extends Model
{
    use HasFactory, HasUuids;

    public const NET_QUALITY_UNKNOWN = 'unknown';

    public const NET_QUALITY_ESTIMATED = 'estimated';

    public const NET_QUALITY_DERIVED = 'derived';

    /** Reserved for a future upstream unlock (plugin swap reports); unused today. */
    public const NET_QUALITY_REPORTED = 'reported';

    public const NET_QUALITY_FINAL = 'final';

    protected $fillable = [
        'store_id',
        'btcpay_invoice_id',
        'payment_method_id',
        'payment_id',
        'category',
        'destination',
        'payment_status',
        'paid_at',
        'gross_sats',
        'invoice_currency',
        'invoice_amount',
        'rate',
        'settlement_asset',
        'estimated_service_fee_sats',
        'estimated_network_fee_sats',
        'estimated_net_settlement_sats',
        'estimate_basis',
        'net_quality',
        'boltz_swap_id',
        'settlement_txid',
        'flags',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'synced_at' => 'datetime',
            'gross_sats' => 'integer',
            'estimated_service_fee_sats' => 'integer',
            'estimated_network_fee_sats' => 'integer',
            'estimated_net_settlement_sats' => 'integer',
            'invoice_amount' => 'decimal:8',
            'rate' => 'decimal:8',
            'estimate_basis' => 'array',
            'flags' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
