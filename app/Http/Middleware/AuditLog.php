<?php

namespace App\Http\Middleware;

use App\Models\AuditLog as AuditLogModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLog
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $response = $next($request);

        // Only log successful requests
        if ($response->isSuccessful() && $request->user()) {
            $targetType = null;
            $targetId = null;

            // Try to get target from route parameters
            if ($request->route('store')) {
                $targetType = 'store';
                $targetId = $request->route('store')->id ?? null;
            } elseif ($request->route('export')) {
                $targetType = 'export';
                $targetId = $request->route('export')->id ?? null;
            }

            AuditLogModel::log(
                $action,
                $targetType,
                $targetId,
                [
                    'method' => $request->method(),
                    'path' => $request->path(),
                ]
            );
        }

        return $response;
    }
}
