<?php

namespace App\Providers;

use App\Contracts\Invoicing\UsSalesTaxCalculator;
use App\Models\App;
use App\Models\BankImportBatch;
use App\Models\BankTransaction;
use App\Models\BusinessDocument;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseAttachment;
use App\Models\BusinessRecurringProfile;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyDocumentSequence;
use App\Models\CompanyStockItem;
use App\Models\CompanyWarehouse;
use App\Models\PosTerminal;
use App\Models\Store;
use App\Models\StoreApiKey;
use App\Models\StoreEmailRule;
use App\Models\WalletConnection;
use App\Pdf\DomPdfDriver;
use App\Policies\StorePolicy;
use App\Services\Invoicing\UsSalesTax\StripeTaxUsSalesTaxCalculator;
use App\Services\Invoicing\UsSalesTax\UsSalesTaxCalculationService;
use App\Support\ErrorRateCounter;
use App\Support\ProductionConfigValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(UsSalesTaxCalculationService::class, function ($app) {
            return new UsSalesTaxCalculationService([
                $app->make(StripeTaxUsSalesTaxCalculator::class),
            ]);
        });

        $this->app->bind(UsSalesTaxCalculator::class, StripeTaxUsSalesTaxCalculator::class);

        $this->app->singleton('laravel-pdf.driver.dompdf', function () {
            return new DomPdfDriver(config('laravel-pdf.dompdf', []));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->enforceProductionConfig();

        // Error-rate counters (P1 phase 8): count error+ log records per hour
        // (counts only, never message content) for health checks + dashboard.
        Event::listen(
            MessageLogged::class,
            function (MessageLogged $event): void {
                if (ErrorRateCounter::shouldCount($event->level)) {
                    ErrorRateCounter::increment();
                }
            },
        );

        // Authorization policies
        Gate::policy(Store::class, StorePolicy::class);

        $this->registerRouteBindings();

        // Define rate limiters
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
        // Separate limiter for password reset (so first attempt isn't throttled by other auth)
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
        // Passkey envelope fetch during sign-in: separate from throttle:auth
        // so a passkey attempt never eats the password-login budget. The
        // endpoint returns ciphertext only, so a slightly higher cap is safe.
        RateLimiter::for('passkey-envelope', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
        // Per-user limiter for authenticated API endpoints (avoids shared-IP throttling)
        RateLimiter::for('api-user', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by('user:'.$request->user()->id)
                : Limit::perMinute(30)->by($request->ip());
        });
    }

    /**
     * Production boot guards (P1 phase 7): generated URLs are forced to
     * https, and missing security-critical configuration refuses to serve
     * HTTP traffic (fail-fast, same posture as the CSP guard). Console
     * commands only log - deploy tooling (key:generate, config:cache) must
     * keep working on a half-configured box.
     */
    protected function enforceProductionConfig(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        URL::forceScheme('https');

        $missing = ProductionConfigValidator::missing(fn (string $key) => config($key));
        if ($missing === []) {
            return;
        }

        Log::critical('Production configuration is incomplete', [
            'missing' => $missing,
        ]);

        if (! $this->app->runningInConsole()) {
            abort(500, 'Server configuration error.');
        }
    }

    /**
     * Route-model bindings. Nested resources are scoped to their route parent
     * (declarative IDOR protection) - a child belonging to a different
     * company/store/expense 404s at binding time. In-controller ownership
     * asserts stay as defense in depth.
     *
     * Deliberately unscoped: 'connection' (support routes only, role-gated),
     * integration-inbox '{inbox}' (no direct FK - linked via
     * store_integration_id, asserted in the inbox service) and '{export}'
     * (no route parent; ExportController keeps the 403 contract).
     */
    protected function registerRouteBindings(): void
    {
        // Root bindings (UUID lookup; ownership enforced by middleware)
        Route::bind('store', fn ($value) => Store::where('id', $value)->firstOrFail());
        Route::bind('company', fn ($value) => Company::where('id', $value)->firstOrFail());
        Route::bind('connection', fn ($value) => WalletConnection::where('id', $value)->firstOrFail());

        // Children scoped to {company}
        $this->bindScopedToParent('businessDocument', BusinessDocument::class, 'company', 'company_id');
        $this->bindScopedToParent('contact', CompanyContact::class, 'company', 'company_id');
        $this->bindScopedToParent('businessExpense', BusinessExpense::class, 'company', 'company_id');
        $this->bindScopedToParent('bankTransaction', BankTransaction::class, 'company', 'company_id');
        $this->bindScopedToParent('stockItem', CompanyStockItem::class, 'company', 'company_id');
        $this->bindScopedToParent('warehouse', CompanyWarehouse::class, 'company', 'company_id');
        $this->bindScopedToParent('recurringProfile', BusinessRecurringProfile::class, 'company', 'company_id');
        $this->bindScopedToParent('sequence', CompanyDocumentSequence::class, 'company', 'company_id');
        $this->bindScopedToParent('batch', BankImportBatch::class, 'company', 'company_id');

        // Children scoped to {store}
        $this->bindScopedToParent('app', App::class, 'store', 'store_id');
        $this->bindScopedToParent('apiKey', StoreApiKey::class, 'store', 'store_id');
        $this->bindScopedToParent('store_email_rule', StoreEmailRule::class, 'store', 'store_id');
        $this->bindScopedToParent('pos_terminal', PosTerminal::class, 'store', 'store_id');

        // Attachment scoped to {businessExpense}
        $this->bindScopedToParent('businessExpenseAttachment', BusinessExpenseAttachment::class, 'businessExpense', 'business_expense_id');

        // {export} stays unscoped on purpose: it has no route parent and the
        // API contract is 403 for foreign exports (ExportController checks).
    }

    /**
     * Bind a child route parameter scoped by the parent route parameter's id
     * when the parent is present on the route (parents resolve first - they
     * appear earlier in the URI).
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function bindScopedToParent(string $param, string $modelClass, string $parentParam, string $foreignKey): void
    {
        Route::bind($param, function ($value, $route) use ($modelClass, $parentParam, $foreignKey) {
            $query = $modelClass::query()->where('id', $value);

            $parent = $route->parameter($parentParam);
            if ($parent instanceof Model) {
                $query->where($foreignKey, $parent->getKey());
            }

            return $query->firstOrFail();
        });
    }
}
