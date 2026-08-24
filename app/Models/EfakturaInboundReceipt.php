<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EfakturaInboundReceipt extends Model
{
    use HasUuids;

    public const INBOX_PENDING = 'pending';

    public const INBOX_IMPORTED = 'imported';

    public const INBOX_DISMISSED = 'dismissed';

    protected $fillable = [
        'company_id',
        'external_document_id',
        'business_expense_id',
        'status',
        'inbox_status',
        'evolu_expense_id',
        'draft_json',
        'ubl_encrypted',
        'external_number',
        'supplier_name',
        'total',
        'currency',
        'inbox_resolved_at',
        'attachment_disk',
        'attachment_path',
        'acknowledged_at',
        'response_payload',
    ];

    /** The encrypted UBL and raw provider payload never leave the server by accident. */
    protected $hidden = [
        'ubl_encrypted',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'draft_json' => 'array',
            'acknowledged_at' => 'datetime',
            'inbox_resolved_at' => 'datetime',
            'total' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(BusinessExpense::class, 'business_expense_id');
    }

    public function scopeInboxPending(Builder $query): Builder
    {
        return $query->where('inbox_status', self::INBOX_PENDING);
    }

    public function isInboxPending(): bool
    {
        return $this->inbox_status === self::INBOX_PENDING;
    }
}
