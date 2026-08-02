<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a Content-Security-Policy header (config/security.php).
 * See the config file for the rollout procedure and policy rationale.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fail-closed: refuse to serve production traffic with CSP explicitly
        // disabled rather than shipping invoicing pages (which hold an account
        // mnemonic in browser storage) without script/connect restrictions.
        if (! config('security.csp.enabled') && app()->environment('production')) {
            Log::critical('CSP is disabled in production (CSP_ENABLED=false). Refusing to serve without a Content-Security-Policy.');
            abort(500, 'Server security configuration error.');
        }

        $response = $next($request);

        if (! config('security.csp.enabled')) {
            return $response;
        }

        $header = config('security.csp.report_only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        if (! $response->headers->has($header)) {
            // Include the authenticated user's own Evolu relay so a custom relay
            // set in Profile is not blocked by connect-src on the next document load.
            $userRelay = $this->userRelayOrigin($request);
            $response->headers->set($header, $this->buildPolicy($userRelay));
        }

        return $response;
    }

    protected function userRelayOrigin(Request $request): ?string
    {
        $user = $request->user();
        $relay = is_object($user) ? ($user->evolu_relay_url ?? null) : null;

        return is_string($relay) && $relay !== '' ? $this->originOf($relay) : null;
    }

    protected function buildPolicy(?string $userRelayOrigin = null): string
    {
        // Matomo (when configured) loads matomo.js from its own origin.
        $matomoOrigin = $this->originOf((string) config('services.matomo.url', ''));
        // Chorala support widget script (loaded client-side when a widget key is configured).
        $choralaOrigin = $this->originOf((string) config('services.chorala.widget_url', ''));
        // BTCPay Server origin - the landing page embeds a live POS terminal
        // (checkout iframe) served from BTCPay.
        $btcpayOrigin = $this->originOf((string) config('services.btcpay.public_url', ''));

        $directives = [
            'default-src' => ["'self'"],
            // 'wasm-unsafe-eval': Evolu's sqlite WASM module (local-first invoicing DB).
            // Allows WebAssembly compilation only - JS eval stays blocked.
            'script-src' => array_filter(["'self'", "'wasm-unsafe-eval'", $matomoOrigin, $choralaOrigin]),
            // 'unsafe-inline' styles: Vue transitions/components set inline style attributes.
            // Google Fonts: WooCommerce connect pages (resources/views/woocommerce/*).
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
            'font-src' => ["'self'", 'https://fonts.gstatic.com'],
            // https: - user-controlled remote images (crowdfund mainImageUrl, BTCPay logos);
            // blob:/data: - client-generated previews and QR codes.
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            // Explicit allowlist (no bare https:/wss:): BTCPay, Evolu relay,
            // Matomo beacon, Chorala widget, the user's own relay and any extras.
            'connect-src' => $this->connectSrc($matomoOrigin, $choralaOrigin, $userRelayOrigin),
            'worker-src' => ["'self'", 'blob:'],
            // YouTube embeds: landing SK video + documentation articles (both use youtube-nocookie);
            // Chorala widget may render its UI in an iframe.
            'frame-src' => array_filter(["'self'", 'blob:', 'https://www.youtube-nocookie.com', 'https://www.youtube.com', $choralaOrigin, $btcpayOrigin]),
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            // Violation reports land in the app log via CspReportController.
            'report-uri' => ['/api/csp-report'],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive) => $directive.' '.implode(' ', $sources))
            ->implode('; ');
    }

    /**
     * connect-src allowlist: self + BTCPay + Evolu relay (ws/wss) + Matomo +
     * extras. XHR/fetch and WebSocket targets the SPA legitimately reaches.
     *
     * @return list<string>
     */
    protected function connectSrc(?string $matomoOrigin, ?string $choralaOrigin = null, ?string $userRelayOrigin = null): array
    {
        $sources = ["'self'"];

        // 'self' covers only the page scheme (https) - the Echo/Reverb
        // websocket (broadcasting/auth + ws connection) needs the wss twin of
        // the app origin explicitly.
        $appOrigin = $this->originOf((string) config('app.url', ''));
        if ($appOrigin && str_starts_with($appOrigin, 'http')) {
            $sources[] = 'ws'.substr($appOrigin, 4);
        }

        if ($choralaOrigin) {
            $sources[] = $choralaOrigin;
        }

        $btcpay = $this->originOf((string) config('services.btcpay.public_url', ''));
        if ($btcpay) {
            $sources[] = $btcpay;
        }

        // Build-default relay plus the authenticated user's own relay override.
        foreach ([
            $this->originOf((string) config('security.csp.evolu_relay_url', '')),
            $userRelayOrigin,
        ] as $relayOrigin) {
            if ($relayOrigin) {
                $sources[] = $relayOrigin;
                // The relay host is used over BOTH schemes: wss for sync and
                // https for the usage/quota endpoint - allow the twin form.
                if (str_starts_with($relayOrigin, 'http')) {
                    $sources[] = 'ws'.substr($relayOrigin, 4);
                } elseif (str_starts_with($relayOrigin, 'ws')) {
                    $sources[] = 'http'.substr($relayOrigin, 2);
                }
            }
        }

        if ($matomoOrigin) {
            $sources[] = $matomoOrigin;
        }

        $extra = trim((string) config('security.csp.connect_src_extra', ''));
        if ($extra !== '') {
            foreach (preg_split('/\s+/', $extra) ?: [] as $origin) {
                // Reject tokens with CSP metacharacters (;,'" and control chars) -
                // a malformed env value must not be able to rewrite the policy
                // structure or smuggle extra directives.
                if ($origin !== '' && preg_match('/^[A-Za-z0-9+.:\/\*\-_\[\]@]+$/', $origin) === 1) {
                    $sources[] = $origin;
                }
            }
        }

        return array_values(array_unique($sources));
    }

    protected function originOf(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (! $scheme || ! $host) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }
}
