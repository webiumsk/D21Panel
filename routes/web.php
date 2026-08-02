<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Integrations\WooCommerceConnectController;
use App\Http\Controllers\Invoicing\BusinessDocumentController;
use App\Http\Controllers\Invoicing\BusinessDocumentPayController;
use App\Http\Controllers\LandingPayButtonController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StoreAppPageController;
use App\Http\Middleware\EnsureCompanyOwnership;
use App\Http\Middleware\EnsurePlanAllowsBusinessInvoicing;
use App\Http\Middleware\EnsureStoreOwnership;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Support\PublicSpaRoutes;
use Illuminate\Filesystem\ServeFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Signed file downloads must be registered BEFORE the SPA catch-all `/{any}`.
 * Otherwise `/export-files/...` and `/storage/...` match `{any}` and return the SPA HTML.
 */
Route::get('/export-files/{path}', function (Request $request, string $path) {
    $disk = 'exports';
    $config = config("filesystems.disks.{$disk}");

    return (new ServeFile($disk, is_array($config) ? $config : [], app()->isProduction()))($request, $path);
})->where('path', '.*');

// Must use the "public" disk (storage/app/public). "local" points at app/private - wrong for /storage/products/… uploads.
Route::get('/storage/{path}', function (Request $request, string $path) {
    $disk = 'public';
    $config = config("filesystems.disks.{$disk}");

    return (new ServeFile($disk, is_array($config) ? $config : [], app()->isProduction()))($request, $path);
})->where('path', '.*');

// OG Image for social media sharing
Route::get('/og-image.webp', [OgImageController::class, 'generate']);

// SEO: robots.txt with dynamic sitemap URL
Route::get('/robots.txt', function () {
    $sitemapUrl = rtrim(config('app.url'), '/').'/sitemap.xml';
    $content = "User-agent: *\n"
        ."Allow: /\n"
        ."Allow: /documentation\n"
        ."Allow: /documentation/*\n"
        ."Allow: /faq\n"
        ."Allow: /support\n"
        ."Allow: /login\n"
        ."Allow: /register\n"
        ."Allow: /password\n"
        ."Disallow: /stores\n"
        ."Disallow: /account\n"
        ."Disallow: /admin\n"
        ."Disallow: /dashboard\n"
        ."Disallow: /api/\n\n"
        ."Sitemap: {$sitemapUrl}\n";

    return response($content, 200, ['Content-Type' => 'text/plain']);
});

// SEO: sitemap.xml
Route::get('/sitemap.xml', [SitemapController::class, 'index']);

// Public business invoice Bitcoin pay (lazy BTCPay checkout)
Route::middleware(['throttle:30,1'])->group(function () {
    Route::get('/pay/i/{payment_token}', [BusinessDocumentPayController::class, 'show'])
        ->where('payment_token', '[A-Za-z0-9]{64}');
});

// Password reset: same URL as API so SPA can POST /api/auth/password/reset-link
// Web route is registered first → no Sanctum, no 401
Route::middleware(['throttle:password-reset'])->group(function () {
    Route::post('/api/auth/password/reset-link', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/api/auth/password/reset', [PasswordResetController::class, 'reset']);
});

// Named route for password reset email link (Laravel ResetPassword notification calls route('password.reset'))
Route::get('/reset-password/{token}', function (Request $request, string $token) {
    $email = $request->query('email', '');
    $url = rtrim(config('app.url'), '/').'/password/reset?token='.urlencode($token).'&email='.urlencode($email);

    return redirect($url);
})->name('password.reset');

// Named route for authentication redirects (used by Laravel auth middleware)
Route::get('/login', function () {
    return view('public');
})->name('login');

// Verification route for Vue router (redirects to SPA, component handles API call)
Route::get('/auth/verify-email/{id}/{hash}', function () {
    return view('public');
})->where(['id' => '[0-9]+', 'hash' => '[a-f0-9]+']);

// WooCommerce plugin - Connect flow
Route::middleware(['auth'])->group(function () {
    Route::get('/woocommerce/connect', [WooCommerceConnectController::class, 'connect']);
    Route::post('/woocommerce/connect/select-store', [WooCommerceConnectController::class, 'selectStore']);
    Route::get('/woocommerce/satflux-tickets/connect', [WooCommerceConnectController::class, 'connect']);
    Route::post('/woocommerce/satflux-tickets/connect/select-store', [WooCommerceConnectController::class, 'selectStore']);
    // Legacy aliases - WordPress plugins in the field still link the old path.
    Route::get('/woocommerce/satoshi-tickets/connect', [WooCommerceConnectController::class, 'connect']);
    Route::post('/woocommerce/satoshi-tickets/connect/select-store', [WooCommerceConnectController::class, 'selectStore']);
});

// Business invoice PDF: session auth (direct browser GET / copy link; API /api/... is stateless without SPA headers)
Route::middleware(['auth', RequireVerifiedEmail::class, EnsurePlanAllowsBusinessInvoicing::class])
    ->get('/invoicing/companies/{company}/documents/{businessDocument}/pdf', [BusinessDocumentController::class, 'pdf'])
    ->middleware(EnsureCompanyOwnership::class);
Route::middleware(['auth', RequireVerifiedEmail::class, EnsurePlanAllowsBusinessInvoicing::class])
    ->get('/invoicing/companies/{company}/documents/{businessDocument}/isdoc', [BusinessDocumentController::class, 'isdoc'])
    ->middleware(EnsureCompanyOwnership::class);
Route::middleware(['auth', RequireVerifiedEmail::class, EnsurePlanAllowsBusinessInvoicing::class])
    ->get('/invoicing/companies/{company}/documents/{businessDocument}/ubl', [BusinessDocumentController::class, 'ubl'])
    ->middleware(EnsureCompanyOwnership::class);

// Marketing landing Pay Button (BTCPay store resolved server-side; no store ID in frontend markup)
Route::post('/landing/pay-button', [LandingPayButtonController::class, 'store']);

// Inertia routes: store apps (must be before SPA catch-all)
Route::middleware(['auth'])->group(function () {
    Route::get('/stores/{store}/apps', [StoreAppPageController::class, 'index'])
        ->middleware(EnsureStoreOwnership::class);
    Route::get('/stores/{store}/apps/create', [StoreAppPageController::class, 'create'])
        ->middleware(EnsureStoreOwnership::class);
    Route::post('/stores/{store}/apps', [StoreAppPageController::class, 'store'])
        ->middleware(EnsureStoreOwnership::class);
    Route::get('/stores/{store}/apps/{app}', [StoreAppPageController::class, 'show'])
        ->middleware(EnsureStoreOwnership::class);
});

// SPA fallback (Vue Router handles all other routes)
// When an Inertia request hits a non-Inertia URL, return 409 + X-Inertia-Location
// so Inertia does a full page visit instead of showing the HTML in a modal.
// Use APP_URL + request URI so the redirect has the correct host/port (e.g. localhost:8080).
Route::get('/{any}', function (Request $request) {
    if ($request->header('X-Inertia')) {
        $location = rtrim(config('app.url', $request->getSchemeAndHttpHost()), '/').'/'.ltrim($request->getRequestUri(), '/');

        return response('', 409)->header('X-Inertia-Location', $location);
    }

    $path = $request->path();
    if (PublicSpaRoutes::isPublicMarketing($path)) {
        return view('public', [
            'showLandingShell' => PublicSpaRoutes::isLandingHome($path),
        ]);
    }

    return view('app');
})->where('any', '.*');
