<?php

namespace App\Services\BtcPay;

use App\Services\BtcPay\Exceptions\BtcPayException;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing Lightning node connections in BTCPay Server.
 *
 * Connection string formats:
 * - Blink: type=blink;server=https://api.blink.sv/graphql;api-key=blink_xxx;wallet-id=xxx
 * - Boltz/Aqua: Uses watch-only Aqua wallet output descriptor
 *
 * Note: BTCPay Greenfield API may not support custom Lightning connection strings.
 * If API endpoints fail, connection strings are stored in DB with 'needs_support' status
 * for manual configuration by support team.
 */
class LightningService
{
    protected BtcPayClient $client;

    public function __construct(BtcPayClient $client)
    {
        $this->client = $client;
    }

    /**
     * Connect a Lightning node to a store.
     *
     * Tries multiple endpoint variants and request body formats. For Blink and Boltz plugins,
     * BTCPay accepts connection strings/descriptors directly.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $cryptoCode  Cryptocurrency code (e.g., 'BTC')
     * @param  string  $connectionString  Connection string or descriptor
     *                                    - Blink: type=blink;server=...;api-key=...;wallet-id=...
     *                                    - Aqua/Bull (Boltz): ct(slip77(...),elsh(wpkh(...))) or ct(slip77(...),elwpkh(...))
     * @param  string|null  $userApiKey  User-level API key (optional)
     * @return array Response data with success status and message
     *
     * @throws BtcPayException
     */
    public function connectLightningNode(string $storeId, string $cryptoCode, string $connectionString, ?string $userApiKey = null): array
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            // First, try to get Lightning node info to see if it exists
            // If it doesn't exist, we might need to create it first
            try {
                $lightningInfo = $this->client->get("/api/v1/stores/{$storeId}/lightning/{$cryptoCode}");
                Log::info('Lightning node already exists, proceeding with connection', [
                    'store_id' => $storeId,
                    'crypto_code' => $cryptoCode,
                ]);
            } catch (BtcPayException $e) {
                if ($e->getStatusCode() === 404) {
                    Log::info('Lightning node does not exist yet, will try to create/connect it', [
                        'store_id' => $storeId,
                        'crypto_code' => $cryptoCode,
                    ]);
                } else {
                    Log::warning('Error checking Lightning node existence', [
                        'store_id' => $storeId,
                        'crypto_code' => $cryptoCode,
                        'error' => $e->getMessage(),
                        'status_code' => $e->getStatusCode(),
                    ]);
                }
            }

            // BTCPay 2.x configures the store's Lightning wallet via the unified
            // payment-methods API - try it first. The legacy /lightning/{crypto}/*
            // routes below no longer configure anything on 2.x (the POST /connect
            // that still answers is the node PEER connect, hence 422 "Invalid NodeUri").
            try {
                $response = $this->client->put(
                    "/api/v1/stores/{$storeId}/payment-methods/{$cryptoCode}-LN",
                    [
                        'enabled' => true,
                        'config' => ['connectionString' => $connectionString],
                    ],
                );

                Log::info('BTCPay Lightning connected via payment-methods API', [
                    'store_id' => $storeId,
                    'crypto_code' => $cryptoCode,
                    'response_keys' => array_keys($response),
                ]);

                return [
                    'success' => true,
                    'message' => 'Lightning node connected successfully',
                    'data' => $response,
                ];
            } catch (BtcPayException $e) {
                // Fall back only when the route itself is unavailable (pre-2.x
                // server). Auth errors, validation rejections (the real reason
                // a 2.x server refused the string) and server errors would fail
                // the legacy routes too - preserve the original failure instead.
                if (! in_array($e->getStatusCode(), [404, 405], true)) {
                    throw $e;
                }

                Log::info('payment-methods Lightning route unavailable; falling back to legacy endpoints', [
                    'store_id' => $storeId,
                    'crypto_code' => $cryptoCode,
                    'status' => $e->getStatusCode(),
                    'message' => $e->getMessage(),
                ]);
            }

            // Try different endpoints, HTTP methods, and request body formats
            // BTCPay documentation says nodeURI, but plugins may accept connectionString or connection_string
            // Also try PUT/PATCH methods as some APIs use them for updates
            // Note: Some endpoints might require Lightning node to exist first
            $endpoints = [
                ["/api/v1/stores/{$storeId}/lightning/{$cryptoCode}/connect", 'POST'],
                ["/api/v1/stores/{$storeId}/lightning/{$cryptoCode}", 'PUT'], // Update Lightning settings
                ["/api/v1/stores/{$storeId}/lightning/{$cryptoCode}/connect", 'PUT'],
                ["/api/v1/stores/{$storeId}/lightning/{$cryptoCode}/setup", 'POST'],
            ];

            $requestBodyVariants = [
                ['ConnectionString' => $connectionString], // BTCPay UI uses "ConnectionString" (capital C and S)
                ['connectionString' => $connectionString], // camelCase variant
                ['connection_string' => $connectionString], // snake_case variant
                ['nodeURI' => $connectionString], // Standard format per documentation
            ];

            // Try each endpoint with each request body variant
            foreach ($endpoints as $endpointIndex => $endpointConfig) {
                $endpoint = is_array($endpointConfig) ? $endpointConfig[0] : $endpointConfig;
                $method = is_array($endpointConfig) ? ($endpointConfig[1] ?? 'POST') : 'POST';

                foreach ($requestBodyVariants as $index => $requestBody) {
                    try {
                        $bodyKey = array_keys($requestBody)[0];
                        $bodyValuePreview = strlen($connectionString) > 100
                            ? substr($connectionString, 0, 100).'...'
                            : $connectionString;

                        Log::info('Trying BTCPay Lightning connect with request body variant', [
                            'store_id' => $storeId,
                            'crypto_code' => $cryptoCode,
                            'endpoint' => $endpoint,
                            'method' => $method,
                            'endpoint_index' => $endpointIndex + 1,
                            'variant_index' => $index + 1,
                            'total_endpoints' => count($endpoints),
                            'total_variants' => count($requestBodyVariants),
                            'body_key' => $bodyKey,
                            'body_value_length' => strlen($connectionString),
                            'body_value_preview' => $bodyValuePreview,
                        ]);

                        // Use appropriate HTTP method
                        if ($method === 'PUT') {
                            $response = $this->client->put($endpoint, $requestBody);
                        } elseif ($method === 'PATCH') {
                            $response = $this->client->patch($endpoint, $requestBody);
                        } else {
                            $response = $this->client->post($endpoint, $requestBody);
                        }

                        Log::info('BTCPay Lightning node connected successfully', [
                            'store_id' => $storeId,
                            'crypto_code' => $cryptoCode,
                            'endpoint' => $endpoint,
                            'body_key' => $bodyKey,
                            'response_keys' => is_array($response) ? array_keys($response) : 'NOT_ARRAY',
                            'response_preview' => is_array($response) ? json_encode($response) : $response,
                        ]);

                        return [
                            'success' => true,
                            'message' => 'Lightning node connected successfully',
                            'data' => $response,
                        ];
                    } catch (BtcPayException $e) {
                        $bodyKey = array_keys($requestBody)[0];
                        $statusCode = $e->getStatusCode(); // Use getStatusCode() instead of getCode()

                        // If validation error (422), try next body format
                        if ($statusCode === 422) {
                            Log::info('Request body format not accepted (422), trying next variant', [
                                'store_id' => $storeId,
                                'crypto_code' => $cryptoCode,
                                'endpoint' => $endpoint,
                                'error_code' => $statusCode,
                                'body_key' => $bodyKey,
                                'variant_index' => $index + 1,
                                'error_message' => $e->getMessage(),
                                'will_try_next' => ($index + 1) < count($requestBodyVariants),
                            ]);

                            continue;
                        }

                        // If endpoint doesn't exist (404) or method not allowed (405), check error message
                        if ($statusCode === 404 || $statusCode === 405) {
                            $errorMessage = $e->getMessage();
                            // If 404 with "lightning node is not set up", it might be a validation error, try next variant
                            if ($statusCode === 404 && stripos($errorMessage, 'lightning node is not set up') !== false) {
                                Log::info('BTCPay Lightning node not set up (404), trying next variant', [
                                    'store_id' => $storeId,
                                    'crypto_code' => $cryptoCode,
                                    'endpoint' => $endpoint,
                                    'error_code' => $statusCode,
                                    'error_message' => $errorMessage,
                                    'body_key' => $bodyKey,
                                    'variant_index' => $index + 1,
                                    'will_try_next' => ($index + 1) < count($requestBodyVariants),
                                ]);

                                continue; // Try next body format
                            }

                            // Real endpoint not found - try next endpoint (but continue with other body formats first)
                            Log::info('BTCPay Lightning connect endpoint not available, will try next endpoint after all body variants', [
                                'store_id' => $storeId,
                                'crypto_code' => $cryptoCode,
                                'endpoint' => $endpoint,
                                'error_code' => $statusCode,
                                'error_message' => $errorMessage,
                                'body_key' => $bodyKey,
                            ]);
                            break; // Break out of inner loop (body variants), continue with next endpoint
                        }

                        // Other errors (auth, etc.) - log and throw
                        Log::error('BTCPay API error when connecting Lightning node', [
                            'store_id' => $storeId,
                            'crypto_code' => $cryptoCode,
                            'endpoint' => $endpoint,
                            'error_code' => $statusCode,
                            'error_message' => $e->getMessage(),
                            'body_key' => $bodyKey,
                        ]);
                        throw $e;
                    }
                } // End of requestBodyVariants loop
            } // End of endpoints loop

            // If all endpoints and request body variants failed, return workaround info
            Log::warning('All BTCPay Lightning connect endpoints and request body variants failed', [
                'store_id' => $storeId,
                'crypto_code' => $cryptoCode,
                'endpoints_tried' => count($endpoints),
                'variants_per_endpoint' => count($requestBodyVariants),
                'total_attempts' => count($endpoints) * count($requestBodyVariants),
                'connection_string_length' => strlen($connectionString),
                'connection_string_preview' => substr($connectionString, 0, 100).'...',
            ]);

            return [
                'success' => false,
                'message' => 'BTCPay API does not support custom Lightning connection strings via API. Connection string will be stored for manual configuration.',
                'requires_manual_config' => true,
            ];
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }

    /**
     * Test Lightning connection by verifying node configuration.
     *
     * Note: Actual connection testing may require the connection to be configured first.
     * This method validates the connection string format and attempts to verify
     * if Lightning is configured for the store.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $cryptoCode  Cryptocurrency code (e.g., 'BTC')
     * @param  string  $connectionString  Connection string or descriptor (for validation only)
     * @param  string|null  $userApiKey  User-level API key (optional)
     * @return array Test result with success status and message
     */
    public function testConnection(string $storeId, string $cryptoCode, string $connectionString, ?string $userApiKey = null): array
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            // Try to get Lightning node info to verify connection
            // This will fail if Lightning is not configured
            $nodeInfo = $this->getLightningNodeInfo($storeId, $cryptoCode, $userApiKey);

            if (empty($nodeInfo)) {
                // Lightning node info endpoint not available or not configured
                // For custom connection strings, configuration may need to be done manually
                return [
                    'success' => false,
                    'message' => 'Lightning node is not configured or connection info is not available via API. The connection string format appears valid, but manual configuration may be required.',
                    'requires_manual_config' => true,
                ];
            }

            // If we got node info, connection is configured
            return [
                'success' => true,
                'message' => 'Lightning connection is configured and accessible.',
                'node_info' => $nodeInfo,
            ];
        } catch (BtcPayException $e) {
            Log::error('Lightning connection test failed', [
                'store_id' => $storeId,
                'crypto_code' => $cryptoCode,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Lightning connection test failed: '.$e->getMessage(),
            ];
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }

    /**
     * Get information about the currently configured Lightning node.
     *
     * @param  string  $storeId  BTCPay store ID
     * @param  string  $cryptoCode  Cryptocurrency code (e.g., 'BTC')
     * @param  string|null  $userApiKey  User-level API key (optional)
     * @return array Lightning node information
     */
    public function getLightningNodeInfo(string $storeId, string $cryptoCode, ?string $userApiKey = null): array
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            $endpoint = "/api/v1/stores/{$storeId}/lightning/{$cryptoCode}/info";

            try {
                return $this->client->get($endpoint);
            } catch (BtcPayException $e) {
                if ($e->getStatusCode() === 404) {
                    // Endpoint not available or Lightning not configured
                    return [];
                }
                throw $e;
            }
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }

    /**
     * Best-effort: clear the store's Lightning node binding at BTCPay (e.g. Boltz/Aqua) before applying Blink.
     * Not all BTCPay versions expose DELETE; 404/405 are ignored.
     */
    public function tryRemoveStoreLightningNodeConfiguration(string $storeId, string $cryptoCode, ?string $userApiKey = null): void
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        $path = "/api/v1/stores/{$storeId}/lightning/{$cryptoCode}";

        try {
            try {
                $this->client->delete($path);
                Log::info('BTCPay store Lightning configuration removed (DELETE)', [
                    'store_id' => $storeId,
                    'crypto_code' => $cryptoCode,
                ]);
            } catch (BtcPayException $e) {
                if (in_array($e->getStatusCode(), [404, 405], true)) {
                    return;
                }

                Log::warning('BTCPay DELETE store Lightning configuration failed', [
                    'store_id' => $storeId,
                    'crypto_code' => $cryptoCode,
                    'status' => $e->getStatusCode(),
                    'message' => $e->getMessage(),
                ]);
            }
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }

    /**
     * Best-effort: remove Lightning checkout payment methods at BTCPay (Greenfield DELETE payment-methods)
     * so invoices stop offering LN after the store switches to Cashu-only in Satflux.
     *
     * @param  string  $storeId  BTCPay store id
     */
    public function tryRemoveLightningCheckoutPaymentMethods(string $storeId, string $userApiKey): void
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            foreach (['BTC-LN', 'BTC-LNURL'] as $paymentMethodId) {
                $path = '/api/v1/stores/'.$storeId.'/payment-methods/'.$paymentMethodId;

                try {
                    $this->client->delete($path);
                } catch (BtcPayException $e) {
                    if ($e->getStatusCode() === 404) {
                        continue;
                    }

                    Log::warning('BTCPay DELETE Lightning payment method failed', [
                        'store_id' => $storeId,
                        'payment_method_id' => $paymentMethodId,
                        'status' => $e->getStatusCode(),
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }
}
