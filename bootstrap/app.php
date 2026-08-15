<?php

use App\Http\Middleware\RejectGuestRestrictedFeatures;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\TrustProxies;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            TrustProxies::class,
        ]);
        $middleware->web(prepend: [
            TrustProxies::class,
            SetLocale::class,
        ]);
        // CSP on the SPA shell and web views (opt-in via CSP_ENABLED)
        $middleware->web(append: [
            SetSecurityHeaders::class,
        ]);
        $middleware->statefulApi();
        // Browser-native CSP report-uri POSTs carry the session cookie but no
        // XSRF header - statefulApi() would 419 them. The endpoint is throttled,
        // size-capped and logs sanitized technical fields only.
        $middleware->validateCsrfTokens(except: [
            'api/csp-report',
        ]);

        $middleware->alias([
            'guest.restrict' => RejectGuestRestrictedFeatures::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // For API routes, return JSON 401 instead of redirecting to login
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // Handle RouteNotFoundException that might occur during authentication redirect
        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();
