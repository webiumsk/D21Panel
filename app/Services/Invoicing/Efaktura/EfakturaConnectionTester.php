<?php

namespace App\Services\Invoicing\Efaktura;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * One-shot SAPI-SK credential check for the merchant settings form. Calls
 * authenticate() directly (never accessToken()) so a test can not poison
 * the cached token, and maps failures to stable codes the UI can translate.
 */
class EfakturaConnectionTester
{
    public function __construct(
        protected SapiSkClient $client,
    ) {}

    /**
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function test(?string $baseUrl, ?string $clientId, ?string $clientSecret): array
    {
        $baseUrl = trim((string) $baseUrl);
        $clientId = trim((string) $clientId);
        $clientSecret = trim((string) $clientSecret);

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'code' => 'missing_fields'];
        }

        try {
            $payload = $this->client->authenticate($clientId, $clientSecret, $baseUrl);
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            if (in_array($status, [400, 401, 403], true)) {
                return ['ok' => false, 'code' => 'invalid_credentials'];
            }

            return ['ok' => false, 'code' => 'http_error', 'message' => 'HTTP '.$status];
        } catch (ConnectionException) {
            return ['ok' => false, 'code' => 'unreachable'];
        } catch (\RuntimeException $exception) {
            // Base URL validation (HTTPS-only, SSRF guard, DNS) speaks in
            // RuntimeExceptions - surface the reason to the merchant.
            return ['ok' => false, 'code' => 'invalid_base_url', 'message' => $exception->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'code' => 'error'];
        }

        if (trim((string) ($payload['access_token'] ?? '')) === '') {
            return ['ok' => false, 'code' => 'unexpected_response'];
        }

        return ['ok' => true];
    }
}
