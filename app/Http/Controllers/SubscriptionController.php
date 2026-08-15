<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\SubscriptionService as BtcPaySubscriptionService;
use App\Services\Invoicing\SubscriptionBillingInvoiceService;
use App\Services\SubscriptionCheckoutRegistry;
use App\Services\SubscriptionCreditLedgerService;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    protected BtcPaySubscriptionService $btcpaySubscriptionService;

    protected SubscriptionEntitlementService $subscriptionService;

    public function __construct(BtcPaySubscriptionService $btcpaySubscriptionService, SubscriptionEntitlementService $subscriptionService)
    {
        $this->btcpaySubscriptionService = $btcpaySubscriptionService;
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Create a plan checkout and return the checkout URL.
     *
     * POST /api/subscriptions/checkout
     * Body: { plan: 'pro'|'enterprise', customerEmail? }
     *
     * For custom integrations, can also use:
     * Body: { storeId, planId, offeringId, customerEmail? }
     */
    public function checkout(Request $request)
    {
        // Feature flag: allow non-authenticated users to checkout
        // For MVP, we require auth, but this can be made optional later
        $allowGuestCheckout = config('services.btcpay.allow_guest_subscriptions', false);

        if (! $allowGuestCheckout && ! $request->user()) {
            return response()->json([
                'message' => 'Authentication required to create checkout',
            ], 401);
        }

        $request->validate([
            'plan' => ['required_without_all:planId,storeId', 'string', 'in:pro,enterprise'],
            'storeId' => ['required_without:plan', 'string'],
            'planId' => ['required_without:plan', 'string'],
            'offeringId' => ['required_without:plan', 'string'],
            'customerEmail' => ['nullable', 'email', 'max:255'],
        ]);

        if ($blocked = $this->subscriptionBlockedForGuestResponse($request)) {
            return $blocked;
        }

        // If plan name is provided, use subscription store config
        if ($request->has('plan')) {
            $storeId = config('services.btcpay.subscription_store_id');
            $offeringId = config('services.btcpay.subscription_offering_id');
            $planId = config("services.btcpay.subscription_plans.{$request->input('plan')}");

            if (! $storeId || ! $offeringId || ! $planId) {
                return response()->json([
                    'message' => 'Subscription configuration is incomplete. Please contact support.',
                ], 500);
            }
        } else {
            // Use provided IDs (for custom integrations)
            $storeId = $request->input('storeId');
            $offeringId = $request->input('offeringId');
            $planId = $request->input('planId');
        }

        try {
            $options = [];

            // Add customer email if provided
            if ($request->filled('customerEmail')) {
                $options['newSubscriberEmail'] = $request->input('customerEmail');
            } elseif ($request->user() && $request->user()->email) {
                // Use authenticated user's email if available
                $options['newSubscriberEmail'] = $request->user()->email;
            }

            // Build success redirect URL with checkout ID
            // We'll include the checkout ID in the URL so we can track it
            $baseUrl = config('app.url');
            $successUrl = config('services.btcpay.subscription_success_url', "{$baseUrl}/billing/success");
            $options['successRedirectUrl'] = $successUrl;

            // Add cancel URL if configured
            if (config('services.btcpay.subscription_cancel_url')) {
                $options['cancelRedirectUrl'] = config('services.btcpay.subscription_cancel_url');
            }

            if ($request->user()?->hasConsumedTrial()) {
                $options['isTrial'] = false;
            }

            // Create checkout via BTCPay
            // Use BTCPay Store ID directly from config (no local Store record needed)
            $checkout = $this->btcpaySubscriptionService->createPlanCheckout(
                $storeId, // BTCPay Store ID from config
                $offeringId,
                $planId,
                $options
            );

            // Update success URL with checkout ID if needed
            if (strpos($options['successRedirectUrl'], '{checkout}') !== false) {
                $checkout['checkoutUrl'] = str_replace('{checkout}', $checkout['checkoutId'], $checkout['checkoutUrl']);
            }

            Log::info('Checkout created via API', [
                'checkout_id' => $checkout['checkoutId'],
                'store_id' => $storeId, // BTCPay Store ID
                'plan' => $request->input('plan'),
                'user_id' => $request->user()?->id,
            ]);

            if ($request->user() && $request->filled('plan')) {
                app(SubscriptionCheckoutRegistry::class)->bind(
                    $checkout['checkoutId'],
                    $request->user()->id,
                    (string) $request->input('plan'),
                );
            }

            // Return only safe data - never expose btcpay_store_id
            return response()->json([
                'checkoutUrl' => $checkout['checkoutUrl'],
                'checkoutId' => $checkout['checkoutId'],
                'expiresAt' => $checkout['expiresAt'] ?? null,
            ]);
        } catch (BtcPayException $e) {
            $statusCode = $e->getStatusCode() ?: 500;
            $errorMessage = $e->getMessage();

            Log::error('Failed to create subscription checkout', [
                'store_id' => $storeId ?? 'unknown',
                'plan' => $request->input('plan'),
                'plan_id' => $planId ?? $request->input('planId'),
                'offering_id' => $offeringId ?? $request->input('offeringId'),
                'error' => $errorMessage,
                'status_code' => $statusCode,
            ]);

            // Map BTCPay errors to appropriate HTTP status codes
            if ($statusCode === 404) {
                return response()->json([
                    'message' => 'Plan or offering not found',
                ], 422);
            }

            if ($statusCode === 422) {
                return response()->json([
                    'message' => $errorMessage,
                ], 422);
            }

            return response()->json([
                'message' => 'Failed to create checkout. Please try again later.',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error creating subscription checkout', [
                'store_id' => $storeId ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again later.',
            ], 500);
        }
    }

    /**
     * Handle subscription success redirect from BTCPay.
     *
     * GET /api/subscriptions/success?checkoutPlanId=...
     *
     * Syncs subscription state after redirect only when payment is settled on BTCPay
     * or a trial was started. Primary activation remains the HMAC-verified webhook.
     */
    public function success(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $checkoutPlanId = $request->query('checkoutPlanId');

        if (! $checkoutPlanId) {
            return response()->json([
                'message' => 'Missing checkoutPlanId parameter',
            ], 400);
        }

        $binding = app(SubscriptionCheckoutRegistry::class)->resolve($checkoutPlanId);
        if (! $binding || $binding['user_id'] !== $user->id) {
            Log::warning('Subscription success - checkout binding mismatch', [
                'checkout_id' => $checkoutPlanId,
                'user_id' => $user->id,
                'bound_user_id' => $binding['user_id'] ?? null,
            ]);

            return response()->json([
                'message' => 'Checkout session not found or does not belong to your account.',
            ], 403);
        }

        try {
            $checkoutDetails = $this->btcpaySubscriptionService->getPlanCheckout($checkoutPlanId);

            $planId = $checkoutDetails['plan']['id']
                ?? $checkoutDetails['subscriber']['plan']['id']
                ?? $checkoutDetails['planId']
                ?? null;

            $planName = $this->btcpaySubscriptionService->resolvePlanNameFromId($planId);

            if (! $planName) {
                Log::warning('Subscription success - unknown plan ID', [
                    'checkout_id' => $checkoutPlanId,
                    'plan_id' => $planId,
                ]);

                return response()->json([
                    'message' => 'Unknown subscription plan',
                ], 400);
            }

            if ($planName !== $binding['plan']) {
                Log::warning('Subscription success - plan mismatch with checkout binding', [
                    'checkout_id' => $checkoutPlanId,
                    'bound_plan' => $binding['plan'],
                    'resolved_plan' => $planName,
                    'plan_id' => $planId,
                ]);

                return response()->json([
                    'message' => 'Checkout plan does not match your subscription request.',
                ], 403);
            }

            $subscriptionId = $checkoutDetails['subscriber']['customer']['id']
                ?? $checkoutDetails['subscriptionId']
                ?? null;

            $customerEmail = $checkoutDetails['subscriber']['customer']['identities']['Email']
                ?? $checkoutDetails['subscriber']['customer']['email']
                ?? $checkoutDetails['customerEmail']
                ?? $checkoutDetails['subscriberEmail']
                ?? $checkoutDetails['email']
                ?? null;

            $subscriptionStoreId = config('services.btcpay.subscription_store_id');
            $paidInvoice = $subscriptionStoreId
                ? $this->btcpaySubscriptionService->resolvePaidInvoiceFromCheckout(
                    $subscriptionStoreId,
                    $checkoutDetails,
                    $checkoutPlanId,
                    $customerEmail,
                )
                : null;

            $trialActivated = $this->btcpaySubscriptionService->checkoutTrialWasActivated($checkoutDetails);

            if (! $paidInvoice && ! $trialActivated) {
                Log::info('Subscription success redirect pending payment confirmation', [
                    'checkout_id' => $checkoutPlanId,
                    'user_id' => $user->id,
                    'plan' => $planName,
                ]);

                return response()->json([
                    'message' => 'Payment confirmation pending. Your subscription will activate once BTCPay confirms payment.',
                    'activated' => false,
                    'plan' => $planName,
                ]);
            }

            if ($paidInvoice) {
                $subscription = $this->subscriptionService->activateSubscription(
                    $user,
                    $planName,
                    $subscriptionId
                );
            } else {
                $subscription = $this->subscriptionService->activateTrialSubscription(
                    $user,
                    $planName,
                    $this->btcpaySubscriptionService->resolveTrialEndsAt($checkoutDetails),
                    $subscriptionId
                );
            }

            $oldRole = $user->role;
            $user->role = $planName;
            if ($subscriptionId) {
                $user->btcpay_subscription_id = $subscriptionId;
            }
            $user->save();

            if ($paidInvoice) {
                try {
                    app(SubscriptionBillingInvoiceService::class)->fulfillPaidInvoice(
                        $user,
                        $planName,
                        $paidInvoice['id'],
                        $paidInvoice['payload'],
                    );
                } catch (\Throwable $e) {
                    Log::error('Subscription billing invoice failed on success redirect', [
                        'user_id' => $user->id,
                        'checkout_id' => $checkoutPlanId,
                        'invoice_id' => $paidInvoice['id'],
                        'error' => $e->getMessage(),
                    ]);
                    report($e);
                }
            }

            Log::info('Subscription activated after checkout success', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'old_role' => $oldRole,
                'new_role' => $planName,
                'checkout_id' => $checkoutPlanId,
                'plan_id' => $planId,
                'subscription_id' => $subscription->id,
                'expires_at' => $subscription->expires_at,
                'billing_invoice_id' => $paidInvoice['id'] ?? null,
                'trial_activation' => $trialActivated && ! $paidInvoice,
            ]);

            return response()->json([
                'message' => 'Subscription activated successfully',
                'activated' => true,
                'plan' => $planName,
                'subscription' => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'expires_at' => $subscription->expires_at,
                ],
                'user' => $user->makeVisible('role'),
            ]);

        } catch (BtcPayException $e) {
            Log::error('Failed to process subscription success', [
                'checkout_id' => $checkoutPlanId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);

            return response()->json([
                'message' => 'Failed to process subscription. Please contact support.',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error processing subscription success', [
                'checkout_id' => $checkoutPlanId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred. Please contact support.',
            ], 500);
        }
    }

    /**
     * Get user's subscription details.
     *
     * GET /api/subscriptions/details
     */
    public function details(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        if ((bool) ($user->is_guest ?? false)) {
            return response()->json([
                'subscriber' => null,
                'creditBalance' => 0,
                'billing' => null,
                'creditHistory' => [],
            ]);
        }

        $storeId = config('services.btcpay.subscription_store_id');
        $offeringId = config('services.btcpay.subscription_offering_id');

        try {
            // Get subscriber details using email as selector
            $subscriber = $this->btcpaySubscriptionService->getSubscriber($storeId, $offeringId, $user->email);

            $creditBalance = 0;
            try {
                $credits = $this->btcpaySubscriptionService->getSubscriberCredits($storeId, $offeringId, $user->email, 'SATS');
                $creditBalance = $this->btcpaySubscriptionService->parseSubscriberCreditBalance($credits);
            } catch (\Exception $e) {
                Log::debug('Could not fetch credit balance', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $creditHistory = $this->btcpaySubscriptionService->getSubscriberCreditHistory(
                $storeId,
                $offeringId,
                $user->email,
                'SATS'
            );

            if ($creditHistory === []) {
                $creditHistory = app(SubscriptionCreditLedgerService::class)->listForUser($user);
            }

            return response()->json([
                'subscriber' => $subscriber,
                'creditBalance' => $creditBalance,
                'billing' => $this->btcpaySubscriptionService->buildSubscriptionBillingSummary($subscriber, $creditBalance),
                'creditHistory' => $creditHistory,
            ]);

        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 404) {
                // User doesn't have a subscription yet
                return response()->json([
                    'subscriber' => null,
                    'creditBalance' => 0,
                    'billing' => null,
                    'creditHistory' => [],
                ]);
            }

            Log::error('Failed to get subscription details', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch subscription details',
            ], 500);
        }
    }

    /**
     * Get subscriber credit balance.
     *
     * GET /api/subscriptions/credits
     */
    public function getCredits(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $currency = $this->normalizeSubscriptionCreditsCurrency((string) $request->query('currency', 'SATS'));

        if ((bool) ($user->is_guest ?? false)) {
            return response()->json([
                'balance' => 0,
                'currency' => $currency,
                'details' => [],
            ]);
        }

        $storeId = config('services.btcpay.subscription_store_id');
        $offeringId = config('services.btcpay.subscription_offering_id');

        try {
            $credits = $this->btcpaySubscriptionService->getSubscriberCredits($storeId, $offeringId, $user->email, $currency);

            return response()->json([
                'balance' => $this->btcpaySubscriptionService->parseSubscriberCreditBalance($credits),
                'currency' => $currency,
                'details' => $credits,
            ]);

        } catch (BtcPayException $e) {
            Log::error('Failed to get credit balance', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch credit balance',
            ], 500);
        }
    }

    /**
     * Create a BTCPay invoice for purchasing subscriber credits.
     *
     * POST /api/subscriptions/credits
     * Body: { amount: number, currency?: string }
     */
    public function addCredits(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'in:SATS,BTC'],
        ]);

        if ($blocked = $this->subscriptionBlockedForGuestResponse($request)) {
            return $blocked;
        }

        $storeId = config('services.btcpay.subscription_store_id');
        $offeringId = config('services.btcpay.subscription_offering_id');
        $amount = $request->input('amount');

        if (! $storeId || ! $offeringId) {
            return response()->json([
                'message' => 'Subscription configuration is incomplete. Please contact support.',
            ], 500);
        }

        try {
            $subscriber = $this->btcpaySubscriptionService->getSubscriber($storeId, $offeringId, $user->email);
            $planId = $subscriber['plan']['id'] ?? null;

            if (! $planId) {
                return response()->json([
                    'message' => 'Active subscription required before purchasing credits.',
                ], 422);
            }

            $baseUrl = config('app.url');
            $successUrl = config('services.btcpay.subscription_success_url', "{$baseUrl}/billing/success");

            $checkout = $this->btcpaySubscriptionService->createCreditPurchaseCheckout(
                $storeId,
                $offeringId,
                $planId,
                $user->email,
                $amount,
                ['successRedirectUrl' => $successUrl]
            );

            Log::info('Credit purchase checkout created via API', [
                'checkout_id' => $checkout['checkoutId'],
                'invoice_id' => $checkout['invoiceId'] ?? null,
                'user_id' => $user->id,
                'amount' => $amount,
            ]);

            return response()->json([
                'message' => 'Credit checkout created successfully',
                'paymentUrl' => $checkout['paymentUrl'] ?? $checkout['invoiceUrl'],
                'checkoutUrl' => $checkout['checkoutUrl'],
                'checkoutId' => $checkout['checkoutId'],
                'invoiceId' => $checkout['invoiceId'],
                'invoiceUrl' => $checkout['invoiceUrl'],
                'expiresAt' => $checkout['expiresAt'] ?? null,
            ]);

        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 404) {
                return response()->json([
                    'message' => 'Active subscription required before purchasing credits.',
                ], 422);
            }

            Log::error('Failed to create credit purchase checkout', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);

            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to create credit checkout',
            ], $e->getStatusCode() ?: 500);
        }
    }

    private function subscriptionBlockedForGuestResponse(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User && (bool) ($user->is_guest ?? false)) {
            return response()->json([
                'message' => __('messages.subscription_guest_must_upgrade_account'),
                'code' => 'guest_subscription_blocked',
            ], 422);
        }

        return null;
    }

    /**
     * Credits API only supports SATS/BTC; reject arbitrary query/body values.
     */
    private function normalizeSubscriptionCreditsCurrency(string $raw): string
    {
        $upper = strtoupper(trim($raw));

        return in_array($upper, ['SATS', 'BTC'], true) ? $upper : 'SATS';
    }
}
