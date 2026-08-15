<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure store doesn't exceed API key limit (per store).
 *
 * - Only ACTIVE keys count; revoked keys do not count.
 * - Limit is per store: Free = 1 active key per store, PRO = 3 per store.
 * - Admin/Support have full access (can create keys regardless of limit).
 */
class EnsureApiKeyLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        // Admin, Support and Enterprise users have full access
        if ($user->hasUnlimitedAccess()) {
            return $next($request);
        }

        $store = $request->route('store');
        if (! $store instanceof Store) {
            return $next($request);
        }

        // Enforce store owner's plan limit for this store (active keys per store)
        $owner = $store->user;
        $plan = $owner->currentSubscriptionPlan();
        $maxApiKeys = $plan?->max_api_keys;

        if ($maxApiKeys === null) {
            return $next($request);
        }

        $activeCount = $store->apiKeys()->where('is_active', true)->count();

        if ($activeCount >= $maxApiKeys) {
            return response()->json([
                'message' => "This store has reached the maximum number of active API keys ({$maxApiKeys}) for the plan.",
                'current_count' => $activeCount,
                'max_allowed' => $maxApiKeys,
                'plan' => $plan?->display_name ?? 'Free',
            ], 403);
        }

        return $next($request);
    }
}
