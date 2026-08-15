<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyContact extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'name',
        'registration_number',
        'peppol_participant_id',
        'email',
        'phone',
        'fax',
        'tax_id',
        'vat_id',
        'street',
        'city',
        'postal_code',
        'state_region',
        'country',
        'bank_account',
        'bank_code',
        'iban',
        'swift',
        'delivery_street',
        'delivery_postal_code',
        'delivery_city',
        'delivery_country',
        'default_payment_terms_days',
        'notes',
        'contact_persons',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'contact_persons' => 'array',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusinessDocument::class, 'company_contact_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
