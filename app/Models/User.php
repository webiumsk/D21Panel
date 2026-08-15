<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'last_login_at',
        'password',
        'btcpay_user_id',
        'btcpay_api_key',
        // 'role' is intentionally NOT fillable - assign explicitly or via forceFill()
        // in trusted flows only (admin update, subscription sync, provisioning)
        'btcpay_subscription_id',
        'subscription_expires_at',
        'subscription_grace_period_ends_at',
        'trial_consumed_at',
        'is_guest',
        'allows_satflux_email_changes',
        'evolu_relay_url',
        'guest_recovery_public_key',
        'guest_recovery_enrolled_at',
        'privacy_consent_at',
        'terms_accepted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'btcpay_api_key',
        'guest_recovery_public_key',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'btcpay_api_key' => 'encrypted', // Encrypt API key in database
            'subscription_expires_at' => 'datetime',
            'subscription_grace_period_ends_at' => 'datetime',
            'trial_consumed_at' => 'datetime',
            'is_guest' => 'boolean',
            'allows_satflux_email_changes' => 'boolean',
            'guest_recovery_enrolled_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    /**
     * Check if user is a support user.
     */
    public function isSupport(): bool
    {
        return $this->role === 'support' || $this->role === 'admin';
    }

    /**
     * Check if user has unlimited access (admin, support, or enterprise).
     */
    public function hasUnlimitedAccess(): bool
    {
        return $this->isAdmin() || $this->isSupport() || $this->isEnterprise();
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is on free tier (role 'free', formerly 'merchant').
     */
    public function isMerchant(): bool
    {
        return $this->role === 'free' || empty($this->role);
    }

    /**
     * Check if user has a PRO plan.
     */
    public function isPro(): bool
    {
        return $this->role === 'pro';
    }

    /**
     * Check if user has an Enterprise plan.
     */
    public function isEnterprise(): bool
    {
        return $this->role === 'enterprise';
    }

    /**
     * Check if user has a paid plan (Pro or Enterprise).
     */
    public function isPaidPlan(): bool
    {
        return $this->isPro() || $this->isEnterprise();
    }

    /**
     * Get maximum number of Lightning Addresses allowed for this user.
     * Based on subscription plan (max_ln_addresses).
     *
     * @return int|null Maximum number of addresses (null = unlimited)
     */
    public function getMaxLightningAddresses(): ?int
    {
        if ($this->hasUnlimitedAccess()) {
            return null;
        }

        $plan = $this->currentSubscriptionPlan();
        if (! $plan) {
            return 1; // No plan = Free tier
        }

        // Free plan: always max 1 (never unlimited), even if max_ln_addresses is null in DB
        $code = strtolower($plan->code ?? $plan->name ?? '');
        if ($code === 'free') {
            return (int) ($plan->max_ln_addresses ?? 1);
        }

        if ($plan->hasUnlimitedLnAddresses()) {
            return null;
        }

        return (int) $plan->max_ln_addresses;
    }

    /**
     * Get maximum number of ticket events allowed per store.
     * Admin/support: unlimited. Otherwise from plan: Free=1, Pro=3, Enterprise=unlimited.
     *
     * @return int|null Maximum events per store (null = unlimited)
     */
    public function getMaxEventsPerStore(): ?int
    {
        if ($this->hasUnlimitedAccess()) {
            return null;
        }

        $plan = $this->currentSubscriptionPlan();
        if (! $plan) {
            return 1; // fallback for free / no plan
        }

        if ($plan->max_events === null) {
            return null; // unlimited
        }

        return (int) $plan->max_events;
    }

    /**
     * Maximum raffles per store. Guest: 0 (feature blocked). Free: 1. Paid / admin / support: unlimited.
     *
     * @return int|null null = unlimited
     */
    public function getMaxRafflesPerStore(): ?int
    {
        if ((bool) ($this->is_guest ?? false)) {
            return 0;
        }

        if ($this->hasUnlimitedAccess() || $this->hasActivePaidSubscription()) {
            return null;
        }

        return 1;
    }

    /**
     * True when the user has an active (or paid grace) PRO / Enterprise entitlement.
     */
    public function hasActivePaidSubscription(): bool
    {
        return $this->hasActiveProEntitlement();
    }

    /**
     * Active PRO / Enterprise entitlement (trial, paid, or paid grace). Not expired.
     */
    public function hasActiveProEntitlement(): bool
    {
        if ($this->hasUnlimitedAccess()) {
            return true;
        }

        $subscription = $this->currentSubscription();
        if (! $subscription || $subscription->isExpired()) {
            return false;
        }

        if (! $subscription->isActive() && ! $subscription->isInGracePeriod()) {
            return false;
        }

        $code = strtolower((string) ($subscription->plan->code ?? ''));

        return in_array($code, ['pro', 'enterprise'], true);
    }

    public function hasConsumedTrial(): bool
    {
        return $this->trial_consumed_at !== null;
    }

    /**
     * Get the stores for the user.
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Get the subscriptions for the user.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the user's panel API keys (not BTCPay keys).
     */
    public function userApiKeys(): HasMany
    {
        return $this->hasMany(UserApiKey::class, 'user_id');
    }

    /**
     * Get the user's messages (notifications).
     */
    public function messages(): HasMany
    {
        return $this->hasMany(UserMessage::class);
    }

    /**
     * Get the current active subscription for the user.
     * Returns the most recent active subscription, or null if none exists.
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->select('subscriptions.*')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->whereIn('subscriptions.status', ['active', 'grace'])
            ->orderByRaw("CASE WHEN subscription_plans.code = 'enterprise' THEN 0 WHEN subscription_plans.code = 'pro' THEN 1 ELSE 2 END")
            ->orderBy('subscriptions.expires_at', 'desc')
            ->first();
    }

    /**
     * Get the current subscription plan for the user.
     * Returns the plan for the current subscription, or falls back to the plan
     * matching the user's role (pro, enterprise, free).
     */
    public function currentSubscriptionPlan(): ?SubscriptionPlan
    {
        $subscription = $this->currentSubscription();
        if ($subscription) {
            return $subscription->plan;
        }

        if ($this->hasUnlimitedAccess()) {
            return SubscriptionPlan::where('code', 'enterprise')->first()
                ?? SubscriptionPlan::where('code', 'free')->first();
        }

        $latestPaid = $this->subscriptions()
            ->whereHas('plan', fn ($query) => $query->whereIn('code', ['pro', 'enterprise']))
            ->orderByDesc('expires_at')
            ->first();

        if ($latestPaid && $latestPaid->status === 'expired') {
            return SubscriptionPlan::where('code', 'free')->first();
        }

        // Legacy admin-assigned role without a subscription row.
        $role = $this->role ?? 'free';
        if (in_array($role, ['pro', 'enterprise'], true)) {
            $plan = SubscriptionPlan::where('code', $role)->first();
            if ($plan) {
                return $plan;
            }
        }

        return SubscriptionPlan::where('code', 'free')->first();
    }

    /**
     * Check if the user's plan has a feature (e.g. advanced_stats, automatic_csv_exports, offline_payment_methods).
     */
    public function planFeature(string $feature): bool
    {
        $plan = $this->currentSubscriptionPlan();

        return $plan ? $plan->hasFeature($feature) : false;
    }

    /**
     * Check if user has an active subscription (not expired).
     */
    public function hasActiveSubscription(): bool
    {
        $subscription = $this->currentSubscription();

        return $subscription && $subscription->isActive();
    }

    /**
     * Check if user's subscription is in grace period.
     */
    public function isSubscriptionInGracePeriod(): bool
    {
        $subscription = $this->currentSubscription();

        return $subscription && $subscription->isInGracePeriod();
    }

    /**
     * Check if user's subscription is expired.
     */
    public function isSubscriptionExpired(): bool
    {
        $subscription = $this->currentSubscription();

        return ! $subscription || $subscription->isExpired();
    }

    /**
     * Mark the email as verified.
     */
    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Determine if the user has verified their email address.
     */
    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    /**
     * Send our signed verification link (same URL shape as registration flow).
     */
    public function sendEmailVerificationNotification(): void
    {
        if (! $this->email) {
            return;
        }

        $url = app(EmailVerificationService::class)->signedVerificationUrlForUser($this);

        $this->notify(new VerifyEmailNotification($url));
    }

    public function getEmailForVerification(): string
    {
        return (string) ($this->email ?? '');
    }

    public function hasRecoveryPhraseEnrolled(): bool
    {
        return ! empty($this->guest_recovery_public_key);
    }

    /** Legacy email/password account that must enroll a recovery phrase (seed-first rollout). */
    public function requiresRecoveryMigration(): bool
    {
        if (! config('guest.seed_first_registration')) {
            return false;
        }

        if ((bool) ($this->is_guest ?? false)) {
            return false;
        }

        return ! $this->hasRecoveryPhraseEnrolled();
    }

    public function canUsePasswordLogin(): bool
    {
        return ! $this->hasRecoveryPhraseEnrolled();
    }

    /**
     * Get BTCPay API key or throw exception.
     *
     * @return string The decrypted BTCPay API key
     *
     * @throws HttpResponseException
     */
    public function getBtcPayApiKeyOrFail(): string
    {
        if (! $this->btcpay_api_key) {
            abort(500, 'BTCPay API key not configured. Please contact support.');
        }

        return $this->btcpay_api_key;
    }
}
