<?php

namespace App\Models;

use App\Enums\BusinessDocumentQuoteStatus;
use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Support\Invoicing\BuyerSnapshot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BusinessDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'company_contact_id',
        'buyer_snapshot',
        'store_id',
        'source_document_id',
        'type',
        'status',
        'quote_status',
        'number',
        'title',
        'variable_symbol',
        'constant_symbol',
        'specific_symbol',
        'issue_date',
        'delivery_date',
        'due_date',
        'currency',
        'subtotal',
        'tax_total',
        'discount_percent',
        'total',
        'note_above_lines',
        'note_footer',
        'internal_note',
        'pdf_locale',
        'pdf_bank_qr',
        'pdf_show_signature',
        'pdf_show_payment_info',
        'paid_at',
        'amount_paid',
        'tags',
        'btcpay_invoice_id',
        'btcpay_checkout_link',
        'payment_token',
        'btcpay_checkout_created_at',
        'payment_btc_enabled',
        'payment_bank_enabled',
        'email_sent_at',
    ];

    protected $appends = [
        'can_update',
        'can_delete',
        'can_cancel',
        'can_unmark_paid',
    ];

    protected function casts(): array
    {
        return [
            'type' => BusinessDocumentType::class,
            'status' => BusinessDocumentStatus::class,
            'quote_status' => BusinessDocumentQuoteStatus::class,
            'issue_date' => 'date',
            'delivery_date' => 'date',
            'due_date' => 'date',
            'tags' => 'array',
            'buyer_snapshot' => 'array',
            'payment_btc_enabled' => 'boolean',
            'payment_bank_enabled' => 'boolean',
            'pdf_show_signature' => 'boolean',
            'pdf_show_payment_info' => 'boolean',
            'paid_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'btcpay_checkout_created_at' => 'datetime',
            'amount_paid' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CompanyContact::class, 'company_contact_id');
    }

    /**
     * Buyer for PDF/exports: frozen snapshot when present, else live contact.
     */
    public function resolvedBuyer(): ?CompanyContact
    {
        $snapshot = $this->buyer_snapshot;
        if (is_array($snapshot) && $snapshot !== []) {
            return BuyerSnapshot::asContact($snapshot);
        }

        if ($this->relationLoaded('contact')) {
            return $this->contact;
        }

        if ($this->company_contact_id) {
            return $this->contact()->first();
        }

        return null;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_document_id');
    }

    public function finalInvoice(): HasOne
    {
        return $this->hasOne(self::class, 'source_document_id')
            ->where('type', BusinessDocumentType::Invoice)
            ->where('status', '!=', BusinessDocumentStatus::Cancelled);
    }

    public function derivedDocuments(): HasMany
    {
        return $this->hasMany(self::class, 'source_document_id')
            ->where('status', '!=', BusinessDocumentStatus::Cancelled);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BusinessDocumentLine::class)->orderBy('sort_order');
    }

    public function complianceSubmissions(): HasMany
    {
        return $this->hasMany(BusinessDocumentCompliance::class);
    }

    public function bankMatch(): HasOne
    {
        return $this->hasOne(BankTransactionMatch::class, 'business_document_id');
    }

    public function canUpdate(?Company $company = null): bool
    {
        if ($this->hasGermanGobdIssuedContentLock($company)) {
            return false;
        }

        return in_array($this->status, [
            BusinessDocumentStatus::Draft,
            BusinessDocumentStatus::Issued,
        ], true);
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [
            BusinessDocumentStatus::Issued,
            BusinessDocumentStatus::Paid,
        ], true);
    }

    public function canUnmarkPaid(): bool
    {
        return $this->status === BusinessDocumentStatus::Paid;
    }

    /**
     * Draft and cancelled documents are always deletable.
     * Issued/paid only when this is the newest document for the company and has no active derivatives.
     */
    public function canDelete(?Company $company = null): bool
    {
        if ($this->hasGermanGobdIssuedContentLock($company)) {
            return false;
        }

        if (in_array($this->status, [
            BusinessDocumentStatus::Draft,
            BusinessDocumentStatus::Cancelled,
        ], true)) {
            return ! $this->hasBlockingRelations();
        }

        if (! in_array($this->status, [
            BusinessDocumentStatus::Issued,
            BusinessDocumentStatus::Paid,
        ], true)) {
            return false;
        }

        return $this->isLatestForCompany() && ! $this->hasBlockingRelations();
    }

    protected function hasGermanGobdIssuedContentLock(?Company $company = null): bool
    {
        if ($this->status === BusinessDocumentStatus::Draft) {
            return false;
        }

        $company ??= $this->relationLoaded('company')
            ? $this->company
            : $this->company()->first(['id', 'jurisdiction']);

        return $company?->jurisdiction === CompanyJurisdiction::EuDe;
    }

    public function isLatestForCompany(): bool
    {
        $latestId = self::query()
            ->where('company_id', $this->company_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        return $latestId === $this->id;
    }

    protected function hasBlockingRelations(): bool
    {
        if ($this->relationLoaded('bankMatch')) {
            if ($this->bankMatch !== null) {
                return true;
            }
        } elseif ($this->bankMatch()->exists()) {
            return true;
        }

        if ($this->relationLoaded('derivedDocuments')) {
            return $this->derivedDocuments->isNotEmpty();
        }

        return $this->derivedDocuments()->exists();
    }

    public function canIssue(): bool
    {
        return $this->status === BusinessDocumentStatus::Draft;
    }

    public function getCanUpdateAttribute(): bool
    {
        return $this->canUpdate();
    }

    public function getCanDeleteAttribute(): bool
    {
        return $this->canDelete();
    }

    public function getCanCancelAttribute(): bool
    {
        return $this->canCancel();
    }

    public function getCanUnmarkPaidAttribute(): bool
    {
        return $this->canUnmarkPaid();
    }

    public function resolvedQuoteStatus(): ?BusinessDocumentQuoteStatus
    {
        if ($this->type !== BusinessDocumentType::Quote) {
            return null;
        }

        if ($this->quote_status === BusinessDocumentQuoteStatus::Pending
            && $this->due_date?->isPast()) {
            return BusinessDocumentQuoteStatus::Expired;
        }

        return $this->quote_status;
    }

    protected function appendResolvedQuoteStatus(array $array): array
    {
        if ($this->type === BusinessDocumentType::Quote) {
            $array['resolved_quote_status'] = $this->resolvedQuoteStatus()?->value;
        }

        return $array;
    }

    public function toArray(): array
    {
        return $this->appendResolvedQuoteStatus(parent::toArray());
    }
}
