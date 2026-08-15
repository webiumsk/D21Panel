<?php

namespace App\Services\BtcPay;

use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserService
{
    protected BtcPayClient $client;

    public function __construct(BtcPayClient $client)
    {
        $this->client = $client;
    }

    /**
     * Create a new user in BTCPay Server.
     *
     * When password is provided, user is created as active (not "Pending Invitation").
     *
     * @param  array  $data  User data containing:
     *                       - email (required): User email address
     *                       - password (optional): User password - if provided, user is created as active
     *                       - isAdministrator (optional): Whether user should be administrator (default: false)
     * @return array Created user data
     *
     * @throws BtcPayException
     */
    public function createUser(array $data): array
    {
        try {
            // Prepare user data for BTCPay API
            $userData = [
                'email' => $data['email'],
                'isAdministrator' => $data['isAdministrator'] ?? false,
                'sendInvitationEmail' => $data['sendInvitationEmail'] ?? false,
            ];

            // Add password if provided - this ensures user is created as active
            if (isset($data['password']) && ! empty($data['password'])) {
                $userData['password'] = $data['password'];
            }

            return $this->client->post('/api/v1/users', $userData);
        } catch (BtcPayException $e) {
            Log::error('BTCPay user creation failed', [
                'error' => $e->getMessage(),
                'data' => [
                    'email' => LogSanitizer::email($data['email'] ?? null),
                    'has_password' => isset($data['password']) && ! empty($data['password']),
                ],
            ]);
            throw $e;
        }
    }

    /**
     * Get a user by ID from BTCPay Server.
     *
     * @param  string  $userId  BTCPay user ID
     * @return array User data
     *
     * @throws BtcPayException
     */
    public function getUser(string $userId): array
    {
        try {
            return $this->client->get("/api/v1/users/{$userId}");
        } catch (BtcPayException $e) {
            Log::error('BTCPay user retrieval failed', [
                'error' => $e->getMessage(),
                'userId' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * List all users from BTCPay Server.
     *
     * @return array List of users
     *
     * @throws BtcPayException
     */
    public function listUsers(): array
    {
        try {
            return $this->client->get('/api/v1/users');
        } catch (BtcPayException $e) {
            Log::error('BTCPay users listing failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if email exists on BTCPay Server.
     *
     * @param  string  $email  Email address to check
     * @return bool True if email exists, false otherwise
     *
     * @throws BtcPayException
     */
    public function checkEmailExists(string $email): bool
    {
        try {
            return $this->getUserByEmail($email) !== null;
        } catch (BtcPayException $e) {
            Log::error('BTCPay email check failed', [
                'error' => $e->getMessage(),
                'email' => LogSanitizer::email($email),
            ]);
            throw $e;
        }
    }

    /**
     * Get user by email from BTCPay Server.
     *
     * Prefers Greenfield GET /api/v1/users/{idOrEmail} with URL-encoded email. Falls back to GET /api/v1/users
     * when the server returns 404 for the direct lookup (older instances), and normalizes wrapped list payloads
     * (for example an `items` array).
     *
     * Results (including not-found) are cached briefly per API key + normalized email. Exceptions from BTCPay
     * are not cached and propagate unchanged.
     *
     * @param  string  $email  Email address
     * @return array|null User data or null if not found
     *
     * @throws BtcPayException
     */
    public function getUserByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $apiKey = $this->client->getApiKey();
        $keyFingerprint = $apiKey !== '' ? hash('sha256', $apiKey) : 'no_api_key';
        $normalized = strtolower($email);
        $ttl = max(1, (int) config('services.btcpay.user_by_email_cache_ttl', 300));
        $cacheKey = 'btcpay:user_by_email:'.$keyFingerprint.':'.hash('sha256', $normalized);

        return Cache::remember($cacheKey, $ttl, function () use ($email) {
            return $this->resolveGetUserByEmailUncached($email);
        });
    }

    /**
     * @throws BtcPayException
     */
    private function resolveGetUserByEmailUncached(string $email): ?array
    {
        try {
            $segment = rawurlencode($email);

            return $this->client->get("/api/v1/users/{$segment}");
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 404) {
                return $this->getUserByEmailViaUserList($email);
            }
            Log::error('BTCPay user retrieval by email failed', [
                'error' => $e->getMessage(),
                'status' => $e->getStatusCode(),
                'email' => LogSanitizer::email($email),
            ]);
            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUsersListResponse(array $response): array
    {
        foreach (['items', 'data', 'users'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && $this->arrayLooksLikeUserList($response[$key])) {
                return $response[$key];
            }
        }

        if ($this->arrayLooksLikeUserList($response)) {
            return array_is_list($response) ? $response : array_values($response);
        }

        return [];
    }

    /**
     * @param  array<int|string, mixed>  $arr
     */
    private function arrayLooksLikeUserList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        $first = reset($arr);

        return is_array($first) && (isset($first['email']) || isset($first['id']));
    }

    private function getUserByEmailViaUserList(string $email): ?array
    {
        $users = $this->normalizeUsersListResponse($this->listUsers());
        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }
            if (isset($user['email']) && strtolower((string) $user['email']) === strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Create an API key for a user in BTCPay Server.
     * Requires server-level API key with user management permissions.
     *
     * @param  string  $userId  BTCPay user ID
     * @param  array  $permissions  Permissions for the API key (e.g., ['btcpay.store.cancreateinvoice', 'btcpay.store.canviewstoresettings'])
     * @param  array  $specificStores  Optional: array of store IDs to limit API key access to specific stores
     * @param  string  $label  Optional: label for the API key
     * @return array Created API key data (contains 'apiKey' field with the actual key)
     *
     * @throws BtcPayException
     */
    public function createApiKey(string $userId, array $permissions = [], array $specificStores = [], string $label = 'satflux.io API Key'): array
    {
        try {
            $defaultPermissions = config('btcpay_merchant_permissions.merchant_api_key', [
                'btcpay.store.cancreateinvoice',
                'btcpay.store.canviewstoresettings',
                'btcpay.store.canmodifyinvoices',
                'btcpay.store.canmodifystoresettings',
                'btcpay.store.canviewinvoices',
                'btcpay.user.canviewnotificationsforuser',
                'btcpay.user.canmanagenotificationsforuser',
                'btcpay.user.canmodifyprofile',
            ]);
            $finalPermissions = ! empty($permissions) ? $permissions : $defaultPermissions;

            $data = [
                'label' => $label,
                'permissions' => $finalPermissions,
            ];

            if (! empty($specificStores)) {
                $data['strict'] = true;
                $data['storeMode'] = 'Specific';
                $data['specificStores'] = $specificStores;
            }

            return $this->client->post("/api/v1/users/{$userId}/api-keys", $data);
        } catch (BtcPayException $e) {
            Log::error('BTCPay API key creation failed', [
                'error' => $e->getMessage(),
                'userId' => $userId,
                'permissions_provided' => $permissions,
                'permissions_used' => $finalPermissions,
                'label' => $label,
            ]);
            throw $e;
        }
    }

    /**
     * Revoke (delete) an API key for a user in BTCPay Server.
     * Requires server-level API key with user management permissions.
     *
     * BTCPay Greenfield API accepts the API key string itself (not an ID).
     * There is no GET endpoint to list user API keys.
     *
     * @param  string  $userId  BTCPay user ID
     * @param  string  $apiKey  The API key string to revoke
     *
     * @throws BtcPayException
     */
    public function deleteUserApiKey(string $userId, string $apiKey): void
    {
        try {
            $this->client->delete("/api/v1/users/{$userId}/api-keys/{$apiKey}");
        } catch (BtcPayException $e) {
            Log::error('BTCPay API key revocation failed', [
                'error' => $e->getMessage(),
                'userId' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Delete a BTCPay user.
     *
     * @throws BtcPayException
     */
    public function deleteUser(string $userId): void
    {
        try {
            $this->client->delete("/api/v1/users/{$userId}");
        } catch (BtcPayException $e) {
            Log::error('BTCPay user deletion failed', [
                'error' => $e->getMessage(),
                'userId' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Update the BTCPay profile for whoever owns the given API key (PUT /api/v1/users/me).
     * Greenfield does not expose PUT/PATCH on /api/v1/users/{id} for arbitrary users; server-key
     * updates must go through this "current user" endpoint using that user's merchant API key.
     *
     * @param  array<string, mixed>  $payload  e.g. email, name, imageUrl, currentPassword, newPassword (see BTCPay swagger)
     * @return array<string, mixed>
     *
     * @throws BtcPayException
     */
    public function updateCurrentUserProfile(string $merchantApiKey, array $payload): array
    {
        $previousKey = $this->client->getApiKey();
        try {
            $this->client->setApiKey($merchantApiKey);

            return $this->client->put('/api/v1/users/me', $payload);
        } finally {
            $this->client->setApiKey($previousKey);
        }
    }

    /**
     * Update a user in BTCPay Server (PATCH/PUT /api/v1/users/{id}).
     * Prefer {@see updateCurrentUserProfile} when you have the user's merchant API key; current
     * BTCPay Greenfield only documents self-service updates on PUT /api/v1/users/me.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws BtcPayException
     */
    public function updateUser(string $userId, array $data): array
    {
        try {
            // Try PATCH first (more RESTful for partial updates)
            // If that fails, try PUT
            try {
                return $this->client->patch("/api/v1/users/{$userId}", $data);
            } catch (BtcPayException $e) {
                if (str_contains($e->getMessage(), '405') || str_contains($e->getMessage(), 'Method Not Allowed')) {
                    // PATCH not supported, try PUT
                    return $this->client->put("/api/v1/users/{$userId}", $data);
                }
                throw $e;
            }
        } catch (BtcPayException $e) {
            Log::error('BTCPay user update failed', [
                'error' => $e->getMessage(),
                'userId' => $userId,
                'data' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Confirm email for a user in BTCPay Server.
     * Tries various possible endpoints for email confirmation.
     *
     * @param  string  $userId  BTCPay user ID
     * @return array Updated user data
     *
     * @throws BtcPayException
     */
    public function confirmUserEmail(string $userId): array
    {
        // Try different possible endpoints for email confirmation
        $possibleEndpoints = [
            "/api/v1/users/{$userId}/confirm-email",
            "/api/v1/users/{$userId}/email/confirm",
            "/api/v1/users/{$userId}/verify-email",
        ];

        foreach ($possibleEndpoints as $endpoint) {
            try {
                return $this->client->post($endpoint);
            } catch (BtcPayException $e) {
                // Continue to next endpoint if this one doesn't exist
                if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
                    continue;
                }
                // If it's a different error (e.g., 401, 403), throw it
                throw $e;
            }
        }

        // If all endpoints failed, throw the last exception
        throw new BtcPayException('No email confirmation endpoint found');
    }

    /**
     * Accept a BTCPay user invitation by calling the invitation URL.
     *
     * Invitation URLs have format: https://pay.example.org/invite/{inviteId}/{approvalCode}
     *
     * @param  string  $invitationUrl  Full invitation URL from BTCPay API response
     * @return bool True if invitation was accepted successfully, false otherwise
     */
    public function acceptInvitation(string $invitationUrl): bool
    {
        try {
            // Parse invitation URL to extract invite ID and approval code
            // Format: https://pay.example.org/invite/{inviteId}/{approvalCode}
            if (! preg_match('#/invite/([^/]+)/([^/?]+)#', $invitationUrl, $matches)) {
                Log::warning('Invalid invitation URL format', [
                    'invitation_url' => '[REDACTED]',
                ]);

                return false;
            }

            $inviteId = $matches[1];
            $approvalCode = $matches[2];

            // Try API endpoint first (if available)
            $possibleApiEndpoints = [
                "/api/v1/users/invitations/{$inviteId}/accept",
                "/api/v1/invitations/{$inviteId}/accept",
            ];

            foreach ($possibleApiEndpoints as $endpoint) {
                try {
                    $this->client->post($endpoint, ['approvalCode' => $approvalCode]);
                    Log::info('BTCPay invitation accepted via API', [
                        'invite_id' => $inviteId,
                        'endpoint' => $endpoint,
                    ]);

                    return true;
                } catch (BtcPayException $e) {
                    // Continue to next endpoint if this one doesn't exist
                    if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
                        continue;
                    }
                    // If it's a different error, log and try direct URL call
                    Log::warning('BTCPay invitation API endpoint failed', [
                        'invite_id' => $inviteId,
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // If API endpoints don't work, make a GET request to the invitation URL
            // This simulates clicking the invitation link in a browser
            $baseUrl = rtrim(config('services.btcpay.base_url', env('BTCPAY_BASE_URL')), '/');
            $invitationPath = parse_url($invitationUrl, PHP_URL_PATH);

            if ($invitationPath) {
                // Use the same base URL as BTCPay API to maintain session/cookies if needed
                $response = Http::baseUrl($baseUrl)
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'User-Agent' => 'BTCPay-satflux.io/1.0',
                    ])
                    ->timeout(30)
                    ->get($invitationPath);

                if ($response->successful()) {
                    Log::info('BTCPay invitation accepted via direct URL call', [
                        'invite_id' => $inviteId,
                        'status' => $response->status(),
                    ]);

                    return true;
                } else {
                    Log::warning('BTCPay invitation URL call failed', [
                        'invite_id' => $inviteId,
                        'status' => $response->status(),
                    ]);

                    return false;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::error('BTCPay invitation acceptance failed', [
                'invitation_url' => '[REDACTED]',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the BTCPay user ID associated with the server-level API key (admin user).
     * This is the admin account that the server-level API key belongs to.
     *
     * @return string|null BTCPay user ID or null if not found
     *
     * @throws BtcPayException
     */
    public function getAdminBtcPayUserId(): ?string
    {
        try {
            // Cache the result since it doesn't change frequently
            return Cache::remember('btcpay:admin_user_id', 3600, function () {
                // Try different possible endpoints to get current user info
                $possibleEndpoints = [
                    '/api/v1/api-keys/current',
                    '/api/v1/users/me',
                    '/api/v1/me',
                ];

                foreach ($possibleEndpoints as $endpoint) {
                    try {
                        $response = $this->client->get($endpoint);

                        // Extract user ID from response
                        $userId = $response['id'] ?? $response['userId'] ?? $response['user']['id'] ?? null;

                        if ($userId) {
                            Log::info('Retrieved admin BTCPay user ID', [
                                'user_id' => $userId,
                                'endpoint' => $endpoint,
                            ]);

                            return $userId;
                        }
                    } catch (BtcPayException $e) {
                        // Continue to next endpoint if this one doesn't exist
                        if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
                            continue;
                        }
                        // If it's a different error, log and try next
                        Log::warning('Failed to get admin user ID from endpoint', [
                            'endpoint' => $endpoint,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Fallback: list users and find admin user
                try {
                    $users = $this->listUsers();
                    foreach ($users as $user) {
                        if (isset($user['isAdministrator']) && $user['isAdministrator'] === true) {
                            $adminId = $user['id'] ?? $user['userId'] ?? null;
                            if ($adminId) {
                                Log::info('Found admin BTCPay user ID via user list', [
                                    'user_id' => $adminId,
                                ]);

                                return $adminId;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to find admin user via user list', [
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::error('Could not determine admin BTCPay user ID');

                return null;
            });
        } catch (BtcPayException $e) {
            Log::error('Failed to get admin BTCPay user ID', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
