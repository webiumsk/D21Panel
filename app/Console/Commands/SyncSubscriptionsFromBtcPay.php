<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\InvoiceService;
use App\Services\BtcPay\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSubscriptionsFromBtcPay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:sync-from-btcpay';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch all subscriptions from BTCPay and sync them with users by email';

    protected SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        parent::__construct();
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching subscriptions from BTCPay...');

        $storeId = config('services.btcpay.subscription_store_id');

        try {
            // Fetch all subscriptions from BTCPay
            $this->info("Attempting to fetch subscriptions from store: {$storeId}");

            try {
                $subscriptions = $this->subscriptionService->listSubscriptions($storeId);

                // BTCPay API might return array of subscriptions or wrapped in a data property
                $subscriptionsList = $subscriptions['data'] ?? $subscriptions;

                if (! is_array($subscriptionsList)) {
                    $this->error('Unexpected response format from BTCPay API');
                    Log::error('Unexpected subscription list format', ['response' => $subscriptions]);

                    return Command::FAILURE;
                }

                $this->info('Found '.count($subscriptionsList).' subscriptions in BTCPay');

            } catch (BtcPayException $e) {
                if ($e->getStatusCode() === 404) {
                    $this->warn('Direct subscriptions endpoint returned 404, trying via offerings/subscribers...');

                    // Try alternative: get subscriptions via offerings/subscribers endpoint
                    try {
                        return $this->syncViaOfferings($storeId);
                    } catch (\Exception $e2) {
                        $this->error('HTTP 404 - Subscription API endpoints not found.');
                        $this->warn("Tried: /api/v1/stores/{$storeId}/subscriptions");
                        $this->warn("Tried: /api/v1/stores/{$storeId}/offerings/{offeringId}/subscribers");
                        $this->newLine();
                        $this->info('Attempting alternative method: checking subscription invoices...');

                        // Alternative: try to find subscriptions via invoices with subscription metadata
                        return $this->syncViaInvoices($storeId);
                    }
                }
                throw $e;
            }

            $synced = 0;
            $upgraded = 0;
            $notFound = 0;

            foreach ($subscriptionsList as $subscriptionData) {
                $subscriptionId = $subscriptionData['id'] ?? null;

                if (! $subscriptionId) {
                    $this->warn('Subscription without ID found, skipping...');

                    continue;
                }

                // Get customer email from subscription
                $customerEmail = $subscriptionData['customerEmail']
                    ?? $subscriptionData['subscriberEmail']
                    ?? $subscriptionData['email']
                    ?? $subscriptionData['metadata']['customerEmail']
                    ?? $subscriptionData['metadata']['buyerEmail']
                    ?? null;

                if (! $customerEmail) {
                    $this->warn("Subscription {$subscriptionId} has no customer email, skipping...");
                    $notFound++;

                    continue;
                }

                // Find user by email
                $user = User::where('email', $customerEmail)->first();

                if (! $user) {
                    $this->warn("User with email {$customerEmail} not found for subscription {$subscriptionId}");
                    $notFound++;

                    continue;
                }

                // Get subscription status and plan
                $status = $subscriptionData['status'] ?? null;
                $planId = $subscriptionData['planId'] ?? null;

                // Get expiration date
                $expiresAt = null;
                if (isset($subscriptionData['expiresAt'])) {
                    $expiresAt = is_numeric($subscriptionData['expiresAt'])
                        ? now()->parse($subscriptionData['expiresAt'])
                        : now()->parse($subscriptionData['expiresAt']);
                } elseif (isset($subscriptionData['endDate'])) {
                    $expiresAt = is_numeric($subscriptionData['endDate'])
                        ? now()->parse($subscriptionData['endDate'])
                        : now()->parse($subscriptionData['endDate']);
                }

                // Map plan ID to role
                $subscriptionPlans = config('services.btcpay.subscription_plans', []);
                $expectedRole = null;

                if ($planId === ($subscriptionPlans['pro'] ?? null)) {
                    $expectedRole = 'pro';
                } elseif ($planId === ($subscriptionPlans['enterprise'] ?? null)) {
                    $expectedRole = 'enterprise';
                }

                // Update user
                $updateData = [
                    'btcpay_subscription_id' => $subscriptionId,
                    'subscription_expires_at' => $expiresAt,
                    'subscription_grace_period_ends_at' => null, // BTCPay handles grace period
                ];

                $oldRole = $user->role;

                // Only update role if subscription is active
                if (in_array($status, ['active', 'activeRenewing']) && $expectedRole) {
                    $updateData['role'] = $expectedRole;

                    if ($oldRole !== $expectedRole) {
                        $upgraded++;
                        $this->line("Upgraded {$user->email} from {$oldRole} to {$expectedRole} (subscription: {$subscriptionId})");
                    }
                } elseif (in_array($status, ['expired', 'cancelled', 'suspended'])) {
                    // Subscription is not active - downgrade if user has paid role
                    if (in_array($oldRole, ['pro', 'enterprise'])) {
                        $updateData['role'] = 'free';
                        $updateData['btcpay_subscription_id'] = null; // Clear subscription ID
                        $this->line("Downgraded {$user->email} from {$oldRole} to free (subscription expired: {$subscriptionId})");
                    }
                }

                $user->forceFill($updateData)->save();
                $synced++;

                Log::info('Synced subscription from BTCPay', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'subscription_id' => $subscriptionId,
                    'status' => $status,
                    'old_role' => $oldRole,
                    'new_role' => $user->fresh()->role,
                ]);
            }

            $this->info("Synced {$synced} subscriptions");
            if ($upgraded > 0) {
                $this->info("Upgraded {$upgraded} users");
            }
            if ($notFound > 0) {
                $this->warn("Could not match {$notFound} subscriptions to users");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error fetching subscriptions: {$e->getMessage()}");
            Log::error('Error syncing subscriptions from BTCPay', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Alternative sync method: get subscriptions via offerings/subscribers endpoint.
     * BTCPay API doesn't support listing all subscribers, so we iterate through users
     * and check each one individually using their email as customerSelector.
     */
    protected function syncViaOfferings(string $storeId): int
    {
        $this->info('Fetching subscriptions via offerings/subscribers endpoint...');

        try {
            $offeringId = config('services.btcpay.subscription_offering_id');

            if (! $offeringId) {
                $this->error('Subscription offering ID not configured');

                return Command::FAILURE;
            }

            $this->info("Checking subscribers for offering: {$offeringId}");

            // BTCPay API doesn't support listing all subscribers
            // We need to check each user individually by email
            $users = User::whereNotNull('email')
                ->where('email', '!=', '')
                ->get();

            $this->info("Checking {$users->count()} users for subscriptions...");

            $synced = 0;
            $upgraded = 0;
            $notFound = 0;

            foreach ($users as $user) {
                // Try to get subscriber using email as customerSelector
                try {
                    // Use email as customerSelector (BTCPay supports Email: prefix or just email)
                    $customerSelector = $user->email;

                    $subscriberData = $this->subscriptionService->getSubscriber($storeId, $offeringId, $customerSelector);

                } catch (BtcPayException $e) {
                    if ($e->getStatusCode() === 404) {
                        // User doesn't have a subscription for this offering - skip
                        continue;
                    }
                    // Other error - log and continue
                    $this->warn("Error checking subscription for {$user->email}: {$e->getMessage()}");

                    continue;
                }

                // Extract subscription information from subscriber data
                $planId = $subscriberData['plan']['id'] ?? null;
                $isActive = $subscriberData['isActive'] ?? false;
                $isSuspended = $subscriberData['isSuspended'] ?? false;
                $phase = $subscriberData['phase'] ?? null;

                // Determine status
                $status = null;
                if ($isSuspended) {
                    $status = 'suspended';
                } elseif (! $isActive) {
                    $status = 'expired';
                } elseif ($phase === 'Normal') {
                    $status = 'active';
                } else {
                    $status = 'active'; // Default to active if we can't determine
                }

                // Get expiration date
                $expiresAt = null;
                if (isset($subscriberData['periodEnd'])) {
                    $expiresAt = is_numeric($subscriberData['periodEnd'])
                        ? now()->parse($subscriberData['periodEnd'])
                        : now()->parse($subscriberData['periodEnd']);
                }

                // Try to get subscription ID from customer or other fields
                $subscriptionId = $subscriberData['customer']['id']
                    ?? $subscriberData['id']
                    ?? null;

                // Map plan ID to role
                $subscriptionPlans = config('services.btcpay.subscription_plans', []);
                $expectedRole = null;

                if ($planId === ($subscriptionPlans['pro'] ?? null)) {
                    $expectedRole = 'pro';
                } elseif ($planId === ($subscriptionPlans['enterprise'] ?? null)) {
                    $expectedRole = 'enterprise';
                }

                // Update user
                $updateData = [
                    'btcpay_subscription_id' => $subscriptionId ?? $user->email, // Use email as fallback ID
                    'subscription_expires_at' => $expiresAt,
                    'subscription_grace_period_ends_at' => null,
                ];

                $oldRole = $user->role;

                // Only update role if subscription is active
                if (in_array($status, ['active']) && $expectedRole) {
                    $updateData['role'] = $expectedRole;

                    if ($oldRole !== $expectedRole) {
                        $upgraded++;
                        $this->line("Upgraded {$user->email} from {$oldRole} to {$expectedRole} (plan: {$planId})");
                    }
                } elseif (in_array($status, ['expired', 'suspended'])) {
                    // Subscription is not active - downgrade if user has paid role
                    if (in_array($oldRole, ['pro', 'enterprise'])) {
                        $updateData['role'] = 'free';
                        $updateData['btcpay_subscription_id'] = null;
                        $this->line("Downgraded {$user->email} from {$oldRole} to free (status: {$status})");
                    }
                }

                $user->forceFill($updateData)->save();
                $synced++;

                Log::info('Synced subscription from BTCPay via offerings/subscribers', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'subscription_id' => $subscriptionId,
                    'plan_id' => $planId,
                    'status' => $status,
                    'old_role' => $oldRole,
                    'new_role' => $user->fresh()->role,
                ]);
            }

            $this->info("Synced {$synced} subscriptions via offerings/subscribers");
            if ($upgraded > 0) {
                $this->info("Upgraded {$upgraded} users");
            }
            if ($notFound > 0) {
                $this->warn("Could not match {$notFound} subscriptions to users");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error syncing via offerings: {$e->getMessage()}");
            Log::error('Error syncing subscriptions via offerings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Re-throw to trigger fallback to invoices method
        }
    }

    /**
     * Alternative sync method: find subscriptions via invoices with subscription metadata.
     */
    protected function syncViaInvoices(string $storeId): int
    {
        $this->info('Fetching invoices from subscription store to find subscriptions...');

        try {
            $invoiceService = app(InvoiceService::class);

            // Fetch recent invoices (last 100 should be enough)
            $invoices = $invoiceService->listInvoices($storeId, [], 0, 100);

            $invoicesList = $invoices['data'] ?? $invoices;

            if (! is_array($invoicesList)) {
                $this->error('Unexpected response format from BTCPay invoices API');

                return Command::FAILURE;
            }

            $this->info('Found '.count($invoicesList).' invoices to check');

            $synced = 0;
            $upgraded = 0;
            $subscriptionsFound = [];

            foreach ($invoicesList as $invoice) {
                // Look for subscription ID in invoice metadata
                $subscriptionId = $invoice['metadata']['subscriptionId']
                    ?? $invoice['subscriptionId']
                    ?? null;

                if (! $subscriptionId || isset($subscriptionsFound[$subscriptionId])) {
                    continue; // Skip if no subscription ID or already processed
                }

                $subscriptionsFound[$subscriptionId] = true;

                // Get customer email
                $customerEmail = $invoice['metadata']['customerEmail']
                    ?? $invoice['metadata']['buyerEmail']
                    ?? $invoice['buyerEmail']
                    ?? $invoice['customerEmail']
                    ?? null;

                if (! $customerEmail) {
                    continue;
                }

                // Find user
                $user = User::where('email', $customerEmail)->first();
                if (! $user) {
                    continue;
                }

                // Try to get subscription details (might still fail with 404)
                try {
                    $subscription = $this->subscriptionService->getSubscription($storeId, $subscriptionId);
                    $status = $subscription['status'] ?? null;
                    $planId = $subscription['planId'] ?? null;

                    // Get expiration
                    $expiresAt = null;
                    if (isset($subscription['expiresAt'])) {
                        $expiresAt = is_numeric($subscription['expiresAt'])
                            ? now()->parse($subscription['expiresAt'])
                            : now()->parse($subscription['expiresAt']);
                    }

                    // Map plan to role
                    $subscriptionPlans = config('services.btcpay.subscription_plans', []);
                    $expectedRole = null;

                    if ($planId === ($subscriptionPlans['pro'] ?? null)) {
                        $expectedRole = 'pro';
                    } elseif ($planId === ($subscriptionPlans['enterprise'] ?? null)) {
                        $expectedRole = 'enterprise';
                    }

                    // Update user
                    $updateData = [
                        'btcpay_subscription_id' => $subscriptionId,
                        'subscription_expires_at' => $expiresAt,
                        'subscription_grace_period_ends_at' => null,
                    ];

                    $oldRole = $user->role;

                    if (in_array($status, ['active', 'activeRenewing']) && $expectedRole) {
                        $updateData['role'] = $expectedRole;
                        if ($oldRole !== $expectedRole) {
                            $upgraded++;
                            $this->line("Upgraded {$user->email} from {$oldRole} to {$expectedRole}");
                        }
                    }

                    $user->forceFill($updateData)->save();
                    $synced++;

                } catch (BtcPayException $e) {
                    // If we can't get subscription details, at least store the subscription ID
                    $user->update([
                        'btcpay_subscription_id' => $subscriptionId,
                    ]);
                    $synced++;
                    $this->line("Synced subscription ID for {$user->email} (details unavailable)");
                }
            }

            $this->info("Synced {$synced} subscriptions via invoices");
            if ($upgraded > 0) {
                $this->info("Upgraded {$upgraded} users");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error syncing via invoices: {$e->getMessage()}");
            Log::error('Error syncing subscriptions via invoices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
