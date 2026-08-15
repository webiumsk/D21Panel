<?php

namespace App\Services\Boltz;

use App\Models\Store;
use App\Models\StoreSettlement;
use App\Models\User;
use App\Services\BtcPay\InvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Syncs actual payments of BTCPay invoices into the local store_settlements ledger.
 *
 * Source of truth: Greenfield invoice payment-methods (per-payment id, value, status, date).
 * For Lightning payments on aqua_boltz stores the L-BTC net settlement is an ESTIMATE from
 * the public Boltz pair snapshot; when that snapshot is unavailable the net side stays
 * unknown rather than being fabricated. Upserts are idempotent on the payment identity
 * (store, invoice, method, payment id) - re-syncing the same invoice never duplicates rows.
 */
class SettlementLedgerService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected BoltzBackendClient $backendClient,
    ) {}

    /**
     * Sync all payments of one invoice into the ledger.
     *
     * @return int number of ledger rows created or updated
     */
    public function syncInvoice(Store $store, string $invoiceId, bool $forgetCache = false): int
    {
        $owner = $store->user;
        $userApiKey = $owner instanceof User ? $owner->btcpay_api_key : null;
        if (! filled($userApiKey)) {
            Log::info('Settlement sync skipped: store owner has no BTCPay API key', [
                'store_id' => $store->id,
            ]);

            return 0;
        }

        $btcpayStoreId = (string) $store->btcpay_store_id;

        if ($forgetCache) {
            $this->invoiceService->forgetInvoiceCache($btcpayStoreId, $invoiceId, $userApiKey);
        }

        $invoice = $this->invoiceService->getInvoice($btcpayStoreId, $invoiceId, $userApiKey);
        $methods = $this->invoiceService->getInvoicePaymentMethods($btcpayStoreId, $invoiceId, $userApiKey);

        $count = 0;
        foreach ($methods as $method) {
            if (! is_array($method)) {
                continue;
            }
            $count += $this->syncPaymentMethod($store, $invoiceId, $invoice, $method);
        }

        return $count;
    }

    /**
     * Backfill: sync recent settled invoices of the store.
     *
     * @return array{invoices: int, rows: int}
     */
    public function syncRecent(Store $store, int $limit = 50): array
    {
        $owner = $store->user;
        $userApiKey = $owner instanceof User ? $owner->btcpay_api_key : null;
        if (! filled($userApiKey)) {
            return ['invoices' => 0, 'rows' => 0];
        }

        $result = $this->invoiceService->listInvoices(
            (string) $store->btcpay_store_id,
            ['status' => ['Settled']],
            0,
            $limit,
            $userApiKey
        );
        $invoices = is_array($result['data'] ?? null) ? $result['data'] : $result;

        $rows = 0;
        $synced = 0;
        foreach ($invoices as $invoice) {
            $invoiceId = (string) ($invoice['id'] ?? '');
            if ($invoiceId === '') {
                continue;
            }
            try {
                $rows += $this->syncInvoice($store, $invoiceId);
                $synced++;
            } catch (\Throwable $e) {
                Log::warning('Settlement sync failed for invoice', [
                    'store_id' => $store->id,
                    'invoice_id' => $invoiceId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return ['invoices' => $synced, 'rows' => $rows];
    }

    protected function syncPaymentMethod(Store $store, string $invoiceId, array $invoice, array $method): int
    {
        $methodId = (string) ($method['paymentMethodId'] ?? $method['paymentMethod'] ?? '');
        $payments = $method['payments'] ?? [];
        if ($methodId === '' || ! is_array($payments) || $payments === []) {
            return 0;
        }

        $category = $this->categorize($store, $methodId);
        $rate = isset($method['rate']) && is_numeric($method['rate']) ? (string) $method['rate'] : null;

        $count = 0;
        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }
            $paymentId = (string) ($payment['id'] ?? '');
            $value = $payment['value'] ?? null;
            if ($paymentId === '' || ! is_numeric($value)) {
                continue;
            }

            $grossSats = InvoiceService::btcAmountToSats((float) $value);
            if ($grossSats <= 0) {
                continue;
            }

            $estimate = $this->estimateNetSettlement($category, $grossSats);

            StoreSettlement::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'btcpay_invoice_id' => $invoiceId,
                    'payment_method_id' => $methodId,
                    'payment_id' => $paymentId,
                ],
                [
                    'category' => $category,
                    'destination' => isset($payment['destination']) ? (string) $payment['destination'] : null,
                    'payment_status' => isset($payment['status']) ? (string) $payment['status'] : null,
                    'paid_at' => isset($payment['receivedDate']) ? Carbon::parse($payment['receivedDate']) : null,
                    'gross_sats' => $grossSats,
                    'invoice_currency' => isset($invoice['currency']) ? strtoupper((string) $invoice['currency']) : null,
                    'invoice_amount' => isset($invoice['amount']) && is_numeric($invoice['amount']) ? (string) $invoice['amount'] : null,
                    'rate' => $rate,
                    ...$estimate,
                    'synced_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * lightning_boltz: LN payment on a store settling via the Boltz plugin (aqua_boltz).
     */
    protected function categorize(Store $store, string $methodId): string
    {
        $isLightning = in_array($methodId, ['BTC-LN', 'BTC-LNURL'], true);
        if ($isLightning) {
            return $store->wallet_type === 'aqua_boltz' ? 'lightning_boltz' : 'lightning';
        }
        if ($methodId === 'BTC-CHAIN') {
            return 'onchain';
        }

        return 'other';
    }

    /**
     * Estimate the net settlement for one payment.
     *
     * - lightning_boltz: gross - service fee (pair percentage) - claim miner fee, from the
     *   public Boltz pair snapshot; quality "estimated" with the snapshot kept in estimate_basis.
     *   When the snapshot is unavailable, everything stays null/unknown - never fabricated.
     * - onchain: the merchant wallet receives the paid amount itself; net == gross ("derived").
     * - other: unknown.
     *
     * @return array<string, mixed>
     */
    protected function estimateNetSettlement(string $category, int $grossSats): array
    {
        if ($category === 'onchain') {
            return [
                'settlement_asset' => 'BTC',
                'estimated_service_fee_sats' => null,
                'estimated_network_fee_sats' => null,
                'estimated_net_settlement_sats' => $grossSats,
                'estimate_basis' => null,
                'net_quality' => StoreSettlement::NET_QUALITY_DERIVED,
            ];
        }

        if ($category !== 'lightning_boltz') {
            return [
                'settlement_asset' => null,
                'estimated_service_fee_sats' => null,
                'estimated_network_fee_sats' => null,
                'estimated_net_settlement_sats' => null,
                'estimate_basis' => null,
                'net_quality' => StoreSettlement::NET_QUALITY_UNKNOWN,
            ];
        }

        $pair = $this->backendClient->getReversePairBtcToLbtc();
        if ($pair === null) {
            return [
                'settlement_asset' => 'LBTC',
                'estimated_service_fee_sats' => null,
                'estimated_network_fee_sats' => null,
                'estimated_net_settlement_sats' => null,
                'estimate_basis' => null,
                'net_quality' => StoreSettlement::NET_QUALITY_UNKNOWN,
            ];
        }

        $serviceFee = (int) ceil($grossSats * $pair['fee_percentage'] / 100);
        // The claim fee is what lands the swap output in the merchant wallet; lockup is paid by Boltz.
        $networkFee = isset($pair['miner_fees']['claim']) ? (int) $pair['miner_fees']['claim'] : 0;

        return [
            'settlement_asset' => 'LBTC',
            'estimated_service_fee_sats' => $serviceFee,
            'estimated_network_fee_sats' => $networkFee,
            'estimated_net_settlement_sats' => max(0, $grossSats - $serviceFee - $networkFee),
            'estimate_basis' => [
                'source' => BoltzBackendClient::SOURCE,
                'fee_percentage' => $pair['fee_percentage'],
                'miner_fees' => $pair['miner_fees'],
                'pair_fetched_at' => $pair['fetched_at'],
            ],
            'net_quality' => StoreSettlement::NET_QUALITY_ESTIMATED,
        ];
    }
}
