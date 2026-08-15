<?php

namespace App\Http\Middleware;

use App\Services\BtcPay\LightningAddressService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks creating new LN address when user reaches max_ln_addresses for their plan.
 * Count is from BTCPay API per user's stores. LightningAddressController also enforces
 * the same limit; this middleware provides server-side enforcement at route level.
 */
class EnsurePlanAllowsLnAddressCreation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        if ($user->hasUnlimitedAccess()) {
            return $next($request);
        }

        $plan = $user->currentSubscriptionPlan();
        $max = $plan?->max_ln_addresses;
        if ($max === null) {
            return $next($request);
        }

        $store = $request->route('store');
        if (! $store || $store->user_id !== $user->id) {
            return $next($request);
        }

        $currentCount = 0;
        try {
            $apiKey = $user->getBtcPayApiKeyOrFail();
            $lnService = app(LightningAddressService::class);
            foreach ($user->stores as $s) {
                $list = $lnService->listAddresses($s->btcpay_store_id, $apiKey);
                $currentCount += count($list ?? []);
            }
        } catch (\Throwable $e) {
            // Fail open: let controller handle (e.g. no API key)
            return $next($request);
        }

        if ($currentCount >= $max) {
            return response()->json([
                'message' => __('messages.lightning_address_limit_reached', [
                    'max' => $max,
                    'plan' => $plan?->display_name ?? 'Free',
                ]),
                'current_count' => $currentCount,
                'max_allowed' => $max,
                'plan' => $plan?->display_name ?? 'Free',
            ], 403);
        }

        return $next($request);
    }
}
