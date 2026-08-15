<?php

namespace Tests;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail loudly when a test triggers an HTTP call no Http::fake() matches,
        // instead of silently hitting the real network.
        Http::preventStrayRequests();
    }

    public function createApplication()
    {
        // Set cache driver to array BEFORE bootstrapping to prevent Redis usage
        $_ENV['CACHE_DRIVER'] = 'array';
        putenv('CACHE_DRIVER=array');

        // Also disable Redis completely
        $_ENV['REDIS_CLIENT'] = 'array';
        putenv('REDIS_CLIENT=array');

        // CI copies .env.example with seed-first enabled; PHPUnit env should win,
        // but force these before bootstrap so register/compliance tests stay stable.
        $_ENV['SEED_FIRST_REGISTRATION'] = 'false';
        putenv('SEED_FIRST_REGISTRATION=false');
        $_ENV['COMPLIANCE_SCREENING_ENABLED'] = 'false';
        putenv('COMPLIANCE_SCREENING_ENABLED=false');
        $_ENV['COMPLIANCE_LIST_SCREENING_ENABLED'] = 'false';
        putenv('COMPLIANCE_LIST_SCREENING_ENABLED=false');

        $app = require __DIR__.'/../bootstrap/app.php';

        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        // After bootstrap, ensure cache uses array
        config(['cache.default' => 'array']);
        config(['cache.stores.array' => ['driver' => 'array']]);

        // Default to compliance disabled in the shared test environment.
        // Individual compliance test suites explicitly enable it.
        config(['compliance.enabled' => false]);
        config(['guest.seed_first_registration' => false]);

        // Allow private SMTP hosts in tests so email tests don't hit real DNS.
        // SmtpHostGuardTest explicitly disables this to test the guard itself.
        config(['invoicing.smtp_allow_private_hosts' => true]);

        // Override RateLimiter AFTER bootstrap to use array cache
        // This prevents Redis connection attempts during rate limiting
        // Get the existing RateLimiter to preserve configured limiters
        $existingLimiter = $app->make('Illuminate\Cache\RateLimiter');

        // Get existing limiters via reflection before replacing
        $reflection = new \ReflectionClass($existingLimiter);
        $limitersProperty = $reflection->getProperty('limiters');
        $limitersProperty->setAccessible(true);
        $existingLimiters = $limitersProperty->getValue($existingLimiter);

        // Clear any existing instance
        if ($app->resolved('Illuminate\Cache\RateLimiter')) {
            $app->forgetInstance('Illuminate\Cache\RateLimiter');
        }

        // Create new RateLimiter with array cache
        $newLimiter = new CacheRateLimiter(Cache::store('array'));

        // Copy over any configured limiters from the old instance
        if (! empty($existingLimiters)) {
            $newLimitersProperty = new \ReflectionClass($newLimiter);
            $newLimitersProp = $newLimitersProperty->getProperty('limiters');
            $newLimitersProp->setAccessible(true);
            $newLimitersProp->setValue($newLimiter, $existingLimiters);
        }

        // Bind the new instance
        $app->instance('Illuminate\Cache\RateLimiter', $newLimiter);

        return $app;
    }
}
