<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\SubscriptionService as BtcPaySubscriptionService;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSubscriptionStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check-statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check subscription statuses from BTCPay API and sync user roles. Also upgrades users with active subscriptions who still have "free" role.';

    public function __construct(
        protected BtcPaySubscriptionService $btcpaySubscriptionService,
        protected SubscriptionEntitlementService $subscriptionService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking subscription statuses from BTCPay API...');

        $this->subscriptionService->updateAllSubscriptionStatuses();

        $storeId = config('services.btcpay.subscription_store_id');

        // Find ALL users with subscription_id (regardless of role)
        // This includes users who have active subscription in BTCPay but still "free" role in DB
        $usersWithSubscriptions = User::whereNotNull('btcpay_subscription_id')->get();

        $this->info("Found {$usersWithSubscriptions->count()} users with subscription IDs");

        $downgraded = 0;
        $upgraded = 0;
        $checked = 0;

        foreach ($usersWithSubscriptions as $user) {
            $checked++;

            try {
                // Check subscription status directly from BTCPay API
                // BTCPay manages grace period internally, so we trust its status
                if (! $user->btcpay_subscription_id) {
                    // User has paid role but no subscription ID - downgrade
                    $this->downgradeUser($user, 'No subscription ID found');
                    $downgraded++;

                    continue;
                }

                try {
                    // Fetch current subscription status from BTCPay
                    $subscription = $this->btcpaySubscriptionService->getSubscription(
                        $storeId,
                        $user->btcpay_subscription_id
                    );

                    $status = $subscription['status'] ?? null;

                    // BTCPay subscription statuses:
                    // - active: subscription is active and paid
                    // - activeRenewing: subscription is active and will renew
                    // - expired: subscription has expired (after grace period)
                    // - cancelled: subscription was cancelled
                    // - suspended: subscription is suspended
                    // BTCPay handles grace period internally, so if status is "expired",
                    // it means grace period has passed

                    if (in_array($status, ['expired', 'cancelled', 'suspended'])) {
                        // Subscription is truly expired/cancelled/suspended - downgrade immediately
                        // BTCPay has already handled grace period
                        $this->downgradeUser($user, "Subscription status from BTCPay: {$status}");
                        $downgraded++;
                    } elseif (in_array($status, ['active', 'activeRenewing'])) {
                        // Subscription is active - sync expiration date from BTCPay
                        $expiresAt = null;
                        if (isset($subscription['expiresAt'])) {
                            $expiresAt = is_numeric($subscription['expiresAt'])
                                ? now()->parse($subscription['expiresAt'])
                                : now()->parse($subscription['expiresAt']);
                        } elseif (isset($subscription['endDate'])) {
                            $expiresAt = is_numeric($subscription['endDate'])
                                ? now()->parse($subscription['endDate'])
                                : now()->parse($subscription['endDate']);
                        }

                        // Update expiration date and clear any old grace period tracking
                        $user->update([
                            'subscription_expires_at' => $expiresAt,
                            'subscription_grace_period_ends_at' => null, // BTCPay handles grace period
                        ]);

                        // Ensure user role matches subscription plan
                        $planId = $subscription['planId'] ?? null;
                        $subscriptionPlans = config('services.btcpay.subscription_plans', []);
                        $expectedRole = null;

                        if ($planId === ($subscriptionPlans['pro'] ?? null)) {
                            $expectedRole = 'pro';
                        } elseif ($planId === ($subscriptionPlans['enterprise'] ?? null)) {
                            $expectedRole = 'enterprise';
                        }

                        if ($expectedRole && $user->role !== $expectedRole) {
                            $oldRole = $user->role;
                            $user->forceFill(['role' => $expectedRole])->save();

                            if (in_array($oldRole, ['free']) && in_array($expectedRole, ['pro', 'enterprise'])) {
                                $upgraded++;
                                $this->line("Upgraded {$user->email} from {$oldRole} to {$expectedRole} (subscription active in BTCPay)");
                            }

                            Log::info('User role synced with subscription plan', [
                                'user_id' => $user->id,
                                'old_role' => $oldRole,
                                'new_role' => $expectedRole,
                                'subscription_id' => $user->btcpay_subscription_id,
                            ]);
                        }
                    } else {
                        // Unknown status - log warning but don't downgrade
                        Log::warning('Unknown subscription status from BTCPay', [
                            'user_id' => $user->id,
                            'subscription_id' => $user->btcpay_subscription_id,
                            'status' => $status,
                        ]);
                    }
                } catch (BtcPayException $e) {
                    // Subscription not found in BTCPay (404) - might have been deleted
                    if ($e->getStatusCode() === 404) {
                        Log::warning('Subscription not found in BTCPay - may have been deleted', [
                            'user_id' => $user->id,
                            'user_email' => $user->email,
                            'subscription_id' => $user->btcpay_subscription_id,
                        ]);

                        // Subscription doesn't exist in BTCPay - downgrade
                        $this->downgradeUser($user, 'Subscription not found in BTCPay (404)');
                        $downgraded++;
                    } else {
                        // Other error - log but don't downgrade (might be temporary)
                        Log::error('Error fetching subscription from BTCPay', [
                            'user_id' => $user->id,
                            'subscription_id' => $user->btcpay_subscription_id,
                            'error' => $e->getMessage(),
                            'status_code' => $e->getStatusCode(),
                        ]);
                    }
                }

            } catch (\Exception $e) {
                Log::error('Error checking subscription status for user', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("Error checking user {$user->email}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$checked} users");
        if ($downgraded > 0) {
            $this->info("Downgraded {$downgraded} users");
        }
        if ($upgraded > 0) {
            $this->info("Upgraded {$upgraded} users");
        }

        return Command::SUCCESS;
    }

    /**
     * Downgrade user role to free.
     * BTCPay has already handled grace period, so we can downgrade immediately.
     */
    protected function downgradeUser(User $user, string $reason): void
    {
        $oldRole = $user->role;
        $this->subscriptionService->expireSubscription($user, $reason);

        $this->line("Downgraded {$user->email} from {$oldRole} to free: {$reason}");
    }
}
