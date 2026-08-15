<?php

namespace App\Services;

use App\Models\Store;
use App\Services\BtcPay\InvoiceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Invoice-based stats per store (by source) for main dashboard.
 * Cached per store; used by DashboardController::stats to merge with PosOrder data.
 */
class StoreInvoiceStatsService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected InvoiceSourceService $invoiceSourceService
    ) {}

    /**
     * Forget all store-level stats caches (for refresh button).
     * Call before recomputing to bypass cache.
     */
    public function forgetStoreCaches(Store $store): void
    {
        try {
            $apiKey = $store->user->getBtcPayApiKeyOrFail();
        } catch (\Throwable) {
            return;
        }
        $hash = md5($apiKey);
        Cache::forget("btcpay:store_total_revenue_sats:{$store->id}:{$hash}");
        Cache::forget("btcpay:store_total_revenue_by_currency:{$store->id}:{$hash}");
        Cache::forget("btcpay:store_invoice_stats:{$store->id}:{$hash}");
    }

    /**
     * Total received sats from BTCPay invoices (all settled/complete).
     * Uses actual crypto received from payment-methods for fiat-denominated invoices (EUR/USD),
     * so "celkový počet prijatých sats" reflects real on-chain/Lightning received amount.
     */
    public function getTotalRevenueSats(Store $store): int
    {
        try {
            $apiKey = $store->user->getBtcPayApiKeyOrFail();
        } catch (\Throwable) {
            return 0;
        }
        $apiKeyHash = md5($apiKey);
        $cacheKey = "btcpay:store_total_revenue_sats:{$store->id}:{$apiKeyHash}";

        return (int) Cache::remember($cacheKey, 3600, function () use ($store, $apiKey) {
            $allInvoices = $this->fetchAllInvoices($store->btcpay_store_id, $apiKey);
            $sats = 0;
            foreach ($allInvoices as $inv) {
                $status = $inv['status'] ?? null;
                if (! in_array($status, ['Settled', 'Complete'], true)) {
                    continue;
                }
                $sats += $this->getReceivedSatsForInvoice($store->btcpay_store_id, $apiKey, $inv);
            }

            return $sats;
        });
    }

    /**
     * Total paid amount from BTCPay invoices by currency.
     * 'sats' = actual received sats (from payment-methods for all invoices, so EUR/USD invoices count in sats too).
     * Other keys: eur, usd, etc. from invoice amount/currency. Cached 5 min.
     *
     * @return array<string, int|float>
     */
    public function getTotalRevenueByCurrency(Store $store): array
    {
        try {
            $apiKey = $store->user->getBtcPayApiKeyOrFail();
        } catch (\Throwable) {
            return [];
        }
        $apiKeyHash = md5($apiKey);
        $cacheKey = "btcpay:store_total_revenue_by_currency:{$store->id}:{$apiKeyHash}";

        return Cache::remember($cacheKey, 3600, function () use ($store, $apiKey) {
            $allInvoices = $this->fetchAllInvoices($store->btcpay_store_id, $apiKey);
            $byCurrency = ['sats' => 0];
            foreach ($allInvoices as $inv) {
                $status = $inv['status'] ?? null;
                if (! in_array($status, ['Settled', 'Complete'], true)) {
                    continue;
                }
                $currency = strtoupper(trim((string) ($inv['currency'] ?? '')));
                $amount = (float) ($inv['amount'] ?? 0);
                $byCurrency['sats'] += $this->getReceivedSatsForInvoice($store->btcpay_store_id, $apiKey, $inv);
                if ($currency === 'SATS' || $currency === 'BTC') {
                    // fiat breakdown not from invoice amount for SATS/BTC
                } elseif ($currency !== '') {
                    $key = strtolower($currency);
                    $byCurrency[$key] = ($byCurrency[$key] ?? 0) + $amount;
                }
            }
            $byCurrency['sats'] = (int) $byCurrency['sats'];

            return $byCurrency;
        });
    }

    /**
     * Get received sats for a single BTCPay invoice by ID (e.g. for PoS orders that have btcpay_invoice_id).
     * Cached per invoice 5 min.
     */
    public function getReceivedSatsForBtcPayInvoiceId(Store $store, string $invoiceId): int
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            return 0;
        }
        try {
            $apiKey = $store->user->getBtcPayApiKeyOrFail();
        } catch (\Throwable) {
            return 0;
        }
        $apiKeyHash = md5($apiKey);
        $cacheKey = "btcpay:invoice_sats:{$store->btcpay_store_id}:{$invoiceId}:{$apiKeyHash}";

        return (int) Cache::remember($cacheKey, 3600, function () use ($store, $invoiceId, $apiKey) {
            try {
                $methods = $this->invoiceService->getInvoicePaymentMethods($store->btcpay_store_id, $invoiceId, $apiKey);

                return InvoiceService::sumReceivedSatsFromPaymentMethods($methods);
            } catch (\Throwable) {
                return 0;
            }
        });
    }

    /**
     * For a single settled invoice, return received amount in sats.
     * If invoice currency is SATS/BTC uses amount; otherwise fetches payment-methods and sums BTC received.
     */
    private function getReceivedSatsForInvoice(string $btcpayStoreId, string $apiKey, array $inv): int
    {
        $currency = strtoupper(trim((string) ($inv['currency'] ?? '')));
        $amount = (float) ($inv['amount'] ?? 0);
        if ($currency === 'SATS') {
            return (int) round($amount);
        }
        if ($currency === 'BTC') {
            return (int) round($amount * 100_000_000);
        }
        $invoiceId = $inv['id'] ?? null;
        if ($invoiceId === null || $invoiceId === '') {
            return 0;
        }
        try {
            $methods = $this->invoiceService->getInvoicePaymentMethods($btcpayStoreId, $invoiceId, $apiKey);

            return InvoiceService::sumReceivedSatsFromPaymentMethods($methods);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getInvoiceStatsBySource(Store $store): array
    {
        try {
            $apiKey = $store->user->getBtcPayApiKeyOrFail();
        } catch (\Throwable) {
            return $this->emptyBySource();
        }

        $apiKeyHash = md5($apiKey);
        $cacheKey = "btcpay:store_invoice_stats:{$store->id}:{$apiKeyHash}";

        return Cache::remember($cacheKey, 3600, function () use ($store, $apiKey) {
            $allInvoices = $this->fetchAllInvoices($store->btcpay_store_id, $apiKey);
            $invoicesBySource = [];
            foreach (InvoiceSourceService::SOURCES as $s) {
                $invoicesBySource[$s] = [];
            }
            foreach ($allInvoices as $inv) {
                $status = $inv['status'] ?? null;
                if (! in_array($status, ['Settled', 'Complete'], true)) {
                    continue;
                }
                $source = $this->invoiceSourceService->detectSource($inv);
                if (! isset($invoicesBySource[$source])) {
                    $invoicesBySource[$source] = [];
                }
                $invoicesBySource[$source][] = $inv;
            }

            $bySource = [];
            foreach (InvoiceSourceService::SOURCES as $key) {
                $list = $invoicesBySource[$key] ?? [];
                $sales7 = $this->buildDays(7);
                $sales30 = $this->buildDays(30);
                $total7d = 0;
                $total30d = 0;
                foreach ($list as $inv) {
                    $createdTime = $inv['createdTime'] ?? null;
                    if (! $createdTime) {
                        continue;
                    }
                    $day = Carbon::parse($createdTime)->startOfDay()->format('Y-m-d');
                    if (isset($sales7[$day])) {
                        $sales7[$day]['count']++;
                        $total7d++;
                    }
                    if (isset($sales30[$day])) {
                        $sales30[$day]['count']++;
                        $total30d++;
                    }
                }
                $bySource[$key] = [
                    'sales_7d' => array_values($sales7),
                    'sales_30d' => array_values($sales30),
                    'total_7d' => $total7d,
                    'total_30d' => $total30d,
                ];
            }

            return ['by_source' => $bySource];
        });
    }

    /** @return array{by_source: array} */
    private function emptyBySource(): array
    {
        $bySource = [];
        foreach (InvoiceSourceService::SOURCES as $key) {
            $bySource[$key] = [
                'sales_7d' => array_values($this->buildDays(7)),
                'sales_30d' => array_values($this->buildDays(30)),
                'total_7d' => 0,
                'total_30d' => 0,
            ];
        }

        return ['by_source' => $bySource];
    }

    private function buildDays(int $days): array
    {
        $arr = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $arr[$date->format('Y-m-d')] = ['date' => $date->format('M j'), 'count' => 0];
        }

        return $arr;
    }

    private function fetchAllInvoices(string $btcpayStoreId, string $apiKey): array
    {
        $out = [];
        $skip = 0;
        $take = 100;
        do {
            $result = $this->invoiceService->listInvoices($btcpayStoreId, [], $skip, $take, $apiKey);
            $chunk = $result['data'] ?? $result;
            if (! is_array($chunk)) {
                break;
            }
            foreach ($chunk as $inv) {
                $out[] = $inv;
            }
            $skip += $take;
        } while (count($chunk) === $take);

        return $out;
    }
}
