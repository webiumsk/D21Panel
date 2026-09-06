<?php

namespace App\Notifications;

use App\Models\Store;
use App\Models\WalletConnection;
use App\Services\WalletSecurity\WalletSecurityNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Merchant e-mail: the wallet configuration on BTCPay no longer matches the
 * wallet connected through Satflux (WalletConfigIntegrityService drift).
 */
class WalletConfigDriftNotification extends Notification
{
    use Queueable;

    /** @param array{changed: string[], added: string[], removed: string[]} $diff */
    public function __construct(
        public Store $store,
        public WalletConnection $walletConnection,
        public array $diff,
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
            ->subject('SECURITY: wallet configuration changed outside Satflux - '.$this->store->name)
            ->line('The payment configuration of your store **'.$this->store->name.'** on the payment server no longer matches the wallet you connected in Satflux.')
            ->line('**What changed:** '.WalletSecurityNotifier::describeForMerchant($this->walletConnection, $this->diff))
            ->line('**Wallet you connected in Satflux:** `'.($this->walletConnection->masked_secret ?: '******').'`')
            ->line('Until this is resolved, payments to this store may be routed to a wallet you do not control.')
            ->line('**What to do now:** open the wallet connection page, confirm the change with the email code and reconnect your wallet. Then contact support so we can investigate how the configuration was changed.')
            ->action('Reconnect wallet', $storeUrl)
            ->line('If you did not expect this message, please contact support immediately.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'store_id' => $this->store->id,
            'wallet_connection_id' => $this->walletConnection->id,
            'diff' => $this->diff,
        ];
    }
}
