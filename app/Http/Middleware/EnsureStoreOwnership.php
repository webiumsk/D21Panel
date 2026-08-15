<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreOwnership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->route('store');

        if (! $store instanceof Store) {
            abort(404, 'Store not found');
        }

        // Owner, admin and support can access the store
        $user = $request->user();
        if ($store->user_id !== $user->id) {
            if (! $user->isSupport() && ! $user->isAdmin()) {
                abort(403, 'Unauthorized access to store');
            }

            AuditLog::log(
                'store.privileged_access',
                'store',
                $store->id,
                [
                    'accessor_role' => $user->role,
                    'store_owner_id' => $store->user_id,
                    'method' => $request->method(),
                    'path' => $request->path(),
                ]
            );
        }

        return $next($request);
    }
}
