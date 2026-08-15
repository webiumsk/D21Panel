<?php

namespace App\Services;

use App\Models\PosOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aggregates dashboard analytics for an arbitrary date range: per-day series,
 * period totals, previous-window totals (for delta badges) and breakdowns by
 * payment source and by store.
 *
 * Semantics deliberately MIRROR the existing dashboard numbers so the redesign
 * does not change what merchants see:
 * - counts follow DashboardController::stats (BTCPay Settled/Complete invoices
 *   bucketed by InvoiceSourceService + paid PoS orders),
 * - revenue follows StoreDashboardController::show (fiat from invoice
 *   amount/currency, sats from received payments; PoS orders added on top;
 *   currencies are NEVER summed across each other).
 */
class DashboardAnalyticsService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected InvoiceSourceService $invoiceSourceService,
    ) {}

    /**
     * @param  Collection<int, Store>  $stores  already ownership-filtered
     * @return array{
     *   totals: array,
     *   previous: array,
     *   series: array<int, array>,
     *   by_source: array<string, array>,
     *   by_store: array<int, array>
     * }
     */
    public function analytics(
        User $user,
        Collection $stores,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $source = null,
    ): array {
        $days = $this->dayKeys($from, $to);

        $series = [];
        foreach ($days as $key => $label) {
            $series[$key] = [
                'date' => $key,
                'label' => $label,
                'paid_count' => 0,
                'amount_sats' => 0,
                'amount_by_currency' => [],
            ];
        }

        $bySource = [];
        foreach (InvoiceSourceService::SOURCES as $s) {
            $bySource[$s] = ['paid_count' => 0, 'amount_sats' => 0];
        }

        $byStore = [];

        // The stores are ownership-filtered to $user, so the merchant key is
        // resolved once here instead of per store via $store->user.
        try {
            $apiKey = $user->getBtcPayApiKeyOrFail();
        } catch (\Throwable) {
            $apiKey = null;
        }

        foreach ($stores as $store) {
            $storeAgg = [
                'store_id' => $store->id,
                'name' => $store->name,
                'paid_count' => 0,
                'amount_sats' => 0,
                'amount_by_currency' => [],
            ];

            $this->aggregateInvoices($store, $from, $to, $source, $apiKey, $series, $bySource, $storeAgg);
            $this->aggregatePosOrders($store, $from, $to, $source, $series, $bySource, $storeAgg);

            $byStore[] = $storeAgg;
        }

        usort($byStore, fn ($a, $b) => $b['amount_sats'] <=> $a['amount_sats']);

        return [
            'totals' => $this->totalsFromSeries($series),
            'previous' => $this->previousTotals($stores, $from, $to, $source, $apiKey),
            'series' => array_values($series),
            'by_source' => $bySource,
            'by_store' => $byStore,
        ];
    }

    /** Totals for the equally long window that ends right before `from`. */
    protected function previousTotals(Collection $stores, CarbonImmutable $from, CarbonImmutable $to, ?string $source, ?string $apiKey): array
    {
        $spanDays = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($spanDays - 1);

        $series = [];
        foreach ($this->dayKeys($prevFrom, $prevTo) as $key => $label) {
            $series[$key] = [
                'date' => $key,
                'label' => $label,
                'paid_count' => 0,
                'amount_sats' => 0,
                'amount_by_currency' => [],
            ];
        }
        $bySource = [];
        foreach (InvoiceSourceService::SOURCES as $s) {
            $bySource[$s] = ['paid_count' => 0, 'amount_sats' => 0];
        }

        foreach ($stores as $store) {
            $unusedStoreAgg = ['paid_count' => 0, 'amount_sats' => 0, 'amount_by_currency' => []];
            $this->aggregateInvoices($store, $prevFrom, $prevTo, $source, $apiKey, $series, $bySource, $unusedStoreAgg);
            $this->aggregatePosOrders($store, $prevFrom, $prevTo, $source, $series, $bySource, $unusedStoreAgg);
        }

        $totals = $this->totalsFromSeries($series);

        return [
            'from' => $prevFrom->format('Y-m-d'),
            'to' => $prevTo->format('Y-m-d'),
            'paid_count' => $totals['paid_count'],
            'amount_sats' => $totals['amount_sats'],
            'amount_by_currency' => $totals['amount_by_currency'],
        ];
    }

    /**
     * BTCPay Settled/Complete invoices of the store within the window,
     * bucketed per day / source / currency.
     *
     * @param  array<array-key, array<string, mixed>>  $series
     * @param  array<array-key, array<string, mixed>>  $bySource
     * @param  array<string, mixed>  $storeAgg
     */
    protected function aggregateInvoices(
        Store $store,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $source,
        ?string $apiKey,
        array &$series,
        array &$bySource,
        array &$storeAgg,
    ): void {
        if ($apiKey === null) {
            return;
        }

        $fromTs = $from->startOfDay()->getTimestamp();
        $toTs = $to->endOfDay()->getTimestamp();

        try {
            $invoices = $this->fetchAllInvoices((string) $store->btcpay_store_id, $apiKey, $fromTs, $toTs);
        } catch (\Throwable) {
            return;
        }

        foreach ($invoices as $inv) {
            if (! in_array($inv['status'] ?? null, ['Settled', 'Complete'], true)) {
                continue;
            }
            $created = $inv['createdTime'] ?? null;
            $ts = is_numeric($created) ? (int) $created : (int) strtotime((string) $created);
            if ($ts < $fromTs || $ts > $toTs) {
                continue;
            }
            $invSource = $this->invoiceSourceService->detectSource($inv);
            if ($source !== null && $invSource !== $source) {
                continue;
            }

            $dayKey = date('Y-m-d', $ts);
            if (! isset($series[$dayKey])) {
                continue;
            }

            $sats = $this->receivedSatsForInvoice($store, $apiKey, $inv);
            $currency = strtoupper(trim((string) ($inv['currency'] ?? '')));
            $amount = (float) ($inv['amount'] ?? 0);

            $series[$dayKey]['paid_count']++;
            $series[$dayKey]['amount_sats'] += $sats;
            $storeAgg['paid_count']++;
            $storeAgg['amount_sats'] += $sats;
            $bySource[$invSource]['paid_count']++;
            $bySource[$invSource]['amount_sats'] += $sats;

            if ($currency !== '' && $currency !== 'SATS' && $currency !== 'BTC') {
                $key = strtolower($currency);
                $series[$dayKey]['amount_by_currency'][$key] = ($series[$dayKey]['amount_by_currency'][$key] ?? 0) + $amount;
                $storeAgg['amount_by_currency'][$key] = ($storeAgg['amount_by_currency'][$key] ?? 0) + $amount;
            }
        }
    }

    /**
     * Paid PoS orders within the window - added ON TOP of invoices, exactly
     * like the existing dashboards do (counts in stats(), revenue in the
     * per-store dashboard). They live under the "pos" source bucket.
     *
     * @param  array<array-key, array<string, mixed>>  $series
     * @param  array<array-key, array<string, mixed>>  $bySource
     * @param  array<string, mixed>  $storeAgg
     */
    protected function aggregatePosOrders(
        Store $store,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $source,
        array &$series,
        array &$bySource,
        array &$storeAgg,
    ): void {
        if ($source !== null && $source !== InvoiceSourceService::SOURCE_POS) {
            return;
        }

        $orders = PosOrder::query()
            ->where('store_id', $store->id)
            ->where('status', PosOrder::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['paid_at', 'amount', 'currency', 'paid_method', 'btcpay_invoice_id']);

        foreach ($orders as $order) {
            $dayKey = Carbon::parse($order->paid_at)->format('Y-m-d');
            if (! isset($series[$dayKey])) {
                continue;
            }

            $currency = strtoupper(trim((string) ($order->currency ?? '')));
            $amount = (float) $order->amount;
            $sats = 0;
            if ($currency === 'SATS') {
                $sats = (int) round($amount);
            } elseif ($currency === 'BTC') {
                $sats = (int) round($amount * 100_000_000);
            }

            $series[$dayKey]['paid_count']++;
            $series[$dayKey]['amount_sats'] += $sats;
            $storeAgg['paid_count']++;
            $storeAgg['amount_sats'] += $sats;
            $bySource[InvoiceSourceService::SOURCE_POS]['paid_count']++;
            $bySource[InvoiceSourceService::SOURCE_POS]['amount_sats'] += $sats;

            if ($currency !== '' && $currency !== 'SATS' && $currency !== 'BTC') {
                $key = strtolower($currency);
                $series[$dayKey]['amount_by_currency'][$key] = ($series[$dayKey]['amount_by_currency'][$key] ?? 0) + $amount;
                if (isset($storeAgg['amount_by_currency'])) {
                    $storeAgg['amount_by_currency'][$key] = ($storeAgg['amount_by_currency'][$key] ?? 0) + $amount;
                }
            }
        }
    }

    /** @param array<array-key, array<string, mixed>> $series */
    protected function totalsFromSeries(array $series): array
    {
        $paidCount = 0;
        $amountSats = 0;
        $byCurrency = [];
        $bestDay = null;

        foreach ($series as $day) {
            $paidCount += $day['paid_count'];
            $amountSats += $day['amount_sats'];
            foreach ($day['amount_by_currency'] as $cur => $amt) {
                $byCurrency[$cur] = ($byCurrency[$cur] ?? 0) + $amt;
            }
            if ($day['paid_count'] > 0 && ($bestDay === null || $day['amount_sats'] > $bestDay['amount_sats']
                || ($day['amount_sats'] === $bestDay['amount_sats'] && $day['paid_count'] > $bestDay['paid_count']))) {
                $bestDay = [
                    'date' => $day['date'],
                    'paid_count' => $day['paid_count'],
                    'amount_sats' => $day['amount_sats'],
                ];
            }
        }

        return [
            'paid_count' => $paidCount,
            'amount_sats' => $amountSats,
            'amount_by_currency' => $byCurrency,
            'avg_order_sats' => $paidCount > 0 ? (int) round($amountSats / $paidCount) : 0,
            'best_day' => $bestDay,
        ];
    }

    /** @return array<string, string> Y-m-d => short label */
    protected function dayKeys(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        for ($d = $from->startOfDay(); $d->lessThanOrEqualTo($to->startOfDay()); $d = $d->addDay()) {
            $days[$d->format('Y-m-d')] = $d->format('M j');
        }

        return $days;
    }

    /**
     * Paginated Greenfield fetch, server-side filtered to the requested
     * window (startDate/endDate are unix timestamps in the Greenfield API) -
     * fetching the whole store history for a 7-day chart does not scale.
     */
    protected function fetchAllInvoices(string $btcpayStoreId, string $apiKey, int $fromTs, int $toTs): array
    {
        $out = [];
        $skip = 0;
        $take = 100;
        do {
            $result = $this->invoiceService->listInvoices(
                $btcpayStoreId,
                ['startDate' => $fromTs, 'endDate' => $toTs],
                $skip,
                $take,
                $apiKey,
            );
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

    /**
     * Received sats for one settled invoice - SATS/BTC from the amount,
     * fiat via the (per-invoice cached) payment-methods call. Mirrors
     * StoreInvoiceStatsService::getReceivedSatsForInvoice.
     */
    protected function receivedSatsForInvoice(Store $store, string $apiKey, array $inv): int
    {
        $currency = strtoupper(trim((string) ($inv['currency'] ?? '')));
        $amount = (float) ($inv['amount'] ?? 0);
        if ($currency === 'SATS') {
            return (int) round($amount);
        }
        if ($currency === 'BTC') {
            return (int) round($amount * 100_000_000);
        }
        $invoiceId = (string) ($inv['id'] ?? '');
        if ($invoiceId === '') {
            return 0;
        }
        try {
            $methods = $this->invoiceService->getInvoicePaymentMethods((string) $store->btcpay_store_id, $invoiceId, $apiKey);

            return InvoiceService::sumReceivedSatsFromPaymentMethods($methods);
        } catch (\Throwable) {
            return 0;
        }
    }
}
