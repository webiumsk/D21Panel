<?php

namespace App\Jobs;

use App\Models\Export;
use App\Notifications\ExportReadyNotification;
use App\Notifications\MonthlyExportReadyNotification;
use App\Services\BtcPay\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GenerateXlsxExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const GENERIC_FAILURE_MESSAGE = 'Export generation failed. Please try again or contact support.';

    /** Maximum number of seconds the job may run (worker --timeout must be >= this). */
    public int $timeout = 600;

    /** Transient BTCPay/storage failures are retried; permanent ones return early. */
    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(
        protected Export $export
    ) {
        $this->onQueue('exports');
    }

    /** Runs after the last attempt - only then surface the failure to the user. */
    public function failed(\Throwable $exception): void
    {
        $this->export->markAsFailed(self::GENERIC_FAILURE_MESSAGE);
    }

    public function handle(InvoiceService $invoiceService): void
    {
        $this->export->markAsRunning();

        try {
            $store = $this->export->store;
            $filters = $this->export->filters ?? [];

            $store->load('user');

            try {
                $store->user->getBtcPayApiKeyOrFail();
            } catch (HttpResponseException|HttpException $e) {
                $this->export->markAsFailed('BTCPay API key not configured. Please contact support.');

                return;
            }

            $btcpayFilters = $this->buildBtcpayFilters($filters);

            $disk = Storage::disk('exports');
            $filePath = $this->export->id.'_'.time().'.xlsx';
            $fullPath = $disk->path($filePath);

            $directory = dirname($fullPath);
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            if (! is_writable($directory)) {
                @chmod($directory, 0775);
            }
            if (! is_writable($directory)) {
                throw new \RuntimeException("Export directory is not writable: {$directory}");
            }

            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Invoices');

            $headers = [
                'invoiceId', 'store', 'pos', 'createdTime', 'status', 'amount', 'currency',
                'paidAmount', 'paidSats', 'paymentRate', 'tax', 'tip', 'discount', 'paymentMethod', 'buyerEmail', 'orderId', 'checkoutLink',
            ];
            $sheet->fromArray($headers, null, 'A1');

            $userApiKey = $store->user->getBtcPayApiKeyOrFail();
            $skip = 0;
            $take = 100;
            $row = 2;

            do {
                $response = $invoiceService->listInvoices(
                    $store->btcpay_store_id,
                    $btcpayFilters,
                    $skip,
                    $take,
                    $userApiKey
                );
                $invoices = $response['data'] ?? $response;
                if (! is_array($invoices)) {
                    $invoices = [];
                }

                foreach ($invoices as $invoice) {
                    $posData = $this->parsePosData($invoice['metadata'] ?? []);
                    $paymentMethods = isset($invoice['availablePaymentMethods']) && is_array($invoice['availablePaymentMethods'])
                        ? implode(',', $invoice['availablePaymentMethods']) : '';

                    $paidSats = '';
                    $paymentRate = '';
                    if (in_array($invoice['status'] ?? '', ['Settled', 'Complete'], true)) {
                        $sats = $invoiceService->getReceivedSatsForInvoice($store->btcpay_store_id, $invoice, $userApiKey);
                        $paidSats = $sats !== null ? (string) $sats : '';
                        $rate = $invoiceService->getPaymentRateForInvoice(
                            $store->btcpay_store_id,
                            $invoice['id'] ?? '',
                            $userApiKey
                        );
                        $paymentRate = $rate ?? '';
                    }

                    $sheet->fromArray([
                        $invoice['id'] ?? '',
                        $store->name ?? '',
                        $posData['pos'],
                        $this->formatCreatedTimeEu($invoice['createdTime'] ?? null),
                        $invoice['status'] ?? '',
                        $invoice['amount'] ?? '',
                        $invoice['currency'] ?? '',
                        $invoice['paidAmount'] ?? '',
                        $paidSats,
                        $paymentRate,
                        $posData['tax'],
                        $posData['tip'],
                        $posData['discount'],
                        $paymentMethods,
                        $invoice['buyer']['buyerEmail'] ?? '',
                        $invoice['metadata']['orderId'] ?? '',
                        $invoice['checkoutLink'] ?? '',
                    ], null, 'A'.$row);
                    $row++;
                }

                $skip += $take;
            } while (count($invoices) === $take);

            $writer = new Xlsx($spreadsheet);
            $writer->save($fullPath);

            $ttl = (int) env('EXPORT_SIGNED_URL_TTL', 3600);
            $signedUrl = $disk->temporaryUrl(
                $filePath,
                now()->addSeconds($ttl)
            );

            $this->export->markAsFinished(
                $filePath,
                $signedUrl,
                now()->addSeconds($ttl)
            );

            $user = $this->export->user;
            $store = $this->export->store;
            if ($this->export->source === Export::SOURCE_AUTOMATIC) {
                $dateFrom = $filters['date_from'] ?? null;
                $monthLabel = $dateFrom
                    ? (new \DateTime($dateFrom))->format('F Y')
                    : now()->subMonth()->format('F Y');
                $email = $store->auto_report_email ?: $user->email;
                if ($email) {
                    Notification::route('mail', $email)
                        ->notify(new MonthlyExportReadyNotification($this->export, $store, $monthLabel));
                }
            } elseif ($this->export->source === Export::SOURCE_MANUAL && $user->email) {
                $label = date('Y-m-d_His');
                Notification::route('mail', $user->email)
                    ->notify(new ExportReadyNotification($this->export, $store, $label));
            }
        } catch (\Exception $e) {
            Log::error('XLSX export generation failed', [
                'export_id' => $this->export->id,
                'store_id' => $this->export->store_id,
                'user_id' => $this->export->user_id,
                'attempt' => $this->attempts(),
                'exception' => $e,
            ]);

            // Rethrow so the queue retries; failed() marks the export after the last attempt.
            throw $e;
        }
    }

    protected function buildBtcpayFilters(array $filters): array
    {
        $btcpayFilters = [];
        if (isset($filters['status'])) {
            $btcpayFilters['status'] = $filters['status'];
        }
        if (isset($filters['date_from']) && $filters['date_from']) {
            $dateFrom = strtotime($filters['date_from']);
            if ($dateFrom !== false) {
                $btcpayFilters['startDate'] = $dateFrom;
            }
        }
        if (isset($filters['date_to']) && $filters['date_to']) {
            $dateTo = strtotime($filters['date_to'].' 23:59:59');
            if ($dateTo !== false) {
                $btcpayFilters['endDate'] = $dateTo;
            }
        }

        return $btcpayFilters;
    }

    protected function parsePosData(array $metadata): array
    {
        $posData = $metadata['posData'] ?? $metadata['pos'] ?? $metadata['posId'] ?? null;
        if ($posData === null || $posData === '') {
            return ['pos' => '', 'tax' => '', 'tip' => '', 'discount' => ''];
        }
        $data = is_string($posData) ? json_decode($posData, true) : $posData;
        if (! is_array($data)) {
            return ['pos' => '', 'tax' => '', 'tip' => '', 'discount' => ''];
        }
        $posLabel = '';
        if (isset($data['WooCommerce']) || isset($data['Magento']) || isset($data['PrestaShop']) || isset($data['OpenCart']) || isset($data['Shopify'])) {
            $posLabel = 'Eshop';
        } elseif (isset($data['tax']) || isset($data['tip']) || array_key_exists('cart', $data)) {
            $posLabel = 'PoS';
        }
        $tax = isset($data['tax']) ? (string) $data['tax'] : '';
        $tip = isset($data['tip']) ? (string) $data['tip'] : '';
        $discount = isset($data['discountAmount']) ? (string) $data['discountAmount'] : '';

        return ['pos' => $posLabel, 'tax' => $tax, 'tip' => $tip, 'discount' => $discount];
    }

    protected function formatCreatedTimeEu(mixed $createdTime): string
    {
        if ($createdTime === null || $createdTime === '') {
            return '';
        }
        $ts = is_numeric($createdTime) ? (float) $createdTime : strtotime($createdTime);
        if ($ts === false) {
            return (string) $createdTime;
        }
        if ($ts > 10000000000) {
            $ts = $ts / 1000;
        }
        $date = \DateTime::createFromFormat('U', (string) (int) $ts);

        return $date ? $date->format('d.m.Y H:i') : (string) $createdTime;
    }
}
