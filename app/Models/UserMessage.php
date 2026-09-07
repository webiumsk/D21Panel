<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class UserMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'wallet_connection_id',
        'title',
        'body',
        'link',
        'link_text',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Create a message for a user. Use from webhooks, jobs, or controllers.
     *
     * @param  string  $type  info|success|warning|invoice|subscription|support
     * @param  string|null  $link  Optional URL
     * @param  string|null  $linkText  Optional link label
     * @param  string|null  $walletConnectionId  Wallet connection a security message is about
     */
    public static function createForUser(
        int $userId,
        string $title,
        ?string $body = null,
        string $type = 'info',
        ?string $link = null,
        ?string $linkText = null,
        ?string $walletConnectionId = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'type' => $type,
            'wallet_connection_id' => $walletConnectionId,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'link_text' => $linkText,
        ]);
    }
}
