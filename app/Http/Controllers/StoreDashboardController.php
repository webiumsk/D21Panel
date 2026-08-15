<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\PosOrder;
use App\Models\Store;
use App\Services\BtcPay\AppService;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\InvoiceService;
use App\Services\InvoiceSourceService;
use App\Services\StoreInvoiceStatsService;
use App\Services\SubscriptionEntitlementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StoreDashboardController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected AppService $appService,
        protected InvoiceSourceService $invoiceSourceService,
        protected StoreInvoiceStatsService $storeInvoiceStatsService,
        protected SubscriptionEntitlementService $subscriptionService
    ) {}

    /**
     * Get dashboard data for a store.
     * Optional ?source= (pos|pay_button|ln_address|tickets|api|other|all) for Pro: filter by payment method.
     */
    public function show(Request $request, Store $store)
    {
        $user = $request->user();

        // Load merchant API key
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        // Cache key includes API key hash to prevent cross-merchant cache pollution
        $apiKeyHash = md5($userApiKey);
        $cacheKey = "btcpay:dashboard:{$store->id}:{$apiKeyHash}";

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
            $this->storeInvoiceStatsService->forgetStoreCaches($store);
        }

        $payload = Cache::remember($cacheKey, 3600, function () use ($store, $userApiKey) {
            try {
                // Calculate date 7 days ago
                $sevenDaysAgo = now()->subDays(7)->toIso8601String();

                // Get all invoices for calculations (paginate to support stores with >1000 invoices)
                $allInvoices = $this->fetchAllInvoicesForStore($store->btcpay_store_id, $userApiKey);

                // Tag each invoice with source (payment method) and bucket paid invoices by source
                $invoicesBySource = [];
                foreach (InvoiceSourceService::SOURCES as $s) {
                    $invoicesBySource[$s] = [];
                }
                foreach ($allInvoices as $invoice) {
                    $status = $invoice['status'] ?? null;
                    if (! in_array($status, ['Settled', 'Complete'], true)) {
                        continue;
                    }
                    $source = $this->invoiceSourceService->detectSource($invoice);
                    if (! isset($invoicesBySource[$source])) {
                        $invoicesBySource[$source] = [];
                    }
                    $invoicesBySource[$source][] = $invoice;
                }

                // Build aggregates for "all" (current behaviour)
                $paidInvoicesLast7d = 0;
                $totalInvoices = count($allInvoices);
                foreach ($allInvoices as $invoice) {
                    $status = $invoice['status'] ?? null;
                    $createdTime = $invoice['createdTime'] ?? null;
                    if (in_array($status, ['Settled', 'Complete'], true)) {
                        $invoiceTime = is_numeric($createdTime) ? (int) $createdTime : strtotime($createdTime);
                        if ($invoiceTime && $invoiceTime >= strtotime($sevenDaysAgo)) {
                            $paidInvoicesLast7d++;
                        }
                    }
                }

                // Recent invoices: first 10 from full list (BTCPay API returns newest first)
                $recentSlice = array_slice($allInvoices, 0, 10);
                $formattedRecentInvoices = array_map(function ($invoice) {
                    return [
                        'id' => $invoice['id'] ?? null,
                        'invoice_id' => $invoice['id'] ?? null,
                        'status' => $invoice['status'] ?? null,
                        'amount' => $invoice['amount'] ?? null,
                        'currency' => $invoice['currency'] ?? null,
                        'created_time' => $invoice['createdTime'] ?? null,
                        'source' => $this->invoiceSourceService->detectSource($invoice),
                    ];
                }, $recentSlice);

                // Sales over time (last 7 and 30 days) - all invoices
                $salesLast7Days = $this->buildSalesDaysArray(7);
                $salesLast30Days = $this->buildSalesDaysArray(30);
                $totalSales7d = 0;
                $totalSales30d = 0;
                foreach ($allInvoices as $invoice) {
                    $status = $invoice['status'] ?? null;
                    $createdTime = $invoice['createdTime'] ?? null;
                    if (! in_array($status, ['Settled', 'Complete'], true) || ! $createdTime) {
                        continue;
                    }
                    $invoiceDate = Carbon::parse($createdTime)->startOfDay();
                    $dateKey = $invoiceDate->format('Y-m-d');
                    if ($invoiceDate->isAfter(now()->subDays(7)->startOfDay()) && isset($salesLast7Days[$dateKey])) {
                        $salesLast7Days[$dateKey]['count']++;
                        $totalSales7d++;
                    }
                    if ($invoiceDate->isAfter(now()->subDays(30)->startOfDay()) && isset($salesLast30Days[$dateKey])) {
                        $salesLast30Days[$dateKey]['count']++;
                        $totalSales30d++;
                    }
                }

                // Top items (all paid invoices)
                $itemCounts = [];
                foreach ($allInvoices as $invoice) {
                    $status = $invoice['status'] ?? null;
                    if (in_array($status, ['Settled', 'Complete'], true)) {
                        $itemName = $this->getItemNameFromInvoice($invoice);
                        $itemAmount = (float) ($invoice['amount'] ?? 0);
                        $currency = $invoice['currency'] ?? 'EUR';
                        if ($itemName) {
                            $itemCounts[$itemName] = $itemCounts[$itemName] ?? ['count' => 0, 'total' => 0, 'currency' => $currency];
                            $itemCounts[$itemName]['count']++;
                            $itemCounts[$itemName]['total'] += $itemAmount;
                        }
                    }
                }
                uasort($itemCounts, fn ($a, $b) => $b['count'] <=> $a['count']);
                $topItems = array_slice(array_map(function ($name, $data) {
                    return ['name' => $name, 'count' => $data['count'], 'total' => $data['total'], 'currency' => $data['currency']];
                }, array_keys($itemCounts), $itemCounts), 0, 5);

                // Per-source aggregates (for PRO filter and dashboard)
                $bySource = [];
                foreach (InvoiceSourceService::SOURCES as $sourceKey) {
                    $sourceInvoices = $invoicesBySource[$sourceKey] ?? [];
                    $bySource[$sourceKey] = $this->aggregateInvoicesBySource($sourceInvoices);
                }

                // Get apps for the store
                $apps = [];
                $appsGrouped = [
                    'crowdfund' => [],
                    'point_of_sale' => [],
                    'payment_button' => [],
                ];

                try {
                    $btcpayApps = $this->appService->listApps($store->btcpay_store_id, $userApiKey);

                    // Get local app metadata
                    $localApps = App::where('store_id', $store->id)
                        ->get()
                        ->keyBy('btcpay_app_id');

                    // Group apps by type
                    if (is_array($btcpayApps)) {
                        foreach ($btcpayApps as $btcpayApp) {
                            $btcpayAppId = $btcpayApp['id'] ?? null;
                            if (! $btcpayAppId) {
                                continue;
                            }

                            $localApp = $localApps->get($btcpayAppId);
                            $appTypeRaw = $localApp ? $localApp->app_type : ($btcpayApp['appType'] ?? 'PointOfSale');
                            $appType = strtolower($appTypeRaw);

                            $formattedApp = [
                                'id' => $localApp ? $localApp->id : null,
                                'name' => $btcpayApp['name'] ?? 'Untitled App',
                                'app_type' => $appTypeRaw, // Keep original format (e.g., 'Crowdfund', 'PointOfSale')
                                'btcpay_app_id' => $btcpayAppId,
                            ];

                            // Group by type (normalize to lowercase for comparison)
                            if ($appType === 'crowdfund') {
                                $appsGrouped['crowdfund'][] = $formattedApp;
                            } elseif ($appType === 'pointofsale' || $appType === 'point_of_sale') {
                                $appsGrouped['point_of_sale'][] = $formattedApp;
                            } elseif ($appType === 'paymentbutton' || $appType === 'payment_button') {
                                $appsGrouped['payment_button'][] = $formattedApp;
                            }

                            $apps[] = $formattedApp;
                        }
                    }
                } catch (BtcPayException $e) {
                    Log::warning('BTCPay apps listing failed for dashboard', [
                        'store_id' => $store->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with empty apps - fallback to local DB if needed
                    $localAppsFallback = App::where('store_id', $store->id)->get();
                    foreach ($localAppsFallback as $localApp) {
                        $appTypeRaw = $localApp->app_type;
                        $appType = strtolower($appTypeRaw);
                        $formattedApp = [
                            'id' => $localApp->id,
                            'name' => $localApp->name,
                            'app_type' => $appTypeRaw, // Keep original format
                            'btcpay_app_id' => $localApp->btcpay_app_id,
                        ];

                        if ($appType === 'crowdfund') {
                            $appsGrouped['crowdfund'][] = $formattedApp;
                        } elseif ($appType === 'pointofsale' || $appType === 'point_of_sale') {
                            $appsGrouped['point_of_sale'][] = $formattedApp;
                        } elseif ($appType === 'paymentbutton' || $appType === 'payment_button') {
                            $appsGrouped['payment_button'][] = $formattedApp;
                        }
                    }
                }

                // Determine store status (ready to accept payments if has wallet connection)
                $store->load('walletConnection');
                $hasWalletConnection = ($store->wallet_type ?? null) === 'cashu'
                    || $store->walletConnection !== null;
                $isReady = $hasWalletConnection;

                // Total revenue for this store (sats + default currency) - BTCPay + PoS, visible for all tiers
                $defaultCurrency = strtolower(trim($store->default_currency ?? 'EUR'));
                $byCurrency = [];
                try {
                    $byCurrency = $this->storeInvoiceStatsService->getTotalRevenueByCurrency($store);
                } catch (\Throwable) {
                    $byCurrency = ['sats' => 0];
                }
                $posOrders = PosOrder::where('store_id', $store->id)
                    ->where('status', PosOrder::STATUS_PAID)
                    ->get(['amount', 'currency', 'btcpay_invoice_id', 'paid_method']);
                foreach ($posOrders as $order) {
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
                        $invoiceId = $order->btcpay_invoice_id ?? '';
                        $paidMethod = $order->paid_method ?? '';
                        if ($invoiceId !== '' && in_array($paidMethod, [PosOrder::PAID_METHOD_LIGHTNING, PosOrder::PAID_METHOD_ONCHAIN], true)) {
                            $byCurrency['sats'] = ($byCurrency['sats'] ?? 0) + $this->storeInvoiceStatsService->getReceivedSatsForBtcPayInvoiceId($store, $invoiceId);
                        }
                    }
                }
                $totalRevenueSats = (int) ($byCurrency['sats'] ?? 0);
                $totalRevenueDefault = round((float) ($byCurrency[$defaultCurrency] ?? 0), 2);

                return [
                    'paid_invoices_last_7d' => $paidInvoicesLast7d,
                    'total_invoices' => $totalInvoices,
                    'recent_invoices' => $formattedRecentInvoices,
                    'apps' => $appsGrouped,
                    'is_ready' => $isReady,
                    'has_wallet_connection' => $hasWalletConnection,
                    'sales' => [
                        'last_7_days' => array_values($salesLast7Days),
                        'last_30_days' => array_values($salesLast30Days),
                        'total_7d' => $totalSales7d,
                        'total_30d' => $totalSales30d,
                    ],
                    'top_items' => $topItems,
                    'by_source' => $bySource,
                    'total_revenue_sats' => $totalRevenueSats,
                    'total_revenue_default_currency' => $totalRevenueDefault,
                    'default_currency' => $defaultCurrency,
                ];
            } catch (BtcPayException $e) {
                Log::error('BTCPay API error when loading dashboard', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'paid_invoices_last_7d' => 0,
                    'total_invoices' => 0,
                    'recent_invoices' => [],
                    'apps' => ['crowdfund' => [], 'point_of_sale' => [], 'payment_button' => []],
                    'is_ready' => false,
                    'has_wallet_connection' => false,
                    'sales' => ['last_7_days' => [], 'last_30_days' => [], 'total_7d' => 0, 'total_30d' => 0],
                    'top_items' => [],
                    'by_source' => array_fill_keys(InvoiceSourceService::SOURCES, [
                        'paid_invoices_last_7d' => 0,
                        'total_invoices' => 0,
                        'sales' => ['last_7_days' => [], 'last_30_days' => [], 'total_7d' => 0, 'total_30d' => 0],
                        'top_items' => [],
                        'recent_invoices' => [],
                    ]),
                    'total_revenue_sats' => 0,
                    'total_revenue_default_currency' => 0,
                    'default_currency' => strtolower(trim($store->default_currency ?? 'EUR')),
                    'error' => 'Failed to load dashboard data',
                ];
            }
        });

        // Pro-only: filter by payment method when ?source= is set
        $canFilterBySource = $this->subscriptionService->canViewAdvancedStats($user);
        $sourceFilter = $request->query('source');
        if ($canFilterBySource && $sourceFilter && $sourceFilter !== 'all' && isset($payload['by_source'][$sourceFilter])) {
            $src = $payload['by_source'][$sourceFilter];
            $payload['paid_invoices_last_7d'] = $src['paid_invoices_last_7d'] ?? 0;
            $payload['total_invoices'] = $src['total_invoices'] ?? 0;
            $payload['sales'] = $src['sales'] ?? ['last_7_days' => [], 'last_30_days' => [], 'total_7d' => 0, 'total_30d' => 0];
            $payload['top_items'] = $src['top_items'] ?? [];
            $payload['recent_invoices'] = $src['recent_invoices'] ?? [];
        }
        $payload['can_filter_by_source'] = $canFilterBySource;

        return response()->json(['data' => $payload]);
    }

    /**
     * Fetch all invoices for a store with pagination (avoids 1000-invoice cap).
     */
    private function fetchAllInvoicesForStore(string $btcpayStoreId, string $apiKey): array
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

    /** @return array<string, array{date: string, count: int}> */
    private function buildSalesDaysArray(int $days): array
    {
        $arr = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $arr[$date->format('Y-m-d')] = ['date' => $date->format('M j'), 'count' => 0];
        }

        return $arr;
    }

    private function getItemNameFromInvoice(array $invoice): ?string
    {
        if (isset($invoice['itemCode'])) {
            return $invoice['itemCode'];
        }
        $meta = $invoice['metadata'] ?? [];
        if (isset($meta['posData'])) {
            $posData = is_string($meta['posData']) ? json_decode($meta['posData'], true) : $meta['posData'];
            if (is_array($posData) && isset($posData['itemCode'])) {
                return $posData['itemCode'];
            }
        }

        return $meta['itemName'] ?? null;
    }

    /**
     * Aggregate a list of paid invoices into dashboard stats (for one source).
     *
     * @param  array<int, array>  $invoices
     */
    private function aggregateInvoicesBySource(array $invoices): array
    {
        $sevenDaysAgo = now()->subDays(7)->toIso8601String();
        $paid7d = 0;
        foreach ($invoices as $inv) {
            $createdTime = $inv['createdTime'] ?? null;
            $ts = is_numeric($createdTime) ? (int) $createdTime : strtotime($createdTime);
            if ($ts && $ts >= strtotime($sevenDaysAgo)) {
                $paid7d++;
            }
        }
        $sales7 = $this->buildSalesDaysArray(7);
        $sales30 = $this->buildSalesDaysArray(30);
        $total7d = 0;
        $total30d = 0;
        foreach ($invoices as $inv) {
            $createdTime = $inv['createdTime'] ?? null;
            if (! $createdTime) {
                continue;
            }
            $invoiceDate = Carbon::parse($createdTime)->startOfDay();
            $dateKey = $invoiceDate->format('Y-m-d');
            if (isset($sales7[$dateKey])) {
                $sales7[$dateKey]['count']++;
                $total7d++;
            }
            if (isset($sales30[$dateKey])) {
                $sales30[$dateKey]['count']++;
                $total30d++;
            }
        }
        $itemCounts = [];
        foreach ($invoices as $inv) {
            $itemName = $this->getItemNameFromInvoice($inv);
            if ($itemName) {
                $amount = (float) ($inv['amount'] ?? 0);
                $currency = $inv['currency'] ?? 'EUR';
                $itemCounts[$itemName] = $itemCounts[$itemName] ?? ['count' => 0, 'total' => 0, 'currency' => $currency];
                $itemCounts[$itemName]['count']++;
                $itemCounts[$itemName]['total'] += $amount;
            }
        }
        uasort($itemCounts, fn ($a, $b) => $b['count'] <=> $a['count']);
        $topItems = array_slice(array_map(function ($name, $data) {
            return ['name' => $name, 'count' => $data['count'], 'total' => $data['total'], 'currency' => $data['currency']];
        }, array_keys($itemCounts), $itemCounts), 0, 5);
        $recentForSource = [];
        foreach (array_slice($invoices, 0, 10) as $inv) {
            $recentForSource[] = [
                'id' => $inv['id'] ?? null,
                'invoice_id' => $inv['id'] ?? null,
                'status' => $inv['status'] ?? null,
                'amount' => $inv['amount'] ?? null,
                'currency' => $inv['currency'] ?? null,
                'created_time' => $inv['createdTime'] ?? null,
                'source' => $this->invoiceSourceService->detectSource($inv),
            ];
        }

        return [
            'paid_invoices_last_7d' => $paid7d,
            'total_invoices' => count($invoices),
            'sales' => [
                'last_7_days' => array_values($sales7),
                'last_30_days' => array_values($sales30),
                'total_7d' => $total7d,
                'total_30d' => $total30d,
            ],
            'top_items' => $topItems,
            'recent_invoices' => array_slice($recentForSource, 0, 10),
        ];
    }
}
