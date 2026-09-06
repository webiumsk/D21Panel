<?php

namespace App\Services\WalletSecurity;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\BtcPay\InvoiceService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 of wallet protection: verify WHO gets paid, independently of what
 * BTCPay says its configuration is.
 *
 * Every Lightning invoice is signed by the node that will receive the funds.
 * When Satflux connects a wallet it creates a tiny canary invoice, recovers
 * the signing node id from it and stores it as the allow-list (falling back
 * to the first settled payment when the canary cannot be read). From then on
 * every settled Lightning payment is decoded and its payee must be on the
 * list; anything else raises a security incident. A BTCPay that lies about
 * its config still has to hand out real invoices, so this check holds as
 * long as the invoice data is real.
 */
class PayeeAttestationService
{
    public const CANARY_AMOUNT_BTC = '0.00000100';

    public const LIGHTNING_METHODS = ['BTC-LN', 'BTC-LNURL'];

    public function __construct(
        protected InvoiceService $invoices,
        protected WalletSecurityNotifier $notifier,
    ) {}

    /**
     * Learn the payee node from a canary invoice (never paid, archived right
     * after reading). Best-effort: returns false when the invoice cannot be
     * created or carries no Lightning destination (lazy payment methods).
     */
    public function learn(WalletConnection $connection, ?User $by = null, string $reason = 'connected'): bool
    {
        $store = $connection->store;
        $owner = $store instanceof Store ? $store->user : null;
        if (! $store instanceof Store || ! $owner instanceof User || ! filled($owner->btcpay_api_key)) {
            return false;
        }
        $apiKey = (string) $owner->btcpay_api_key;
        $btcpayStoreId = (string) $store->btcpay_store_id;

        $invoiceId = null;
        try {
            $invoice = $this->invoices->createInvoice($btcpayStoreId, [
                'amount' => self::CANARY_AMOUNT_BTC,
                'currency' => 'BTC',
                'metadata' => ['satflux_canary' => true, 'itemDesc' => 'Satflux wallet check (not payable)'],
                'checkout' => ['expirationMinutes' => 1, 'paymentMethods' => self::LIGHTNING_METHODS],
            ], $apiKey);
            $invoiceId = isset($invoice['id']) ? (string) $invoice['id'] : null;
            if ($invoiceId === null || $invoiceId === '') {
                throw new \RuntimeException('Canary invoice has no id');
            }
            $this->invoices->forgetInvoiceCache($btcpayStoreId, $invoiceId, $apiKey);
            $methods = $this->invoices->getInvoicePaymentMethods($btcpayStoreId, $invoiceId, $apiKey);
        } catch (\Throwable $e) {
            Log::warning('Payee canary skipped', [
                'connection_id' => $connection->id,
                'store_id' => $store->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
            $this->archiveQuietly($btcpayStoreId, $invoiceId, $apiKey);

            return false;
        }

        $payee = null;
        foreach (self::lightningDestinations($methods, requirePayments: false) as $bolt11) {
            $payee = Bolt11::payee($bolt11);
            if ($payee !== null) {
                break;
            }
        }
        $this->archiveQuietly($btcpayStoreId, $invoiceId, $apiKey);

        if ($payee === null) {
            Log::info('Payee canary produced no Lightning invoice', [
                'connection_id' => $connection->id,
                'store_id' => $store->id,
            ]);

            return false;
        }

        $this->setAllowlist($connection, [$payee], 'canary', $by, $reason);

        return true;
    }

    /**
     * Check every settled Lightning payment of an invoice (called from the
     * settlement ledger sync with the payment methods it already fetched).
     *
     * @param  list<mixed>  $methods  Greenfield invoice payment-methods payload
     * @return array<string, string> bolt11 => outcome
     */
    public function attestInvoice(Store $store, string $invoiceId, array $methods): array
    {
        $connection = $store->walletConnection;
        if (! $connection instanceof WalletConnection || $connection->status !== 'connected') {
            return [];
        }

        $results = [];
        foreach (self::lightningDestinations($methods, requirePayments: true) as $method => $bolt11) {
            $results[$bolt11] = $this->attestBolt11($connection, $bolt11, [
                'invoice_id' => $invoiceId,
                'method' => preg_replace('/#\d+$/', '', (string) $method),
            ]);
        }

        return $results;
    }

    /**
     * @param  array{invoice_id?: string|null, method?: string|null}  $context
     * @return 'ok'|'learned'|'mismatch'|'unparsed'|'skipped'
     */
    public function attestBolt11(WalletConnection $connection, string $bolt11, array $context = []): string
    {
        if ($connection->status !== 'connected') {
            return 'skipped';
        }
        $payee = Bolt11::payee($bolt11);
        if ($payee === null) {
            Log::warning('Payee attestation: invoice could not be decoded', [
                'connection_id' => $connection->id,
                'invoice_id' => $context['invoice_id'] ?? null,
            ]);

            return 'unparsed';
        }

        $allowed = $connection->payee_pubkeys ?? [];
        if ($allowed === []) {
            // Trust on first use - the canary could not be read at baseline time.
            $this->setAllowlist($connection, [$payee], 'first_payment', null, 'first_payment', $context);

            return 'learned';
        }
        if (in_array($payee, $allowed, true)) {
            return 'ok';
        }

        $store = $connection->store;
        $details = [
            'pubkey' => $payee,
            'invoice_id' => $context['invoice_id'] ?? null,
            'method' => $context['method'] ?? null,
            'expected' => $allowed,
            'seen_at' => now()->toIso8601String(),
        ];
        $connection->payee_mismatch_details = $details;
        $connection->save();

        // Only the first observer of a mismatch raises the incident.
        $first = WalletConnection::query()
            ->whereKey($connection->id)
            ->whereNull('payee_mismatch_at')
            ->update(['payee_mismatch_at' => now()]) === 1;
        $connection->refresh();

        if ($first && $store instanceof Store) {
            AuditLog::log('wallet_connection.payee_mismatch', 'wallet_connection', $connection->id, [
                'store_id' => $store->id,
                ...$details,
            ], null);
            Log::error('Payee attestation mismatch', ['connection_id' => $connection->id, ...$details]);
            $this->notifier->payeeMismatch($store, $connection, $details);
        }

        return 'mismatch';
    }

    /** Admin accepts a node after investigating: add it to the allow-list and close the incident. */
    public function accept(WalletConnection $connection, string $pubkey, User $admin): void
    {
        $pubkey = strtolower(trim($pubkey));
        if (preg_match('/^0[23][0-9a-f]{64}$/', $pubkey) !== 1) {
            throw new \InvalidArgumentException('Not a compressed secp256k1 public key');
        }
        /** @var list<string> $list */
        $list = array_unique([...($connection->payee_pubkeys ?? []), $pubkey]);
        $connection->forceFill([
            'payee_pubkeys' => $list,
            'payee_learned_at' => $connection->payee_learned_at ?? now(),
            'payee_learn_source' => $connection->payee_learn_source ?? 'admin',
            'payee_mismatch_at' => null,
            'payee_mismatch_details' => null,
        ])->save();

        AuditLog::log('wallet_connection.payee_accepted', 'wallet_connection', $connection->id, [
            'store_id' => $connection->store_id,
            'pubkey' => $pubkey,
            'allowed' => $list,
        ], $admin->id);
    }

    /**
     * @param  list<string>  $pubkeys
     * @param  array<string, mixed>  $context
     */
    protected function setAllowlist(WalletConnection $connection, array $pubkeys, string $source, ?User $by, string $reason, array $context = []): void
    {
        $connection->forceFill([
            'payee_pubkeys' => $pubkeys,
            'payee_learn_source' => $source,
            'payee_learned_at' => now(),
            'payee_mismatch_at' => null,
            'payee_mismatch_details' => null,
        ])->save();

        AuditLog::log('wallet_connection.payee_learned', 'wallet_connection', $connection->id, [
            'store_id' => $connection->store_id,
            'source' => $source,
            'reason' => $reason,
            'pubkeys' => $pubkeys,
            ...array_filter($context, fn ($v) => $v !== null),
        ], $by?->id);
    }

    /**
     * BOLT11 strings of the Lightning payment methods of an invoice payload:
     * the method-level destination plus every payment's own destination.
     *
     * @param  list<mixed>  $methods
     * @return array<string, string> "METHOD" or "METHOD#i" => bolt11
     */
    public static function lightningDestinations(array $methods, bool $requirePayments): array
    {
        $out = [];
        foreach ($methods as $method) {
            if (! is_array($method)) {
                continue;
            }
            $id = strtoupper((string) ($method['paymentMethodId'] ?? $method['paymentMethod'] ?? ''));
            if (! in_array($id, self::LIGHTNING_METHODS, true)) {
                continue;
            }
            $payments = is_array($method['payments'] ?? null) ? $method['payments'] : [];
            if ($requirePayments && $payments === []) {
                continue;
            }
            $destination = $method['destination'] ?? null;
            if (is_string($destination) && str_starts_with(strtolower($destination), 'ln')) {
                $out[$id] = $destination;
            }
            foreach ($payments as $i => $payment) {
                $d = is_array($payment) ? ($payment['destination'] ?? null) : null;
                if (is_string($d) && str_starts_with(strtolower($d), 'ln') && ! in_array($d, $out, true)) {
                    $out[$id.'#'.$i] = $d;
                }
            }
        }

        return $out;
    }

    private function archiveQuietly(string $btcpayStoreId, ?string $invoiceId, string $apiKey): void
    {
        if ($invoiceId === null || $invoiceId === '') {
            return;
        }
        try {
            $this->invoices->archiveInvoice($btcpayStoreId, $invoiceId, $apiKey);
        } catch (\Throwable $e) {
            Log::info('Payee canary invoice could not be archived', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
        }
    }
}
