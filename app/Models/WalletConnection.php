<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class WalletConnection extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'store_id',
        'type',
        'configuration_source',
        'encrypted_secret',
        'status',
        'reconfig',
        'bot_failure_message',
        'bot_failed_at',
        'secret_updated_at',
        'submitted_by_user_id',
        'revealed_last_at',
        'revealed_last_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revealed_last_at' => 'datetime',
            'reconfig' => 'boolean',
            'bot_failed_at' => 'datetime',
            'secret_updated_at' => 'datetime',
        ];
    }

    /**
     * Get the store that owns the wallet connection.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the user who submitted the wallet connection.
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * Get the user who last revealed the secret.
     */
    public function revealedLastBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revealed_last_by');
    }

    /**
     * Connection-string keys whose values are public identifiers, kept in
     * clear so the owner can tell which wallet/address is connected.
     */
    private const MASK_CLEAR_KEYS = ['type', 'server', 'ln-address', 'lnaddress', 'username'];

    /**
     * Get the masked secret for display (owner card, support list, e-mails).
     * key=value connection strings keep their type/server/Lightning address and
     * mask only the credential values; anything else shows first 6 + last 6.
     * This is an accessor that can be accessed via $model->masked_secret.
     */
    public function getMaskedSecretAttribute(): string
    {
        if (($this->attributes['configuration_source'] ?? null) === 'samrock') {
            return 'SamRock';
        }

        $encrypted = $this->attributes['encrypted_secret'] ?? '';
        if (empty($encrypted)) {
            return '';
        }

        try {
            return self::maskSecret(Crypt::decryptString($encrypted));
        } catch (\Exception $e) {
            // If decryption fails, return masked placeholder
            return '******...******';
        }
    }

    public static function maskSecret(string $plain): string
    {
        $plain = trim($plain);

        if (preg_match('/^[^;=\s]+@[^;=\s]+$/', $plain) === 1) {
            // Bare Lightning address - public by design.
            return $plain;
        }

        // BTCPay-style connection string (type=...;key=value;...) - URIs and
        // descriptors never carry a leading type= pair.
        if (preg_match('/(?:^|;)\s*type\s*=/i', $plain) === 1) {
            $parts = [];
            foreach (array_filter(array_map('trim', explode(';', $plain))) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $key = trim($key);
                $value = trim($value);
                if (in_array(strtolower($key), self::MASK_CLEAR_KEYS, true)) {
                    $parts[] = $key.'='.$value;
                } else {
                    $parts[] = $key.'='.self::maskValue($value);
                }
            }

            return implode(';', $parts).';';
        }

        if (strlen($plain) <= 12) {
            // If secret is too short, just show stars
            return str_repeat('*', strlen($plain));
        }

        return substr($plain, 0, 6).'...'.substr($plain, -6);
    }

    /** Last four characters of a credential, the rest starred. */
    private static function maskValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return '****'.substr($value, -4);
    }

    /**
     * Reveal the plaintext secret (only for support/admin use).
     *
     * @return string Plaintext secret
     */
    public function reveal(): string
    {
        return Crypt::decryptString($this->encrypted_secret);
    }
}
