<?php

namespace App\Http\Controllers;

use App\Models\PosOrder;
use App\Models\Store;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\StoreService;
use App\Services\DashboardAnalyticsService;
use App\Services\InvoiceSourceService;
use App\Services\StoreInvoiceStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class DashboardController extends Controller
{
    public function __construct(
        protected StoreService $storeService,
        protected StoreInvoiceStatsService $storeInvoiceStatsService
    ) {}

    /**
     * Get dashboard data.
     * Stores are loaded from BTCPay API, then merged with local metadata.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get local stores first - these are the source of truth for what stores belong to this user
        $localStores = Store::where('user_id', $user->id)
            ->with(['checklistItems'])
            ->get()
            ->keyBy('btcpay_store_id');

        // Try to load stores from BTCPay API if merchant has API key
        $btcpayStores = [];
        try {
            if ($user->btcpay_api_key) {
                // Load stores from BTCPay API using merchant token
                $btcpayStores = $this->storeService->listStores($user->btcpay_api_key);
            }
        } catch (BtcPayException $e) {
            // If API fails, we'll use local stores only
            Log::warning('BTCPay API failed when loading dashboard stores, using local stores only', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Only return stores that exist in BOTH local DB AND BTCPay Server
        // Store must be in local DB (metadata) AND merchant must have access via BTCPay API
        if (empty($btcpayStores)) {
            // If no BTCPay stores returned (no API key or API failed), return empty array
            // Store must exist on BTCPay server to be visible
            $stores = collect([]);
        } else {
            // Filter local stores to only include those that exist in BTCPay API response
            $btcpayStoreIds = collect($btcpayStores)->map(function ($bs) {
                return $bs['id'] ?? $bs['storeId'] ?? null;
            })->filter()->values()->toArray();

            $stores = $localStores->filter(function ($localStore) use ($btcpayStoreIds) {
                // Only include if local store's btcpay_store_id exists in BTCPay API response
                return in_array($localStore->btcpay_store_id, $btcpayStoreIds);
            })->map(function ($localStore) use ($btcpayStores) {
                // Find matching BTCPay store data
                $btcpayStore = collect($btcpayStores)->first(function ($bs) use ($localStore) {
                    $btcpayStoreId = $bs['id'] ?? $bs['storeId'] ?? null;

                    return $btcpayStoreId === $localStore->btcpay_store_id;
                });

                return [
                    'id' => $localStore->id,
                    'name' => $btcpayStore['name'] ?? $localStore->name,
                    'wallet_type' => $localStore->wallet_type,
                    'created_at' => $localStore->created_at ?? ($btcpayStore['created'] ?? now()),
                ];
            })->values();
        }

        // Total revenue by currency: PoS orders (DB) + BTCPay settled invoices, cached 5 min
        $storeIds = $stores->pluck('id')->toArray();
        if ($request->boolean('refresh')) {
            Cache::forget('btcpay:http_ping');
        }
        $btcpayPing = Cache::remember('btcpay:http_ping', 30, fn () => $this->measureBtcpayPing());

        $cacheKey = 'dashboard:user:'.$user->id.':total_revenue_v2';
        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
            /** @var Store $store */
            foreach (Store::whereIn('id', $storeIds)->with('user')->get() as $store) {
                $this->storeInvoiceStatsService->forgetStoreCaches($store);
            }
        }
        $revenueData = Cache::remember($cacheKey, 3600, function () use ($storeIds) {
            $byCurrency = [];
            if (! empty($storeIds)) {
                $orders = PosOrder::whereIn('store_id', $storeIds)
                    ->where('status', PosOrder::STATUS_PAID)
                    ->get(['amount', 'currency', 'store_id', 'btcpay_invoice_id', 'paid_method']);
                /** @var Collection<int, Store> $localStoresById */
                $localStoresById = Store::whereIn('id', $storeIds)->with('user')->get()->keyBy('id');
                foreach ($orders as $order) {
                    $currency = strtoupper(trim($order->currency ?? ''));
                    $amount = (float) $order->amount;
                    if ($currency === 'SATS') {
                        $byCurrency['sats'] = ($byCurrency['sats'] ?? 0) + (int) round($amount);
                    } elseif ($currency === 'BTC') {
                        $byCurrency['sats'] = ($byCurrency['sats'] ?? 0) + (int) round($amount * 100_000_000);
                    } else {
                        if ($currency !== '') {
                            $key = strtolower($currency);
                            $byCurrency[$key] = ($byCurrency[$key] ?? 0) + $amount;
                        }
                        // PoS v EUR/USD platene cez Lightning/onchain: reálne sats z BTCPay faktúry
                        $invoiceId = $order->btcpay_invoice_id ?? '';
                        $paidMethod = $order->paid_method ?? '';
                        if ($invoiceId !== '' && in_array($paidMethod, [PosOrder::PAID_METHOD_LIGHTNING, PosOrder::PAID_METHOD_ONCHAIN], true)) {
                            $store = $localStoresById->get($order->store_id);
                            if ($store instanceof Store) {
                                $byCurrency['sats'] = ($byCurrency['sats'] ?? 0) + $this->storeInvoiceStatsService->getReceivedSatsForBtcPayInvoiceId($store, $invoiceId);
                            }
                        }
                    }
                }
                foreach ($localStoresById as $store) {
                    $storeTotals = $this->storeInvoiceStatsService->getTotalRevenueByCurrency($store);
                    foreach ($storeTotals as $key => $value) {
                        $byCurrency[$key] = ($byCurrency[$key] ?? 0) + $value;
                    }
                }
            }
            $byCurrency['sats'] = (int) ($byCurrency['sats'] ?? 0);
            foreach (array_keys($byCurrency) as $k) {
                if ($k !== 'sats' && is_numeric($byCurrency[$k])) {
                    $byCurrency[$k] = round((float) $byCurrency[$k], 2);
                }
            }

            return $byCurrency;
        });

        $totalRevenueByCurrency = $revenueData;
        $availableCurrencies = array_keys(array_filter($totalRevenueByCurrency, fn ($v) => $v > 0));
        if ($availableCurrencies === []) {
            $availableCurrencies = ['sats'];
        }
        if (! in_array('sats', $availableCurrencies, true)) {
            $availableCurrencies = array_merge(['sats'], $availableCurrencies);
        }

        return response()->json([
            'stores' => $stores,
            'store_count' => $stores->count(),
            'total_revenue' => (int) ($totalRevenueByCurrency['sats'] ?? 0),
            'total_revenue_by_currency' => $totalRevenueByCurrency,
            'available_revenue_currencies' => array_values($availableCurrencies),
            'btcpay_ping' => $btcpayPing,
        ]);
    }

    /**
     * Lightweight reachability check against BTCPay Greenfield (no API key).
     * Unauthenticated GET /api/v1/users/me typically returns 401 when the server is up.
     *
     * @return array{http_status: int|null, state: string}
     */
    private function measureBtcpayPing(): array
    {
        $base = rtrim((string) config('services.btcpay.base_url'), '/');
        if ($base === '') {
            return ['http_status' => null, 'state' => 'unknown'];
        }

        $url = $base.'/api/v1/users/me';

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->acceptJson()
                ->get($url);
            $status = $response->status();
        } catch (\Throwable $e) {
            Log::debug('BTCPay ping: connection failed', ['url' => $url, 'error' => $e->getMessage()]);

            return ['http_status' => null, 'state' => 'unreachable'];
        }

        if ($status >= 200 && $status < 400) {
            return ['http_status' => $status, 'state' => 'online'];
        }

        if ($status === 401 || $status === 403) {
            return ['http_status' => $status, 'state' => 'online'];
        }

        if ($status >= 400 && $status < 500) {
            return ['http_status' => $status, 'state' => 'client_error'];
        }

        if ($status >= 500) {
            return ['http_status' => $status, 'state' => 'server_error'];
        }

        return ['http_status' => $status, 'state' => 'unknown'];
    }

    /**
     * Dashboard stats (sales by store and by payment method).
     * Returns per_store with pos (PoS orders) and invoices by_source (BTCPay: LN, Pay Button, Tickets, etc.).
     * Top-level sales_7d/sales_30d = combined all stores, all methods. PRO + admin/support only.
     */
    public function stats(Request $request)
    {
        $user = $request->user();

        $storesCollection = Store::where('user_id', $user->id)
            ->with('user')
            ->orderBy('name')
            ->get();

        if ($request->boolean('refresh')) {
            foreach ($storesCollection as $store) {
                $this->storeInvoiceStatsService->forgetStoreCaches($store);
            }
        }
        $stores = $storesCollection->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all();

        $plan = $user->currentSubscriptionPlan();
        $planCode = $plan ? strtolower($plan->code ?? '') : 'free';
        $canViewStats = in_array($user->role, ['admin', 'support'], true)
            || in_array($planCode, ['pro', 'enterprise'], true);

        $sales7d = [];
        $sales30d = [];
        $total7d = 0;
        $total30d = 0;
        $perStore = [];

        if ($canViewStats && $storesCollection->isNotEmpty()) {
            $sevenDaysAgo = now()->subDays(7)->startOfDay();
            $thirtyDaysAgo = now()->subDays(30)->startOfDay();

            $dateLabels7 = [];
            $dateLabels30 = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = now()->subDays($i)->startOfDay();
                $dateLabels7[$d->format('Y-m-d')] = ['date' => $d->format('M j'), 'count' => 0];
            }
            for ($i = 29; $i >= 0; $i--) {
                $d = now()->subDays($i)->startOfDay();
                $dateLabels30[$d->format('Y-m-d')] = ['date' => $d->format('M j'), 'count' => 0];
            }

            $merged7 = $dateLabels7;
            $merged30 = $dateLabels30;

            /** @var Store $store */
            foreach ($storesCollection as $store) {
                $storeId = $store->id;

                // PoS orders for this store
                $orders7d = PosOrder::where('store_id', $storeId)
                    ->where('status', PosOrder::STATUS_PAID)
                    ->whereNotNull('paid_at')
                    ->where('paid_at', '>=', $sevenDaysAgo)
                    ->get(['paid_at']);
                $orders30d = PosOrder::where('store_id', $storeId)
                    ->where('status', PosOrder::STATUS_PAID)
                    ->whereNotNull('paid_at')
                    ->where('paid_at', '>=', $thirtyDaysAgo)
                    ->get(['paid_at']);

                $pos7 = $dateLabels7;
                $pos30 = $dateLabels30;
                $posTotal7d = 0;
                $posTotal30d = 0;
                foreach ($orders7d as $o) {
                    $key = $o->paid_at->startOfDay()->format('Y-m-d');
                    if (isset($pos7[$key])) {
                        $pos7[$key]['count']++;
                        $posTotal7d++;
                        $merged7[$key]['count']++;
                        $total7d++;
                    }
                }
                foreach ($orders30d as $o) {
                    $key = $o->paid_at->startOfDay()->format('Y-m-d');
                    if (isset($pos30[$key])) {
                        $pos30[$key]['count']++;
                        $posTotal30d++;
                        $merged30[$key]['count']++;
                        $total30d++;
                    }
                }

                // Invoice stats by source (BTCPay) - may fail if no API key or BTCPay error
                try {
                    $invoiceStats = $this->storeInvoiceStatsService->getInvoiceStatsBySource($store);
                } catch (\Throwable $e) {
                    $invoiceStats = ['by_source' => []];
                    foreach (InvoiceSourceService::SOURCES as $sk) {
                        $invoiceStats['by_source'][$sk] = ['sales_7d' => array_values($dateLabels7), 'sales_30d' => array_values($dateLabels30), 'total_7d' => 0, 'total_30d' => 0];
                    }
                }
                $invoicesBySource = [];
                $dayKeys7 = array_keys($dateLabels7);
                $dayKeys30 = array_keys($dateLabels30);
                foreach (InvoiceSourceService::SOURCES as $sourceKey) {
                    $src = $invoiceStats['by_source'][$sourceKey] ?? [];
                    $invoicesBySource[$sourceKey] = [
                        'sales_7d' => $src['sales_7d'] ?? [],
                        'sales_30d' => $src['sales_30d'] ?? [],
                        'total_7d' => (int) ($src['total_7d'] ?? 0),
                        'total_30d' => (int) ($src['total_30d'] ?? 0),
                    ];
                    $arr7 = $src['sales_7d'] ?? [];
                    $arr30 = $src['sales_30d'] ?? [];
                    for ($i = 0; $i < count($dayKeys7) && $i < count($arr7); $i++) {
                        $k = $dayKeys7[$i] ?? null;
                        if ($k !== null && isset($merged7[$k])) {
                            $c = (int) ($arr7[$i]['count'] ?? 0);
                            $merged7[$k]['count'] += $c;
                            $total7d += $c;
                        }
                    }
                    for ($i = 0; $i < count($dayKeys30) && $i < count($arr30); $i++) {
                        $k = $dayKeys30[$i] ?? null;
                        if ($k !== null && isset($merged30[$k])) {
                            $c = (int) ($arr30[$i]['count'] ?? 0);
                            $merged30[$k]['count'] += $c;
                            $total30d += $c;
                        }
                    }
                }

                $perStore[] = [
                    'store_id' => $storeId,
                    'name' => $store->name,
                    'pos' => [
                        'sales_7d' => array_values($pos7),
                        'sales_30d' => array_values($pos30),
                        'total_7d' => $posTotal7d,
                        'total_30d' => $posTotal30d,
                    ],
                    'invoices' => ['by_source' => $invoicesBySource],
                ];
            }

            $sales7d = array_values($merged7);
            $sales30d = array_values($merged30);
        }

        return response()->json([
            'stores' => $stores,
            'can_view_stats' => $canViewStats,
            'sales_7d' => $sales7d,
            'sales_30d' => $sales30d,
            'total_7d' => $total7d,
            'total_30d' => $total30d,
            'per_store' => $perStore,
        ]);
    }

    /**
     * Range analytics for the redesigned dashboard: per-day series, totals,
     * previous-window totals and source/store breakdowns
     * (DashboardAnalyticsService). Free plans get totals only - the same
     * boundary the stats() chart gate draws.
     */
    public function analytics(Request $request, DashboardAnalyticsService $analyticsService)
    {
        $user = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'in:'.implode(',', InvoiceSourceService::SOURCES)],
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['from'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['to'])->startOfDay();
        if ($from->diffInDays($to) > 400) {
            return response()->json(['message' => 'Date range too large (max 400 days).'], 422);
        }

        $stores = Store::where('user_id', $user->id)
            ->when($validated['store_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get();
        if (($validated['store_id'] ?? null) && $stores->isEmpty()) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        $plan = $user->currentSubscriptionPlan();
        $planCode = $plan ? strtolower($plan->code ?? '') : 'free';
        $canViewStats = in_array($user->role, ['admin', 'support'], true)
            || in_array($planCode, ['pro', 'enterprise'], true);

        $cacheKey = 'dashboard:analytics:'.$user->id.':'.md5(json_encode([
            $validated['from'], $validated['to'],
            $validated['store_id'] ?? null, $validated['source'] ?? null,
        ]));
        if ($request->boolean('refresh')) {
            // A cache-busting refresh recomputes against BTCPay - throttle it
            // tighter than the generic api-user limit (which still applies).
            $refreshKey = 'dashboard-analytics-refresh:'.$user->id;
            if (RateLimiter::tooManyAttempts($refreshKey, 5)) {
                return response()->json(['message' => 'Too many refreshes - try again shortly.'], 429);
            }
            RateLimiter::hit($refreshKey, 60);
            Cache::forget($cacheKey);
        }

        $payload = Cache::remember($cacheKey, 600, function () use ($analyticsService, $user, $stores, $from, $to, $validated) {
            return $analyticsService->analytics($user, $stores, $from, $to, $validated['source'] ?? null);
        });

        if (! $canViewStats) {
            // Same product boundary as the stats() chart gate: KPIs for
            // everyone, series and breakdowns for Pro.
            $payload['series'] = null;
            $payload['by_source'] = null;
            $payload['by_store'] = null;
            $payload['previous'] = null;
        }

        return response()->json(array_merge($payload, [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'can_view_stats' => $canViewStats,
        ]));
    }
}
