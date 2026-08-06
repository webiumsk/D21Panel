<?php

namespace App\Services;

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
        $lnurlpUrl = 'https://'.strtolower($domain).'/.well-known/lnurlp/'.rawurlencode($user);

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
     * SSRF guard mirroring the plugin's safe-HTTP rules: https only, no
     * userinfo, no custom port, a dotted hostname (no IP literals) that does
     * not resolve to a private or reserved address.
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

        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getJson(string $url): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            Log::info('LUD-21 probe request failed', ['url_host' => parse_url($url, PHP_URL_HOST), 'message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
