<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side LUD-21 support probe for unknown Lightning-address domains -
 * mirrors the BTCPay LnAddress plugin's save-time CheckVerifySupport: fetch
 * the LNURLp descriptor, request an invoice with a clamped amount and check
 * that the callback response carries a usable https verify URL.
 */
class LnAddressLud21Prober
{
    private const TIMEOUT_SECONDS = 8;

    private const MIN_PROBE_MSAT = 1000;

    /** Probe results are user-specific because LNURLp descriptors live under /lnurlp/{user}. */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array{lud21: bool, reason: string}
     *                                            reason: 'ok'|'invalid_address'|'unreachable'|'invalid_lnurlp'|'no_verify'
     */
    public function probe(string $address): array
    {
        $trimmed = trim($address);
        if (! preg_match('/^([^@\s;=]+)@([^@\s;=]+\.[^@\s;=]{2,})$/', $trimmed, $matches)) {
            return ['lud21' => false, 'reason' => 'invalid_address'];
        }

        [, $user, $domain] = $matches;
        $domain = strtolower($domain);
        $cacheKey = 'lnaddress-lud21-probe:'.hash('sha256', $user.'@'.$domain);

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->probeUncached($user, $domain)
        );
    }

    /**
     * @return array{lud21: bool, reason: string}
     */
    protected function probeUncached(string $user, string $domain): array
    {
        $lnurlpUrl = 'https://'.$domain.'/.well-known/lnurlp/'.rawurlencode($user);

        if (! $this->isSafeUrl($lnurlpUrl)) {
            return ['lud21' => false, 'reason' => 'invalid_address'];
        }

        $descriptor = $this->getJson($lnurlpUrl);
        if ($descriptor === null) {
            return ['lud21' => false, 'reason' => 'unreachable'];
        }

        $callback = $descriptor['callback'] ?? null;
        if (! is_string($callback) || ! $this->isSafeUrl($callback)) {
            return ['lud21' => false, 'reason' => 'invalid_lnurlp'];
        }

        // Clamp like the plugin probe: at least 1 sat, within the wallet's bounds.
        $min = is_numeric($descriptor['minSendable'] ?? null) ? (int) $descriptor['minSendable'] : self::MIN_PROBE_MSAT;
        $max = is_numeric($descriptor['maxSendable'] ?? null) ? (int) $descriptor['maxSendable'] : PHP_INT_MAX;
        $amount = min(max($min, self::MIN_PROBE_MSAT), max($max, 1));

        $separator = str_contains($callback, '?') ? '&' : '?';
        $invoice = $this->getJson($callback.$separator.'amount='.$amount);
        $pr = $invoice['pr'] ?? null;
        if ($invoice === null || ! is_string($pr) || $pr === '') {
            return ['lud21' => false, 'reason' => 'invalid_lnurlp'];
        }

        $verify = $invoice['verify'] ?? null;
        if (! is_string($verify) || ! $this->isSafeUrl($verify)) {
            return ['lud21' => false, 'reason' => 'no_verify'];
        }

        return ['lud21' => true, 'reason' => 'ok'];
    }

    /**
     * Syntactic SSRF guard mirroring the plugin's safe-HTTP rules: https only,
     * no userinfo, no custom port, a dotted hostname (no IP literals). DNS is
     * checked separately per request via resolvePublicIps().
     */
    protected function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || ! str_contains($host, '.')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return false;
        }

        return true;
    }

    /**
     * All public addresses the host resolves to (A and AAAA), or null when
     * resolution fails, returns nothing, or ANY resolved address is private,
     * reserved, loopback or link-local. The result is pinned into the request
     * (CURLOPT_RESOLVE), so the connection cannot re-resolve elsewhere.
     *
     * @return list<string>|null
     */
    protected function resolvePublicIps(string $host): ?array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return null;
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip) || $ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
            $ips[] = $ip;
        }

        return $ips === [] ? null : array_values(array_unique($ips));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getJson(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ips = $this->resolvePublicIps($host);
        if ($ips === null) {
            Log::info('LUD-21 probe refused: host does not resolve to public addresses only', ['url_host' => $host]);

            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions([
                    'allow_redirects' => false,
                    // Pin the connection to the addresses validated above (DNS rebinding guard).
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.implode(',', $ips)]],
                ])
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            Log::info('LUD-21 probe request failed', ['url_host' => $host, 'message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
