<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\DocumentationImageController;
use App\Http\Controllers\Admin\EfakturaCpdsProviderController;
use App\Http\Controllers\Admin\FaqCategoryController;
use App\Http\Controllers\Admin\FaqItemController;
use App\Http\Controllers\Admin\RegWatchController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GuestAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasskeyEnvelopeLoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BlinkMigrationAlertController;
use App\Http\Controllers\BoltzReadinessController;
use App\Http\Controllers\CashuController;
use App\Http\Controllers\ChoralaController;
use App\Http\Controllers\ChoralaProxyController;
use App\Http\Controllers\ContactInquiryController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\EshopIntegrationController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\Integrations\WooCommerceConnectController;
use App\Http\Controllers\Integrations\WooCommerceIntegrationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Invoicing\AccountantExportController;
use App\Http\Controllers\Invoicing\BankInboundWebhookController;
use App\Http\Controllers\Invoicing\BankTransactionController;
use App\Http\Controllers\Invoicing\BusinessDocumentController;
use App\Http\Controllers\Invoicing\BusinessDocumentImportController;
use App\Http\Controllers\Invoicing\BusinessExpenseController;
use App\Http\Controllers\Invoicing\BusinessExpenseImportController;
use App\Http\Controllers\Invoicing\BusinessRecurringProfileController;
use App\Http\Controllers\Invoicing\CompanyAutoIssueProfileController;
use App\Http\Controllers\Invoicing\CompanyBrandingController;
use App\Http\Controllers\Invoicing\CompanyContactController;
use App\Http\Controllers\Invoicing\CompanyController;
use App\Http\Controllers\Invoicing\CompanyDocumentSequenceController;
use App\Http\Controllers\Invoicing\CompanyEmailSettingsController;
use App\Http\Controllers\Invoicing\CompanyInviteController;
use App\Http\Controllers\Invoicing\CompanyMemberController;
use App\Http\Controllers\Invoicing\CompanyNumberAllocatorController;
use App\Http\Controllers\Invoicing\CompanyRegistryController;
use App\Http\Controllers\Invoicing\CompanySlotController;
use App\Http\Controllers\Invoicing\CompanyStockItemController;
use App\Http\Controllers\Invoicing\CompanyWarehouseController;
use App\Http\Controllers\Invoicing\EfakturaController;
use App\Http\Controllers\Invoicing\EfakturaInboundInboxController;
use App\Http\Controllers\Invoicing\EphemeralBusinessDocumentController;
use App\Http\Controllers\Invoicing\IntegrationDocumentInboxController;
use App\Http\Controllers\Invoicing\InvoicingMigrationController;
use App\Http\Controllers\Invoicing\StoreDocumentSequenceController;
use App\Http\Controllers\Invoicing\UsSalesTaxController;
use App\Http\Controllers\Invoicing\ViesValidationController;
use App\Http\Controllers\Invoicing\WiseBankController;
use App\Http\Controllers\LightningAddressController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PasskeyEnvelopeController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PosOrderController;
use App\Http\Controllers\PosTerminalController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\RaffleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportSettingsController;
use App\Http\Controllers\SamRockController;
use App\Http\Controllers\SepaController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\StoreApiKeyController;
use App\Http\Controllers\StoreChecklistController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreDashboardController;
use App\Http\Controllers\StoreEmailRuleController;
use App\Http\Controllers\StoreSettingsController;
use App\Http\Controllers\StoreSettlementController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketEventImageController;
use App\Http\Controllers\UserApiKeyController;
use App\Http\Controllers\WalletConnectionController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\AuditLog;
use App\Http\Middleware\AuthenticateWooCommerceIntegration;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureApiKeyLimit;
use App\Http\Middleware\EnsureCompanyLimit;
use App\Http\Middleware\EnsureCompanyOwnership;
use App\Http\Middleware\EnsureCompanyRole;
use App\Http\Middleware\EnsurePlanAllowsBusinessInvoicing;
use App\Http\Middleware\EnsurePlanAllowsExportsAccess;
use App\Http\Middleware\EnsurePlanAllowsLnAddressCreation;
use App\Http\Middleware\EnsurePlanAllowsOfflinePaymentMethods;
use App\Http\Middleware\EnsurePlanAllowsStripe;
use App\Http\Middleware\EnsurePlanAllowsUserApiKeyCreation;
use App\Http\Middleware\EnsureStoreLimit;
use App\Http\Middleware\EnsureStoreOwnership;
use App\Http\Middleware\EnsureSupportOrAdminRole;
use App\Http\Middleware\EnsureSupportRole;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Models\EfakturaCpdsProvider;
use App\Models\Store;
use App\Services\BtcPay\StoreService;
use App\Services\Invoicing\CompanySlotService;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// Health check endpoint
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Locale endpoints (public - no auth required, but need session) (public - no auth required, but need session)
Route::middleware([StartSession::class])->group(function () {
    Route::get('/locale', [LocaleController::class, 'index']);
    Route::post('/locale', [LocaleController::class, 'setLocale']);
});

// Public pricing (single source: config/pricing.php)
Route::get('/pricing', function () {
    $pricing = config('pricing', []);

    return response()->json([
        'trial_days' => (int) ($pricing['trial_days'] ?? 30),
        'grace_days' => (int) ($pricing['grace_days'] ?? 30),
        'free' => [
            'sats_per_year' => (int) ($pricing['free']['sats_per_year'] ?? 0),
        ],
        'pro' => [
            'sats_per_year' => (int) ($pricing['pro']['sats_per_year'] ?? 0),
            'sats_per_month_display' => (int) ($pricing['pro']['sats_per_month_display'] ?? 0),
        ],
        'company_slot_packs' => app(CompanySlotService::class)->availablePacks(),
    ]);
});

// Public plan features (single source: config/plans.php)
Route::get('/plan-features', function () {
    $plans = config('plans', []);

    return response()->json([
        'invoicing_highlight_keys' => $plans['invoicing_highlight_keys'] ?? [],
        'free' => ['feature_keys' => $plans['free']['feature_keys'] ?? []],
        'pro' => ['feature_keys' => $plans['pro']['feature_keys'] ?? []],
        'enterprise' => ['feature_keys' => $plans['enterprise']['feature_keys'] ?? []],
    ]);
});

// Version endpoint (public - no auth required)
Route::get('/version', function () {
    // Try to read version from package.json
    $packageJsonPath = base_path('package.json');
    $version = '1.1.0'; // Default fallback

    if (file_exists($packageJsonPath)) {
        try {
            $packageJson = json_decode(file_get_contents($packageJsonPath), true);
            if (isset($packageJson['version'])) {
                $version = $packageJson['version'];
            }
        } catch (Exception $e) {
            // Fallback to default version
        }
    }

    return response()->json([
        'version' => $version.' (beta)',
        'name' => 'satflux.io',
    ]);
});

// Public BTCPay URL for SPA (BTCPAY_PUBLIC_URL or BTCPAY_BASE_URL; no Vite rebuild needed)
Route::get('/config', function () {
    $publicBase = rtrim((string) config('services.btcpay.public_url', ''), '/');
    if ($publicBase === '') {
        $publicBase = rtrim((string) config('services.btcpay.base_url', ''), '/');
    }

    return response()->json([
        'btcpay_base_url' => $publicBase,
        'btcpay_lightning_address_domain' => (string) (config('services.btcpay.lightning_address_domain') ?? ''),
        'efaktura_enabled' => (bool) config('efaktura.enabled'),
        // Exposed unconditionally - readiness messaging shows the statutory
        // deadline to SK companies even before the module is switched on.
        'efaktura_mandatory_from' => (string) config('efaktura.mandatory_from'),
        'efaktura_cpds_presets' => config('efaktura.enabled')
            ? EfakturaCpdsProvider::activePresets()
            : [],
    ]);
});

// Debug route for local development - verify stores exist in DB
Route::get('/debug/stores', function (Request $request) {
    if (! app()->environment('local')) {
        abort(404);
    }

    if (! auth()->check()) {
        return response()->json(['error' => 'Not authenticated'], 401);
    }

    $user = auth()->user();

    $stores = Store::where('user_id', $user->id)
        ->with('user:id,email,btcpay_user_id')
        ->get();

    // Try to get BTCPay stores if API key exists
    $btcpayStores = [];
    $btcpayError = null;
    if ($user->btcpay_api_key) {
        try {
            $storeService = app(StoreService::class);
            $btcpayStores = $storeService->listStores($user->btcpay_api_key);
        } catch (Exception $e) {
            $btcpayError = $e->getMessage();
        }
    }

    return response()->json([
        'user_id' => $user->id,
        'user_email' => $user->email,
        'btcpay_user_id' => $user->btcpay_user_id,
        'has_api_key' => ! empty($user->btcpay_api_key),
        'local_stores_count' => $stores->count(),
        'btcpay_stores_count' => is_array($btcpayStores) ? count($btcpayStores) : 0,
        'btcpay_error' => $btcpayError,
        'local_stores' => $stores->map(function ($store) {
            return [
                'id' => $store->id,
                'name' => $store->name,
                'has_btcpay_store_id' => ! empty($store->btcpay_store_id),
                'wallet_type' => $store->wallet_type,
                'created_at' => $store->created_at,
            ];
        }),
        'btcpay_stores' => is_array($btcpayStores) ? array_map(function ($s) {
            return [
                'name' => $s['name'] ?? null,
            ];
        }, $btcpayStores) : [],
        'matching_stores' => $stores->filter(function ($localStore) use ($btcpayStores) {
            if (! is_array($btcpayStores)) {
                return false;
            }
            $localBtcpayId = $localStore->btcpay_store_id;

            return collect($btcpayStores)->contains(function ($bs) use ($localBtcpayId) {
                $btcpayStoreId = $bs['id'] ?? $bs['storeId'] ?? null;

                return $btcpayStoreId === $localBtcpayId;
            });
        })->map(function ($store) {
            return [
                'id' => $store->id,
                'name' => $store->name,
                'matched' => true,
            ];
        })->values(),
    ]);
})->middleware(['auth:sanctum', RequireVerifiedEmail::class]);

// Webhooks (no auth required - verified via signature)
Route::post('/webhooks/btcpay', [WebhookController::class, 'handle']);
Route::post('/webhooks/bank-inbound', [BankInboundWebhookController::class, 'handle']);

// Public E-shop Integration API (rate limited, no auth required - uses tokens)
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/public/eshop/connect', [EshopIntegrationController::class, 'connect']);
    Route::get('/public/eshop/token/{token}', [EshopIntegrationController::class, 'getToken']);
    Route::get('/integrations/woocommerce/oauth-exchange', [WooCommerceConnectController::class, 'exchangeConnectCode']);
});

// WooCommerce plugin integration API (Bearer integration token)
Route::middleware(['throttle:60,1', AuthenticateWooCommerceIntegration::class])
    ->prefix('integrations/woocommerce')
    ->group(function () {
        Route::get('/connection', [WooCommerceIntegrationController::class, 'connection']);
        Route::post('/contacts/upsert', [WooCommerceIntegrationController::class, 'upsertContact']);
        Route::post('/documents', [WooCommerceIntegrationController::class, 'createDocument']);
        Route::get('/documents/{documentId}', [WooCommerceIntegrationController::class, 'showDocument']);
        Route::get('/documents/{documentId}/pdf', [WooCommerceIntegrationController::class, 'documentPdf']);
        Route::post('/documents/{documentId}/issue', [WooCommerceIntegrationController::class, 'issueDocument']);
    });

Route::middleware(['throttle:10,1'])->post('/contact', [ContactInquiryController::class, 'store']);

// Authentication routes (rate limited)
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/auth/register', [RegisterController::class, 'register']);
    Route::post('/auth/login', [LoginController::class, 'login']);
    Route::post('/auth/guest', [GuestAuthController::class, 'create']);
    Route::post('/auth/guest/recovery/challenge', [GuestAuthController::class, 'recoveryChallenge']);
    Route::post('/auth/guest/recovery', [GuestAuthController::class, 'recoveryRestore']);
    Route::post('/auth/logout', [LoginController::class, 'logout'])->middleware('auth:sanctum');

    // Password reset: only in web.php (POST /api/auth/password/reset-link) so no Sanctum 401

    // Email verification
    Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'sendVerificationEmail']);
});

// Passkey sign-in envelope fetch: unauthenticated by design (the ciphertext
// grants nothing without the physical authenticator; the session comes from
// the guest-recovery challenge signed with the DECRYPTED phrase). Own
// limiter so passkey attempts never consume the throttle:auth budget.
Route::middleware(['throttle:passkey-envelope'])
    ->post('/auth/passkey/envelope', [PasskeyEnvelopeLoginController::class, 'fetch']);

// Email verification (GET request from email link - no auth required, separate from main auth group)
Route::get('/auth/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['throttle:6,1'])
    ->name('verification.verify');

// CSP violation reports (report-uri; browsers POST without auth). Sanitized + size-capped.
Route::post('/csp-report', [CspReportController::class, 'store'])
    ->middleware(['throttle:30,1']);

// Chorala widget public API proxy (localhost / dev - avoids cross-origin CORS to chorala.com)
Route::any('/chorala-proxy/v1/{path}', [ChoralaProxyController::class, 'forward'])
    ->where('path', '.*')
    ->middleware(['throttle:60,1']);

Route::get('/chorala/widget-settings', [ChoralaController::class, 'widgetSettings'])
    ->middleware(['throttle:60,1']);

// Profile fetch is deliberately outside RequireVerifiedEmail: a non-guest
// account whose email is still unverified (legacy link flow) must be able to
// load its own profile, otherwise the SPA drops the session and shows the
// login/register form instead of the "check your email" screen.
Route::middleware(['auth:sanctum', 'throttle:api-user'])
    ->get('/user', [AccountController::class, 'user']);

// Authenticated routes (email must be verified - classic registration and API use)
Route::middleware(['auth:sanctum', RequireVerifiedEmail::class, 'throttle:api-user'])->group(function () {
    // User/Account routes
    Route::get('/chorala/widget-token', [ChoralaController::class, 'widgetToken']);
    Route::get('/user/limits', [AccountController::class, 'limits']);
    // Passkey recovery envelopes (ciphertext-only, see PasskeyEnvelopeController)
    Route::get('/account/passkey-envelopes', [PasskeyEnvelopeController::class, 'index']);
    Route::put('/account/passkey-envelopes/{credentialId}', [PasskeyEnvelopeController::class, 'upsert']);
    Route::delete('/account/passkey-envelopes/{credentialId}', [PasskeyEnvelopeController::class, 'destroy']);
    Route::put('/user', [AccountController::class, 'updateProfile']);
    Route::put('/user/password', [AccountController::class, 'updatePassword'])
        ->middleware('throttle:5,1');
    // Guest -> Free upgrade via 6-digit email code (EmailCodeChallengeService).
    // Own limiters: guessing and outbound mail never eat the api-user budget.
    Route::post('/user/guest/upgrade/request', [AccountController::class, 'requestGuestUpgrade'])
        ->middleware('throttle:email-code-send');
    Route::post('/user/guest/upgrade/confirm', [AccountController::class, 'confirmGuestUpgrade'])
        ->middleware('throttle:email-code-confirm');
    Route::post('/user/guest/upgrade/resend', [AccountController::class, 'resendGuestUpgrade'])
        ->middleware('throttle:email-code-send');

    // Panel API keys (for our API, not BTCPay)
    Route::middleware('guest.restrict')->group(function () {
        Route::get('/user/api-keys', [UserApiKeyController::class, 'index']);
        Route::post('/user/api-keys', [UserApiKeyController::class, 'store'])
            ->middleware(EnsurePlanAllowsUserApiKeyCreation::class);
        Route::delete('/user/api-keys/{user_api_key}', [UserApiKeyController::class, 'destroy']);
    });

    // Messages (notifications)
    Route::get('/messages', [MessageController::class, 'index']);
    Route::get('/messages/count', [MessageController::class, 'count']);
    Route::patch('/messages/{id}/read', [MessageController::class, 'markAsRead'])->where('id', '[a-zA-Z0-9_-]+');
    Route::post('/messages/mark-all-read', [MessageController::class, 'markAllAsRead']);

    // Company invite acceptance (docs/COMPANY_SHARING.md, "C4"). Reachable by any
    // authenticated user - the invitee is not yet a member and entitlement is
    // scoped to the company OWNER's plan, so no per-invitee plan gate here.
    Route::prefix('invoicing')->middleware(['throttle:30,1'])->group(function () {
        Route::get('/invites/{token}', [CompanyInviteController::class, 'show'])
            ->where('token', '[A-Za-z0-9_]{4,64}');
        Route::post('/invites/{token}/accept', [CompanyInviteController::class, 'accept'])
            ->where('token', '[A-Za-z0-9_]{4,64}');
    });

    // Business invoicing (Pro+; seed accounts may use local-first + ephemeral bridges)
    Route::middleware([EnsurePlanAllowsBusinessInvoicing::class])
        ->prefix('invoicing')
        ->group(function () {
            Route::middleware(['throttle:30,1'])->group(function () {
                Route::get('/company-registry/coverage', [CompanyRegistryController::class, 'coverage']);
                Route::get('/company-registry/search', [CompanyRegistryController::class, 'search']);
                Route::get('/company-registry/entities/{entityId}', [CompanyRegistryController::class, 'show'])
                    ->where('entityId', '[A-Za-z0-9._-]{4,64}');
                Route::post('/vies/validate', [ViesValidationController::class, 'validateVat']);
            });

            Route::get('/companies', [CompanyController::class, 'index']);
            Route::get('/migration/status', [InvoicingMigrationController::class, 'status']);
            Route::get('/migration/export', [InvoicingMigrationController::class, 'export'])
                ->middleware('throttle:5,60');
            Route::get('/migration/export-attachments', [InvoicingMigrationController::class, 'exportAttachments'])
                ->middleware('throttle:3,60');
            Route::post('/ephemeral/pdf', [EphemeralBusinessDocumentController::class, 'pdfWithoutCompany']);
            Route::post('/ephemeral/email-preview', [EphemeralBusinessDocumentController::class, 'emailPreviewWithoutCompany']);
            Route::post('/ephemeral/send-email', [EphemeralBusinessDocumentController::class, 'sendEmailWithoutCompany']);
            Route::post('/ephemeral/email-settings/test-smtp', [EphemeralBusinessDocumentController::class, 'testEmailSettingsSmtpWithoutCompany']);
            Route::post('/ephemeral/isdoc', [EphemeralBusinessDocumentController::class, 'isdocWithoutCompany']);
            Route::post('/ephemeral/ubl', [EphemeralBusinessDocumentController::class, 'ublWithoutCompany']);
            Route::post('/ephemeral/btcpay-checkout', [EphemeralBusinessDocumentController::class, 'btcpayCheckoutWithoutCompany']);
            Route::post('/ephemeral/btcpay-checkout/existing', [EphemeralBusinessDocumentController::class, 'btcpayCheckoutExistingWithoutCompany']);
            Route::get('/ephemeral/btcpay-status', [EphemeralBusinessDocumentController::class, 'btcpayStatus']);
            Route::get('/ephemeral/efaktura/bridge', [EphemeralBusinessDocumentController::class, 'efakturaBridge']);
            Route::get('/ephemeral/efaktura/status', [EphemeralBusinessDocumentController::class, 'efakturaStatus']);
            Route::post('/ephemeral/efaktura/status-bulk', [EphemeralBusinessDocumentController::class, 'efakturaStatusBulk']);
            Route::post('/ephemeral/efaktura/send', [EphemeralBusinessDocumentController::class, 'efakturaSendWithoutCompany']);
            Route::post('/ephemeral/efaktura/refresh', [EphemeralBusinessDocumentController::class, 'efakturaRefreshWithoutCompany']);
            Route::post('/ephemeral/efaktura/test-connection', [EphemeralBusinessDocumentController::class, 'efakturaTestConnection'])
                ->middleware('throttle:10,1');
            Route::post('/ephemeral/bulk/pdf-zip', [EphemeralBusinessDocumentController::class, 'bulkPdfZipWithoutCompany']);
            Route::post('/ephemeral/bulk/pdf-merge', [EphemeralBusinessDocumentController::class, 'bulkPdfMergeWithoutCompany']);
            Route::post('/ephemeral/accountant-export', [AccountantExportController::class, 'ephemeralWithoutCompany']);
            Route::post('/companies', [CompanyController::class, 'store'])
                ->middleware(EnsureCompanyLimit::class);
            // User-level slot purchase - deliberately no company ownership/limit middleware
            Route::post('/company-slots/purchase', [CompanySlotController::class, 'purchase'])
                ->middleware('throttle:10,1');
            Route::get('/companies/{company}', [CompanyController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/summary', [CompanyController::class, 'summary'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/us-sales-tax/preview', [UsSalesTaxController::class, 'preview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}', [CompanyController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/app-settings', [CompanyController::class, 'updateAppSettings'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::patch('/companies/{company}/email-settings', [CompanyEmailSettingsController::class, 'update'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);

            Route::get('/companies/{company}/number-series/preview', [CompanyDocumentSequenceController::class, 'preview'])
                ->middleware(EnsureCompanyOwnership::class);

            // Server number allocator (audit F3) - company-scoped, store-independent.
            Route::post('/companies/{company}/number-allocator/release', [CompanyNumberAllocatorController::class, 'release'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/number-allocator/reserve', [CompanyNumberAllocatorController::class, 'reserve'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/number-allocator/confirm', [CompanyNumberAllocatorController::class, 'confirm'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/number-allocator/void', [CompanyNumberAllocatorController::class, 'void'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/number-allocator/status', [CompanyNumberAllocatorController::class, 'status'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/number-series', [CompanyDocumentSequenceController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/number-series', [CompanyDocumentSequenceController::class, 'store'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/number-series/{sequence}', [CompanyDocumentSequenceController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/number-series/{sequence}', [CompanyDocumentSequenceController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/email-settings/test-smtp', [CompanyEmailSettingsController::class, 'testSmtp'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::post('/companies/{company}/email-settings/ephemeral/test-smtp', [EphemeralBusinessDocumentController::class, 'testEmailSettingsSmtp'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/reset-data', [CompanyController::class, 'resetData'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);

            // Company invites - owner-only (docs/COMPANY_SHARING.md, "C4").
            Route::get('/companies/{company}/invite-recipient', [CompanyInviteController::class, 'recipient'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::get('/companies/{company}/invites', [CompanyInviteController::class, 'index'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::post('/companies/{company}/invites', [CompanyInviteController::class, 'store'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::delete('/companies/{company}/invites/{invite}', [CompanyInviteController::class, 'destroy'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);

            // Company members - owner-only (docs/COMPANY_SHARING.md, "C5").
            Route::get('/companies/{company}/members', [CompanyMemberController::class, 'index'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::delete('/companies/{company}/members/{member}', [CompanyMemberController::class, 'destroy'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);
            Route::patch('/companies/{company}/stores', [CompanyController::class, 'updateStores'])
                ->middleware([EnsureCompanyOwnership::class, EnsureCompanyRole::class.':owner']);

            Route::post('/companies/{company}/branding/logo', [CompanyBrandingController::class, 'uploadLogo'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/branding/logo', [CompanyBrandingController::class, 'deleteLogo'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/branding/logo', [CompanyBrandingController::class, 'showLogo'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/branding/signature-stamp', [CompanyBrandingController::class, 'uploadSignatureStamp'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/branding/signature-stamp', [CompanyBrandingController::class, 'deleteSignatureStamp'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/branding/signature-stamp', [CompanyBrandingController::class, 'showSignatureStamp'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/companies/{company}/contacts', [CompanyContactController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/contacts', [CompanyContactController::class, 'store'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/contacts/import/example', [CompanyContactController::class, 'importExample'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/contacts/import/preview', [CompanyContactController::class, 'importPreview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/contacts/import', [CompanyContactController::class, 'import'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/contacts/bulk', [CompanyContactController::class, 'bulk'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/contacts/{contact}', [CompanyContactController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/contacts/{contact}', [CompanyContactController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/contacts/{contact}', [CompanyContactController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/companies/{company}/stock-items/search', [CompanyStockItemController::class, 'search'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/stock-items/import/example', [CompanyStockItemController::class, 'importExample'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/stock-items/import/preview', [CompanyStockItemController::class, 'importPreview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/stock-items/import', [CompanyStockItemController::class, 'import'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/stock-items', [CompanyStockItemController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/stock-items', [CompanyStockItemController::class, 'store'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/stock-items/{stockItem}', [CompanyStockItemController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/stock-items/{stockItem}', [CompanyStockItemController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/stock-items/{stockItem}', [CompanyStockItemController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/stock-items/{stockItem}/transfer', [CompanyWarehouseController::class, 'transfer'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/companies/{company}/warehouses', [CompanyWarehouseController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/warehouses', [CompanyWarehouseController::class, 'store'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/warehouses/{warehouse}', [CompanyWarehouseController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/warehouses/{warehouse}', [CompanyWarehouseController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/warehouses/{warehouse}', [CompanyWarehouseController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/companies/{company}/recurring-profiles', [BusinessRecurringProfileController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/recurring-profiles', [BusinessRecurringProfileController::class, 'store'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/recurring-profiles/from-document/{businessDocument}', [BusinessRecurringProfileController::class, 'fromDocument'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/recurring-profiles/{recurringProfile}', [BusinessRecurringProfileController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/recurring-profiles/{recurringProfile}', [BusinessRecurringProfileController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/recurring-profiles/{recurringProfile}', [BusinessRecurringProfileController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/recurring-profiles/{recurringProfile}/generate', [BusinessRecurringProfileController::class, 'generateNow'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/integration-inbox/deeplink', [IntegrationDocumentInboxController::class, 'resolveDeepLink']);

            Route::get('/companies/{company}/integration-inbox', [IntegrationDocumentInboxController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/integration-inbox/{inbox}/dismiss', [IntegrationDocumentInboxController::class, 'dismiss'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/integration-inbox/{inbox}/imported', [IntegrationDocumentInboxController::class, 'markImported'])
                ->middleware(EnsureCompanyOwnership::class);

            // Headless auto-issue profile (WooCommerce paid orders, P3).
            Route::get('/companies/{company}/auto-issue-profile', [CompanyAutoIssueProfileController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::put('/companies/{company}/auto-issue-profile', [CompanyAutoIssueProfileController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/auto-issue-profile', [CompanyAutoIssueProfileController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/stores/{store}/integration-inbox', [IntegrationDocumentInboxController::class, 'indexForStore'])
                ->middleware(EnsureStoreOwnership::class);
            Route::post('/stores/{store}/integration-inbox/{inbox}/dismiss', [IntegrationDocumentInboxController::class, 'dismissForStore'])
                ->middleware(EnsureStoreOwnership::class);
            Route::post('/stores/{store}/integration-inbox/{inbox}/imported', [IntegrationDocumentInboxController::class, 'markImportedForStore'])
                ->middleware(EnsureStoreOwnership::class);
            Route::get('/stores/{store}/number-series/preview', [StoreDocumentSequenceController::class, 'preview'])
                ->middleware(EnsureStoreOwnership::class);
            Route::post('/stores/{store}/number-series/reserve', [StoreDocumentSequenceController::class, 'reserve'])
                ->middleware(EnsureStoreOwnership::class);

            Route::get('/companies/{company}/documents/import/fields', [BusinessDocumentImportController::class, 'fields'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/import/example', [BusinessDocumentImportController::class, 'example'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/import/preview', [BusinessDocumentImportController::class, 'preview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/import', [BusinessDocumentImportController::class, 'import'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/companies/{company}/documents', [BusinessDocumentController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/bulk', [BusinessDocumentController::class, 'bulk'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents', [BusinessDocumentController::class, 'store'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/pdf', [EphemeralBusinessDocumentController::class, 'pdf'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/email-preview', [EphemeralBusinessDocumentController::class, 'emailPreview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/send-email', [EphemeralBusinessDocumentController::class, 'sendEmail'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/isdoc', [EphemeralBusinessDocumentController::class, 'isdoc'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/ubl', [EphemeralBusinessDocumentController::class, 'ubl'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/btcpay-checkout', [EphemeralBusinessDocumentController::class, 'btcpayCheckout'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/efaktura/send', [EphemeralBusinessDocumentController::class, 'efakturaSend'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/efaktura/refresh', [EphemeralBusinessDocumentController::class, 'efakturaRefresh'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/bulk/pdf-zip', [EphemeralBusinessDocumentController::class, 'bulkPdfZip'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/bulk/pdf-merge', [EphemeralBusinessDocumentController::class, 'bulkPdfMerge'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/ephemeral/accountant-export', [AccountantExportController::class, 'ephemeral'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/accountant-export', [AccountantExportController::class, 'download'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/credit-note-from-invoice', [BusinessDocumentController::class, 'createCreditNoteFromInvoice'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/{businessDocument}', [BusinessDocumentController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/documents/{businessDocument}', [BusinessDocumentController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/issue', [BusinessDocumentController::class, 'issue'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/cancel', [BusinessDocumentController::class, 'cancel'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/{businessDocument}/pdf', [BusinessDocumentController::class, 'pdf'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/{businessDocument}/isdoc', [BusinessDocumentController::class, 'isdoc'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/{businessDocument}/ubl', [BusinessDocumentController::class, 'ubl'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/{businessDocument}/efaktura/compliance', [EfakturaController::class, 'compliance'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/efaktura/send', [EfakturaController::class, 'send'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/efaktura/compliance/refresh', [EfakturaController::class, 'refreshCompliance'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/efaktura/poll-inbound', [EfakturaController::class, 'pollInbound'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/efaktura/inbox', [EfakturaInboundInboxController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/efaktura/inbox/{receipt}', [EfakturaInboundInboxController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/efaktura/inbox/{receipt}/imported', [EfakturaInboundInboxController::class, 'markImported'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/efaktura/inbox/{receipt}/dismiss', [EfakturaInboundInboxController::class, 'dismiss'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/efaktura/test-connection', [EfakturaController::class, 'testConnection'])
                ->middleware([EnsureCompanyOwnership::class, 'throttle:10,1']);
            Route::post('/companies/{company}/efaktura/compliance-bulk', [EfakturaController::class, 'complianceBulk'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/mark-paid', [BusinessDocumentController::class, 'markPaid'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/unmark-paid', [BusinessDocumentController::class, 'unmarkPaid'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/{businessDocument}/email-preview', [BusinessDocumentController::class, 'emailPreview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/send-email', [BusinessDocumentController::class, 'sendEmail'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/documents/{businessDocument}/history', [BusinessDocumentController::class, 'history'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/create-final-invoice', [BusinessDocumentController::class, 'createFinalInvoice'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/approve-quote', [BusinessDocumentController::class, 'approveQuote'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/reject-quote', [BusinessDocumentController::class, 'rejectQuote'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/create-invoice-from-quote', [BusinessDocumentController::class, 'createInvoiceFromQuote'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/documents/{businessDocument}/duplicate', [BusinessDocumentController::class, 'duplicate'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/documents/{businessDocument}', [BusinessDocumentController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/companies/{company}/expenses', [BusinessExpenseController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses', [BusinessExpenseController::class, 'store'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/expenses/import/excel/example', [BusinessExpenseImportController::class, 'example'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/expenses/import/excel/fields', [BusinessExpenseImportController::class, 'fields'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/import/excel/preview', [BusinessExpenseImportController::class, 'preview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/import/excel', [BusinessExpenseImportController::class, 'import'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/import/attachments/preview', [BusinessExpenseImportController::class, 'attachmentsPreview'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/import/attachments', [BusinessExpenseImportController::class, 'attachmentsImport'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/expenses/isdoc-extract-quota', [BusinessExpenseController::class, 'isdocExtractQuota'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/isdoc-packs/purchase', [BusinessExpenseController::class, 'purchaseIsdocPack'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/detect-isdoc', [BusinessExpenseController::class, 'detectIsdoc'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/extract', [BusinessExpenseController::class, 'extract'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/import', [BusinessExpenseController::class, 'importFromDocument'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/bulk', [BusinessExpenseController::class, 'bulk'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/expenses/{businessExpense}', [BusinessExpenseController::class, 'show'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::patch('/companies/{company}/expenses/{businessExpense}', [BusinessExpenseController::class, 'update'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/{businessExpense}/duplicate', [BusinessExpenseController::class, 'duplicate'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/{businessExpense}/mark-paid', [BusinessExpenseController::class, 'markPaid'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/{businessExpense}/unmark-paid', [BusinessExpenseController::class, 'unmarkPaid'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/expenses/{businessExpense}', [BusinessExpenseController::class, 'destroy'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/expenses/{businessExpense}/attachment', [BusinessExpenseController::class, 'uploadAttachment'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/expenses/{businessExpense}/attachment', [BusinessExpenseController::class, 'attachment'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/expenses/{businessExpense}/attachments/{businessExpenseAttachment}', [BusinessExpenseController::class, 'downloadStoredAttachment'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::delete('/companies/{company}/expenses/{businessExpense}/attachments/{businessExpenseAttachment}', [BusinessExpenseController::class, 'destroyStoredAttachment'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/expenses/{businessExpense}/history', [BusinessExpenseController::class, 'history'])
                ->middleware(EnsureCompanyOwnership::class);

            Route::get('/companies/{company}/bank-transactions', [BankTransactionController::class, 'index'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/bank-transactions/batches', [BankTransactionController::class, 'batches'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/bank-transactions/import', [BankTransactionController::class, 'import'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/bank-transactions/batches/{batch}/auto-match', [BankTransactionController::class, 'autoMatchBatch'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/bank-transactions/inbound-email', [BankTransactionController::class, 'inboundEmailAddress'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/bank-transactions/{bankTransaction}/suggestions', [BankTransactionController::class, 'suggestions'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/bank-transactions/{bankTransaction}/match', [BankTransactionController::class, 'match'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/bank-transactions/{bankTransaction}/ignore', [BankTransactionController::class, 'ignore'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/bank-transactions/{bankTransaction}/unmatch', [BankTransactionController::class, 'unmatch'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/bank-transactions/{bankTransaction}/create-expense', [BankTransactionController::class, 'createExpense'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::get('/companies/{company}/wise/status', [WiseBankController::class, 'status'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/wise/connect', [WiseBankController::class, 'connect'])
                ->middleware(EnsureCompanyOwnership::class);
            Route::post('/companies/{company}/wise/sync', [WiseBankController::class, 'sync'])
                ->middleware(EnsureCompanyOwnership::class);
        });

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/analytics', [DashboardController::class, 'analytics']);

    // Stores
    Route::get('/stores', [StoreController::class, 'index']);
    Route::post('/stores', [StoreController::class, 'store'])
        ->middleware([EnsureStoreLimit::class, AuditLog::class.':store.created']);
    Route::patch('/stores/{store}/wallet-type', [StoreController::class, 'setWalletType'])
        ->middleware(EnsureStoreOwnership::class);
    Route::get('/stores/{store}', [StoreController::class, 'show'])
        ->middleware(EnsureStoreOwnership::class);

    Route::prefix('stores/{store}')->middleware(EnsureStoreOwnership::class)->group(function () {
        // Store Checklist
        Route::get('/checklist', [StoreChecklistController::class, 'index']);
        Route::put('/checklist/{itemKey}', [StoreChecklistController::class, 'update']);

        // Store Dashboard
        Route::get('/dashboard', [StoreDashboardController::class, 'show']);

        // Store Settings
        Route::get('/settings', [StoreSettingsController::class, 'show']);
        Route::put('/settings', [StoreSettingsController::class, 'update'])
            ->middleware(AuditLog::class.':store.updated');

        Route::get('/email-rules/triggers', [StoreEmailRuleController::class, 'triggers']);
        Route::get('/email-rules', [StoreEmailRuleController::class, 'index']);
        Route::post('/email-rules', [StoreEmailRuleController::class, 'store']);
        Route::put('/email-rules/{store_email_rule}', [StoreEmailRuleController::class, 'update']);
        Route::delete('/email-rules/{store_email_rule}', [StoreEmailRuleController::class, 'destroy']);

        Route::post('/blink-migration-alert/snooze', [BlinkMigrationAlertController::class, 'snooze']);
        Route::post('/blink-migration-alert/dismiss', [BlinkMigrationAlertController::class, 'dismiss']);
    });

    // Cashu (wallet_type=cashu)
    Route::middleware([EnsureStoreOwnership::class])->prefix('stores/{store}/cashu')->group(function () {
        Route::post('confirm-edit', [CashuController::class, 'confirmEdit']);
        Route::get('settings', [CashuController::class, 'getSettings']);
        Route::put('settings', [CashuController::class, 'updateSettings']);

        Route::get('payments', [CashuController::class, 'listPayments']);
        Route::post('payments/{quoteId}/retry', [CashuController::class, 'retryPayment']);
    });

    // SamRock (Aqua + Boltz)
    Route::middleware([EnsureStoreOwnership::class])->prefix('stores/{store}/samrock')->group(function () {
        Route::post('otps', [SamRockController::class, 'createOtp']);
        Route::get('otps/{otp}', [SamRockController::class, 'getOtpStatus']);
        Route::get('otps/{otp}/qr', [SamRockController::class, 'getOtpQr']);
        Route::delete('otps/{otp}', [SamRockController::class, 'deleteOtp']);
        Route::post('complete', [SamRockController::class, 'complete']);
    });

    // Store Logo
    Route::post('/stores/{store}/logo', [StoreController::class, 'uploadLogo'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':store.logo.uploaded']);
    Route::delete('/stores/{store}/logo', [StoreController::class, 'deleteLogo'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':store.logo.deleted']);

    // Delete Store
    Route::delete('/stores/{store}', [StoreController::class, 'destroy'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':store.deleted']);

    // Invoices
    Route::get('/stores/{store}/invoices', [InvoiceController::class, 'index'])
        ->middleware(EnsureStoreOwnership::class);
    Route::get('/stores/{store}/invoices/export', [InvoiceController::class, 'exportCsv'])
        ->middleware(EnsureStoreOwnership::class);

    // Reports (list visible to all; download/retry require Pro+ or admin/support)
    Route::middleware('guest.restrict')->group(function () {
        Route::get('/stores/{store}/exports', [ExportController::class, 'index'])
            ->middleware(EnsureStoreOwnership::class);
        Route::post('/stores/{store}/exports', [ExportController::class, 'store'])
            ->middleware([EnsureStoreOwnership::class, EnsurePlanAllowsExportsAccess::class, AuditLog::class.':export.created']);
        Route::get('/exports', [ExportController::class, 'all']);
        Route::get('/exports/{export}/download', [ExportController::class, 'download'])
            ->middleware(EnsurePlanAllowsExportsAccess::class);
        Route::post('/exports/{export}/retry', [ExportController::class, 'retry'])
            ->middleware(EnsurePlanAllowsExportsAccess::class);
        Route::delete('/exports/{export}', [ExportController::class, 'destroy'])
            ->middleware(EnsurePlanAllowsExportsAccess::class);

        // PDF report (Pro+ or admin/support only)
        Route::get('/stores/{store}/report/pdf', [ReportController::class, 'pdf'])
            ->middleware([EnsureStoreOwnership::class, EnsurePlanAllowsExportsAccess::class]);

        // Report settings (GET for all, PUT requires Pro+)
        Route::get('/stores/{store}/report-settings', [ReportSettingsController::class, 'show'])
            ->middleware(EnsureStoreOwnership::class);
        Route::put('/stores/{store}/report-settings', [ReportSettingsController::class, 'update'])
            ->middleware([EnsureStoreOwnership::class, EnsurePlanAllowsExportsAccess::class]);
    });

    // Apps
    Route::get('/stores/{store}/apps', [AppController::class, 'index'])
        ->middleware(EnsureStoreOwnership::class);
    Route::post('/stores/{store}/apps', [AppController::class, 'store'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':app.created']);
    Route::get('/stores/{store}/apps/{app}', [AppController::class, 'show'])
        ->middleware(EnsureStoreOwnership::class);
    Route::put('/stores/{store}/apps/{app}', [AppController::class, 'update'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':app.updated']);
    Route::delete('/stores/{store}/apps/{app}', [AppController::class, 'destroy'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':app.deleted']);

    // Lightning Addresses
    Route::middleware('guest.restrict')->group(function () {
        Route::get('/stores/{store}/lightning-addresses', [LightningAddressController::class, 'index'])
            ->middleware(EnsureStoreOwnership::class);
        Route::get('/stores/{store}/lightning-addresses/{username}', [LightningAddressController::class, 'show'])
            ->middleware(EnsureStoreOwnership::class);
        Route::post('/stores/{store}/lightning-addresses/{username}', [LightningAddressController::class, 'store'])
            ->middleware([EnsureStoreOwnership::class, EnsurePlanAllowsLnAddressCreation::class, AuditLog::class.':lightning-address.created']);
        Route::put('/stores/{store}/lightning-addresses/{username}', [LightningAddressController::class, 'update'])
            ->middleware([EnsureStoreOwnership::class, EnsurePlanAllowsLnAddressCreation::class, AuditLog::class.':lightning-address.updated']);
        Route::delete('/stores/{store}/lightning-addresses/{username}', [LightningAddressController::class, 'destroy'])
            ->middleware([EnsureStoreOwnership::class, AuditLog::class.':lightning-address.deleted']);
    });

    // Stripe (Pro+ only)
    Route::middleware(['guest.restrict', EnsureStoreOwnership::class, EnsurePlanAllowsStripe::class])->group(function () {
        Route::get('/stores/{store}/stripe/settings', [StripeController::class, 'getSettings']);
        Route::put('/stores/{store}/stripe/settings', [StripeController::class, 'updateSettings']);
        Route::delete('/stores/{store}/stripe/settings', [StripeController::class, 'deleteSettings']);
        Route::post('/stores/{store}/stripe/test', [StripeController::class, 'testConnection']);
        Route::post('/stores/{store}/stripe/webhook/register', [StripeController::class, 'registerWebhook']);
        Route::get('/stores/{store}/stripe/webhook/status', [StripeController::class, 'getWebhookStatus']);
    });

    // SEPA Instant QR - deliberately available to ALL roles incl. guests
    // (core free feature for the Slovak cashless mandate): no guest.restrict,
    // no plan middleware, only store ownership.
    Route::prefix('stores/{store}/sepa')->middleware([EnsureStoreOwnership::class])->group(function () {
        Route::get('/status', [SepaController::class, 'status'])
            ->middleware('throttle:10,1');
        Route::get('/settings', [SepaController::class, 'getSettings']);
        Route::get('/inbound-email', [SepaController::class, 'inboundEmail']);
        Route::put('/settings', [SepaController::class, 'updateSettings'])
            ->middleware(AuditLog::class.':sepa.settings_updated');
        Route::post('/certificate', [SepaController::class, 'uploadCertificate'])
            ->middleware(AuditLog::class.':sepa.certificate_uploaded');
        Route::delete('/certificate', [SepaController::class, 'deleteCertificate'])
            ->middleware(AuditLog::class.':sepa.certificate_deleted');
        Route::post('/fio-token', [SepaController::class, 'setFioToken'])
            ->middleware(AuditLog::class.':sepa.fio_token_set');
        Route::delete('/fio-token', [SepaController::class, 'clearFioToken'])
            ->middleware(AuditLog::class.':sepa.fio_token_cleared');
        Route::post('/test', [SepaController::class, 'testBackend'])
            ->middleware('throttle:10,1');
        Route::get('/payment-requests', [SepaController::class, 'paymentRequests']);
        Route::post('/payment-requests/{reference}/confirm', [SepaController::class, 'confirmPaymentRequest'])
            ->where('reference', '[a-zA-Z0-9_-]+')
            ->middleware(AuditLog::class.':sepa.payment_confirmed');
    });

    // Store API Keys
    Route::middleware('guest.restrict')->group(function () {
        Route::get('/stores/{store}/api-keys', [StoreApiKeyController::class, 'index'])
            ->middleware(EnsureStoreOwnership::class);
        Route::post('/stores/{store}/api-keys', [StoreApiKeyController::class, 'store'])
            ->middleware([EnsureStoreOwnership::class, EnsureApiKeyLimit::class, AuditLog::class.':api-key.created', 'throttle:10,1']);
        Route::get('/stores/{store}/api-keys/{apiKey}', [StoreApiKeyController::class, 'show'])
            ->middleware(EnsureStoreOwnership::class);
        Route::delete('/stores/{store}/api-keys/{apiKey}', [StoreApiKeyController::class, 'destroy'])
            ->middleware([EnsureStoreOwnership::class, AuditLog::class.':api-key.deleted']);
        Route::post('/stores/{store}/api-keys/{apiKey}/regenerate', [StoreApiKeyController::class, 'regenerate'])
            ->middleware([EnsureStoreOwnership::class, AuditLog::class.':api-key.regenerated', 'throttle:5,1']);
        Route::post('/stores/{store}/api-keys/token', [StoreApiKeyController::class, 'generateToken'])
            ->middleware([EnsureStoreOwnership::class, AuditLog::class.':api-key.token.generated']);
    });

    // Product Images
    Route::post('/stores/{store}/products/image', [ProductImageController::class, 'upload'])
        ->middleware(['guest.restrict', EnsureStoreOwnership::class, AuditLog::class.':product.image.uploaded']);

    // Plans (public pricing)
    Route::get('/plans', [PlanController::class, 'index']);

    // Stats
    Route::get('/stores/{store}/stats', [StatsController::class, 'store'])
        ->middleware(EnsureStoreOwnership::class);
    Route::get('/stats/advanced', [StatsController::class, 'advanced'])
        ->middleware('guest.restrict');

    // PoS terminals and orders
    Route::prefix('stores/{store}')->middleware(EnsureStoreOwnership::class)->group(function () {
        Route::get('/pos-terminals', [PosTerminalController::class, 'index']);
        Route::post('/pos-terminals', [PosTerminalController::class, 'store'])
            ->middleware(AuditLog::class.':pos-terminal.created');
        Route::put('/pos-terminals/{pos_terminal}', [PosTerminalController::class, 'update'])
            ->middleware([EnsurePlanAllowsOfflinePaymentMethods::class, AuditLog::class.':pos-terminal.updated']);
        Route::delete('/pos-terminals/{pos_terminal}', [PosTerminalController::class, 'destroy'])
            ->middleware(AuditLog::class.':pos-terminal.deleted');
        Route::get('/pos-orders', [PosOrderController::class, 'index']);
        Route::post('/pos-orders', [PosOrderController::class, 'store'])
            ->middleware(AuditLog::class.':pos-order.created');
    });

    // Boltz readiness (informational snapshot; authoritative validation happens in BTCPay)
    Route::get('/stores/{store}/boltz/readiness', [BoltzReadinessController::class, 'show'])
        ->middleware([EnsureStoreOwnership::class]);

    // Settlement ledger (synced from BTCPay invoice payments; Boltz net side is estimated)
    Route::get('/stores/{store}/settlements', [StoreSettlementController::class, 'index'])
        ->middleware([EnsureStoreOwnership::class]);
    Route::post('/stores/{store}/settlements/sync', [StoreSettlementController::class, 'sync'])
        ->middleware([EnsureStoreOwnership::class, 'throttle:10,1']);

    // Wallet Connections (Merchant)
    Route::get('/stores/{store}/wallet-connection', [WalletConnectionController::class, 'show'])
        ->middleware([EnsureStoreOwnership::class]);
    Route::post('/stores/{store}/wallet-connection/reveal', [WalletConnectionController::class, 'revealForOwner'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':wallet_connection.revealed', 'throttle:5,1']);
    Route::post('/stores/{store}/wallet-connection', [WalletConnectionController::class, 'store'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':wallet_connection.created']);
    // Replacing a CONNECTED wallet: password re-auth + 6-digit email code
    // (WalletChangeConfirmationGuard). confirm reveals the secret and grants
    // the write for a short window; guests are told to upgrade first.
    Route::post('/stores/{store}/wallet-connection/change/request', [WalletConnectionController::class, 'requestChange'])
        ->middleware([EnsureStoreOwnership::class, 'throttle:email-code-send']);
    Route::post('/stores/{store}/wallet-connection/change/confirm', [WalletConnectionController::class, 'confirmChange'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':wallet_connection.revealed', 'throttle:email-code-confirm']);
    Route::post('/stores/{store}/wallet-connection/change/resend', [WalletConnectionController::class, 'resendChange'])
        ->middleware([EnsureStoreOwnership::class, 'throttle:email-code-send']);
    Route::post('/stores/{store}/wallet-connection/check-duplicate', [WalletConnectionController::class, 'checkDuplicate'])
        ->middleware([EnsureStoreOwnership::class]);
    // Server-side LUD-21 verify probe for unknown Lightning-address domains
    Route::post('/stores/{store}/wallet-connection/lnaddress-probe', [WalletConnectionController::class, 'lnaddressProbe'])
        ->middleware([EnsureStoreOwnership::class, 'throttle:10,1']);
    // Endpoint for checking duplicates when creating new stores (no store ID yet)
    Route::post('/wallet-connection/check-duplicate', [WalletConnectionController::class, 'checkDuplicateNew']);
    Route::post('/stores/{store}/wallet-connection/test', [WalletConnectionController::class, 'testConnection'])
        ->middleware([EnsureStoreOwnership::class]);
    Route::post('/stores/{store}/wallet-connection/configure', [WalletConnectionController::class, 'configureLightning'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':wallet_connection.configured']);
    Route::delete('/stores/{store}/wallet-connection', [WalletConnectionController::class, 'destroy'])
        ->middleware([EnsureStoreOwnership::class, AuditLog::class.':wallet_connection.deleted']);

    // SatoshiTickets (Events, Ticket Types, Tickets, Orders)
    Route::prefix('stores/{store}/tickets')->middleware([EnsureStoreOwnership::class, 'guest.restrict'])->group(function () {
        Route::post('/events/image', [TicketEventImageController::class, 'upload']);
        Route::post('/events/{eventId}/logo', [TicketEventImageController::class, 'uploadLogo']);
        Route::delete('/events/{eventId}/logo', [TicketEventImageController::class, 'deleteLogo']);
        Route::get('/events', [TicketController::class, 'listEvents']);
        Route::get('/events/{eventId}', [TicketController::class, 'getEvent']);
        Route::post('/events', [TicketController::class, 'createEvent']);
        Route::put('/events/{eventId}', [TicketController::class, 'updateEvent']);
        Route::delete('/events/{eventId}', [TicketController::class, 'deleteEvent']);
        Route::put('/events/{eventId}/toggle', [TicketController::class, 'toggleEvent']);
        Route::get('/events/{eventId}/ticket-types', [TicketController::class, 'listTicketTypes']);
        Route::post('/events/{eventId}/ticket-types', [TicketController::class, 'createTicketType']);
        Route::put('/events/{eventId}/ticket-types/{ticketTypeId}', [TicketController::class, 'updateTicketType']);
        Route::delete('/events/{eventId}/ticket-types/{ticketTypeId}', [TicketController::class, 'deleteTicketType']);
        Route::put('/events/{eventId}/ticket-types/{ticketTypeId}/toggle', [TicketController::class, 'toggleTicketType']);
        Route::get('/events/{eventId}/tickets', [TicketController::class, 'listTickets']);
        Route::post('/events/{eventId}/tickets/{ticketNumber}/check-in', [TicketController::class, 'checkInTicket']);
        Route::get('/events/{eventId}/orders', [TicketController::class, 'listOrders']);
        Route::post('/events/{eventId}/orders/{orderId}/tickets/{ticketId}/send-reminder', [TicketController::class, 'sendReminder']);
    });

    // BTCPay Raffle plugin
    Route::prefix('stores/{store}/raffles')->middleware([EnsureStoreOwnership::class, 'guest.restrict'])->group(function () {
        Route::get('/status', [RaffleController::class, 'status']);
        Route::get('/', [RaffleController::class, 'index']);
        Route::post('/', [RaffleController::class, 'store']);
        Route::put('/{raffleId}', [RaffleController::class, 'update']);
        Route::delete('/{raffleId}', [RaffleController::class, 'destroy']);
        Route::get('/{raffleId}', [RaffleController::class, 'show']);
        Route::post('/{raffleId}/presenter-token', [RaffleController::class, 'presenterToken']);
        Route::post('/{raffleId}/open', [RaffleController::class, 'open']);
        Route::post('/{raffleId}/close', [RaffleController::class, 'close']);
        Route::post('/{raffleId}/draw', [RaffleController::class, 'draw']);
        Route::post('/{raffleId}/complete', [RaffleController::class, 'complete']);
        Route::post('/{raffleId}/tickets/manual', [RaffleController::class, 'addManualTickets']);
        Route::get('/{raffleId}/tickets', [RaffleController::class, 'tickets']);
        Route::get('/{raffleId}/drawings', [RaffleController::class, 'drawings']);
    });

    // Support routes
    Route::middleware([EnsureSupportRole::class])->group(function () {
        Route::get('/support/wallet-connections', [WalletConnectionController::class, 'indexSupport']);
        Route::get('/support/count', [WalletConnectionController::class, 'getSupportCount']);
        Route::post('/support/wallet-connections/{connection}/reveal', [WalletConnectionController::class, 'reveal'])
            ->middleware(AuditLog::class.':wallet_connection.revealed');
        Route::get('/support/wallet-connections/{connection}/btcpay-store-url', [WalletConnectionController::class, 'getBtcPayStoreUrl']);
        Route::put('/support/wallet-connections/{connection}/mark-connected', [WalletConnectionController::class, 'markConnected'])
            ->middleware(AuditLog::class.':wallet_connection.marked_connected');
        Route::post('/support/wallet-connections/{connection}/bot-failed', [WalletConnectionController::class, 'botFailed']);
    });

    // Admin routes
    Route::middleware([EnsureAdminRole::class])->group(function () {
        Route::get('/admin/system-health', [SystemHealthController::class, 'show']);
        Route::get('/admin/system-health/history', [SystemHealthController::class, 'history']);
        Route::get('/admin/stats', [AdminController::class, 'stats']);
        Route::get('/admin/stats/export', [AdminController::class, 'statsExport']);
        Route::get('/admin/users', [AdminController::class, 'index']);
        Route::get('/admin/users/{user}', [AdminController::class, 'show']);
        Route::put('/admin/users/{user}', [AdminController::class, 'update'])
            ->middleware(AuditLog::class.':admin.user.updated');
        Route::delete('/admin/users/{user}', [AdminController::class, 'destroy'])
            ->middleware(AuditLog::class.':admin.user.deleted');

        // RegWatch review (docs/LEGAL.md): changelog workflow only - rules
        // are edited manually after verifying the official source.
        Route::get('/admin/regwatch/changes', [RegWatchController::class, 'changes']);
        Route::get('/admin/regwatch/changes/{change}', [RegWatchController::class, 'showChange']);
        Route::put('/admin/regwatch/changes/{change}/status', [RegWatchController::class, 'updateChangeStatus'])
            ->middleware(AuditLog::class.':admin.regwatch.change_status_updated');
        Route::get('/admin/regwatch/sources', [RegWatchController::class, 'sources']);
        Route::get('/admin/regwatch/jurisdictions', [RegWatchController::class, 'jurisdictions']);
        Route::get('/admin/regwatch/rules', [RegWatchController::class, 'rules']);
        Route::get('/admin/regwatch/rules/{rule}', [RegWatchController::class, 'showRule']);
        Route::put('/admin/regwatch/rules/{rule}', [RegWatchController::class, 'updateRule'])
            ->middleware(AuditLog::class.':admin.regwatch.rule_updated');

        // E-faktura CPDS presets (digitálny poštár) shown in the merchant
        // settings form - operator adds only verified provider URLs.
        Route::get('/admin/efaktura/cpds-providers', [EfakturaCpdsProviderController::class, 'index']);
        Route::post('/admin/efaktura/cpds-providers', [EfakturaCpdsProviderController::class, 'store'])
            ->middleware(AuditLog::class.':admin.efaktura.cpds_provider_created');
        Route::put('/admin/efaktura/cpds-providers/{provider}', [EfakturaCpdsProviderController::class, 'update'])
            ->middleware(AuditLog::class.':admin.efaktura.cpds_provider_updated');
        Route::delete('/admin/efaktura/cpds-providers/{provider}', [EfakturaCpdsProviderController::class, 'destroy'])
            ->middleware(AuditLog::class.':admin.efaktura.cpds_provider_deleted');
    });
});

// Subscription checkout (auth handled in controller based on feature flag)
Route::post('/subscriptions/checkout', [SubscriptionController::class, 'checkout']);
Route::get('/subscriptions/success', [SubscriptionController::class, 'success'])
    ->middleware(['auth:sanctum', RequireVerifiedEmail::class, 'throttle:30,1']);
Route::get('/subscriptions/details', [SubscriptionController::class, 'details'])
    ->middleware(['auth:sanctum', RequireVerifiedEmail::class]);
Route::get('/subscriptions/credits', [SubscriptionController::class, 'getCredits'])
    ->middleware(['auth:sanctum', RequireVerifiedEmail::class]);
Route::post('/subscriptions/credits', [SubscriptionController::class, 'addCredits'])
    ->middleware(['auth:sanctum', RequireVerifiedEmail::class]);

// Documentation (public - no auth required)
Route::get('/documentation', [DocumentationController::class, 'index']);
Route::get('/documentation/{slug}', [DocumentationController::class, 'show']);

// FAQ (public - no auth required)
Route::get('/faq', [FaqController::class, 'index']);
Route::get('/faq/{slug}', [FaqController::class, 'show']);
Route::post('/faq/{slug}/helpful', [FaqController::class, 'markHelpful']);

// Public Ticket Check-In (no auth required - URL acts as the secret)
Route::middleware(['throttle:30,1'])->prefix('public/ticket-checkin')->group(function () {
    Route::get('/{store}/events/{eventId}', [TicketController::class, 'publicEventInfo']);
    Route::post('/{store}/events/{eventId}/tickets/{ticketNumber}/check-in', [TicketController::class, 'publicCheckIn']);
});

// Documentation is authored as repo Markdown (docs/user), so there is no
// article/category CMS. The image upload endpoint stays: it is the shared
// upload target for the admin rich-text editor (raffles, crowdfunds, tickets).
Route::middleware(['auth:sanctum', RequireVerifiedEmail::class, EnsureSupportOrAdminRole::class])->prefix('admin/documentation')->group(function () {
    Route::post('/upload-image', [DocumentationImageController::class, 'upload']);
});

// Admin FAQ (requires support or admin role)
Route::middleware(['auth:sanctum', RequireVerifiedEmail::class, EnsureSupportOrAdminRole::class])->prefix('admin/faq')->group(function () {
    // Items
    Route::get('/items', [FaqItemController::class, 'index']);
    Route::post('/items', [FaqItemController::class, 'store'])
        ->middleware(AuditLog::class.':faq_item.created');
    Route::get('/items/{item}', [FaqItemController::class, 'show']);
    Route::put('/items/{item}', [FaqItemController::class, 'update'])
        ->middleware(AuditLog::class.':faq_item.updated');
    Route::delete('/items/{item}', [FaqItemController::class, 'destroy'])
        ->middleware(AuditLog::class.':faq_item.deleted');

    // Categories
    Route::get('/categories', [FaqCategoryController::class, 'index']);
    Route::post('/categories', [FaqCategoryController::class, 'store'])
        ->middleware(AuditLog::class.':faq_category.created');
    Route::get('/categories/{category}', [FaqCategoryController::class, 'show']);
    Route::put('/categories/{category}', [FaqCategoryController::class, 'update'])
        ->middleware(AuditLog::class.':faq_category.updated');
    Route::delete('/categories/{category}', [FaqCategoryController::class, 'destroy'])
        ->middleware(AuditLog::class.':faq_category.deleted');
});
