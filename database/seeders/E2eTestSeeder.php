<?php

namespace Database\Seeders;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

/**
 * Deterministic fixture for the Playwright e2e suite (docs/E2E_HARNESS.md).
 * Creates a verified, non-guest email user WITHOUT any BTCPay provisioning -
 * the e2e scenarios deliberately cover only flows that work with an empty
 * store list, because CI has no BTCPay Server.
 */
class E2eTestSeeder extends Seeder
{
    public const EMAIL = 'e2e@satflux.test';

    public const PASSWORD = 'E2e-password-123';

    public function run(): void
    {
        if (App::environment('production')) {
            $this->command?->error('E2eTestSeeder must never run in production.');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'E2E Test User',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'is_guest' => false,
                // Deliberately NO enrolled recovery key (explicit null so a
                // re-run heals a previously enrolled fixture): enrolling one
                // disables password login (User::canUsePasswordLogin), which
                // every spec relies on. The mandatory legacy-migration wizard
                // that an un-enrolled user would get is avoided by running
                // the e2e app with SEED_FIRST_REGISTRATION=false instead
                // (see the e2e job env in .github/workflows/ci.yml).
                'guest_recovery_public_key' => null,
                'guest_recovery_enrolled_at' => null,
            ],
        );

        // BTCPay stub scenarios (E2E_BTCPAY=1, docs/BTCPAY_E2E_SCENARIOS.md):
        // the invoices page requires a merchant API key and store creation
        // assigns the merchant by BTCPay user id - the stub accepts any
        // bearer token, so fixed fake values are enough. The local-first
        // checkout scenario additionally needs the Pro entitlement
        // (business_invoicing) - give the fixture user an active Pro plan.
        if (env('E2E_BTCPAY') === '1') {
            $user->forceFill([
                'btcpay_user_id' => 'stub-merchant-user',
                'btcpay_api_key' => 'stub-merchant-key',
            ])->save();

            $plan = SubscriptionPlan::firstOrCreate(
                ['code' => 'pro'],
                [
                    'name' => 'pro',
                    'display_name' => 'Pro',
                    'price_eur' => 99,
                    'billing_period' => 'year',
                    'max_stores' => 3,
                    'max_api_keys' => 3,
                    'max_ln_addresses' => null,
                    'features' => ['advanced_statistics', 'business_invoicing'],
                    'is_active' => true,
                ],
            );
            // Deterministic fixture even against a pre-existing plan row
            // (local dev DB): guarantee the entitlements the scenarios need
            // WITHOUT clobbering dev-tuned pricing/limits - a full
            // updateOrCreate would overwrite those.
            $features = (array) ($plan->features ?? []);
            $missing = array_diff(['advanced_statistics', 'business_invoicing'], $features);
            if ($missing !== [] || ! $plan->is_active) {
                $plan->forceFill([
                    'features' => array_values(array_merge($features, $missing)),
                    'is_active' => true,
                ])->save();
            }
            Subscription::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => now()->addYear(),
                ],
            );
        }
    }
}
