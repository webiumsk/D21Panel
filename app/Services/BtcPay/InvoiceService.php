<?php

namespace App\Services\BtcPay;

use Illuminate\Support\Facades\Cache;

class InvoiceService
{
    protected BtcPayClient $client;

    public function __construct(BtcPayClient $client)
    {
        $this->client = $client;
    }

    /**
     * List invoices with pagination support.
     * If a user-level API key is provided, it will be used instead of server-level.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  array  $filters  Optional filters (status, orderId, itemCode, etc.)
     * @param  int|null  $skip  Number of records to skip
     * @param  int|null  $take  Number of records to take
     * @param  string|null  $userApiKey  User-level API key (optional)
     * @return array Invoice list with pagination metadata
     */
    /** Archive (soft-delete) a BTCPay invoice - used for the payee-attestation canary. */
    public function archiveInvoice(string $storeId, string $invoiceId, ?string $userApiKey = null): void
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            $this->client->delete("/api/v1/stores/{$storeId}/invoices/{$invoiceId}");
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }

    /**
     * Create a BTCPay store invoice (Greenfield).
     *
     * @param  array{amount: string, currency: string, metadata?: array<string, mixed>}  $payload
     */
    public function createInvoice(string $storeId, array $payload, ?string $userApiKey = null): array
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            return $this->client->post("/api/v1/stores/{$storeId}/invoices", $payload);
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }

    public function listInvoices(string $storeId, array $filters = [], ?int $skip = null, ?int $take = null, ?string $userApiKey = null): array
    {
        $query = $filters;

        if ($skip !== null) {
            $query['skip'] = $skip;
        }

        if ($take !== null) {
            $query['take'] = $take;
        }

        $originalApiKey = null;
        if ($userApiKey) {
            // Temporarily use user-level API key
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            return $this->client->get("/api/v1/stores/{$storeId}/invoices", $query);
        } finally {
            // Restore original API key if we changed it
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }

    /**
     * Get a single invoice by ID.
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    /**
     * Drop cached invoice so webhook / rule processing sees current BTCPay state.
     */
    public function forgetInvoiceCache(string $storeId, string $invoiceId, ?string $userApiKey = null): void
    {
        $apiKeyHash = $userApiKey ? hash('sha256', $userApiKey) : 'server';
        Cache::forget("btcpay:invoice:{$storeId}:{$invoiceId}:{$apiKeyHash}");
        // The payment list changes with every settlement: drop it together
        // with the invoice, otherwise a webhook-driven resync reads stale payments.
        Cache::forget("btcpay:invoice:payment_methods:{$storeId}:{$invoiceId}:{$apiKeyHash}");
    }

    public function getInvoice(string $storeId, string $invoiceId, ?string $userApiKey = null): array
    {
        // Include API key hash in cache key to prevent cross-merchant cache pollution
        $apiKeyHash = $userApiKey ? hash('sha256', $userApiKey) : 'server';
        $cacheKey = "btcpay:invoice:{$storeId}:{$invoiceId}:{$apiKeyHash}";

        return Cache::remember($cacheKey, 3600, function () use ($storeId, $invoiceId, $userApiKey) {
            $originalApiKey = null;
            if ($userApiKey) {
                // Temporarily use user-level API key
                $originalApiKey = $this->client->getApiKey();
                $this->client->setApiKey($userApiKey);
            }

            try {
                return $this->client->get("/api/v1/stores/{$storeId}/invoices/{$invoiceId}");
            } finally {
                // Restore original API key if we changed it
                if ($userApiKey && $originalApiKey) {
                    $this->client->setApiKey($originalApiKey);
                }
            }
        });
    }

    /**
     * Get invoice count for a store (cached).
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function getInvoiceCount(string $storeId, array $filters = [], ?string $userApiKey = null): int
    {
        // Include API key hash in cache key to prevent cross-merchant cache pollution
        $apiKeyHash = $userApiKey ? hash('sha256', $userApiKey) : 'server';
        $cacheKey = "btcpay:invoice:count:{$storeId}:{$apiKeyHash}:".hash('sha256', serialize($filters));

        return Cache::remember($cacheKey, 3600, function () use ($storeId, $filters, $userApiKey) {
            // BTCPay API doesn't have a direct count endpoint, so we fetch a small page
            $result = $this->listInvoices($storeId, $filters, 0, 1, $userApiKey);

            // Note: BTCPay API may return total count in response, adjust based on actual API response
            return count($result) ?? 0;
        });
    }

    /**
     * Get payment methods for an invoice (actual crypto received per method).
     * Used to compute real sats received for invoices denominated in fiat (EUR/USD).
     * Cached 5 min.
     *
     * @return array Array of payment method data (paymentMethod, receivedAmount, payments, etc.)
     */
    public function getInvoicePaymentMethods(string $storeId, string $invoiceId, ?string $userApiKey = null): array
    {
        $apiKeyHash = $userApiKey ? hash('sha256', $userApiKey) : 'server';
        $cacheKey = "btcpay:invoice:payment_methods:{$storeId}:{$invoiceId}:{$apiKeyHash}";

        return Cache::remember($cacheKey, 3600, function () use ($storeId, $invoiceId, $userApiKey) {
            $originalApiKey = null;
            if ($userApiKey) {
                $originalApiKey = $this->client->getApiKey();
                $this->client->setApiKey($userApiKey);
            }

            try {
                $result = $this->client->get("/api/v1/stores/{$storeId}/invoices/{$invoiceId}/payment-methods");

                return is_array($result) ? $result : [];
            } finally {
                if ($userApiKey && $originalApiKey) {
                    $this->client->setApiKey($originalApiKey);
                }
            }
        });
    }

    /**
     * Get the payment rate (exchange rate vs fiat at time of payment) for an invoice.
     * BTCPay Greenfield payment-methods return "rate" (e.g. 1 BTC = 64392.23 USD).
     * Returns rate from the first payment method that received payment; null if unavailable.
     */
    public function getPaymentRateForInvoice(string $storeId, string $invoiceId, ?string $userApiKey = null): ?string
    {
        $methods = $this->getInvoicePaymentMethods($storeId, $invoiceId, $userApiKey);
        foreach ($methods as $pm) {
            $paid = null;
            if (isset($pm['paymentMethodPaid']) && (is_numeric($pm['paymentMethodPaid']) || is_string($pm['paymentMethodPaid']))) {
                $paid = (float) $pm['paymentMethodPaid'];
            }
            if ($paid === null && isset($pm['totalPaid']) && (is_numeric($pm['totalPaid']) || is_string($pm['totalPaid']))) {
                $paid = (float) $pm['totalPaid'];
            }
            if (($paid !== null && $paid > 0) || ! empty($pm['payments'] ?? [])) {
                $rate = $pm['rate'] ?? null;
                if ($rate !== null && $rate !== '') {
                    return (string) $rate;
                }
            }
        }

        return null;
    }

    /**
     * Get received amount in satoshis for an invoice.
     * For SATS: uses amount directly. For BTC: amount × 10⁸.
     * For fiat (EUR, USD, etc.): fetches payment-methods and sums actual BTC received.
     */
    public function getReceivedSatsForInvoice(string $storeId, array $invoice, ?string $userApiKey = null): ?int
    {
        $currency = strtoupper(trim((string) ($invoice['currency'] ?? '')));
        $amount = (float) ($invoice['amount'] ?? 0);

        if ($currency === 'SATS') {
            return $amount > 0 ? (int) round($amount) : null;
        }
        if ($currency === 'BTC') {
            return $amount > 0 ? (int) round($amount * 100_000_000) : null;
        }

        $invoiceId = $invoice['id'] ?? '';
        if ($invoiceId === '') {
            return null;
        }
        $methods = $this->getInvoicePaymentMethods($storeId, $invoiceId, $userApiKey);
        $sats = self::sumReceivedSatsFromPaymentMethods($methods);

        return $sats > 0 ? $sats : null;
    }

    /**
     * Extract total received sats from invoice payment methods (BTC/Lightning only).
     * BTCPay Greenfield returns: paymentMethodId (e.g. BTC-CHAIN, BTC-LN), currency, paymentMethodPaid, totalPaid, payments[].
     *
     * @param  array  $paymentMethods  Result of getInvoicePaymentMethods()
     * @return int Total sats received
     */
    public static function sumReceivedSatsFromPaymentMethods(array $paymentMethods): int
    {
        $sats = 0;
        foreach ($paymentMethods as $pm) {
            $methodId = (string) ($pm['paymentMethodId'] ?? $pm['paymentMethod'] ?? '');
            $currency = strtoupper((string) ($pm['currency'] ?? ''));
            if ($currency !== 'BTC' && stripos($methodId, 'BTC') === false) {
                continue;
            }
            $amount = null;
            if (isset($pm['paymentMethodPaid']) && (is_numeric($pm['paymentMethodPaid']) || is_string($pm['paymentMethodPaid']))) {
                $amount = (float) $pm['paymentMethodPaid'];
            }
            if ($amount === null && isset($pm['totalPaid']) && (is_numeric($pm['totalPaid']) || is_string($pm['totalPaid']))) {
                $amount = (float) $pm['totalPaid'];
            }
            if ($amount === null && isset($pm['receivedAmount']) && is_numeric($pm['receivedAmount'])) {
                $amount = (float) $pm['receivedAmount'];
            }
            if ($amount !== null && $amount > 0) {
                $sats += self::btcAmountToSats($amount);

                continue;
            }
            $payments = $pm['payments'] ?? [];
            if (is_array($payments)) {
                foreach ($payments as $p) {
                    if (isset($p['value']) && (is_numeric($p['value']) || is_string($p['value']))) {
                        $v = (float) $p['value'];
                        if ($v > 0) {
                            $sats += self::btcAmountToSats($v);
                        }
                    }
                }
            }
        }

        return $sats;
    }

    /**
     * Convert BTCPay amount to sats. API may return in BTC (e.g. 0.00005) or in sats (e.g. 5000).
     */
    public static function btcAmountToSats(float $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }
        if ($amount < 0.00000001) {
            return 0;
        }
        if ($amount < 1) {
            return (int) round($amount * 100_000_000);
        }

        return (int) round($amount);
    }

    /**
     * Estimate invoice count for export decision.
     * This is a quick check to determine if export should be synchronous or asynchronous.
     * Fetches a larger sample (1000 invoices) to get a better estimate.
     * If a user-level API key is provided, it will be used instead of server-level.
     */
    public function estimateInvoiceCount(string $storeId, array $filters = [], ?string $userApiKey = null): int
    {
        // Fetch up to 1000 invoices to get accurate count for decision
        // We use a larger take value to get a better estimate
        $result = $this->listInvoices($storeId, $filters, 0, 1000, $userApiKey);

        // BTCPay API returns invoices in the data array or directly
        $invoices = $result['data'] ?? $result;

        if (! is_array($invoices)) {
            return 0;
        }

        $count = count($invoices);

        // If we got exactly 1000, there might be more, so return 1001 to trigger async
        // Otherwise return the actual count
        return $count >= 1000 ? 1001 : $count;
    }
}
