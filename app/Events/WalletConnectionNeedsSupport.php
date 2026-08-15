<?php

namespace App\Events;

use App\Models\Store;
use App\Models\WalletConnection;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a wallet connection needs support (new or merchant re-submitted).
 * Broadcasts immediately (ShouldBroadcastNow) so support/admin get an instant in-app toast.
 * Mail is still sent via SupportNeededNotification; this event is for real-time only.
 */
class WalletConnectionNeedsSupport implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WalletConnection $walletConnection,
        public Store $store
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.wallet-connections'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'wallet-connection.needs-support';
    }

    /**
     * Data to broadcast (same shape as SupportNeededNotification for frontend).
     */
    public function broadcastWith(): array
    {
        $appUrl = config('app.url');
        $storeName = $this->store->name;
        $type = $this->walletConnection->type === 'blink' ? 'Blink' : 'Aqua/Bull (Boltz)';

        return [
            'message' => "New wallet connection needs support: {$storeName} ({$type})",
            'store_name' => $storeName,
            'store_id' => $this->store->id,
            'wallet_connection_id' => $this->walletConnection->id,
            'type' => $type,
            'url' => "{$appUrl}/support/wallet-connections",
        ];
    }
}
