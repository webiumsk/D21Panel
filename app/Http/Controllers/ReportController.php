<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\BtcPay\InvoiceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;

class ReportController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    /**
     * Generate and download a PDF report for the store.
     * Pro+ or admin/support only.
     */
    public function pdf(Request $request, Store $store)
    {
        $dateFrom = $request->input('date_from') ? Carbon::parse($request->date_from)->startOfDay() : now()->subMonth()->startOfDay();
        $dateTo = $request->input('date_to') ? Carbon::parse($request->date_to)->endOfDay() : now()->endOfDay();

        if ($dateFrom->gt($dateTo)) {
            return response()->json(['message' => 'Invalid date range'], 422);
        }

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $filters = [
            'startDate' => $dateFrom->timestamp,
            'endDate' => $dateTo->timestamp,
        ];

        $allInvoices = [];
        $skip = 0;
        $take = 250;

        do {
            $result = $this->invoiceService->listInvoices(
                $store->btcpay_store_id,
                $filters,
                $skip,
                $take,
                $userApiKey
            );
            $chunk = $result['data'] ?? $result;
            $allInvoices = array_merge($allInvoices, is_array($chunk) ? $chunk : []);
            $skip += $take;
            if (! is_array($chunk) || count($chunk) < $take) {
                break;
            }
        } while (true);

        $data = $this->aggregateReportData($allInvoices, $dateFrom, $dateTo, $store);

        $filename = sprintf(
            'report-%s-%s-%s.pdf',
            \Str::slug($store->name),
            $dateFrom->format('Y-m-d'),
            $dateTo->format('Y-m-d')
        );

        return Pdf::view('pdf.store-report', $data)
            ->format(Format::A4)
            ->name($filename);
    }

    protected function aggregateReportData(array $invoices, Carbon $dateFrom, Carbon $dateTo, Store $store): array
    {
        $salesByDay = [];
        $totalCount = 0;
        $totalAmount = 0;
        $currency = 'EUR';
        $itemCounts = [];

        for ($d = $dateFrom->copy(); $d->lte($dateTo); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $salesByDay[$key] = ['date' => $d->format('M j'), 'count' => 0, 'amount' => 0];
        }

        foreach ($invoices as $invoice) {
            $status = $invoice['status'] ?? null;
            if (! in_array($status, ['Settled', 'Complete'], true)) {
                continue;
            }

            $createdTime = $invoice['createdTime'] ?? null;
            if (! $createdTime) {
                continue;
            }

            $invoiceDate = Carbon::parse($createdTime)->startOfDay();
            $dateKey = $invoiceDate->format('Y-m-d');
            if (! isset($salesByDay[$dateKey])) {
                continue;
            }

            $amount = (float) ($invoice['amount'] ?? 0);
            $curr = $invoice['currency'] ?? 'EUR';
            $currency = $curr;

            $salesByDay[$dateKey]['count']++;
            $salesByDay[$dateKey]['amount'] += $amount;
            $totalCount++;
            $totalAmount += $amount;

            $itemName = $invoice['itemCode'] ?? null;
            if (! $itemName && isset($invoice['metadata']['posData']) && is_string($invoice['metadata']['posData'])) {
                $posData = json_decode($invoice['metadata']['posData'], true);
                $itemName = $posData['itemCode'] ?? null;
            }
            $itemName = $itemName ?? ($invoice['metadata']['itemName'] ?? 'Other');

            if (! isset($itemCounts[$itemName])) {
                $itemCounts[$itemName] = ['count' => 0, 'total' => 0, 'currency' => $curr];
            }
            $itemCounts[$itemName]['count']++;
            $itemCounts[$itemName]['total'] += $amount;
        }

        uasort($itemCounts, fn ($a, $b) => $b['count'] <=> $a['count']);
        $topItems = array_slice(array_map(function ($name, $d) {
            return ['name' => $name, 'count' => $d['count'], 'total' => $d['total'], 'currency' => $d['currency']];
        }, array_keys($itemCounts), $itemCounts), 0, 8);

        $maxCount = max(array_column($salesByDay, 'count') ?: [1]);

        return [
            'store' => $store,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'salesByDay' => array_values($salesByDay),
            'totalCount' => $totalCount,
            'totalAmount' => round($totalAmount, 2),
            'currency' => $currency,
            'topItems' => $topItems,
            'maxCount' => $maxCount > 0 ? $maxCount : 1,
        ];
    }
}
