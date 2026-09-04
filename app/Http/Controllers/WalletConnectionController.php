<?php

namespace App\Http\Controllers;

use App\Exceptions\EmailCodeChallengeException;
use App\Exceptions\WalletChangeConfirmationException;
use App\Http\Requests\WalletConnectionStoreRequest;
use App\Models\AuditLog;
use App\Models\EmailVerificationChallenge;
use App\Models\Store;
use App\Models\WalletConnection;
use App\Services\Auth\EmailCodeChallengeService;
use App\Services\Auth\SensitiveActionAuthorization;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\LightningService;
use App\Services\LnAddressLud21Prober;
use App\Services\WalletChangeConfirmationGuard;
use App\Services\WalletConnectionService;
use App\Services\WalletConnectionValidator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalletConnectionController extends Controller
{
    protected WalletConnectionService $service;

    protected LightningService $lightningService;

    public function __construct(
        WalletConnectionService $service,
        LightningService $lightningService,
        protected WalletChangeConfirmationGuard $changeGuard,
        protected EmailCodeChallengeService $emailCodes,
    ) {
        $this->service = $service;
        $this->lightningService = $lightningService;
    }

    /**
     * Get wallet connection for a store (masked).
     */
    public function show(Request $request)
    {
        $store = $request->route('store');
        $connection = WalletConnection::where('store_id', $store->id)->first();

        if (! $connection) {
            return response()->json(['data' => null]);
        }

        // The wait-card polls this endpoint while the connection is "pending".
        // Reconcile against live BTCPay state so a configured Lightning node
        // flips the row to "connected" even when the original connect attempt
        // could not record it.
        if ($connection->status === 'pending') {
            try {
                if (app(WalletConnectionService::class)->syncStatusFromBtcpay($store, $connection, $request->user())) {
                    $connection->refresh();
                }
            } catch (\Throwable) {
                // Best-effort - the poll keeps returning the stored status.
            }
        }

        $validator = app(WalletConnectionValidator::class);
        $brand = $connection->type === 'lnaddress'
            ? $validator->resolveLnAddressBrand($connection)
            : $validator->resolveAquaBoltzBrand($connection);

        return response()->json([
            'data' => [
                'id' => $connection->id,
                'type' => $connection->type,
                'status' => $connection->status,
                'configuration_source' => $connection->configuration_source,
                'brand' => $brand,
                'masked_secret' => $connection->masked_secret,
                'submitted_at' => $connection->created_at,
                'secret_updated_at' => $connection->secret_updated_at,
                'submitted_by_user_id' => $connection->submitted_by_user_id,
                'bot_failure_message' => $connection->bot_failure_message,
                // Replacing a connected wallet needs an email-code grant (guests: upgrade first).
                'change_confirmation' => $this->changeGuard->state($store, $request->user()),
            ],
        ]);
    }

    /**
     * Wallet change, step 1: re-auth (password for password accounts) and
     * email a 6-digit code. Only needed when the store already has a
     * CONNECTED wallet; otherwise the client goes straight to the form.
     */
    public function requestChange(Request $request)
    {
        $request->validate([
            'password' => ['nullable', 'string'],
        ]);

        $store = $request->route('store');
        $user = $request->user();

        if (! $this->changeGuard->requiresConfirmation($store)) {
            return response()->json(['data' => ['required' => false]]);
        }
        if ((bool) ($user->is_guest ?? false)) {
            throw WalletChangeConfirmationException::guestUpgradeRequired();
        }

        SensitiveActionAuthorization::assertAllowed($user, $request);

        try {
            $challenge = $this->emailCodes->issue(
                $user,
                EmailVerificationChallenge::PURPOSE_WALLET_CONNECTION_CHANGE,
                (string) $user->email,
                ['store_id' => $store->id],
            );
        } catch (EmailCodeChallengeException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        AuditLog::log('wallet_connection.change_requested', 'store', $store->id, [
            'store_id' => $store->id,
            'challenge_id' => $challenge->id,
        ], $user->id);

        return response()->json([
            'data' => [
                'required' => true,
                'challenge' => $this->emailCodes->summary($challenge),
            ],
        ]);
    }

    /**
     * Wallet change, step 2: verify the code. The verified challenge is the
     * grant (WalletChangeConfirmationGuard::GRANT_MINUTES); the secret is
     * revealed here so the form opens in editing mode right away.
     */
    public function confirmChange(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $store = $request->route('store');
        $user = $request->user();
        $connection = WalletConnection::where('store_id', $store->id)->first();

        if (! $connection || ! $this->changeGuard->requiresConfirmation($store)) {
            return response()->json(['message' => 'No connected wallet to change.'], 404);
        }
        if ((bool) ($user->is_guest ?? false)) {
            throw WalletChangeConfirmationException::guestUpgradeRequired();
        }

        $challenge = $this->emailCodes->verify(
            $user,
            EmailVerificationChallenge::PURPOSE_WALLET_CONNECTION_CHANGE,
            $validated['code'],
        );
        if (($challenge->payload['store_id'] ?? null) !== $store->id) {
            // Code was issued for another store of the same owner.
            $this->emailCodes->consume($challenge);
            throw EmailCodeChallengeException::missing();
        }

        try {
            $plaintext = $this->service->reveal($connection, $user);
        } catch (DecryptException $e) {
            return response()->json([
                'message' => 'Unable to decrypt the stored secret. Please re-submit your wallet connection.',
            ], 500);
        }
        if ($connection->type === 'aqua_descriptor') {
            $plaintext = app(WalletConnectionValidator::class)->stripDescriptorChecksum($plaintext);
        }

        AuditLog::log('wallet_connection.change_granted', 'wallet_connection', $connection->id, [
            'store_id' => $store->id,
            'challenge_id' => $challenge->id,
        ], $user->id);

        return response()->json([
            'data' => [
                'granted_until' => $this->changeGuard->grantedUntil($store, $user)?->toIso8601String(),
                'secret' => $plaintext,
                'type' => $connection->type,
                'masked_secret' => $connection->masked_secret,
            ],
        ]);
    }

    public function resendChange(Request $request)
    {
        $store = $request->route('store');
        $user = $request->user();

        if ((bool) ($user->is_guest ?? false)) {
            throw WalletChangeConfirmationException::guestUpgradeRequired();
        }

        try {
            $challenge = $this->emailCodes->resend($user, EmailVerificationChallenge::PURPOSE_WALLET_CONNECTION_CHANGE);
        } catch (EmailCodeChallengeException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        if (($challenge->payload['store_id'] ?? null) !== $store->id) {
            throw EmailCodeChallengeException::missing();
        }

        return response()->json([
            'data' => ['challenge' => $this->emailCodes->summary($challenge)],
        ]);
    }

    /**
     * Reveal wallet connection secret for store owner.
     * Requires password, LNURL/Nostr confirm, or an authenticated recovery-phrase session.
     */
    public function revealForOwner(Request $request)
    {
        $request->validate([
            'password' => ['nullable', 'string'],
        ]);

        $store = $request->route('store');
        $connection = WalletConnection::where('store_id', $store->id)->first();

        if (! $connection) {
            return response()->json(['message' => 'No wallet connection found for this store.'], 404);
        }

        $user = $request->user();
        SensitiveActionAuthorization::assertAllowed($user, $request);
        // A connected wallet's secret is only shown after the email code
        // (guests keep the password/session reveal - they cannot receive codes).
        $this->changeGuard->assert($store, $user, allowGuest: true);

        try {
            $plaintext = $this->service->reveal($connection, $user);
        } catch (DecryptException $e) {
            return response()->json([
                'message' => 'Unable to decrypt the stored secret. Please re-submit your wallet connection.',
            ], 500);
        }

        if ($connection->type === 'aqua_descriptor') {
            $plaintext = app(WalletConnectionValidator::class)->stripDescriptorChecksum($plaintext);
        }

        return response()->json([
            'data' => [
                'secret' => $plaintext,
                'type' => $connection->type,
                'masked_secret' => $connection->masked_secret,
            ],
        ]);
    }

    /**
     * Probe an unknown Lightning-address domain for LUD-21 verify support -
     * QuickConnect decides between a native lnaddress connection and the
     * CashuMelt path based on the result.
     */
    public function lnaddressProbe(Request $request, LnAddressLud21Prober $prober)
    {
        $request->validate([
            'address' => ['required', 'string', 'max:320', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/'],
        ]);

        $result = $prober->probe($request->string('address')->toString());

        return response()->json(['data' => $result]);
    }

    /**
     * Check if a descriptor is already in use by another store.
     * Used for frontend validation before submission.
     * Works for both existing stores and new stores (when store ID is 'new' or doesn't exist).
     */
    public function checkDuplicate(Request $request)
    {
        $request->validate([
            'descriptor' => 'required|string',
            'type' => 'required|in:aqua_descriptor',
        ]);

        // Only check for aqua_descriptor type
        if ($request->type !== 'aqua_descriptor') {
            return response()->json([
                'duplicate' => false,
                'message' => null,
            ]);
        }

        // Get store from route, but handle case where store doesn't exist yet (for new stores)
        $store = $request->route('store');
        $storeId = null;

        // If store is 'new' or doesn't exist, use null to check against all stores
        if ($store && $store !== 'new' && is_object($store) && isset($store->id)) {
            $storeId = $store->id;
        }

        $result = $this->service->checkDescriptorDuplicate(
            $request->descriptor,
            $storeId ?? 'new' // Use 'new' as placeholder for non-existent stores
        );

        return response()->json([
            'duplicate' => $result['exists'],
            'existing_store_id' => $result['existing_store_id'],
            'existing_store_name' => $result['existing_store_name'],
            'message' => $result['exists']
                ? "This descriptor is already in use by store: {$result['existing_store_name']}. BTCPay allows each descriptor to be used only once. Please use a different wallet/descriptor."
                : null,
        ]);
    }

    /**
     * Check if a descriptor is already in use by another store (for new stores).
     * Used for frontend validation before store creation.
     */
    public function checkDuplicateNew(Request $request)
    {
        $request->validate([
            'descriptor' => 'required|string',
            'type' => 'required|in:aqua_descriptor',
        ]);

        // Only check for aqua_descriptor type
        if ($request->type !== 'aqua_descriptor') {
            return response()->json([
                'duplicate' => false,
                'message' => null,
            ]);
        }

        // For new stores, check against all existing stores
        $result = $this->service->checkDescriptorDuplicate(
            $request->descriptor,
            null // No current store ID for new stores
        );

        return response()->json([
            'duplicate' => $result['exists'],
            'existing_store_id' => $result['existing_store_id'],
            'existing_store_name' => $result['existing_store_name'],
            'message' => $result['exists']
                ? "This descriptor is already in use by store: {$result['existing_store_name']}. BTCPay allows each descriptor to be used only once. Please use a different wallet/descriptor."
                : null,
        ]);
    }

    /**
     * Create or update wallet connection.
     */
    public function store(WalletConnectionStoreRequest $request)
    {
        $store = $request->route('store');
        $user = $request->user();

        // Replacing a connected wallet needs the email-code grant.
        $this->changeGuard->assert($store, $user);

        // pending = bot runs first; support notified only on bot failure
        $connection = $this->service->createOrUpdate(
            $store,
            $request->type,
            $request->secret,
            $user,
            'pending',
            $request->input('fallback_lightning_address') ?: null
        );

        // Audit log
        AuditLog::log(
            'wallet_connection.created',
            'wallet_connection',
            $connection->id,
            [
                'store_id' => $store->id,
                'type' => $connection->type,
            ],
            $user->id
        );

        $this->changeGuard->consumeGrant($store, $user);

        return response()->json([
            'data' => [
                'id' => $connection->id,
                'type' => $connection->type,
                'status' => $connection->status,
                'masked_secret' => $connection->masked_secret,
            ],
            'message' => 'Wallet connection saved successfully',
        ], 201);
    }

    /**
     * Delete wallet connection (only if status is pending).
     */
    public function destroy(Request $request)
    {
        $store = $request->route('store');
        $connection = WalletConnection::where('store_id', $store->id)->first();

        if (! $connection) {
            return response()->json(['message' => 'Wallet connection not found'], 404);
        }

        // Only allow deletion if status is pending
        if ($connection->status !== 'pending') {
            return response()->json([
                'message' => 'Cannot delete wallet connection. Only pending connections can be deleted.',
            ], 422);
        }

        $connectionId = $connection->id;
        $connection->delete();

        // Audit log
        AuditLog::log(
            'wallet_connection.deleted',
            'wallet_connection',
            $connectionId,
            [
                'store_id' => $store->id,
                'type' => $connection->type,
            ],
            $request->user()->id
        );

        return response()->json(['message' => 'Wallet connection deleted successfully']);
    }

    /**
     * List wallet connections (support role only).
     * Query param: status = needs_support (default) | connected | pending | all
     * Bot should use status=pending only (new connections). needs_support = manual support, bot does not retry.
     */
    public function indexSupport(Request $request)
    {
        $status = $request->query('status', 'needs_support');
        $query = WalletConnection::with(['store', 'submittedBy'])->orderBy('updated_at', 'desc');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $connections = $query->get();
        $validator = app(WalletConnectionValidator::class);

        return response()->json([
            'data' => $connections->map(function ($connection) use ($validator) {
                return [
                    'id' => $connection->id,
                    'store_id' => $connection->store_id,
                    'store_name' => $connection->store->name ?? 'Unknown',
                    'type' => $connection->type,
                    'brand' => $validator->resolveAquaBoltzBrand($connection),
                    'status' => $connection->status,
                    'masked_secret' => $connection->masked_secret,
                    'submitted_by' => $connection->submittedBy->email ?? 'Unknown',
                    'submitted_at' => $connection->created_at,
                    'secret_updated_at' => $connection->secret_updated_at,
                    'updated_at' => $connection->updated_at,
                    'revealed_last_at' => $connection->revealed_last_at,
                    'bot_failure_message' => $connection->status === 'needs_support' ? $connection->bot_failure_message : null,
                    'bot_failed_at' => $connection->status === 'needs_support' ? $connection->bot_failed_at : null,
                ];
            }),
        ]);
    }

    /**
     * Get count of items needing support (support role only).
     */
    public function getSupportCount(Request $request)
    {
        $walletConnectionsCount = WalletConnection::where('status', 'needs_support')->count();

        return response()->json([
            'data' => [
                'wallet_connections' => $walletConnectionsCount,
                'total' => $walletConnectionsCount, // For now only wallet connections, can add stores later
            ],
        ]);
    }

    /**
     * Reveal wallet connection secret (support role only).
     * Requires password, LNURL/Nostr confirm, or an authenticated recovery-phrase session.
     */
    public function reveal(Request $request, WalletConnection $connection)
    {
        $request->validate([
            'password' => ['nullable', 'string'],
        ]);

        SensitiveActionAuthorization::assertAllowed($request->user(), $request);

        try {
            $plaintext = $this->service->reveal($connection, $request->user());
        } catch (DecryptException $e) {
            return response()->json([
                'message' => 'Unable to decrypt the stored secret. This usually happens when APP_KEY was changed after the secret was saved. The merchant will need to re-submit their wallet connection.',
            ], 500);
        }

        if ($connection->type === 'aqua_descriptor') {
            $plaintext = app(WalletConnectionValidator::class)->stripDescriptorChecksum($plaintext);
        }

        $connection->loadMissing('store');
        $store = $connection->store;

        return response()->json([
            'data' => [
                'secret' => $plaintext,
                'type' => $connection->type,
                'reconfig' => (bool) $connection->reconfig,
                'btcpay_store_id' => $store?->btcpay_store_id,
                'store_name' => $store?->name,
                'masked_secret' => $connection->masked_secret,
                'revealed_at' => $connection->revealed_last_at,
            ],
            'message' => 'Secret revealed (will auto-hide after 30 seconds)',
        ]);
    }

    /**
     * Mark wallet connection as connected (support role only).
     */
    public function markConnected(Request $request, WalletConnection $connection)
    {
        $this->service->markConnected($connection, $request->user());

        return response()->json([
            'data' => [
                'id' => $connection->id,
                'status' => $connection->status,
            ],
            'message' => 'Wallet connection marked as connected',
        ]);
    }

    /**
     * Report config bot failure: set status to needs_support and send support notifications (support role / bot token).
     */
    public function botFailed(Request $request, WalletConnection $connection)
    {
        $error = $request->input('error', '');
        Log::info('Config bot reported failure', [
            'connection_id' => $connection->id,
            'store_id' => $connection->store_id,
            'error' => $error,
        ]);

        $connection->update([
            'bot_failure_message' => $error ?: null,
            'bot_failed_at' => now(),
        ]);

        $this->service->markNeedsSupportAndNotify($connection);

        return response()->json([
            'data' => [
                'id' => $connection->id,
                'status' => $connection->fresh()->status,
            ],
            'message' => 'Bot failure recorded; support notified',
        ]);
    }

    /**
     * Get Satflux store wallet-connection URL for wallet connection (support role only).
     * Users never log in to BTCPay - links must stay within Satflux.
     */
    public function getBtcPayStoreUrl(Request $request, WalletConnection $connection)
    {
        // Load store relationship if not already loaded
        if (! $connection->relationLoaded('store')) {
            $connection->load('store');
        }

        $store = $connection->store;
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $panelUrl = rtrim(config('app.url', ''), '/');
        $url = "{$panelUrl}/stores/{$store->id}/wallet-connection";

        return response()->json([
            'data' => [
                'url' => $url,
                'store_id' => $store->btcpay_store_id,
                'store_name' => $store->name,
            ],
        ]);
    }

    /**
     * Test Lightning connection.
     *
     * Validates connection string format and attempts to verify Lightning configuration.
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'connection_string' => ['required', 'string'],
            'crypto_code' => ['nullable', 'string', 'in:BTC,LTC'],
        ]);

        $store = $request->route('store');
        $cryptoCode = $request->input('crypto_code', 'BTC');

        // Get merchant API key
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $result = $this->lightningService->testConnection(
            $store->btcpay_store_id,
            $cryptoCode,
            $request->connection_string,
            $userApiKey
        );

        // Audit log
        AuditLog::log(
            'wallet_connection.test_connection',
            'wallet_connection',
            null,
            [
                'store_id' => $store->id,
                'crypto_code' => $cryptoCode,
                'success' => $result['success'] ?? false,
            ],
            $request->user()->id
        );

        Log::info('Lightning connection test performed', [
            'store_id' => $store->id,
            'crypto_code' => $cryptoCode,
            'success' => $result['success'] ?? false,
        ]);

        return response()->json($result);
    }

    /**
     * Configure Lightning node in BTCPay.
     *
     * Attempts to configure Lightning node via BTCPay API.
     * If successful, updates wallet connection status to 'connected'.
     * If API doesn't support custom connection strings, stores in DB with 'needs_support' status.
     * This method can be used to retry connection if automatic connection failed during store creation.
     */
    public function configureLightning(Request $request)
    {
        $request->validate([
            'connection_string' => ['required', 'string'],
            'crypto_code' => ['nullable', 'string', 'in:BTC,LTC'],
        ]);

        $store = $request->route('store');
        $user = $request->user();
        $cryptoCode = $request->input('crypto_code', 'BTC');

        // Same gate as the wallet-connection POST: a connected wallet is only
        // replaced behind the email-code grant.
        $this->changeGuard->assert($store, $user);
        $this->changeGuard->consumeGrant($store, $user);

        // Get merchant API key
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        // Find or create wallet connection in DB
        $connection = WalletConnection::where('store_id', $store->id)->first();
        if (! $connection) {
            // Determine type from connection string
            $type = 'blink'; // Default
            if (strpos($request->connection_string, 'ct(') !== false ||
                strpos($request->connection_string, 'wpkh') !== false ||
                strpos($request->connection_string, 'tr(') !== false ||
                strpos($request->connection_string, 'slip77') !== false) {
                $type = 'aqua_descriptor';
            }

            $connection = $this->service->createOrUpdate(
                $store,
                $type,
                $request->connection_string,
                $user
            );
        }

        // Try to configure via BTCPay API
        try {
            $result = $this->lightningService->connectLightningNode(
                $store->btcpay_store_id,
                $cryptoCode,
                $request->connection_string,
                $userApiKey
            );

            // If connection successful, update status
            if ($result['success'] ?? false) {
                $this->service->markConnected($connection, $user);
                $result['status'] = 'connected';
                $result['message'] = 'Lightning node connected successfully to BTCPay.';

                Log::info('Lightning node connected successfully via configureLightning', [
                    'store_id' => $store->id,
                    'wallet_connection_id' => $connection->id,
                    'crypto_code' => $cryptoCode,
                ]);
            } else {
                // Connection failed - ensure status is needs_support
                if ($connection->status !== 'needs_support') {
                    $connection->update(['status' => 'needs_support']);
                }
                $result['status'] = $connection->status;
                $result['message'] = $result['message'] ?? 'Failed to connect Lightning node. Support will configure it manually.';

                Log::info('Lightning node connection failed via configureLightning', [
                    'store_id' => $store->id,
                    'wallet_connection_id' => $connection->id,
                    'crypto_code' => $cryptoCode,
                    'message' => $result['message'] ?? 'Unknown error',
                ]);
            }

            $result['connection_id'] = $connection->id;
        } catch (BtcPayException $e) {
            // BTCPay API error
            $connection->update(['status' => 'needs_support']);

            $result = [
                'success' => false,
                'message' => 'Failed to connect Lightning node: '.$e->getMessage(),
                'requires_manual_config' => true,
                'connection_id' => $connection->id,
                'status' => 'needs_support',
            ];

            Log::error('BTCPay API error when configuring Lightning node', [
                'store_id' => $store->id,
                'wallet_connection_id' => $connection->id,
                'crypto_code' => $cryptoCode,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
        } catch (\Exception $e) {
            // Other errors
            $connection->update(['status' => 'needs_support']);

            $result = [
                'success' => false,
                'message' => 'An error occurred while connecting Lightning node: '.$e->getMessage(),
                'requires_manual_config' => true,
                'connection_id' => $connection->id,
                'status' => 'needs_support',
            ];

            Log::error('Unexpected error when configuring Lightning node', [
                'store_id' => $store->id,
                'wallet_connection_id' => $connection->id,
                'crypto_code' => $cryptoCode,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
        }

        // Audit log
        AuditLog::log(
            'wallet_connection.configured',
            'wallet_connection',
            $connection->id,
            [
                'store_id' => $store->id,
                'crypto_code' => $cryptoCode,
                'success' => $result['success'] ?? false,
                'requires_manual_config' => $result['requires_manual_config'] ?? false,
                'status' => $result['status'] ?? 'needs_support',
            ],
            $user->id
        );

        return response()->json($result);
    }
}
