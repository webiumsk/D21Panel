<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One atomic document number reservation (invoicing audit F3).
 *
 * Lifecycle: reserved -> confirmed (client persisted the issued snapshot)
 * or reserved -> voided (client abandoned the issue). Numbers are never
 * recycled; voiding leaves a gap. The unique (company, type, issue_request_id)
 * key makes reservation idempotent - a retried request returns this row.
 */
class DocumentNumberReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'company_id',
        'document_type',
        'company_document_sequence_id',
        'issue_request_id',
        'period_key',
        'counter',
        'number',
        'status',
        'confirmed_hash',
        'confirmed_format_version',
        'reserved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'counter' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(CompanyDocumentSequence::class, 'company_document_sequence_id');
    }

    /** The user who made this reservation (null for legacy / deleted users). */
    public function reserver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }
}
