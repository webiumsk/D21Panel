<?php

namespace App\Services\BtcPay;

use App\Services\BtcPay\Exceptions\BtcPayException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    protected BtcPayClient $client;

    public function __construct(BtcPayClient $client)
    {
        $this->client = $client;
    }

    /**
     * Create a plan checkout in BTCPay.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $offeringId  BTCPay offering ID
     * @param  string  $planId  BTCPay plan ID
     * @param  array  $options  Optional checkout options:
     *                          - successRedirectUrl (string): URL to redirect after successful checkout
     *                          - cancelRedirectUrl (string|null): URL to redirect if checkout cancelled
     *                          - newSubscriberEmail (string|null): Email for new subscriber (creates new subscriber)
     *                          - customerSelector (string|null): Email to find existing customer (customer must exist)
     *                          - isTrial (bool|null): Force trial checkout on/off; when omitted, enabled if plan trialDays > 0
     * @return array Checkout data with checkoutUrl, checkoutId, and optional expiresAt
     *
     * @throws BtcPayException
     */
    public function createPlanCheckout(
        string $storeId,
        string $offeringId,
        string $planId,
        array $options = []
    ): array {
        // Validate that plan and offering belong to the store
        // This is important for security - we don't want users to subscribe to plans from other stores
        // However, if API key lacks permissions, we'll skip validation and trust the config values
        $plan = null;
        try {
            // Verify offering belongs to store
            $offering = $this->client->get("/api/v1/stores/{$storeId}/offerings/{$offeringId}");
            if (! isset($offering['id']) || $offering['id'] !== $offeringId) {
                throw new BtcPayException('Offering not found or does not belong to this store', 404);
            }

            // Verify plan belongs to offering
            $plan = $this->client->get("/api/v1/stores/{$storeId}/offerings/{$offeringId}/plans/{$planId}");
            if (! isset($plan['id']) || $plan['id'] !== $planId) {
                throw new BtcPayException('Plan not found or does not belong to this offering', 404);
            }
        } catch (BtcPayException $e) {
            // If error is due to insufficient permissions (403), log warning but continue
            // We trust the config values - they should be set correctly by admin
            if ($e->getStatusCode() === 403) {
                Log::warning('Cannot validate plan/offering ownership - insufficient API permissions', [
                    'store_id' => $storeId,
                    'offering_id' => $offeringId,
                    'plan_id' => $planId,
                    'error' => $e->getMessage(),
                    'note' => 'Proceeding with checkout - trusting config values. Consider adding "btcpay.store.canviewofferings" permission to API key.',
                ]);
                // Continue with checkout - we trust the config values
            } elseif ($e->getStatusCode() === 404) {
                // 404 means plan/offering doesn't exist - this is a real error
                Log::error('Failed to validate plan/offering ownership', [
                    'store_id' => $storeId,
                    'offering_id' => $offeringId,
                    'plan_id' => $planId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            } else {
                // Other errors - log and throw
                Log::error('Failed to validate plan/offering ownership', [
                    'store_id' => $storeId,
                    'offering_id' => $offeringId,
                    'plan_id' => $planId,
                    'error' => $e->getMessage(),
                    'status_code' => $e->getStatusCode(),
                ]);
                throw $e;
            }
        }

        // Build checkout payload
        $payload = [
            'storeId' => $storeId,
            'offeringId' => $offeringId,
            'planId' => $planId,
        ];

        // Add success redirect URL (required)
        if (isset($options['successRedirectUrl'])) {
            $payload['successRedirectUrl'] = $options['successRedirectUrl'];
        } else {
            // Fallback to default from config
            $baseUrl = config('app.url');
            $payload['successRedirectUrl'] = config('services.btcpay.subscription_success_url', "{$baseUrl}/billing/success");
        }

        // Add cancel redirect URL if provided
        if (isset($options['cancelRedirectUrl'])) {
            $payload['cancelRedirectUrl'] = $options['cancelRedirectUrl'];
        } elseif (config('services.btcpay.subscription_cancel_url')) {
            $payload['cancelRedirectUrl'] = config('services.btcpay.subscription_cancel_url');
        }

        // Handle subscriber email - use newSubscriberEmail if provided, otherwise skip
        // Do NOT use customerSelector unless we're certain the customer exists
        if (isset($options['newSubscriberEmail']) && ! empty($options['newSubscriberEmail'])) {
            $payload['newSubscriberEmail'] = $options['newSubscriberEmail'];
        }

        if ($this->shouldEnableTrialForCheckout($plan, $options)) {
            $payload['isTrial'] = true;
        }

        try {
            Log::info('Creating plan checkout', [
                'store_id' => $storeId,
                'offering_id' => $offeringId,
                'plan_id' => $planId,
                'has_email' => isset($payload['newSubscriberEmail']),
                'is_trial' => $payload['isTrial'] ?? false,
            ]);

            $response = $this->client->post('/api/v1/plan-checkout', $payload);
            $result = $this->resolvePlanCheckoutResponse($response);

            Log::info('Plan checkout created successfully', [
                'checkout_id' => $result['checkoutId'],
                'store_id' => $storeId,
                'plan_id' => $planId,
            ]);

            return $result;
        } catch (BtcPayException $e) {
            Log::error('Failed to create plan checkout', [
                'store_id' => $storeId,
                'offering_id' => $offeringId,
                'plan_id' => $planId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Get plan checkout details by checkout ID.
     *
     * @param  string  $checkoutId  BTCPay plan checkout ID
     * @return array Checkout details including plan ID, subscription info, etc.
     *
     * @throws BtcPayException
     */
    public function getPlanCheckout(string $checkoutId): array
    {
        try {
            $response = $this->client->get("/api/v1/plan-checkout/{$checkoutId}");

            return $response;
        } catch (BtcPayException $e) {
            Log::error('Failed to get plan checkout details', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Proceed with a plan checkout session and create the BTCPay invoice when payment is due.
     *
     * @throws BtcPayException
     */
    public function processPlanCheckout(string $checkoutId, ?string $email = null): array
    {
        $endpoint = "/api/v1/plan-checkout/{$checkoutId}";
        if ($email) {
            $endpoint .= '?email='.rawurlencode($email);
        }

        try {
            Log::info('Processing plan checkout', [
                'checkout_id' => $checkoutId,
                'has_email' => $email !== null && $email !== '',
            ]);

            return $this->client->post($endpoint);
        } catch (BtcPayException $e) {
            Log::error('Failed to process plan checkout', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Get subscription details by subscription ID.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $subscriptionId  BTCPay subscription ID
     * @return array Subscription details including status, expiration, etc.
     *
     * @throws BtcPayException
     */
    public function getSubscription(string $storeId, string $subscriptionId): array
    {
        try {
            $response = $this->client->get("/api/v1/stores/{$storeId}/subscriptions/{$subscriptionId}");

            return $response;
        } catch (BtcPayException $e) {
            Log::error('Failed to get subscription details', [
                'store_id' => $storeId,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * List subscriptions for a store.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  array  $filters  Optional filters (status, etc.)
     * @return array List of subscriptions
     *
     * @throws BtcPayException
     */
    public function listSubscriptions(string $storeId, array $filters = []): array
    {
        try {
            $response = $this->client->get("/api/v1/stores/{$storeId}/subscriptions", $filters);

            return $response;
        } catch (BtcPayException $e) {
            Log::error('Failed to list subscriptions', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Get a subscriber by customer selector (email, customer ID, or identity).
     * BTCPay API doesn't support listing all subscribers, only getting individual ones.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $offeringId  BTCPay offering ID
     * @param  string  $customerSelector  Customer selector (email, customer ID, or Email:email@example.com)
     * @return array Subscriber/subscription details
     *
     * @throws BtcPayException
     */
    public function getSubscriber(string $storeId, string $offeringId, string $customerSelector): array
    {
        try {
            // URL encode the customer selector to handle email addresses and special characters
            $encodedSelector = rawurlencode($customerSelector);
            $response = $this->client->get("/api/v1/stores/{$storeId}/offerings/{$offeringId}/subscribers/{$encodedSelector}");

            return $response;
        } catch (BtcPayException $e) {
            Log::error('Failed to get subscriber', [
                'store_id' => $storeId,
                'offering_id' => $offeringId,
                'customer_selector' => $customerSelector,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Get subscriber credit balance.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $offeringId  BTCPay offering ID
     * @param  string  $customerSelector  Customer selector (email, customer ID, or Email:email@example.com)
     * @param  string  $currency  Currency code (e.g., "SATS", "BTC")
     * @return array Credit balance information
     *
     * @throws BtcPayException
     */
    public function getSubscriberCredits(string $storeId, string $offeringId, string $customerSelector, string $currency = 'SATS'): array
    {
        try {
            $encodedSelector = rawurlencode($customerSelector);
            $encodedCurrency = rawurlencode($currency);
            $response = $this->client->get("/api/v1/stores/{$storeId}/offerings/{$offeringId}/subscribers/{$encodedSelector}/credits/{$encodedCurrency}");

            return $response;
        } catch (BtcPayException $e) {
            Log::error('Failed to get subscriber credits', [
                'store_id' => $storeId,
                'offering_id' => $offeringId,
                'customer_selector' => $customerSelector,
                'currency' => $currency,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Parse subscriber credit balance from BTCPay credits response.
     */
    public function parseSubscriberCreditBalance(array $credits): int
    {
        $raw = $credits['balance']
            ?? $credits['amount']
            ?? $credits['value']
            ?? 0;

        return (int) round((float) $raw);
    }

    /**
     * Fetch credit transaction history when BTCPay exposes it.
     *
     * @return array<int, array{date: string, description: string, amount: int, balance: int|null}>
     */
    public function getSubscriberCreditHistory(
        string $storeId,
        string $offeringId,
        string $customerSelector,
        string $currency = 'SATS'
    ): array {
        $encodedSelector = rawurlencode($customerSelector);
        $encodedCurrency = rawurlencode($currency);
        $historyPath = "/api/v1/stores/{$storeId}/offerings/{$offeringId}/subscribers/{$encodedSelector}/credits/{$encodedCurrency}/history";

        try {
            $response = $this->client->get($historyPath);

            return $this->normalizeCreditHistoryPayload($response);
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() !== 404) {
                Log::debug('Could not fetch subscriber credit history from BTCPay', [
                    'customer_selector' => $customerSelector,
                    'error' => $e->getMessage(),
                    'status_code' => $e->getStatusCode(),
                ]);
            }
        }

        try {
            $credits = $this->getSubscriberCredits($storeId, $offeringId, $customerSelector, $currency);
            foreach (['history', 'transactions', 'entries'] as $key) {
                if (! empty($credits[$key]) && is_array($credits[$key])) {
                    return $this->normalizeCreditHistoryPayload($credits[$key]);
                }
            }
        } catch (BtcPayException $e) {
            Log::debug('Could not parse subscriber credit history from credits response', [
                'customer_selector' => $customerSelector,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $payload
     * @return array<int, array{date: string, description: string, amount: int, balance: int|null}>
     */
    public function normalizeCreditHistoryPayload(array $payload): array
    {
        $rows = array_is_list($payload)
            ? $payload
            : ($payload['history'] ?? $payload['transactions'] ?? $payload['entries'] ?? $payload['data'] ?? []);

        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $credit = (float) ($row['credit'] ?? 0);
            $debit = (float) ($row['debit'] ?? 0);
            $amount = isset($row['amount'])
                ? (float) $row['amount']
                : ($credit - $debit);

            if ($amount === 0.0 && ! isset($row['amount'])) {
                continue;
            }

            $timestamp = $row['createdAt']
                ?? $row['created_at']
                ?? $row['date']
                ?? $row['timestamp']
                ?? null;

            $normalized[] = [
                'date' => $this->normalizeCreditHistoryDate($timestamp),
                'description' => (string) ($row['description'] ?? 'Credit adjustment'),
                'amount' => (int) round($amount),
                'balance' => isset($row['balance']) || isset($row['totalBalance'])
                    ? (int) round((float) ($row['balance'] ?? $row['totalBalance']))
                    : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $subscriber
     * @return array{
     *     phase: ?string,
     *     isTrial: bool,
     *     trialEndsAt: ?int,
     *     trialDaysRemaining: ?int,
     *     planPriceSats: int,
     *     creditAppliedSats: int,
     *     nextChargeSats: int,
     *     renewalDate: ?int,
     *     paymentReminderDays: int
     * }
     */
    public function buildSubscriptionBillingSummary(array $subscriber, int $creditBalance): array
    {
        $planPriceSats = (int) round((float) ($subscriber['plan']['price'] ?? 0));
        $creditAppliedSats = min(max(0, $creditBalance), max(0, $planPriceSats));
        $nextChargeSats = max(0, $planPriceSats - $creditAppliedSats);

        $phase = isset($subscriber['phase']) ? (string) $subscriber['phase'] : null;
        $trialEndsAt = $this->normalizeUnixTimestamp($subscriber['trialEnd'] ?? null);
        $renewalDate = $this->normalizeUnixTimestamp($subscriber['periodEnd'] ?? null);
        $isTrial = $phase === 'Trial' || ($trialEndsAt !== null && $trialEndsAt > time());

        $trialDaysRemaining = null;
        if ($isTrial && $trialEndsAt !== null) {
            $trialDaysRemaining = max(0, (int) ceil(($trialEndsAt - time()) / 86400));
        }

        return [
            'phase' => $phase,
            'isTrial' => $isTrial,
            'trialEndsAt' => $trialEndsAt,
            'trialDaysRemaining' => $trialDaysRemaining,
            'planPriceSats' => $planPriceSats,
            'creditAppliedSats' => $creditAppliedSats,
            'nextChargeSats' => $nextChargeSats,
            'renewalDate' => $renewalDate ?? $trialEndsAt,
            'paymentReminderDays' => (int) config('services.btcpay.subscription_payment_reminder_days', 3),
        ];
    }

    protected function normalizeCreditHistoryDate(mixed $timestamp): string
    {
        if (is_numeric($timestamp)) {
            return date('c', (int) $timestamp);
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return date('c', strtotime($timestamp) ?: time());
        }

        return now()->toIso8601String();
    }

    protected function normalizeUnixTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $parsed = strtotime((string) $value);

        return $parsed !== false ? $parsed : null;
    }

    /**
     * Create a plan checkout for purchasing subscriber credits (issues a BTCPay invoice).
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $offeringId  BTCPay offering ID
     * @param  string  $planId  Subscriber's current plan ID
     * @param  string  $customerSelector  Existing subscriber selector (email or customer ID)
     * @param  float|int|string  $amount  Credit amount to purchase (plan currency, e.g. SATS)
     * @param  array  $options  Optional:
     *                          - successRedirectUrl (string)
     *                          - cancelRedirectUrl (string|null)
     * @return array{checkoutId: string, checkoutUrl: string, invoiceId: ?string, invoiceUrl: ?string, expiresAt?: string}
     *
     * @throws BtcPayException
     */
    public function createCreditPurchaseCheckout(
        string $storeId,
        string $offeringId,
        string $planId,
        string $customerSelector,
        $amount,
        array $options = []
    ): array {
        $payload = [
            'storeId' => $storeId,
            'offeringId' => $offeringId,
            'planId' => $planId,
            'customerSelector' => $customerSelector,
            'creditPurchase' => (string) $amount,
            'isTrial' => false,
        ];

        if (isset($options['successRedirectUrl'])) {
            $payload['successRedirectUrl'] = $options['successRedirectUrl'];
        } else {
            $baseUrl = config('app.url');
            $payload['successRedirectUrl'] = config('services.btcpay.subscription_success_url', "{$baseUrl}/billing/success");
        }

        if (isset($options['cancelRedirectUrl'])) {
            $payload['cancelRedirectUrl'] = $options['cancelRedirectUrl'];
        } elseif (config('services.btcpay.subscription_cancel_url')) {
            $payload['cancelRedirectUrl'] = config('services.btcpay.subscription_cancel_url');
        }

        try {
            Log::info('Creating credit purchase checkout', [
                'store_id' => $storeId,
                'offering_id' => $offeringId,
                'plan_id' => $planId,
                'customer_selector' => $customerSelector,
                'amount' => $amount,
            ]);

            $response = $this->client->post('/api/v1/plan-checkout', $payload);
            $result = $this->resolvePlanCheckoutResponse($response);

            if (! $result['invoiceId']) {
                $processed = $this->processPlanCheckout($result['checkoutId'], $customerSelector);
                $result = $this->applyCheckoutDetailsToResult($result, $processed);
            }

            return $this->enrichCheckoutWithInvoicePaymentUrl($storeId, $result);
        } catch (BtcPayException $e) {
            Log::error('Failed to create credit purchase checkout', [
                'store_id' => $storeId,
                'offering_id' => $offeringId,
                'plan_id' => $planId,
                'customer_selector' => $customerSelector,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Merchant-side credit adjustment (no customer payment invoice).
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $offeringId  BTCPay offering ID
     * @param  string  $customerSelector  Customer selector (email, customer ID, or Email:email@example.com)
     * @param  string  $currency  Currency code (e.g., "SATS", "BTC")
     * @param  float|int|string  $amount  Amount to add
     * @return array Result of credit addition
     *
     * @throws BtcPayException
     */
    public function addSubscriberCredits(string $storeId, string $offeringId, string $customerSelector, string $currency, $amount, ?string $description = null): array
    {
        try {
            $encodedSelector = rawurlencode($customerSelector);
            $encodedCurrency = rawurlencode($currency);

            $payload = [
                'credit' => (string) $amount,
            ];

            if ($description) {
                $payload['description'] = $description;
            }

            $response = $this->client->post("/api/v1/stores/{$storeId}/offerings/{$offeringId}/subscribers/{$encodedSelector}/credits/{$encodedCurrency}", $payload);

            return $response;
        } catch (BtcPayException $e) {
            Log::error('Failed to add subscriber credits', [
                'store_id' => $storeId,
                'offering_id' => $offeringId,
                'customer_selector' => $customerSelector,
                'currency' => $currency,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{checkoutId: string, checkoutUrl: string, invoiceId: ?string, invoiceUrl: ?string, expiresAt?: string}
     */
    protected function resolvePlanCheckoutResponse(array $response): array
    {
        $checkoutId = $response['id'] ?? $response['checkoutId'] ?? null;
        $checkoutUrl = $response['url'] ?? $response['checkoutUrl'] ?? $response['redirectUrl'] ?? null;
        $invoiceId = $response['invoiceId'] ?? ($response['invoice']['id'] ?? null);
        $baseUrl = $response['baseUrl'] ?? config('services.btcpay.base_url');

        $invoiceUrl = null;
        if ($invoiceId && $baseUrl) {
            $invoiceUrl = rtrim((string) $baseUrl, '/').'/i/'.$invoiceId;
        }

        if (! $checkoutUrl && $invoiceUrl) {
            $checkoutUrl = $invoiceUrl;
        }

        if (! $checkoutId || ! $checkoutUrl) {
            Log::error('Invalid plan checkout response from BTCPay', [
                'response' => $response,
            ]);
            throw new BtcPayException('Invalid response from BTCPay: missing checkout URL', 500);
        }

        $result = [
            'checkoutId' => $checkoutId,
            'checkoutUrl' => $checkoutUrl,
            'invoiceId' => $invoiceId,
            'invoiceUrl' => $invoiceUrl,
        ];

        if (isset($response['expiration'])) {
            $result['expiresAt'] = is_numeric($response['expiration'])
                ? date('c', (int) $response['expiration'])
                : $response['expiration'];
        } elseif (isset($response['expiresAt'])) {
            $result['expiresAt'] = $response['expiresAt'];
        }

        return $result;
    }

    /**
     * Resolve a direct BTCPay invoice payment URL for a plan checkout (credit purchase).
     *
     * @param  array{checkoutId: string, checkoutUrl: string, invoiceId: ?string, invoiceUrl: ?string, expiresAt?: string}  $result
     * @return array{checkoutId: string, checkoutUrl: string, invoiceId: ?string, invoiceUrl: ?string, paymentUrl: ?string, expiresAt?: string}
     */
    /**
     * @param  array{checkoutId: string, checkoutUrl: string, invoiceId: ?string, invoiceUrl: ?string, expiresAt?: string}  $result
     * @param  array<string, mixed>  $details
     * @return array{checkoutId: string, checkoutUrl: string, invoiceId: ?string, invoiceUrl: ?string, expiresAt?: string}
     */
    protected function applyCheckoutDetailsToResult(array $result, array $details): array
    {
        $invoiceId = $this->extractInvoiceIdFromCheckoutDetails($details);
        if ($invoiceId) {
            $result['invoiceId'] = $invoiceId;
        }

        $redirectUrl = $details['redirectUrl'] ?? null;
        if (is_string($redirectUrl) && $redirectUrl !== '') {
            $result['checkoutUrl'] = $redirectUrl;
        }

        $checkoutUrl = $details['url'] ?? $details['checkoutUrl'] ?? null;
        if (is_string($checkoutUrl) && $checkoutUrl !== '') {
            $result['checkoutUrl'] = $checkoutUrl;
        }

        if (isset($details['expiration'])) {
            $result['expiresAt'] = is_numeric($details['expiration'])
                ? date('c', (int) $details['expiration'])
                : $details['expiration'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function extractInvoiceIdFromCheckoutDetails(array $details): ?string
    {
        $invoiceId = $details['invoiceId']
            ?? ($details['invoice']['id'] ?? null)
            ?? ($details['payment']['invoiceId'] ?? null)
            ?? ($details['subscriber']['processingInvoiceId'] ?? null);

        return is_string($invoiceId) && $invoiceId !== '' ? $invoiceId : null;
    }

    protected function enrichCheckoutWithInvoicePaymentUrl(string $storeId, array $result): array
    {
        $invoiceId = $result['invoiceId'] ?? null;

        if (! $invoiceId && ! empty($result['checkoutId'])) {
            try {
                $details = $this->getPlanCheckout($result['checkoutId']);
                $result = $this->applyCheckoutDetailsToResult($result, $details);
                $invoiceId = $result['invoiceId'] ?? null;
            } catch (BtcPayException $e) {
                Log::debug('Could not load plan checkout for invoice URL', [
                    'checkout_id' => $result['checkoutId'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $invoiceId) {
            Log::error('Credit purchase checkout completed without invoice', [
                'checkout_id' => $result['checkoutId'] ?? null,
            ]);
            throw new BtcPayException('Credit purchase invoice was not created by BTCPay', 500);
        }

        $paymentUrl = null;
        $invoiceService = app(InvoiceService::class);

        try {
            $invoice = $invoiceService->getInvoice($storeId, $invoiceId);
            $paymentUrl = $invoice['checkoutLink'] ?? null;
        } catch (BtcPayException $e) {
            Log::debug('Could not load BTCPay invoice for checkout link', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $paymentUrl) {
            $baseUrl = config('services.btcpay.base_url');
            if ($baseUrl) {
                $paymentUrl = rtrim((string) $baseUrl, '/').'/i/'.$invoiceId;
            }
        }

        if (! $paymentUrl) {
            throw new BtcPayException('Could not resolve invoice payment URL', 500);
        }

        $result['invoiceId'] = $invoiceId;
        $result['invoiceUrl'] = $paymentUrl;
        $result['paymentUrl'] = $paymentUrl;

        return $result;
    }

    /**
     * Map a BTCPay subscription plan ID to the local plan role name.
     */
    public function resolvePlanNameFromId(?string $planId): ?string
    {
        if (! $planId) {
            return null;
        }

        $subscriptionPlans = config('services.btcpay.subscription_plans', []);

        if ($planId === ($subscriptionPlans['pro'] ?? null)) {
            return 'pro';
        }

        if ($planId === ($subscriptionPlans['enterprise'] ?? null)) {
            return 'enterprise';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveTrialEndsAt(array $payload): Carbon
    {
        $subscriber = $payload['subscriber'] ?? $payload;
        $trialEnd = $this->normalizeUnixTimestamp($subscriber['trialEnd'] ?? null);

        if ($trialEnd !== null) {
            return Carbon::createFromTimestamp($trialEnd);
        }

        return now()->addDays((int) config('pricing.trial_days', 30));
    }

    public function subscriberIsInTrial(array $subscriber): bool
    {
        if (($subscriber['phase'] ?? null) === 'Trial') {
            return true;
        }

        $trialEnd = $this->normalizeUnixTimestamp($subscriber['trialEnd'] ?? null);

        return $trialEnd !== null && $trialEnd > time();
    }

    /**
     * True when the subscriber completed a trial checkout on BTCPay (active trial phase).
     *
     * @param  array<string, mixed>  $checkoutDetails
     */
    public function checkoutTrialWasActivated(array $checkoutDetails): bool
    {
        $subscriber = $checkoutDetails['subscriber'] ?? [];

        if (! ($subscriber['isActive'] ?? false)) {
            return false;
        }

        $phase = isset($subscriber['phase']) ? (string) $subscriber['phase'] : null;
        if ($phase === 'Trial') {
            return true;
        }

        $trialEnd = $this->normalizeUnixTimestamp($subscriber['trialEnd'] ?? null);

        return $trialEnd !== null && $trialEnd > time();
    }

    /**
     * Resolve the settled BTCPay invoice for a completed plan checkout (success redirect fallback).
     *
     * @param  array<string, mixed>  $checkoutDetails
     * @return array{id: string, payload: array<string, mixed>}|null
     */
    public function resolvePaidInvoiceFromCheckout(
        string $storeId,
        array $checkoutDetails,
        string $checkoutPlanId,
        ?string $customerEmail,
    ): ?array {
        $invoiceId = $checkoutDetails['invoiceId']
            ?? ($checkoutDetails['invoice']['id'] ?? null)
            ?? ($checkoutDetails['payment']['invoiceId'] ?? null);

        $invoiceService = app(InvoiceService::class);

        if (! $invoiceId) {
            foreach (array_filter([$checkoutPlanId, $customerEmail]) as $search) {
                try {
                    $result = $invoiceService->listInvoices($storeId, [
                        'textSearch' => $search,
                        'status' => 'Settled',
                    ], 0, 1);

                    $list = $result['data'] ?? $result;
                    if (is_array($list) && isset($list[0]['id'])) {
                        $invoiceId = $list[0]['id'];
                        break;
                    }
                } catch (BtcPayException $e) {
                    Log::debug('Subscription checkout invoice lookup failed', [
                        'checkout_id' => $checkoutPlanId,
                        'search' => $search,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (! $invoiceId) {
            return null;
        }

        try {
            $payload = $invoiceService->getInvoice($storeId, $invoiceId);
        } catch (BtcPayException $e) {
            Log::warning('Subscription checkout invoice fetch failed', [
                'checkout_id' => $checkoutPlanId,
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $status = (string) ($payload['status'] ?? '');
        if (strcasecmp($status, 'Settled') !== 0) {
            Log::info('Subscription checkout invoice is not settled', [
                'checkout_id' => $checkoutPlanId,
                'invoice_id' => $invoiceId,
                'status' => $status,
            ]);

            return null;
        }

        return [
            'id' => $invoiceId,
            'payload' => $payload,
        ];
    }

    /**
     * BTCPay only shows "Proceed to free trial" when plan trialDays > 0 AND isTrial is true on checkout.
     */
    private function shouldEnableTrialForCheckout(?array $plan, array $options): bool
    {
        if (array_key_exists('isTrial', $options)) {
            return (bool) $options['isTrial'];
        }

        if (is_array($plan)) {
            return $this->planTrialDays($plan) > 0;
        }

        return (int) config('pricing.trial_days', 0) > 0;
    }

    private function planTrialDays(array $plan): int
    {
        return (int) ($plan['trialDays'] ?? $plan['trial_days'] ?? 0);
    }
}
