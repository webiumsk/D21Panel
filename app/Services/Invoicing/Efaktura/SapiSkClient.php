<?php

namespace App\Services\Invoicing\Efaktura;

use App\Models\EfakturaCpdsProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SapiSkClient
{
    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $clientId, string $clientSecret, ?string $baseUrl = null): array
    {
        $response = $this->http()
            ->asForm()
            ->post($this->url(config('efaktura.providers.sapi_sk.token_path'), $baseUrl), [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        $response->throw();

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException(sprintf(
                'SAPI-SK token response is not valid JSON (HTTP %d).',
                $response->status(),
            ));
        }

        return $json;
    }

    public function accessToken(string $clientId, string $clientSecret, ?string $baseUrl = null): string
    {
        $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
        $cacheKey = 'efaktura.sapi_sk.token.'.hash('sha256', $clientId.'|'.$clientSecret.'|'.$resolvedBaseUrl);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $payload = $this->authenticate($clientId, $clientSecret, $resolvedBaseUrl);
        $token = (string) ($payload['access_token'] ?? '');

        if ($token === '') {
            throw new \RuntimeException('SAPI-SK token response missing access_token.');
        }

        Cache::put($cacheKey, $token, $this->tokenTtlSeconds($payload));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sendDocument(
        string $accessToken,
        string $senderParticipantId,
        array $body,
        ?string $idempotencyKey = null,
        ?string $baseUrl = null,
    ): array {
        $response = $this->http()
            ->withToken($accessToken)
            ->withHeaders([
                'X-Peppol-Participant-Id' => $senderParticipantId,
                'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
            ])
            ->post($this->url(config('efaktura.providers.sapi_sk.send_path'), $baseUrl), $body);

        if ($response->failed()) {
            $response->throw();
        }

        $json = $response->json();

        return is_array($json) ? $json : ['raw' => $response->body()];
    }

    /**
     * @return array<string, mixed>
     */
    public function sentDocument(
        string $accessToken,
        string $senderParticipantId,
        string $documentId,
        ?string $baseUrl = null,
    ): array {
        $pathTemplate = (string) $this->sentDocumentDetailPathTemplate($baseUrl);
        if ($pathTemplate === '') {
            throw new \RuntimeException('SAPI-SK sent document detail path is not configured.');
        }

        $path = str_replace(
            '{id}',
            rawurlencode($documentId),
            $pathTemplate,
        );

        $response = $this->http()
            ->withToken($accessToken)
            ->withHeaders(['X-Peppol-Participant-Id' => $senderParticipantId])
            ->get($this->url($path, $baseUrl));

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : ['payload' => $response->body()];
    }

    /**
     * @return array<string, mixed>
     */
    public function listReceivedDocuments(
        string $accessToken,
        string $receiverParticipantId,
        ?string $status = 'RECEIVED',
        ?int $limit = null,
        ?string $pageToken = null,
        ?string $baseUrl = null,
    ): array {
        $query = array_filter([
            'status' => $status,
            'limit' => $limit ?? (int) config('efaktura.inbound_poll_limit', 20),
            'pageToken' => $pageToken,
        ], fn ($value) => $value !== null && $value !== '');

        $response = $this->http()
            ->withToken($accessToken)
            ->withHeaders(['X-Peppol-Participant-Id' => $receiverParticipantId])
            ->get($this->url(config('efaktura.providers.sapi_sk.receive_path'), $baseUrl), $query);

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : ['documents' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public function receivedDocument(
        string $accessToken,
        string $receiverParticipantId,
        string $documentId,
        ?string $baseUrl = null,
    ): array {
        $path = str_replace(
            '{id}',
            rawurlencode($documentId),
            (string) config('efaktura.providers.sapi_sk.receive_detail_path'),
        );

        $response = $this->http()
            ->withToken($accessToken)
            ->withHeaders(['X-Peppol-Participant-Id' => $receiverParticipantId])
            ->get($this->url($path, $baseUrl));

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : ['payload' => $response->body()];
    }

    /**
     * @return array<string, mixed>
     */
    public function acknowledgeReceived(
        string $accessToken,
        string $receiverParticipantId,
        string $documentId,
        ?string $baseUrl = null,
    ): array {
        $path = str_replace(
            '{id}',
            rawurlencode($documentId),
            (string) config('efaktura.providers.sapi_sk.acknowledge_path'),
        );

        $response = $this->http()
            ->withToken($accessToken)
            ->withHeaders(['X-Peppol-Participant-Id' => $receiverParticipantId])
            ->post($this->url($path, $baseUrl));

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : ['status' => 'acknowledged'];
    }

    public function isRecipientNotFoundError(RequestException $exception): bool
    {
        return $exception->response?->status() === 422;
    }

    /**
     * Validate a base URL (HTTPS-only, SSRF guard, allowlist) without any
     * HTTP call - used by efaktura:doctor for ops diagnostics. Throws a
     * RuntimeException with the human-readable reason.
     */
    public function validateBaseUrl(?string $baseUrl): string
    {
        return $this->resolveBaseUrl($baseUrl);
    }

    /**
     * Effective sent-document detail path: the CPDS preset matching the base
     * URL wins over the global EFAKTURA_SAPI_SEND_DETAIL_PATH - two merchants
     * on different postmen can each track their own status endpoint.
     */
    public function sentDocumentDetailPathTemplate(?string $baseUrl = null): ?string
    {
        $preset = EfakturaCpdsProvider::detailPathForBaseUrl(
            $baseUrl ?? (string) config('efaktura.providers.sapi_sk.base_url'),
        );
        if ($preset !== null) {
            return $preset;
        }

        $global = (string) config('efaktura.providers.sapi_sk.send_detail_path', '');

        return $global !== '' ? $global : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function tokenTtlSeconds(array $payload): int
    {
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);

        return max(60, $expiresIn - 60);
    }

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('efaktura.providers.sapi_sk.timeout_seconds', 30))
            ->acceptJson();
    }

    protected function url(string $path, ?string $baseUrl = null): string
    {
        return $this->resolveBaseUrl($baseUrl).'/'.ltrim($path, '/');
    }

    protected function resolveBaseUrl(?string $baseUrl): string
    {
        $candidate = rtrim((string) ($baseUrl ?? config('efaktura.providers.sapi_sk.base_url')), '/');
        if ($candidate === '') {
            throw new \RuntimeException('SAPI-SK base URL is not configured for this company.');
        }

        $parts = parse_url($candidate);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException('SAPI-SK base URL must be a valid HTTPS URL.');
        }

        $host = strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException('SAPI-SK base URL must not use an IP address.');
        }

        $this->assertResolvablePublicHost($host);
        $this->assertAllowedSapiHost($host);

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port;
    }

    /**
     * Operator-verified hosts: the env allowlist plus active CPDS presets.
     *
     * @return list<string>
     */
    protected function trustedHosts(): array
    {
        $configHosts = array_map('strtolower', (array) config('efaktura.allowed_sapi_hosts', []));

        return array_values(array_unique(array_merge($configHosts, EfakturaCpdsProvider::allowedHosts())));
    }

    protected function assertResolvablePublicHost(string $host): void
    {
        if (in_array($host, $this->trustedHosts(), true)) {
            return;
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                $ips = $fallback;
            }
        }

        if ($ips === []) {
            throw new \RuntimeException('SAPI-SK base URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('SAPI-SK base URL must not resolve to a private or reserved address.');
            }
        }
    }

    protected function assertAllowedSapiHost(string $host): void
    {
        if (in_array($host, $this->trustedHosts(), true)) {
            return;
        }

        // Presets only ADD trusted hosts - the open-by-default behaviour
        // below is keyed on the env allowlist alone, so adding a preset
        // never locks out merchants with a custom (non-preset) CPDS.
        $allowedHosts = array_map('strtolower', (array) config('efaktura.allowed_sapi_hosts', []));

        $globalBase = rtrim((string) config('efaktura.providers.sapi_sk.base_url'), '/');
        $globalHost = is_string($globalBase) && $globalBase !== ''
            ? strtolower((string) parse_url($globalBase, PHP_URL_HOST))
            : '';

        if ($globalHost !== '' && $host === $globalHost) {
            return;
        }

        if ($allowedHosts === [] && $globalHost === '') {
            return;
        }

        throw new \RuntimeException('SAPI-SK base URL host is not in the allowed provider list.');
    }
}
