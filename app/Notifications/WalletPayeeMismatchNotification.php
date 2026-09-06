<?php

namespace App\Notifications;

use App\Models\Store;
use App\Models\WalletConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Merchant e-mail: a settled Lightning payment was signed by a node that is
 * not the one the connected wallet uses (PayeeAttestationService).
 */
class WalletPayeeMismatchNotification extends Notification
{
    use Queueable;

    /** @param array{pubkey: string, invoice_id: string|null, method: string|null, expected: list<string>, seen_at: string} $details */
    public function __construct(
        public Store $store,
        public WalletConnection $walletConnection,
        public array $details,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appUrl = rtrim(config('app.url', 'http://localhost:8080'), '/');
        $storeUrl = "{$appUrl}/stores/{$this->store->id}/wallet-connection";

        return (new MailMessage)
            ->error()
            ->subject('SECURITY: payment received by an unknown wallet - '.$this->store->name)
            ->line('A Lightning payment to your store **'.$this->store->name.'** was received by a node that is not the one your connected wallet uses.')
            ->line('**Invoice:** '.($this->details['invoice_id'] ?? 'unknown'))
            ->line('**Receiving node:** `'.$this->details['pubkey'].'`')
            ->line('**Node(s) of your wallet:** `'.implode('`, `', $this->details['expected']).'`')
            ->line('Money paid to this store may be going to someone else. Check your wallet balance against the payments listed in Satflux.')
            ->line('**What to do now:** reconnect your wallet (confirm with the email code) and contact support immediately so we can investigate.')
            ->action('Wallet connection', $storeUrl)
            ->line('If you recently changed your wallet provider yourself, support can confirm the new node for you.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'store_id' => $this->store->id,
            'wallet_connection_id' => $this->walletConnection->id,
            'details' => $this->details,
        ];
    }
}
