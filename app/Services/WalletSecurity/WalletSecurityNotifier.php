<?php

namespace App\Services\WalletSecurity;

use App\Models\Store;
use App\Models\User;
use App\Models\UserMessage;
use App\Models\WalletConnection;
use App\Notifications\WalletConfigDriftNotification;
use App\Notifications\WalletPayeeMismatchNotification;
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

    /** @param array{changed: string[], added: string[], removed: string[], details?: array<string, array{expected: string|null, actual: string|null}>} $diff */
    public function driftDetected(Store $store, WalletConnection $connection, array $diff): void
    {
        $summary = $this->diffSummary($diff);
        $this->merchantMessage(
            $store,
            'Wallet configuration changed outside Satflux - '.$store->name,
            'The payment configuration of "'.$store->name.'" on the payment server no longer matches the wallet you connected. '
            .self::describeForMerchant($connection, $diff)
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
            'Store "'.$store->name.'" (owner '.($merchant instanceof User && $merchant->email ? $merchant->email : 'unknown').'): '
            .self::describeForMerchant($connection, $diff).' Technical diff: '.$summary,
            16711680,
        );
    }

    /** @param array{pubkey: string, invoice_id: string|null, method: string|null, expected: list<string>, seen_at: string} $details */
    public function payeeMismatch(Store $store, WalletConnection $connection, array $details): void
    {
        $short = fn (string $k) => substr($k, 0, 10).'…'.substr($k, -6);
        $what = 'A Lightning payment'.($details['invoice_id'] ? ' on invoice '.$details['invoice_id'] : '')
            .' was received by node '.$short($details['pubkey'])
            .', not by the node your wallet uses ('.implode(', ', array_map($short, $details['expected'])).').';
        $this->merchantMessage(
            $store,
            'Payment received by an unknown wallet - '.$store->name,
            $what.' Money paid to "'.$store->name.'" may be going to someone else. Check your wallet balance, reconnect your wallet and contact support immediately.',
        );

        $merchant = $store->user;
        if ($merchant instanceof User && $merchant->email) {
            try {
                $merchant->notify(new WalletPayeeMismatchNotification($store, $connection, $details));
            } catch (\Throwable $e) {
                Log::error('Failed to send payee mismatch e-mail', ['store_id' => $store->id, 'error' => $e->getMessage()]);
            }
        }

        $this->adminAlert(
            'Payee mismatch: '.$store->name,
            'Store "'.$store->name.'" (owner '.($merchant instanceof User && $merchant->email ? $merchant->email : 'unknown').'): '.$what
            .' Full node id: '.$details['pubkey'],
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

    /**
     * Merchant-facing sentence(s): "Your Lightning address changed from A to B."
     * for address-based wallets, otherwise which connection string / method
     * changed with masked values. The expected side falls back to the secret
     * Satflux holds when the baseline predates config snapshots.
     *
     * @param  array{changed: string[], added: string[], removed: string[], details?: array<string, array{expected: string|null, actual: string|null}>}  $diff
     */
    public static function describeForMerchant(WalletConnection $connection, array $diff): string
    {
        $sentences = [];
        $details = $diff['details'] ?? [];
        $storedMasked = $connection->masked_secret ?: null;
        $storedAddress = $storedMasked ? self::lightningAddressIn($storedMasked) : null;

        foreach ($diff['changed'] as $method) {
            $expected = $details[$method]['expected'] ?? null;
            $actual = $details[$method]['actual'] ?? null;
            $isLightning = strtoupper($method) === 'BTC-LN' || str_ends_with(strtoupper($method), '-LN');
            $from = self::lightningAddressIn($expected) ?? ($isLightning ? $storedAddress : null);
            $to = self::lightningAddressIn($actual);
            if ($to !== null && $from !== null && $to !== $from) {
                $sentences[] = 'Your Lightning address was changed from '.$from.' to '.$to.'.';
            } elseif ($to !== null && $from === null) {
                $sentences[] = 'The Lightning address on the payment server is now '.$to.($storedMasked ? ' instead of your connection '.$storedMasked : '').'.';
            } elseif ($actual !== null && str_starts_with($actual, 'disabled')) {
                $sentences[] = 'Payment method '.$method.' was disabled.';
            } else {
                $exp = $expected ?? ($isLightning ? $storedMasked : null);
                $sentences[] = 'The connection string of '.$method.' was changed'
                    .($exp !== null ? ' (expected '.$exp : '')
                    .($actual !== null ? ($exp !== null ? ', found ' : ' (found ').$actual : '')
                    .(($exp !== null || $actual !== null) ? ')' : '').'.';
            }
        }
        foreach ($diff['added'] as $method) {
            $actual = $details[$method]['actual'] ?? null;
            $sentences[] = 'Payment method '.$method.' was added'.($actual !== null ? ' ('.$actual.')' : '').'.';
        }
        foreach ($diff['removed'] as $method) {
            $sentences[] = 'Payment method '.$method.' was removed.';
        }

        return $sentences === [] ? 'Details: '.self::diffSummary($diff) : implode(' ', $sentences);
    }

    /** user@domain out of a (masked) connection-string summary or bare address, or null. */
    public static function lightningAddressIn(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        if (preg_match('/(?:ln-address|lnaddress|username)=([^;\s\]]+@[^;\s\]]+)/i', $text, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/(?:^|[\s\[=])([A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})(?:$|[\s\];])/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
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
