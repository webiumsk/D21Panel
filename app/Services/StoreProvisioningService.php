<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\CashuService;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\LightningService;
use App\Services\BtcPay\StoreService;
use App\Services\BtcPay\UserService;
use App\Services\BtcPay\WebhookService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates store creation: BTCPay store + owner assignment, local record,
 * optional Cashu/wallet-connection setup, checklist and webhook provisioning.
 * Extracted from StoreController::store - behavior preserved 1:1.
 */
class StoreProvisioningService
{
    public function __construct(
        protected StoreService $storeService,
        protected UserService $userService,
        protected LightningService $lightningService,
        protected CashuService $cashuService,
        protected WalletConnectionService $walletConnectionService,
    ) {}

    /**
     * @param array{
     *     name: string,
     *     default_currency: string,
     *     timezone: string,
     *     preferred_exchange?: string|null,
     *     wallet_type?: string|null,
     *     connection_string?: string|null,
     *     mint_url?: string|null,
     *     lightning_address?: string|null,
     * } $data Validated StoreCreateRequest payload
     *
     * @throws ValidationException
     */
    public function create(User $user, array $data): Store
    {
        $walletType = $data['wallet_type'] ?? null;
        // One normalized value for both BTCPay and the local record: empty
        // string means "no preference" (null = BTCPay exchange recommendation).
        $preferredExchange = ($data['preferred_exchange'] ?? null) ?: null;

        Log::info('Store provisioning started', [
            'user_id' => $user->id,
            'store_name' => $data['name'],
            'wallet_type' => $walletType,
            'has_connection_string' => ! empty($data['connection_string']),
        ]);

        return DB::transaction(function () use ($user, $data, $walletType, $preferredExchange) {
            // Server-level API key (unrestricted) is required for provisioning.
            // StoreService's injected BtcPayClient defaults to it, so no key
            // juggling here - createStore(..., null) runs with the server key.
            if (! config('services.btcpay.api_key')) {
                abort(500, 'Server-level BTCPay API key not configured.');
            }

            // Preflight: a duplicate Aqua descriptor must fail BEFORE the BTCPay
            // store exists, otherwise the rollback leaves an orphaned BTCPay store.
            if ($walletType !== null && $walletType !== 'cashu' && ! empty($data['connection_string'])) {
                $this->assertDescriptorAvailable($walletType, $data['connection_string']);
            }

            $btcpayStore = $this->createBtcPayStore($data, $preferredExchange);
            $btcpayStoreId = $btcpayStore['id'] ?? $btcpayStore['storeId'] ?? null;
            if (! $btcpayStoreId) {
                abort(500, 'Failed to create store: BTCPay did not return a store ID.');
            }

            // From here on the BTCPay store exists - if any later step throws,
            // delete it so the DB rollback does not leave an orphan behind.
            try {
                $this->forgetStoreCaches($btcpayStoreId, $user);
                $this->assignOwners($btcpayStoreId, $user);

                $store = new Store([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'name' => $data['name'],
                    // default_currency and timezone are required by StoreCreateRequest
                    'default_currency' => $data['default_currency'],
                    'timezone' => $data['timezone'],
                    'preferred_exchange' => $preferredExchange ?? 'kraken',
                    'wallet_type' => $walletType,
                ]);
                $store->btcpay_store_id = $btcpayStoreId;
                $store->save();

                if ($walletType === 'cashu') {
                    $this->configureCashu($store, $user, $data);
                }

                if ($walletType !== null && $walletType !== 'cashu' && ! empty($data['connection_string'])) {
                    $this->createWalletConnection($store, $user, $data);
                }

                StoreChecklistService::ensureChecklistInitialized($store);
            } catch (\Throwable $e) {
                $this->cleanupOrphanedBtcPayStore($btcpayStoreId, $e);
                throw $e;
            }

            $store->load('checklistItems', 'walletConnection');

            $this->scheduleWebhookProvisioning($store->id);

            Log::info('Store provisioning completed', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'wallet_connection_id' => $store->walletConnection->id ?? null,
                'wallet_connection_status' => $store->walletConnection->status ?? null,
            ]);

            return $store;
        });
    }

    /**
     * @throws ValidationException when an Aqua descriptor is already in use
     */
    protected function assertDescriptorAvailable(string $walletType, string $connectionString): void
    {
        if (in_array($walletType, ['blink', 'blitz', 'flash'], true)) {
            return; // Blink tokens and Blitz/Flash addresses are not unique per store
        }

        $duplicateCheck = $this->walletConnectionService->checkDescriptorDuplicate($connectionString, null);
        if ($duplicateCheck['exists']) {
            Log::warning('Aqua descriptor already in use - aborting store creation before BTCPay call', [
                'existing_store_id' => $duplicateCheck['existing_store_id'],
                'existing_store_name' => $duplicateCheck['existing_store_name'],
            ]);

            throw ValidationException::withMessages([
                'connection_string' => [
                    'This descriptor is already in use by another store. '.
                    'BTCPay allows each descriptor to be used only once. '.
                    ($duplicateCheck['existing_store_name']
                        ? "It is currently used by store: {$duplicateCheck['existing_store_name']}"
                        : 'Please use a different wallet/descriptor.'),
                ],
            ]);
        }
    }

    /** Best-effort compensation: the DB transaction rolls back, BTCPay does not. */
    protected function cleanupOrphanedBtcPayStore(string $btcpayStoreId, \Throwable $cause): void
    {
        try {
            $this->storeService->deleteStore($btcpayStoreId, null);
            Log::info('Deleted orphaned BTCPay store after failed provisioning', [
                'btcpay_store_id' => $btcpayStoreId,
                'cause' => $cause->getMessage(),
            ]);
        } catch (\Throwable $deleteError) {
            Log::error('Could not delete orphaned BTCPay store after failed provisioning', [
                'btcpay_store_id' => $btcpayStoreId,
                'provisioning_error' => $cause->getMessage(),
                'delete_error' => $deleteError->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> BTCPay store payload
     */
    protected function createBtcPayStore(array $data, ?string $preferredExchange): array
    {
        $storeData = [
            'name' => $data['name'],
            'defaultCurrency' => $data['default_currency'],
            'timeZone' => $data['timezone'],
            'anyoneCanCreateInvoice' => false,
            'showRecommendedFee' => true,
            'recommendedFeeBlockTarget' => 1,
        ];

        // Preferred exchange if provided (null = BTCPay exchange recommendation)
        if (array_key_exists('preferred_exchange', $data)) {
            $storeData['preferredExchange'] = $preferredExchange;
        }

        Log::info('Creating store in BTCPay', [
            'name' => $storeData['name'],
            'defaultCurrency' => $storeData['defaultCurrency'],
            'timeZone' => $storeData['timeZone'],
            'preferredExchange' => $storeData['preferredExchange'] ?? null,
        ]);

        return $this->storeService->createStore($storeData, null); // null = server-level key (client default)
    }

    /** Clear cached BTCPay store data so fresh data is loaded after creation. */
    protected function forgetStoreCaches(string $btcpayStoreId, User $user): void
    {
        Cache::forget("btcpay:store:{$btcpayStoreId}:server");
        if ($user->btcpay_api_key) {
            $apiKeyHash = hash('sha256', $user->btcpay_api_key);
            Cache::forget("btcpay:store:{$btcpayStoreId}:{$apiKeyHash}");
        }
    }

    /**
     * Add merchant and admin as store Owners. Failures are logged but do not
     * abort provisioning - the store exists in BTCPay at this point.
     */
    protected function assignOwners(string $btcpayStoreId, User $user): void
    {
        if ($user->btcpay_user_id) {
            try {
                $this->storeService->addUserToStore($btcpayStoreId, $user->btcpay_user_id, 'Owner');
                Log::info('Assigned merchant to store after creation', [
                    'merchant_btcpay_user_id' => $user->btcpay_user_id,
                    'store_id' => $btcpayStoreId,
                    'merchant_user_id' => $user->id,
                ]);
            } catch (BtcPayException $e) {
                Log::error('Failed to assign merchant to store after creation', [
                    'store_id' => $btcpayStoreId,
                    'merchant_user_id' => $user->id,
                    'merchant_btcpay_user_id' => $user->btcpay_user_id,
                    'error' => $e->getMessage(),
                    'error_type' => get_class($e),
                ]);
                // Continue - we'll try to add admin anyway
            }
        } else {
            Log::warning('Merchant does not have BTCPay user ID - cannot assign merchant to store', [
                'store_id' => $btcpayStoreId,
                'merchant_user_id' => $user->id,
            ]);
        }

        // Admin as Owner (for support access)
        try {
            $adminBtcPayUserId = $this->userService->getAdminBtcPayUserId();
            if (! $adminBtcPayUserId) {
                Log::error('Could not determine admin BTCPay user ID - admin will not have access to store', [
                    'store_id' => $btcpayStoreId,
                    'merchant_user_id' => $user->id,
                ]);
            } else {
                $this->storeService->addUserToStore($btcpayStoreId, $adminBtcPayUserId, 'Owner');
                Log::info('Assigned admin to store after creation', [
                    'admin_btcpay_user_id' => $adminBtcPayUserId,
                    'store_id' => $btcpayStoreId,
                    'merchant_user_id' => $user->id,
                ]);
            }
        } catch (\Exception $e) {
            // Don't fail the request - store is created, but admin assignment failed
            Log::error('Failed to assign admin to store after creation - admin will not have access to store', [
                'store_id' => $btcpayStoreId,
                'merchant_user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);
        }
    }

    /**
     * Cashu setup (no wallet_connection secret, configured via BTCPay Cashu plugin).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function configureCashu(Store $store, User $user, array $data): void
    {
        try {
            $userApiKey = $user->getBtcPayApiKeyOrFail();

            $this->cashuService->saveSettings(
                $store->btcpay_store_id,
                [
                    'mintUrl' => $data['mint_url'] ?? null,
                    'lightningAddress' => $data['lightning_address'] ?? null,
                    'enabled' => true,
                ],
                $userApiKey
            );

            try {
                $this->lightningService->tryRemoveLightningCheckoutPaymentMethods($store->btcpay_store_id, $userApiKey);
            } catch (\Throwable $e) {
                Log::warning('Could not remove Lightning payment methods at BTCPay after Cashu store create', [
                    'btcpay_store_id' => $store->btcpay_store_id,
                    'message' => $e->getMessage(),
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to save Cashu settings during store creation', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'wallet_type' => $data['wallet_type'] ?? null,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            if ($e instanceof BtcPayException
                && $e->getStatusCode() === 400
                && str_contains($e->getMessage(), 'Request body must be a JSON object')) {
                throw ValidationException::withMessages([
                    'cashu' => [
                        'CashuMelt plugin on BTCPay Server must be updated to version 1.2.0.5 or later '
                        .'(BTCPay Server → Settings → Plugins).',
                    ],
                ]);
            }

            throw ValidationException::withMessages([
                'cashu' => ['Failed to configure BTCPay Cashu plugin: '.$e->getMessage()],
            ]);
        }
    }

    /**
     * Create wallet connection from connection_string (Blink / Aqua descriptor only).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function createWalletConnection(Store $store, User $user, array $data): void
    {
        $connectionType = match ($data['wallet_type']) {
            'blink' => 'blink',
            'blitz' => 'blitz',
            'flash' => 'flash',
            default => 'aqua_descriptor',
        };

        try {
            // For Aqua/Boltz descriptors, check for duplicates BEFORE creating the
            // connection - prevents creating a store when the descriptor is in use.
            if ($connectionType === 'aqua_descriptor') {
                $duplicateCheck = $this->walletConnectionService->checkDescriptorDuplicate(
                    $data['connection_string'],
                    $store->id
                );
                if ($duplicateCheck['exists']) {
                    Log::warning('Aqua descriptor already in use during store creation', [
                        'store_id' => $store->id,
                        'existing_store_id' => $duplicateCheck['existing_store_id'],
                        'existing_store_name' => $duplicateCheck['existing_store_name'],
                    ]);
                    // Rollback transaction by throwing validation exception
                    throw ValidationException::withMessages([
                        'connection_string' => [
                            'This descriptor is already in use by another store. '.
                            'BTCPay allows each descriptor to be used only once. '.
                            ($duplicateCheck['existing_store_name']
                                ? "It is currently used by store: {$duplicateCheck['existing_store_name']}"
                                : 'Please use a different wallet/descriptor.'),
                        ],
                    ]);
                }
            }

            // Create as pending; config bot runs first. Emails sent only on bot failure (via bot-failed).
            $walletConnection = $this->walletConnectionService->createOrUpdate(
                $store,
                $connectionType,
                $data['connection_string'],
                $user,
                'pending',
                $data['fallback_lightning_address'] ?? null
            );

            Log::info('Wallet connection created (pending - config bot will run)', [
                'store_id' => $store->id,
                'wallet_connection_id' => $walletConnection->id ?? null,
                'wallet_type' => $data['wallet_type'],
                'connection_type' => $connectionType,
                'status' => $walletConnection->status ?? null,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Log error but don't fail store creation
            Log::error('Failed to create wallet connection during store creation', [
                'store_id' => $store->id,
                'btcpay_store_id' => $store->btcpay_store_id,
                'wallet_type' => $data['wallet_type'],
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
        }
    }

    /**
     * Provision the panel webhook after the transaction commits (needs the
     * store row to be visible to the queue/HTTP side effects).
     */
    protected function scheduleWebhookProvisioning(string $storeId): void
    {
        DB::afterCommit(function () use ($storeId) {
            $fresh = Store::find($storeId);
            if (! $fresh || $fresh->btcpay_webhook_id !== null) {
                return;
            }
            if (! config('services.btcpay.api_key')) {
                Log::warning('BTCPay webhook not provisioned after store create: BTCPAY_API_KEY missing', [
                    'store_id' => $storeId,
                ]);

                return;
            }
            try {
                $webhookService = app(WebhookService::class);
                $data = $webhookService->replacePanelWebhookForStore($fresh->btcpay_store_id, null);
                $fresh->update([
                    'btcpay_webhook_id' => $data['id'],
                    'webhook_secret' => $data['secret'],
                ]);
                Log::info('BTCPay webhook provisioned after store create', [
                    'store_id' => $fresh->id,
                    'btcpay_store_id' => $fresh->btcpay_store_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('BTCPay webhook provisioning failed after store create', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
