<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\CompanySlotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing subscriptions and feature flags.
 *
 * IMPORTANT: This is a non-custodial system. Feature flags only affect
 * UX/management features, never payment acceptance or existing infrastructure.
 */
class SubscriptionEntitlementService
{
    /**
     * Get or create a FREE subscription for a user.
     */
    public function ensureFreeSubscription(User $user): Subscription
    {
        // Check if user already has an active subscription
        $existingSubscription = $user->currentSubscription();
        if ($existingSubscription && $existingSubscription->isActive()) {
            return $existingSubscription;
        }

        // Get FREE plan
        $freePlan = SubscriptionPlan::where('code', 'free')->orWhere('name', 'free')->first();
        if (! $freePlan) {
            throw new \Exception('FREE subscription plan not found. Please run the seeder.');
        }

        // Create FREE subscription (never expires)
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $freePlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYears(100), // Effectively never expires
        ]);

        Log::info('Created FREE subscription for user', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
        ]);

        return $subscription;
    }

    /**
     * Activate or extend a paid subscription after BTCPay payment settles.
     */
    public function activateSubscription(User $user, string $planName, ?string $btcpaySubscriptionId = null): Subscription
    {
        $plan = SubscriptionPlan::where('code', $planName)->orWhere('name', $planName)->first();
        if (! $plan) {
            throw new \Exception("Subscription plan '{$planName}' not found.");
        }

        return DB::transaction(function () use ($user, $plan, $planName, $btcpaySubscriptionId) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                throw new \Exception('User not found.');
            }

            // A paid plan means a real customer: lift the guest gates. Without
            // this, the router hard-gate and guest-restricted features keep
            // hiding the Pro UI (webhook/admin activation never cleared it).
            if ($lockedUser->is_guest) {
                $lockedUser->is_guest = false;
                $lockedUser->save();
                Log::info('Cleared is_guest on paid subscription activation', [
                    'user_id' => $lockedUser->id,
                    'plan' => $planName,
                ]);
            }

            $existingSubscription = Subscription::where('user_id', $lockedUser->id)
                ->where('plan_id', $plan->id)
                ->whereIn('status', ['active', 'grace'])
                ->orderBy('expires_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($existingSubscription) {
                if ($existingSubscription->isTrial()) {
                    $existingSubscription->convertToPaidYear();
                } else {
                    $existingSubscription->extendOneYear();
                }

                if ($btcpaySubscriptionId) {
                    $existingSubscription->btcpay_subscription_id = $btcpaySubscriptionId;
                    $existingSubscription->save();
                }

                Log::info('Extended paid subscription', [
                    'user_id' => $lockedUser->id,
                    'plan' => $planName,
                    'expires_at' => $existingSubscription->expires_at,
                ]);

                return $existingSubscription->fresh();
            }

            $startsAt = now();
            $expiresAt = $startsAt->copy()->addYear();
            $graceEndsAt = $expiresAt->copy()->addDays((int) config('pricing.grace_days', 30));

            $subscription = Subscription::create([
                'user_id' => $lockedUser->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_phase' => Subscription::BILLING_PAID,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'grace_ends_at' => $graceEndsAt,
                'btcpay_subscription_id' => $btcpaySubscriptionId,
            ]);

            Log::info('Created new paid subscription', [
                'user_id' => $lockedUser->id,
                'plan' => $planName,
                'expires_at' => $expiresAt,
            ]);

            return $subscription;
        });
    }

    /**
     * Activate a BTCPay trial. Expires at trial end with no grace period.
     */
    public function activateTrialSubscription(
        User $user,
        string $planName,
        \DateTimeInterface $trialEndsAt,
        ?string $btcpaySubscriptionId = null,
    ): Subscription {
        $plan = SubscriptionPlan::where('code', $planName)->orWhere('name', $planName)->first();
        if (! $plan) {
            throw new \Exception("Subscription plan '{$planName}' not found.");
        }

        return DB::transaction(function () use ($user, $plan, $planName, $trialEndsAt, $btcpaySubscriptionId) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                throw new \Exception('User not found.');
            }

            $existingSubscription = Subscription::where('user_id', $lockedUser->id)
                ->where('plan_id', $plan->id)
                ->whereIn('status', ['active', 'grace'])
                ->orderBy('expires_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($existingSubscription) {
                if (! $existingSubscription->isTrial()) {
                    if ($btcpaySubscriptionId && ! $existingSubscription->btcpay_subscription_id) {
                        $existingSubscription->btcpay_subscription_id = $btcpaySubscriptionId;
                        $existingSubscription->save();
                    }

                    return $existingSubscription->fresh();
                }

                $existingSubscription->fill([
                    'status' => 'active',
                    'billing_phase' => Subscription::BILLING_TRIAL,
                    'starts_at' => now(),
                    'expires_at' => $trialEndsAt,
                    'trial_ends_at' => $trialEndsAt,
                    'grace_ends_at' => null,
                    'btcpay_subscription_id' => $btcpaySubscriptionId ?? $existingSubscription->btcpay_subscription_id,
                ]);
                $existingSubscription->save();
                $subscription = $existingSubscription;
            } else {
                $subscription = Subscription::create([
                    'user_id' => $lockedUser->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'billing_phase' => Subscription::BILLING_TRIAL,
                    'starts_at' => now(),
                    'expires_at' => $trialEndsAt,
                    'trial_ends_at' => $trialEndsAt,
                    'grace_ends_at' => null,
                    'btcpay_subscription_id' => $btcpaySubscriptionId,
                ]);
            }

            if (! $lockedUser->trial_consumed_at) {
                $lockedUser->trial_consumed_at = now();
                $lockedUser->save();
            }

            Log::info('Activated trial subscription', [
                'user_id' => $lockedUser->id,
                'plan' => $planName,
                'trial_ends_at' => $trialEndsAt,
            ]);

            return $subscription;
        });
    }

    /**
     * Expire active subscriptions and downgrade paid role to free.
     */
    public function expireSubscription(User $user, string $reason = ''): void
    {
        DB::transaction(function () use ($user, $reason) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                return;
            }

            $subscriptions = Subscription::where('user_id', $lockedUser->id)
                ->whereIn('status', ['active', 'grace'])
                ->lockForUpdate()
                ->get();

            foreach ($subscriptions as $subscription) {
                $subscription->status = 'expired';
                $subscription->billing_phase = Subscription::BILLING_EXPIRED;
                $subscription->save();
            }

            if (in_array($lockedUser->role, ['pro', 'enterprise'], true)) {
                $oldRole = $lockedUser->role;
                $lockedUser->role = 'free';
                $lockedUser->btcpay_subscription_id = null;
                $lockedUser->subscription_expires_at = null;
                $lockedUser->subscription_grace_period_ends_at = null;
                $lockedUser->save();

                Log::info('User subscription expired and role downgraded', [
                    'user_id' => $lockedUser->id,
                    'old_role' => $oldRole,
                    'reason' => $reason,
                ]);
            }
        });
    }

    public function hasActiveProEntitlement(User $user): bool
    {
        return $user->hasActiveProEntitlement();
    }

    protected function entitledPlan(User $user): ?SubscriptionPlan
    {
        if (! $this->hasActiveProEntitlement($user)) {
            return null;
        }

        return $user->currentSubscription()?->plan ?? $user->currentSubscriptionPlan();
    }

    protected function entitledPlanHasFeature(User $user, string $feature): bool
    {
        $plan = $this->entitledPlan($user);

        return $plan ? $plan->hasFeature($feature) : false;
    }

    /**
     * Check if user can use XLSX export (Pro+ or admin/support).
     */
    public function canUseXlsxExport(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->hasActiveProEntitlement($user);
    }

    /**
     * Check if user can access exports section (history, automatic exports).
     * Admin and support always have access.
     */
    public function canAccessExports(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->entitledPlanHasFeature($user, 'automatic_csv_exports');
    }

    /**
     * Check if user can use automatic (monthly) exports.
     */
    public function canUseAutomaticExports(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->entitledPlanHasFeature($user, 'automatic_csv_exports');
    }

    /**
     * Check if user can enable cash/card (offline) payment methods in PoS.
     */
    public function canUseOfflinePaymentMethods(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->entitledPlanHasFeature($user, 'offline_payment_methods');
    }

    /**
     * Check if user can view advanced statistics.
     */
    public function canViewAdvancedStats(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->entitledPlanHasFeature($user, 'advanced_statistics');
    }

    /**
     * Check if user can access Stripe (Pro+ or admin/support).
     */
    public function canAccessStripe(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->entitledPlanHasFeature($user, 'stripe');
    }

    public function canUseBusinessInvoicing(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->entitledPlanHasFeature($user, 'business_invoicing');
    }

    /**
     * Max invoicing companies for the user (null = unlimited). 0 = module not available.
     * Finite limits include purchased extra company slots on top of the plan's base.
     */
    public function maxCompaniesForUser(User $user): ?int
    {
        $base = $this->includedCompaniesForUser($user);

        if ($base === null || $base <= 0) {
            return $base;
        }

        return $base + app(CompanySlotService::class)->paidSlotCount($user);
    }

    /**
     * The plan's included company count before purchased slots (null = unlimited).
     */
    public function includedCompaniesForUser(User $user): ?int
    {
        if ($user->hasUnlimitedAccess()) {
            return null;
        }

        if (! $this->canUseBusinessInvoicing($user)) {
            return 0;
        }

        $plan = $user->currentSubscriptionPlan();
        if (! $plan) {
            return 0;
        }

        if ($plan->hasUnlimitedCompanies()) {
            return null;
        }

        if ($plan->code === 'pro') {
            $betaMax = config('invoicing.beta_pro_max_companies');
            if ($betaMax !== null && $betaMax > 0) {
                return $betaMax;
            }
        }

        return $plan->max_companies ?? 0;
    }

    public function canCreateCompany(User $user): bool
    {
        $max = $this->maxCompaniesForUser($user);

        if ($max === null) {
            return $this->canUseBusinessInvoicing($user);
        }

        if ($max <= 0) {
            return false;
        }

        return $user->companies()->count() < $max;
    }

    /**
     * Check if user can manage store users (per-store user management).
     */
    public function canManageStoreUsers(User $user): bool
    {
        if ($user->hasUnlimitedAccess()) {
            return true;
        }

        return $this->entitledPlanHasFeature($user, 'per_store_user_management');
    }

    /**
     * Keep subscription rows aligned when an admin assigns a merchant role.
     * API entitlements use active Pro/Enterprise subscriptions, not users.role alone.
     */
    public function syncSubscriptionForAdminRole(User $user, string $role): void
    {
        if (! in_array($role, ['free', 'pro', 'enterprise'], true)) {
            return;
        }

        DB::transaction(function () use ($user, $role) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                return;
            }

            if (in_array($role, ['pro', 'enterprise'], true)) {
                $this->activateSubscription($lockedUser, $role);

                return;
            }

            Subscription::query()
                ->where('user_id', $lockedUser->id)
                ->whereIn('status', ['active', 'grace'])
                ->whereHas('plan', fn ($query) => $query->whereIn('code', ['pro', 'enterprise']))
                ->lockForUpdate()
                ->get()
                ->each(function (Subscription $subscription) {
                    $subscription->status = 'expired';
                    $subscription->billing_phase = Subscription::BILLING_EXPIRED;
                    $subscription->save();
                });

            $this->ensureFreeSubscription($lockedUser);
        });
    }

    /**
     * Update subscription statuses for all users.
     * This should be called periodically (e.g., via a scheduled task).
     */
    public function updateAllSubscriptionStatuses(): void
    {
        $subscriptions = Subscription::whereIn('status', ['active', 'grace'])
            ->where('expires_at', '<=', now())
            ->get();

        $expiredUsers = [];

        foreach ($subscriptions as $subscription) {
            $wasTrial = $subscription->isTrial();
            $subscription->updateStatus();
            $subscription->refresh();

            if ($subscription->status === 'expired' && $wasTrial) {
                $expiredUsers[$subscription->user_id] = 'Trial ended without payment';
            } elseif ($subscription->status === 'expired') {
                $expiredUsers[$subscription->user_id] = 'Paid subscription grace period ended';
            }
        }

        foreach ($expiredUsers as $userId => $reason) {
            $user = User::find($userId);
            if ($user) {
                $this->expireSubscription($user, $reason);
            }
        }

        Log::info('Updated subscription statuses', [
            'count' => $subscriptions->count(),
            'expired_users' => count($expiredUsers),
        ]);
    }
}
