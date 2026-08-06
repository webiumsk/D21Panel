<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'subjekt_registry' => [
        'base_url' => env('SUBJEKT_REGISTRY_BASE_URL', 'https://api.subjekt.sk/v1'),
    ],

    'openregistry' => [
        'enabled' => env('OPENREGISTRY_ENABLED', true),
        'base_url' => env('OPENREGISTRY_BASE_URL', 'https://openregistry.sophymarine.com/api/v1'),
        'bearer_token' => env('OPENREGISTRY_BEARER_TOKEN'),
    ],

    'vies' => [
        'base_url' => env('VIES_API_BASE_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
        'timeout' => (int) env('VIES_API_TIMEOUT', 15),
    ],

    'stripe' => [
        'tax_secret_key' => env('STRIPE_TAX_SECRET_KEY'),
    ],

    'cashu' => [
        // Default mint for CashuMelt fallback configuration (LN-address-only onboarding).
        'default_mint_url' => env('CASHU_DEFAULT_MINT_URL', 'https://mint.minibits.cash/Bitcoin'),
    ],

    'btcpay' => [
        // Greenfield API (server-to-server). May be an internal Docker URL.
        'base_url' => env('BTCPAY_BASE_URL', 'http://127.0.0.1:14142'),
        // Browser-facing BTCPay origin (/raffle, /i, PoS apps). Defaults to base_url when unset.
        'public_url' => env('BTCPAY_PUBLIC_URL') ?: env('BTCPAY_BASE_URL', 'http://127.0.0.1:14142'),
        'api_key' => env('BTCPAY_API_KEY'),
        // Seconds; BTCPay Greenfield user-by-email (per current API key hash).
        'user_by_email_cache_ttl' => (int) env('BTCPAY_USER_BY_EMAIL_CACHE_TTL', 300),
        'webhook_secret' => env('BTCPAY_WEBHOOK_SECRET'),
        // Subscription store webhooks often use a different secret than merchant stores.
        'subscription_webhook_secret' => env('SUBSCRIPTION_WEBHOOK_SECRET') ?: env('BTCPAY_WEBHOOK_SECRET'),
        'subscription_success_url' => env('SUBSCRIPTION_SUCCESS_URL'),
        'subscription_cancel_url' => env('SUBSCRIPTION_CANCEL_URL'),
        'subscription_payment_reminder_days' => (int) env('SUBSCRIPTION_PAYMENT_REMINDER_DAYS', 3),
        'allow_guest_subscriptions' => env('ALLOW_GUEST_SUBSCRIPTIONS', false),
        'subscription_store_id' => env('SUBSCRIPTION_STORE_ID'),
        'subscription_offering_id' => env('SUBSCRIPTION_OFFERING_ID'),
        'subscription_plans' => [
            'pro' => env('SUBSCRIPTION_PLAN_PRO_ID'),
            'enterprise' => env('SUBSCRIPTION_PLAN_ENTERPRISE_ID'),
        ],
        // Satflux store UUID for the marketing landing Pay Button (BTCPay store ID resolved server-side).
        'landing_pay_demo_store_id' => env('LANDING_PAY_DEMO_STORE_ID'),
        // Lightning Address host in the UI (user@host). Explicit env, else hostname of BTCPAY_BASE_URL (same default as base_url).
        'lightning_address_domain' => (static function (): string {
            $explicit = env('BTCPAY_LIGHTNING_ADDRESS_DOMAIN');
            if (is_string($explicit) && $explicit !== '') {
                return $explicit;
            }
            $base = (string) (env('BTCPAY_PUBLIC_URL') ?: env('BTCPAY_BASE_URL', 'http://127.0.0.1:14142'));
            $host = parse_url($base, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : '';
        })(),
        // Note: Grace period is configured per plan in BTCPay Server, not here
    ],

    'boltz' => [
        // Upstream Boltz backend REST API (read-only; pairs/limits/fees). Informational only -
        // the authoritative per-invoice validation happens in the BTCPay Boltz plugin.
        'api_url' => rtrim((string) env('BOLTZ_API_URL', 'https://api.boltz.exchange'), '/'),
        'timeout' => (int) env('BOLTZ_API_TIMEOUT', 8),
        // Seconds a fetched pair snapshot counts as fresh (avoid hammering the public API).
        'pairs_cache_ttl' => (int) env('BOLTZ_PAIRS_CACHE_TTL', 120),
        // Seconds after which the last good snapshot is flagged stale in readiness output.
        'pairs_stale_after' => (int) env('BOLTZ_PAIRS_STALE_AFTER', 900),
        // Seconds the last good snapshot is kept for stale display before "unavailable".
        'pairs_keep_last_good' => (int) env('BOLTZ_PAIRS_KEEP_LAST_GOOD', 86400),
        // Seconds to back off from the public API after a failed fetch.
        'failure_backoff' => (int) env('BOLTZ_API_FAILURE_BACKOFF', 30),
        // Seconds a per-store readiness snapshot is cached.
        'readiness_cache_ttl' => (int) env('BOLTZ_READINESS_CACHE_TTL', 60),
    ],

    'matomo' => [
        'url' => env('MATOMO_URL'),
        'site_id' => env('MATOMO_SITE_ID'),
    ],

    'chorala' => [
        'project_key' => env('CHORALA_PROJECT_KEY'),
        'project_id' => env('CHORALA_PROJECT_ID'),
        'api_key' => env('CHORALA_API_KEY'),
        'widget_theme' => env('CHORALA_WIDGET_THEME'),
        'widget_primary_color' => env('CHORALA_WIDGET_PRIMARY_COLOR'),
        'widget_url' => rtrim((string) env('CHORALA_WIDGET_URL', 'https://chorala.com'), '/'),
        'end_user_jwt_secret' => env('CHORALA_END_USER_JWT_SECRET'),
    ],

    'discord' => [
        'support_webhook_url' => env('SUPPORT_DISCORD_WEBHOOK_URL'),
    ],

    /*
    | Synthetic BTCPay user emails for guest sessions: guest+<token>@<domain>.
    | Set GUEST_EMAIL_DOMAIN when APP_URL host is localhost, *.local, or otherwise unsuitable for BTCPay.
    | Otherwise the host from APP_URL is used (e.g. satflux.io). Dev/unknown host fallback avoids .local.
    | After editing this closure, run `php artisan config:clear` or `php artisan optimize:clear` if config is cached.
    */
    'auth' => [
        'guest_email_domain' => (static function (): string {
            $explicit = env('GUEST_EMAIL_DOMAIN');
            if (is_string($explicit)) {
                $explicit = trim($explicit);
                if ($explicit !== '') {
                    $validated = filter_var($explicit, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
                    if ($validated !== false && is_string($validated)) {
                        return strtolower($validated);
                    }
                }
            }
            $host = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                return 'guest.example.com';
            }
            $host = strtolower($host);
            if ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || str_ends_with($host, '.local')) {
                return 'guest.example.com';
            }

            return $host;
        })(),
    ],

];
