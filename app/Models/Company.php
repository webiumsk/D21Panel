<?php

namespace App\Models;

use App\Enums\CompanyJurisdiction;
use App\Services\Invoicing\CompanyEmailSettingsService;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyEfakturaSettings;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $hidden = [
        'app_settings',
        'email_settings',
    ];

    protected $fillable = [
        'user_id',
        'legal_name',
        'trade_name',
        'registration_number',
        'tax_id',
        'vat_number',
        'commercial_register',
        'register_court',
        'register_number',
        'managing_directors',
        'supervisory_board_chair',
        'street',
        'city',
        'postal_code',
        'country',
        'state_region',
        'iban',
        'bic',
        'bank_name',
        'bank_account',
        'bank_code',
        'default_currency',
        'jurisdiction',
        'vat_payer',
        'vat_status',
        'vat_rate_default',
        'legal_footer_note',
        'issuer_name',
        'issuer_phone',
        'issuer_email',
        'website',
        'invoice_number_prefix',
        'bank_inbound_token',
        'app_settings',
        'email_settings',
        'logo_path',
        'signature_stamp_path',
    ];

    protected function casts(): array
    {
        return [
            'vat_payer' => 'boolean',
            'vat_rate_default' => 'decimal:2',
            'jurisdiction' => CompanyJurisdiction::class,
            'app_settings' => 'array',
            'email_settings' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedAppSettings(): array
    {
        $settings = CompanyAppSettings::from($this->app_settings)->toArray();
        $stripeTaxSecretSet = is_string($settings['stripe_tax_secret_key'] ?? null)
            && trim((string) $settings['stripe_tax_secret_key']) !== '';

        unset($settings['stripe_tax_secret_key']);
        unset($settings['efaktura_sapi_client_secret_encrypted']);

        return array_merge(
            $settings,
            ['stripe_tax_secret_key_set' => $stripeTaxSecretSet],
            CompanyEfakturaSettings::fromCompany($this)->publicPayload(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedEmailSettings(): array
    {
        return app(CompanyEmailSettingsService::class)
            ->publicPayload($this);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyContact::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(CompanyStockItem::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(CompanyWarehouse::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusinessDocument::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(BusinessExpense::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function bankImportBatches(): HasMany
    {
        return $this->hasMany(BankImportBatch::class);
    }

    public function documentSequences(): HasMany
    {
        return $this->hasMany(CompanyDocumentSequence::class);
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }

    public function usesServerInvoicing(): bool
    {
        if (CompanyAppSettings::from($this->app_settings)->bool('local_first')) {
            return false;
        }

        return ! config('invoicing.local_first', false);
    }

    public function hasBankAccount(): bool
    {
        if (trim((string) ($this->iban ?? '')) !== '') {
            return true;
        }

        return trim((string) ($this->bank_account ?? '')) !== '';
    }

    public function maskedBankAccountLabel(): ?string
    {
        $bankName = trim((string) ($this->bank_name ?? ''));
        $digits = preg_replace('/\D/', '', (string) (($this->iban ?: $this->bank_account) ?: ''));

        if ($bankName === '' && $digits === '') {
            return null;
        }

        $suffix = strlen($digits) >= 4 ? ' ****'.substr($digits, -4) : '';

        if ($bankName !== '') {
            return $bankName.$suffix;
        }

        return $suffix !== '' ? ltrim($suffix) : null;
    }
}
