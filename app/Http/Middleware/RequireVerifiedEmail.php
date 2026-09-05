<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedEmail
{
    /**
     * Block authenticated users who have not verified their email (SPA JSON + optional redirect).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && (bool) ($user->is_guest ?? false)) {
            return $next($request);
        }

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        // Headless BTCPay config bot uses a named Sanctum PAT; service accounts often skip inbox verification.
        if ($this->allowsBtcPayConfigBot($user)) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => __('auth.email_not_verified'), 'code' => 'email_not_verified'], 403)
            : redirect()->guest('/login');
    }

    private function allowsBtcPayConfigBot(MustVerifyEmail $user): bool
    {
        if (! $user instanceof User || ! $user->isSupport()) {
            return false;
        }

        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken
            && $token->name === 'btcpay-config-bot';
    }
}
