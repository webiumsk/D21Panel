<?php

namespace App\Services;

use App\Models\PosOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide paid-order aggregation for the admin dashboard.
 *
 * Orders come from two sources: the native terminal flow (pos_orders) and
 * BTCPay invoices settled platform-wide, which webhooks mirror into
 * store_settlements (one row per payment). Settlement rows are deduped per
 * invoice, restricted to truly settled payments, and invoices already
 * represented by a native paid order are excluded so nothing counts twice.
 */
class PlatformOrderStatsService
{
    /**
     * Per-invoice aggregation of the settlement ledger: settled payments
     * only (payment_status is the raw BTCPay payment status - "Processing"
     * rows may carry paid_at but are not settled), minus invoices that a
     * native paid PoS order already covers.
     */
    public function settledInvoiceBase(): Builder
    {
        return DB::table('store_settlements')
            ->selectRaw('store_id, btcpay_invoice_id, MIN(paid_at) as paid_at, '
                ."MAX(COALESCE(invoice_currency, '')) as currency, "
                .'MAX(COALESCE(invoice_amount, 0)) as amount, '
                .'COALESCE(SUM(gross_sats), 0) as sats')
            ->where('payment_status', 'Settled')
            ->whereNotNull('paid_at')
            ->whereNotIn('btcpay_invoice_id', function ($query) {
                $query->select('btcpay_invoice_id')
                    ->from('pos_orders')
                    ->where('status', PosOrder::STATUS_PAID)
                    ->whereNotNull('btcpay_invoice_id');
            })
            ->groupBy('store_id', 'btcpay_invoice_id');
    }

    public function settledInvoices(): Builder
    {
        return DB::query()->fromSub($this->settledInvoiceBase(), 'inv');
    }

    /** @return array{total: int, since: int} */
    public function settledCounts(Carbon $since): array
    {
        return [
            'total' => (int) $this->settledInvoices()->count(),
            'since' => (int) $this->settledInvoices()->where('paid_at', '>=', $since)->count(),
        ];
    }

    /** @return array{sats: float, eur: float} */
    public function settledAmounts(?Carbon $since = null): array
    {
        $query = fn () => $since
            ? $this->settledInvoices()->where('paid_at', '>=', $since)
            : $this->settledInvoices();

        return [
            'sats' => (float) $query()->whereRaw('UPPER(TRIM(currency)) = ?', ['SATS'])->sum('sats'),
            'eur' => (float) $query()->whereRaw('UPPER(TRIM(currency)) != ?', ['SATS'])->sum('amount'),
        ];
    }

    /**
     * Per-day settled-invoice counts and amount buckets since the given day.
     *
     * @return array{orders: array<string, int>, sats: array<string, float>, eur: array<string, float>}
     */
    public function settledByDay(Carbon $since): array
    {
        $dateExpr = DB::getDriverName() === 'sqlite' ? 'date(paid_at)' : 'CAST(paid_at AS DATE)';

        return [
            'orders' => $this->settledInvoices()
                ->selectRaw("{$dateExpr} as day, count(*) as c")
                ->where('paid_at', '>=', $since)
                ->groupBy('day')->pluck('c', 'day')->toArray(),
            'sats' => $this->settledInvoices()
                ->selectRaw("{$dateExpr} as day, COALESCE(SUM(sats), 0) as total")
                ->where('paid_at', '>=', $since)
                ->whereRaw('UPPER(TRIM(currency)) = ?', ['SATS'])
                ->groupBy('day')->pluck('total', 'day')->toArray(),
            'eur' => $this->settledInvoices()
                ->selectRaw("{$dateExpr} as day, COALESCE(SUM(amount), 0) as total")
                ->where('paid_at', '>=', $since)
                ->whereRaw('UPPER(TRIM(currency)) != ?', ['SATS'])
                ->groupBy('day')->pluck('total', 'day')->toArray(),
        ];
    }

    /**
     * Top stores by paid orders across both sources, ranked in the database
     * (UNION ALL of the two per-store aggregates, then summed and limited -
     * a store can rank in the combined top N without leading either source).
     *
     * @return list<object{store_id: string, cnt: int, sats: float, eur: float}>
     */
    public function topStoreRows(int $limit = 10): array
    {
        $native = DB::table('pos_orders')
            ->selectRaw(
                'store_id, count(*) as cnt, '
                ."COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(currency, ''))) = 'SATS' THEN amount ELSE 0 END), 0) as sats, "
                ."COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(currency, ''))) != 'SATS' THEN amount ELSE 0 END), 0) as eur")
            ->where('status', PosOrder::STATUS_PAID)
            ->groupBy('store_id');

        $settled = $this->settledInvoices()
            ->selectRaw(
                'store_id, count(*) as cnt, '
                ."COALESCE(SUM(CASE WHEN UPPER(TRIM(currency)) = 'SATS' THEN sats ELSE 0 END), 0) as sats, "
                ."COALESCE(SUM(CASE WHEN UPPER(TRIM(currency)) != 'SATS' THEN amount ELSE 0 END), 0) as eur")
            ->groupBy('store_id');

        return DB::query()
            ->fromSub($native->unionAll($settled), 'u')
            ->selectRaw('store_id, SUM(cnt) as cnt, SUM(sats) as sats, SUM(eur) as eur')
            ->groupBy('store_id')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Total sats that flowed through the platform: every settled payment's
     * gross_sats (per payment, no invoice dedup needed and no native-order
     * exclusion - this measures volume, not order counts; cash/card
     * payments on native terminals never move sats).
     */
    public function volumeSats(?Carbon $since = null): float
    {
        $query = DB::table('store_settlements')
            ->where('payment_status', 'Settled')
            ->whereNotNull('paid_at');
        if ($since) {
            $query->where('paid_at', '>=', $since);
        }

        return (float) $query->sum('gross_sats');
    }
}
