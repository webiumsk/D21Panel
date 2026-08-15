<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure user has an active subscription.
 *
 * IMPORTANT: This is a non-custodial system. This middleware:
 * - Allows READ operations even if subscription is expired (grace or expired)
 * - Blocks WRITE operations if subscription is expired (beyond grace period)
 * - Never blocks payment acceptance or existing PoS terminals
 *
 * This middleware should be applied to management/write endpoints only.
 * Read endpoints should allow access even with expired subscriptions.
 */
class EnsureActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'write'): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        // Get user's current subscription
        $subscription = $user->currentSubscription();

        // If no subscription, treat as FREE plan
        if (! $subscription) {
            // FREE plan users can still read, but writes are limited
            if ($mode === 'write') {
                // Allow writes for FREE plan (they have limits enforced elsewhere)
                return $next($request);
            }

            return $next($request);
        }

        // Update subscription status based on expiration
        $subscription->updateStatus();

        // For READ operations, allow even if expired (grace or expired)
        if ($mode === 'read') {
            return $next($request);
        }

        // For WRITE operations, block if expired (beyond grace period)
        if ($subscription->isExpired()) {
            return response()->json([
                'message' => 'Your subscription has expired. Please renew to continue using management features.',
                'subscription_status' => 'expired',
                'note' => 'Payments are still active, but management features are limited.',
            ], 403);
        }

        // Allow if active or in grace period
        return $next($request);
    }
}
