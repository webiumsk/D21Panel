<?php

namespace App\Http\Controllers;

use App\Exceptions\EmailCodeChallengeException;
use App\Models\Store;
use App\Services\Auth\EmailCodeChallengeService;
use App\Services\BtcPay\LightningAddressService;
use App\Services\BtcPay\RaffleService;
use App\Services\BtcPay\TicketService;
use App\Services\Compliance\ComplianceGate;
use App\Services\GuestUpgradeService;
use App\Services\SubscriptionEntitlementService;
use App\Support\Legal\LegalConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(
        protected LightningAddressService $lightningAddressService,
        protected TicketService $ticketService,
        protected RaffleService $raffleService,
        protected GuestUpgradeService $guestUpgradeService,
        protected ComplianceGate $complianceGate,
        protected EmailCodeChallengeService $emailCodes,
    ) {}

    /**
     * Get the authenticated user with plan and subscription info.
     */
    public function user(Request $request)
    {
        $user = $request->user();
        $user->makeVisible('role');

        $subscription = $user->currentSubscription();
        $plan = $user->currentSubscriptionPlan();

        $payload = $user->toArray();
        $subscriptionService = app(SubscriptionEntitlementService::class);
        $maxCompanies = $subscriptionService->maxCompaniesForUser($user);
        $includedCompanies = $subscriptionService->includedCompaniesForUser($user);

        $payload['plan'] = $plan ? [
            'code' => $plan->code,
            'name' => $plan->display_name,
            'max_stores' => $plan->max_stores,
            'max_api_keys' => $plan->max_api_keys,
            'max_ln_addresses' => $user->getMaxLightningAddresses(),
            'max_companies' => $maxCompanies,
            'included_companies' => $includedCompanies,
            'extra_company_slots' => ($maxCompanies !== null && $includedCompanies !== null)
                ? max(0, $maxCompanies - $includedCompanies)
                : 0,
            'companies_unlimited' => $maxCompanies === null && $subscriptionService->canUseBusinessInvoicing($user),
            'features' => $plan->features ?? [],
        ] : null;
        $payload['subscription'] = $subscription ? [
            'status' => $subscription->status,
            'billing_phase' => $subscription->billing_phase,
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
        ] : null;
        $payload['plan_features'] = [
            'advanced_stats' => $user->planFeature('advanced_statistics'),
            'automatic_exports' => $user->planFeature('automatic_csv_exports'),
            'offline_payment_methods' => $user->planFeature('offline_payment_methods'),
            'business_invoicing' => $user->planFeature('business_invoicing'),
            'expense_isdoc_extract_unlimited' => $user->planFeature('expense_isdoc_extract_unlimited'),
        ];
        $payload['guest_recovery_enrolled'] = ! empty($user->guest_recovery_public_key ?? null);
        // The client renders the guest->account upgrade form from this flag so
        // it always matches the server-side validation (password nullable when
        // email-only) - no build-time VITE_* env pair to keep in sync.
        $payload['guest_upgrade_email_only'] = (bool) config('guest.upgrade_email_only');
        $payload['requires_recovery_migration'] = $user->requiresRecoveryMigration();
        $payload['can_use_password_login'] = $user->canUsePasswordLogin();
        // Staged guest -> Free upgrade awaiting its email code (lets the SPA
        // resume the code step after a reload). Never the hash or payload.
        $payload['pending_email_challenge'] = (bool) ($user->is_guest ?? false)
            ? $this->emailCodes->summary($this->guestUpgradeService->pending($user))
            : null;

        return response()->json($payload);
    }

    /**
     * Get account limits (stores, LN addresses, API keys) for the authenticated user.
     * Admin and support always get unlimited for all.
     */
    public function limits(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Admin and support have unlimited access to all features
        if ($user->hasUnlimitedAccess()) {
            $storeCount = $user->stores()->count();
            $apiKeyCount = 0;
            foreach ($user->stores as $store) {
                $apiKeyCount += $store->apiKeys()->count();
            }

            $companyCount = $user->companies()->count();

            return response()->json([
                'stores' => [
                    'current' => $storeCount,
                    'max' => null,
                    'unlimited' => true,
                ],
                'companies' => [
                    'current' => $companyCount,
                    'max' => null,
                    'unlimited' => true,
                ],
                'ln_addresses' => [
                    'current' => 0,
                    'max' => null,
                    'unlimited' => true,
                ],
                'api_keys' => [
                    'current' => $apiKeyCount,
                    'max' => null,
                    'unlimited' => true,
                ],
                'events' => [
                    'max' => null,
                    'unlimited' => true,
                ],
                'raffles' => [
                    'max' => null,
                    'unlimited' => true,
                    'allowed' => true,
                ],
            ]);
        }

        $cacheKey = 'user_limits_'.$user->id;
        $limits = Cache::remember($cacheKey, 60, function () use ($user) {
            $plan = $user->currentSubscriptionPlan();
            $maxStores = $plan?->max_stores;
            $maxApiKeys = $plan?->max_api_keys;
            // Free plan fallback when plan is null or has null limits
            if ($maxStores === null) {
                $maxStores = 1;
            }
            if ($maxApiKeys === null) {
                $maxApiKeys = 1;
            }
            $maxLnAddresses = $user->getMaxLightningAddresses();

            $storeCount = $user->stores()->count();
            // API key limit is per store; for account we show max active keys in any single store
            $apiKeyCount = 0;
            $lnAddressesCount = 0;
            foreach ($user->stores as $store) {
                $activeInStore = $store->apiKeys()->where('is_active', true)->count();
                if ($activeInStore > $apiKeyCount) {
                    $apiKeyCount = $activeInStore;
                }
            }
            if ($maxLnAddresses !== null || $user->hasUnlimitedAccess()) {
                try {
                    $apiKey = $user->getBtcPayApiKeyOrFail();
                    foreach ($user->stores as $store) {
                        $list = $this->lightningAddressService->listAddresses(
                            $store->btcpay_store_id,
                            $apiKey
                        );
                        $lnAddressesCount += is_array($list) ? count($list) : 0;
                    }
                } catch (\Throwable $e) {
                    // Leave count at 0 on BTCPay errors
                }
            }

            $maxEvents = $user->getMaxEventsPerStore();
            $maxRaffles = $user->getMaxRafflesPerStore();
            $subscriptionService = app(SubscriptionEntitlementService::class);
            $maxCompanies = $subscriptionService->maxCompaniesForUser($user);
            $companyCount = $user->companies()->count();

            return [
                'stores' => [
                    'current' => $storeCount,
                    'max' => $maxStores,
                    'unlimited' => false,
                ],
                'ln_addresses' => [
                    'current' => $lnAddressesCount,
                    'max' => $maxLnAddresses,
                    'unlimited' => $maxLnAddresses === null,
                ],
                'api_keys' => [
                    'current' => $apiKeyCount,
                    'max' => $maxApiKeys,
                    'unlimited' => $maxApiKeys === null,
                ],
                'events' => [
                    'max' => $maxEvents,
                    'unlimited' => $maxEvents === null,
                ],
                'raffles' => [
                    'max' => $maxRaffles,
                    'unlimited' => $maxRaffles === null,
                    'allowed' => $maxRaffles !== 0,
                ],
                'companies' => [
                    'current' => $companyCount,
                    'max' => $maxCompanies,
                    'unlimited' => $maxCompanies === null && $subscriptionService->canUseBusinessInvoicing($user),
                ],
            ];
        });

        // When store_id is provided, add per-store counts for sidebar display
        if ($request->filled('store_id')) {
            $store = $user->stores()->find($request->store_id);
            if ($store) {
                try {
                    $apiKey = $user->getBtcPayApiKeyOrFail();
                    $eventList = $this->ticketService->listEvents($store->btcpay_store_id, $apiKey, false, true);
                    $limits['events']['current'] = is_array($eventList) ? count($eventList) : 0;
                } catch (\Throwable $e) {
                    $limits['events']['current'] = 0;
                }
                try {
                    $apiKey = $user->getBtcPayApiKeyOrFail();
                    $raffleList = $this->raffleService->listRaffles($store->btcpay_store_id, $apiKey);
                    $limits['raffles']['current'] = is_array($raffleList) ? count($raffleList) : 0;
                } catch (\Throwable $e) {
                    $limits['raffles']['current'] = 0;
                }
            }
        }

        return response()->json($limits);
    }

    /**
     * Update the user's profile information.
     */
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'evolu_relay_url' => ['nullable', 'string', 'max:512', 'regex:/^wss:\/\/.+/i'],
        ]);

        if (array_key_exists('evolu_relay_url', $validated)) {
            $url = trim((string) ($validated['evolu_relay_url'] ?? ''));
            $validated['evolu_relay_url'] = $url !== '' ? $url : null;
        }

        $request->user()->update($validated);

        return response()->json([
            'message' => __('messages.profile_updated'),
            'user' => $request->user()->fresh(),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json(['message' => __('messages.password_updated')]);
    }

    /**
     * Guest -> Free, step 1: validate the address and email a 6-digit code.
     * The guest row is untouched until the code is confirmed. Password is
     * optional when upgrade_email_only.
     */
    public function requestGuestUpgrade(Request $request)
    {
        $user = $request->user();
        if (! $user || ! (bool) ($user->is_guest ?? false)) {
            return response()->json(['message' => 'Only guest accounts can be upgraded.'], 422);
        }

        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }

        $emailOnlyUpgrade = (bool) config('guest.upgrade_email_only');

        $validated = $request->validate(array_merge([
            'method' => ['required', 'in:email'],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => $emailOnlyUpgrade
                ? ['nullable', 'confirmed', Password::defaults()]
                : ['required', 'confirmed', Password::defaults()],
        ], LegalConsent::registrationRules()));

        $this->complianceGate->assertRegistrationAllowed(
            $request,
            $validated['email'],
            is_string($user->name) ? $user->name : null,
        );

        try {
            $challenge = $this->guestUpgradeService->stage($user, $validated);
        } catch (EmailCodeChallengeException|ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => __('messages.guest_upgrade_code_sent'),
            'challenge' => $this->emailCodes->summary($challenge),
        ]);
    }

    /**
     * Guest -> Free, step 2: confirm the code and apply the staged upgrade.
     * Idempotent for an account that already finished (second tab).
     */
    public function confirmGuestUpgrade(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if (! (bool) ($user->is_guest ?? false)) {
            return response()->json([
                'message' => __('messages.guest_upgrade_already_done'),
                'user' => $user->makeVisible('role'),
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        try {
            $upgradedUser = $this->guestUpgradeService->confirm($user, $validated['code']);
        } catch (EmailCodeChallengeException|ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => __('messages.guest_upgrade_confirmed'),
            'user' => $upgradedUser->makeVisible('role'),
        ]);
    }

    public function resendGuestUpgrade(Request $request)
    {
        $user = $request->user();
        if (! $user || ! (bool) ($user->is_guest ?? false)) {
            return response()->json(['message' => 'Only guest accounts can be upgraded.'], 422);
        }

        try {
            $challenge = $this->guestUpgradeService->resend($user);
        } catch (EmailCodeChallengeException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => __('messages.guest_upgrade_code_sent'),
            'challenge' => $this->emailCodes->summary($challenge),
        ]);
    }
}
