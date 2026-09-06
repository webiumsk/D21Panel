<?php

namespace App\Services\WalletSecurity;

use App\Models\Store;
use App\Models\User;
use App\Models\UserMessage;
use App\Models\WalletConnection;
use App\Notifications\WalletConfigDriftNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Security-grade wallet events for merchants and admins: in-app "security"
 * messages (pinned above the BTCPay notification feed), e-mail for drift, and
 * the support Discord webhook. Every call is best-effort - a notification
 * failure must never break the wallet operation that triggered it.
 */
class WalletSecurityNotifier
{
    public const TYPE = 'security';

    public function walletReplaced(Store $store, WalletConnection $connection, ?User $actor): void
    {
        $this->merchantMessage(
            $store,
            'Wallet connection replaced - '.$store->name,
            'The receiving wallet of "'.$store->name.'" was replaced'
            .($actor ? ' by '.($actor->email ?: 'user #'.$actor->id) : '')
            .' ('.$this->typeLabel($connection).', '.($connection->masked_secret ?: '******').').'
            .' If this was not you, reconnect your wallet now and contact support.',
        );
    }

    public function secretRevealed(Store $store, WalletConnection $connection, ?User $actor, string $context): void
    {
        $who = $actor && $actor->id !== $store->user_id
            ? 'Satflux support ('.($actor->email ?: 'user #'.$actor->id).')'
            : 'your account';
        $this->merchantMessage(
            $store,
            'Wallet secret revealed - '.$store->name,
            'The wallet connection secret of "'.$store->name.'" was revealed by '.$who
            .' ('.$context.'). If this was not you, rotate the credential in your wallet app and reconnect.',
        );
    }

    /** @param array{changed: string[], added: string[], removed: string[]} $diff */
    public function driftDetected(Store $store, WalletConnection $connection, array $diff): void
    {
        $summary = $this->diffSummary($diff);
        $this->merchantMessage(
            $store,
            'Wallet configuration changed outside Satflux - '.$store->name,
            'The payment configuration of "'.$store->name.'" on the payment server no longer matches the wallet you connected ('.$summary.').'
            .' Payments may be routed elsewhere. Reconnect your wallet immediately and contact support.',
        );

        $merchant = $store->user;
        if ($merchant instanceof User && $merchant->email) {
            try {
                $merchant->notify(new WalletConfigDriftNotification($store, $connection, $diff));
            } catch (\Throwable $e) {
                Log::error('Failed to send wallet drift e-mail', ['store_id' => $store->id, 'error' => $e->getMessage()]);
            }
        }

        $this->adminAlert(
            'Wallet config drift: '.$store->name,
            'Store "'.$store->name.'" (owner '.($merchant instanceof User && $merchant->email ? $merchant->email : 'unknown').'): '.$summary
            .'. Config on BTCPay differs from the wallet Satflux connected.',
            16711680,
        );
    }

    public function driftResolved(Store $store, WalletConnection $connection): void
    {
        $this->merchantMessage(
            $store,
            'Wallet configuration restored - '.$store->name,
            'The payment configuration of "'.$store->name.'" matches your connected wallet again.',
            'info',
        );
        $this->adminAlert('Wallet config drift resolved: '.$store->name, 'Configuration matches the baseline again.', 3066993);
    }

    private function merchantMessage(Store $store, string $title, string $body, string $type = self::TYPE): void
    {
        $merchant = $store->user;
        if (! $merchant instanceof User) {
            return;
        }
        try {
            UserMessage::createForUser(
                $merchant->id,
                $title,
                $body,
                $type,
                rtrim((string) config('app.url'), '/').'/stores/'.$store->id.'/wallet-connection',
                'Wallet connection',
            );
        } catch (\Throwable $e) {
            Log::error('Failed to create wallet security message', ['store_id' => $store->id, 'error' => $e->getMessage()]);
        }
    }

    /** In-app security message for every admin plus the support Discord webhook. */
    public function adminAlert(string $title, string $body, int $color): void
    {
        $link = rtrim((string) config('app.url'), '/').'/admin/wallet-changes';
        try {
            User::query()->where('role', 'admin')->each(function (User $admin) use ($title, $body, $link) {
                UserMessage::createForUser($admin->id, $title, $body, self::TYPE, $link, 'Wallet change log');
            });
        } catch (\Throwable $e) {
            Log::error('Failed to create admin wallet security message', ['error' => $e->getMessage()]);
        }

        $webhookUrl = config('services.discord.support_webhook_url');
        if (! $webhookUrl) {
            return;
        }
        try {
            Http::timeout(10)->post($webhookUrl, [
                'content' => '🚨 **'.$title.'**',
                'embeds' => [[
                    'title' => $title,
                    'description' => $body,
                    'url' => $link,
                    'color' => $color,
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to post wallet security alert to Discord', ['error' => $e->getMessage()]);
        }
    }

    /** @param array{changed: string[], added: string[], removed: string[], details?: array<string, array{expected: string|null, actual: string|null}>} $diff */
    public static function diffSummary(array $diff): string
    {
        $parts = [];
        if ($diff['changed'] !== []) {
            $parts[] = 'changed: '.implode(', ', $diff['changed']);
        }
        if ($diff['added'] !== []) {
            $parts[] = 'added: '.implode(', ', $diff['added']);
        }
        if ($diff['removed'] !== []) {
            $parts[] = 'removed: '.implode(', ', $diff['removed']);
        }
        foreach ($diff['details'] ?? [] as $method => $detail) {
            $parts[] = $method.' expected ['.($detail['expected'] ?? '-').'] found ['.($detail['actual'] ?? '-').']';
        }

        return $parts === [] ? 'no differences' : implode('; ', $parts);
    }

    private function typeLabel(WalletConnection $connection): string
    {
        return match ($connection->type) {
            'blink' => 'Blink',
            'blitz' => 'Blitz Wallet',
            'flash' => 'Flash Wallet',
            'lnaddress' => 'Lightning address',
            'nwc' => 'NWC',
            default => 'Aqua/Bull (Boltz)',
        };
    }
}
