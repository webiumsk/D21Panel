<?php

namespace App\Services;

use App\Events\WalletConnectionNeedsSupport;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Notifications\SupportNeededNotification;
use App\Notifications\WalletConnectionChangedNotification;
use App\Notifications\WalletConnectionNeedsSupportMerchantNotification;
use App\Notifications\WalletConnectionReadyNotification;
use App\Services\BtcPay\BoltzService;
use App\Services\BtcPay\CashuService;
use App\Services\BtcPay\LightningService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class WalletConnectionService
{
    public function __construct(
        protected WalletConnectionValidator $validator,
        protected CashuService $cashuService,
        protected LightningService $lightningService,
        protected BoltzService $boltzService,
    ) {}

    /**
     * Create or update a wallet connection for a store.
     *
     * @param  string  $type  Connection type ('blink' or 'aqua_descriptor')
     * @param  string  $secret  Secret value (will be encrypted)
     * @param  User  $user  User submitting the connection
     * @param  string  $initialStatus  'pending' = bot will run first, no support emails yet; 'needs_support' = notify support immediately
     *
     * @throws ValidationException
     */
    public function createOrUpdate(
        Store $store,
        string $type,
        string $secret,
        User $user,
        string $initialStatus = 'needs_support',
        ?string $fallbackLightningAddress = null,
    ): WalletConnection {
        Log::info('WalletConnectionService::createOrUpdate called', [
            'store_id' => $store->id,
            'store_btcpay_store_id' => $store->btcpay_store_id ?? 'NULL',
            'type' => $type,
            'secret_length' => strlen($secret),
            'secret_preview' => substr($secret, 0, 50).'...',
            'user_id' => $user->id,
        ]);

        // Validate the secret
        Log::info('Validating wallet connection secret', [
            'store_id' => $store->id,
            'type' => $type,
        ]);

        $validation = $this->validator->validate($type, $secret);

        Log::info('Wallet connection validation result', [
            'store_id' => $store->id,
            'type' => $type,
            'valid' => $validation['valid'] ?? 'NOT_SET',
            'errors' => $validation['errors'] ?? [],
        ]);

        if (! $validation['valid']) {
            Log::error('Wallet connection validation failed', [
                'store_id' => $store->id,
                'type' => $type,
                'errors' => $validation['errors'] ?? [],
            ]);
            throw ValidationException::withMessages([
                'secret' => $validation['errors'],
            ]);
        }

        // For Aqua/Boltz descriptors, check if this descriptor is already used in another store
        // BTCPay limitation: each descriptor can only be used once
        if ($type === 'aqua_descriptor') {
            $duplicateCheck = $this->checkDescriptorDuplicate($secret, $store->id);
            if ($duplicateCheck['exists']) {
                Log::warning('Aqua descriptor already in use', [
                    'store_id' => $store->id,
                    'existing_store_id' => $duplicateCheck['existing_store_id'],
                    'existing_store_name' => $duplicateCheck['existing_store_name'],
                ]);
                throw ValidationException::withMessages([
                    'secret' => [
                        'This descriptor is already in use by another store. '.
                        'BTCPay allows each descriptor to be used only once. '.
                        ($duplicateCheck['existing_store_name']
                            ? "It is currently used by store: {$duplicateCheck['existing_store_name']}"
                            : 'Please use a different wallet/descriptor.'),
                    ],
                ]);
            }
        }

        // Check if this is a new connection or update
        $existingConnection = WalletConnection::where('store_id', $store->id)->first();
        $isNew = $existingConnection === null;
        $wasConnected = $existingConnection && $existingConnection->status === 'connected';
        $hadAquaDescriptor = $existingConnection && $existingConnection->type === 'aqua_descriptor';

        // BTCPay Lightning UI after Cashu (eCash) is usually not the greenfield "first setup" tabbed
        // page the bot expects (#LightningNodeType-Custom). Use the same path as Blink reconfig:
        // Settings → Change connection (see scripts/btcpay-config-bot/run-config.js).
        // Switching Aqua/Boltz → Blink while still "pending" must also use reconfig: BTCPay may already
        // have a Boltz connection string; the first-setup wizard does not replace it.
        $cameFromCashu = ($store->wallet_type ?? null) === 'cashu';
        $blinkBotUseReconfigPath = in_array($type, ['blink', 'blitz', 'flash', 'lnaddress'], true)
            && ($wasConnected || $cameFromCashu || $hadAquaDescriptor);

        Log::info('Checking for existing wallet connection', [
            'store_id' => $store->id,
            'is_new' => $isNew,
            'existing_connection_id' => $existingConnection->id ?? 'NULL',
            'was_connected' => $wasConnected,
            'had_aqua_descriptor' => $hadAquaDescriptor,
            'came_from_cashu' => $cameFromCashu,
            'blink_bot_reconfig_path' => $blinkBotUseReconfigPath,
        ]);

        // Create or update wallet connection
        Log::info('Creating/updating wallet connection in database', [
            'store_id' => $store->id,
            'type' => $type,
            'is_new' => $isNew,
        ]);

        try {
            $connection = WalletConnection::updateOrCreate(
                ['store_id' => $store->id],
                [
                    'type' => $type,
                    'configuration_source' => null,
                    'encrypted_secret' => Crypt::encryptString($secret),
                    'status' => $initialStatus,
                    'reconfig' => in_array($type, ['blink', 'blitz', 'flash', 'lnaddress'], true) ? $blinkBotUseReconfigPath : $wasConnected,
                    'bot_failure_message' => null,
                    'bot_failed_at' => null,
                    'secret_updated_at' => now(),
                    'submitted_by_user_id' => $user->id,
                ]
            );

            Log::info('Wallet connection created/updated successfully in database', [
                'store_id' => $store->id,
                'connection_id' => $connection->id,
                'type' => $connection->type,
                'status' => $connection->status,
                'user_id' => $user->id,
                'is_new' => $isNew,
            ]);

            // Keep stores.wallet_type in sync with wallet_connection type
            $storeWalletType = $this->resolveStoreWalletType($connection->type);
            $store->update(['wallet_type' => $storeWalletType]);
            $store->refresh();
            StoreChecklistService::ensureChecklistInitialized($store);

            Log::info('Store wallet_type synced', [
                'store_id' => $store->id,
                'wallet_type' => $storeWalletType,
            ]);

            if (in_array($storeWalletType, ['blink', 'blitz', 'flash', 'lnaddress', 'aqua_boltz', 'nwc'], true)) {
                $merchant = $store->user;
                $userApiKey = ($merchant && filled($merchant->btcpay_api_key ?? null))
                    ? $merchant->btcpay_api_key
                    : null;

                if (! $userApiKey) {
                    Log::warning('Skipping BTCPay wallet sync after local save: merchant has no BTCPay API key', [
                        'store_id' => $store->id,
                        'user_id' => $merchant?->id,
                    ]);
                }

                if ($userApiKey) {
                    // CashuMelt stays enabled in parallel as the payout fallback (Boltz/Spark
                    // outages). The address comes from the request or is derived from
                    // blink/blitz ln-address secrets; without one we fall back to the old
                    // behavior of disabling CashuMelt entirely.
                    $fallbackAddress = $fallbackLightningAddress
                        ?: $this->validator->deriveLightningAddressFromSecret($type, $secret);

                    if ($fallbackAddress !== null && $fallbackAddress !== '') {
                        try {
                            $this->configureCashuFallback($store, $fallbackAddress, $userApiKey, $user);
                        } catch (\Throwable $e) {
                            Log::error('Could not configure CashuMelt fallback at BTCPay', [
                                'store_id' => $store->id,
                                'btcpay_store_id' => $store->btcpay_store_id,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    } else {
                        try {
                            $this->cashuService->tryDisableAtBtcPay(
                                $store->btcpay_store_id,
                                $userApiKey
                            );
                        } catch (\Throwable $e) {
                            Log::error('Could not disable CashuMelt at BTCPay after Lightning wallet connection', [
                                'store_id' => $store->id,
                                'btcpay_store_id' => $store->btcpay_store_id,
                                'message' => $e->getMessage(),
                            ]);
                        }

                        try {
                            $this->cashuService->tryRemoveCashuCheckoutPaymentMethods(
                                $store->btcpay_store_id,
                                $userApiKey
                            );
                        } catch (\Throwable $e) {
                            Log::error('Could not remove Cashu checkout payment method at BTCPay', [
                                'store_id' => $store->id,
                                'btcpay_store_id' => $store->btcpay_store_id,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    $this->attemptBtcpayWalletSync(
                        $store,
                        $connection,
                        $type,
                        $secret,
                        $user,
                        $userApiKey,
                    );
                }
            }

            $connection->refresh();
        } catch (\Exception $e) {
            Log::error('Failed to create/update wallet connection in database', [
                'store_id' => $store->id,
                'type' => $type,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        // Notify support only when status is needs_support (e.g. not when initialStatus is 'pending' - bot runs first)
        if ($connection->status === 'needs_support') {
            $this->notifySupportNeeded($connection, $store);
        }

        // Notify store owner: connection changed (masked secret + security warning) and, if pending, that it's being configured
        $merchant = $store->user;
        if ($merchant && $merchant->email) {
            try {
                $merchant->notify(new WalletConnectionChangedNotification($store, $connection));
            } catch (\Exception $e) {
                Log::error('Failed to send wallet connection changed notification', [
                    'connection_id' => $connection->id,
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $connection;
    }

    /**
     * Persist a connected Aqua/Boltz wallet that was configured via BTCPay SamRock OTP (no manual descriptor in Satflux).
     */
    public function markSamRockConnected(Store $store, User $user, ?string $fallbackLightningAddress = null): WalletConnection
    {
        $secret = $this->samRockPlaceholderDescriptor($store);
        $connection = $this->createOrUpdate(
            $store,
            'aqua_descriptor',
            $secret,
            $user,
            'connected',
            $fallbackLightningAddress
        );
        $connection->update(['configuration_source' => 'samrock']);

        return $connection->fresh();
    }

    /**
     * Deterministic watch-only-shaped placeholder unique per store (BTCPay holds real keys; SamRock flow does not store descriptors in Satflux).
     */
    protected function samRockPlaceholderDescriptor(Store $store): string
    {
        $seed = hash('sha256', 'samrock:'.$store->id);
        $slip77 = substr($seed, 0, 64);
        $fp = substr($seed, 0, 8);
        $xpubBody = 'xpub'.str_pad(substr($seed, 8, 100), 100, '0');

        return "ct(slip77({$slip77}),elsh(wpkh([{$fp}/84h/0h/0h]{$xpubBody}/0/*)))";
    }

    /**
     * Mark connection as needs_support and send event, Discord and emails (e.g. after bot failure).
     */
    public function markNeedsSupportAndNotify(WalletConnection $connection): void
    {
        $connection->update(['status' => 'needs_support']);
        $store = $connection->store;
        if ($store) {
            $this->notifySupportNeeded($connection, $store);

            // Notify merchant: same message as in-app notice + optional bot failure details
            $merchant = $store->user;
            if ($merchant && $merchant->email) {
                try {
                    $merchant->notify(new WalletConnectionNeedsSupportMerchantNotification($store, $connection));
                } catch (\Exception $e) {
                    Log::error('Failed to send wallet connection needs-support (merchant) notification', [
                        'connection_id' => $connection->id,
                        'store_id' => $store->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        Log::info('Wallet connection set to needs_support and support + merchant notified', [
            'connection_id' => $connection->id,
            'store_id' => $connection->store_id,
        ]);
    }

    /**
     * Send support notifications: in-app event, Discord webhook, support emails.
     */
    private function notifySupportNeeded(WalletConnection $connection, Store $store): void
    {
        if (config('broadcasting.default') !== 'null') {
            try {
                event(new WalletConnectionNeedsSupport($connection, $store));
                Log::info('WalletConnectionNeedsSupport event broadcast', [
                    'connection_id' => $connection->id,
                    'store_id' => $store->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to broadcast WalletConnectionNeedsSupport', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $webhookUrl = config('services.discord.support_webhook_url');
        if ($webhookUrl) {
            try {
                $storeName = $store->name;
                $typeLabel = match ($connection->type) {
                    'blink' => 'Blink',
                    'blitz' => 'Blitz Wallet',
                    'flash' => 'Flash Wallet',
                    'lnaddress' => 'LN Address',
                    'nwc' => 'NWC',
                    default => 'Aqua/Bull (Boltz)',
                };
                $panelUrl = rtrim(config('app.url'), '/').'/support/wallet-connections';

                Http::timeout(10)->post($webhookUrl, [
                    'content' => "🔔 **Wallet connection needs support**: {$storeName} ({$typeLabel})",
                    'embeds' => [
                        [
                            'title' => 'Wallet Connection Needs Support',
                            'description' => "**Store:** {$storeName}\n**Type:** {$typeLabel}\n**Status:** Needs Support",
                            'url' => $panelUrl,
                            'color' => 5814783,
                        ],
                    ],
                ]);
                Log::info('Discord webhook sent', ['connection_id' => $connection->id]);
            } catch (\Exception $e) {
                Log::error('Failed to send Discord webhook', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            Notification::route('mail', 'support@satflux.io')
                ->notify(new SupportNeededNotification($connection, $store));
            Log::info('Support needed notification sent to support@satflux.io', [
                'connection_id' => $connection->id,
                'store_id' => $store->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send support needed notification', [
                'connection_id' => $connection->id,
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reveal the plaintext secret (for support/admin use).
     *
     * @param  User  $revealedBy  User revealing the secret
     * @return string Plaintext secret
     */
    public function reveal(WalletConnection $connection, User $revealedBy): string
    {
        $plaintext = $connection->reveal();

        // Update revealed fields
        $connection->update([
            'revealed_last_at' => now(),
            'revealed_last_by' => $revealedBy->id,
        ]);

        // Audit log
        AuditLog::log(
            'wallet_connection.revealed',
            'wallet_connection',
            $connection->id,
            [
                'store_id' => $connection->store_id,
                'type' => $connection->type,
                'masked_secret' => $connection->masked_secret,
            ],
            $revealedBy->id
        );

        Log::info('Wallet connection secret revealed', [
            'connection_id' => $connection->id,
            'store_id' => $connection->store_id,
            'revealed_by' => $revealedBy->id,
        ]);

        return $plaintext;
    }

    /**
     * Check if a descriptor is already used in another store.
     * BTCPay limitation: each descriptor can only be used once.
     *
     * @param  string  $descriptor  The descriptor to check
     * @param  string|null  $currentStoreId  Current store ID (to exclude from check), or null/'new' for new stores
     * @return array ['exists' => bool, 'existing_store_id' => string|null, 'existing_store_name' => string|null]
     */
    public function checkDescriptorDuplicate(string $descriptor, ?string $currentStoreId = null): array
    {
        // Get all Aqua descriptor connections (excluding current store if provided)
        $query = WalletConnection::where('type', 'aqua_descriptor');

        // Exclude current store if it exists (not 'new' or null)
        if ($currentStoreId && $currentStoreId !== 'new') {
            $query->where('store_id', '!=', $currentStoreId);
        }

        $connections = $query->get();

        foreach ($connections as $connection) {
            try {
                $decrypted = Crypt::decryptString($connection->encrypted_secret);
                // Compare descriptors (normalize by trimming)
                if (trim($decrypted) === trim($descriptor)) {
                    $store = $connection->store;

                    return [
                        'exists' => true,
                        'existing_store_id' => $store->id,
                        'existing_store_name' => $store->name,
                    ];
                }
            } catch (\Exception $e) {
                // Skip if decryption fails (shouldn't happen, but be safe)
                Log::warning('Failed to decrypt wallet connection for duplicate check', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return [
            'exists' => false,
            'existing_store_id' => null,
            'existing_store_name' => null,
        ];
    }

    /**
     * Mark wallet connection as connected.
     *
     * @param  User  $markedBy  User marking as connected
     */
    public function markConnected(WalletConnection $connection, User $markedBy): void
    {
        $wasNeedsSupport = $connection->status === 'needs_support';

        $connection->update([
            'status' => 'connected',
        ]);

        // Audit log
        AuditLog::log(
            'wallet_connection.marked_connected',
            'wallet_connection',
            $connection->id,
            [
                'store_id' => $connection->store_id,
                'type' => $connection->type,
                'was_needs_support' => $wasNeedsSupport,
            ],
            $markedBy->id
        );

        // If status changed from needs_support to connected, notify the merchant
        if ($wasNeedsSupport) {
            $store = $connection->store;
            $merchant = $store->user;

            if ($merchant && $merchant->email) {
                try {
                    $merchant->notify(new WalletConnectionReadyNotification($store, $connection));
                    Log::info('Wallet connection ready notification sent', [
                        'connection_id' => $connection->id,
                        'store_id' => $connection->store_id,
                        'merchant_id' => $merchant->id,
                        'merchant_email' => $merchant->email,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send wallet connection ready notification', [
                        'connection_id' => $connection->id,
                        'store_id' => $connection->store_id,
                        'merchant_id' => $merchant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Wallet connection marked as connected', [
            'connection_id' => $connection->id,
            'store_id' => $connection->store_id,
            'marked_by' => $markedBy->id,
            'was_needs_support' => $wasNeedsSupport,
        ]);
    }

    /**
     * Configures CashuMelt as the parallel payout fallback: keeps the plugin enabled
     * with the given Lightning address (existing mint preserved, default mint otherwise)
     * and records the state on the store. Checkout prefers the Lightning method; Cashu
     * stays available when Boltz/Spark are down.
     */
    public function configureCashuFallback(Store $store, string $lightningAddress, string $userApiKey, ?User $user = null): void
    {
        $mintUrl = config('services.cashu.default_mint_url');
        try {
            $existing = $this->cashuService->getSettings($store->btcpay_store_id, $userApiKey);
            if (! empty($existing['mintUrl'])) {
                $mintUrl = $existing['mintUrl'];
            }
        } catch (\Throwable) {
            // No existing settings - the default mint applies.
        }

        $this->cashuService->saveSettings($store->btcpay_store_id, [
            'mintUrl' => $mintUrl,
            'lightningAddress' => $lightningAddress,
            'enabled' => true,
        ], $userApiKey);

        $store->forceFill([
            'cashu_fallback_enabled' => true,
            'cashu_fallback_address' => $lightningAddress,
        ])->save();

        AuditLog::log(
            'store.cashu_fallback_configured',
            'store',
            $store->id,
            [
                'lightning_address' => $lightningAddress,
                'mint_url' => $mintUrl,
            ],
            $user?->id
        );

        Log::info('CashuMelt parallel fallback configured', [
            'store_id' => $store->id,
            'btcpay_store_id' => $store->btcpay_store_id,
            'mint_url' => $mintUrl,
        ]);
    }

    protected function resolveStoreWalletType(string $connectionType): string
    {
        return match ($connectionType) {
            'aqua_descriptor' => 'aqua_boltz',
            'nwc' => 'nwc',
            'blitz' => 'blitz',
            'flash' => 'flash',
            'lnaddress' => 'lnaddress',
            default => 'blink',
        };
    }

    protected function attemptBtcpayWalletSync(
        Store $store,
        WalletConnection $connection,
        string $type,
        string $secret,
        User $user,
        string $userApiKey,
    ): void {
        if ($connection->status !== 'pending') {
            return;
        }

        // Lightning-address style connections share one flow: best-effort clear of
        // the existing BTCPay Lightning config, then connect with the canonical string.
        $lnFlows = [
            'blink' => ['label' => 'Blink', 'format' => fn (string $s): string => $this->validator->formatBtcpayBlinkConnectionString($s)],
            'blitz' => ['label' => 'Blitz', 'format' => fn (string $s): string => $this->validator->formatBtcpayBlitzConnectionString($s)],
            'flash' => ['label' => 'Flash', 'format' => fn (string $s): string => $this->validator->formatBtcpayFlashConnectionString($s)],
            'lnaddress' => ['label' => 'LN address', 'format' => fn (string $s): string => $this->validator->formatBtcpayLnAddressConnectionString($s)],
        ];

        if (isset($lnFlows[$type])) {
            try {
                $this->lightningService->tryRemoveStoreLightningNodeConfiguration(
                    $store->btcpay_store_id,
                    'BTC',
                    $userApiKey
                );
            } catch (\Throwable $e) {
                Log::info("Best-effort clear BTCPay Lightning before {$lnFlows[$type]['label']} connect", [
                    'store_id' => $store->id,
                    'message' => $e->getMessage(),
                ]);
            }

            $btcpayString = $lnFlows[$type]['format']($secret);
            $this->tryConnectLightningAndMarkConnected($store, $connection, $btcpayString, $user, $userApiKey);

            return;
        }

        if ($type === 'nwc') {
            $btcpayString = $this->validator->formatBtcpayNwcConnectionString($secret);
            $this->tryConnectLightningAndMarkConnected($store, $connection, $btcpayString, $user, $userApiKey);
            $this->markConnectedIfBtcpayLightningActive($store, $connection, $user, $userApiKey);

            return;
        }

        if ($type === 'aqua_descriptor') {
            try {
                $walletName = $this->boltzService->buildWalletName($store);
                $boltzResult = $this->boltzService->importDescriptorAndEnableSetup(
                    $store->btcpay_store_id,
                    $walletName,
                    $secret,
                    $userApiKey
                );
                if ($boltzResult['success'] ?? false) {
                    $connection->refresh();
                    if ($connection->status === 'pending') {
                        $this->markConnected($connection, $user);
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Boltz Greenfield import not applied; trying direct Lightning connect', [
                    'store_id' => $store->id,
                    'message' => $e->getMessage(),
                ]);
            }

            if ($connection->fresh()->status === 'pending') {
                $descriptor = $this->validator->stripDescriptorChecksum(trim($secret));
                $this->tryConnectLightningAndMarkConnected($store, $connection, $descriptor, $user, $userApiKey);
            }

            $this->markConnectedIfBtcpayLightningActive($store, $connection->fresh(), $user, $userApiKey);
        }
    }

    protected function tryConnectLightningAndMarkConnected(
        Store $store,
        WalletConnection $connection,
        string $connectionString,
        User $user,
        string $userApiKey,
    ): void {
        try {
            $apiResult = $this->lightningService->connectLightningNode(
                $store->btcpay_store_id,
                'BTC',
                $connectionString,
                $userApiKey
            );
            if ($apiResult['success'] ?? false) {
                $connection->refresh();
                if ($connection->status === 'pending') {
                    $this->markConnected($connection, $user);
                }
            }
        } catch (\Throwable $e) {
            Log::info('Greenfield Lightning connect not applied; config bot may configure', [
                'store_id' => $store->id,
                'connection_type' => $connection->type,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function markConnectedIfBtcpayLightningActive(
        Store $store,
        WalletConnection $connection,
        User $user,
        string $userApiKey,
    ): bool {
        if ($connection->status !== 'pending') {
            return false;
        }

        try {
            $nodeInfo = $this->lightningService->getLightningNodeInfo(
                $store->btcpay_store_id,
                'BTC',
                $userApiKey
            );
            if ($nodeInfo === []) {
                return false;
            }

            $this->markConnected($connection, $user);

            return true;
        } catch (\Throwable $e) {
            Log::info('BTCPay Lightning probe did not mark connection connected', [
                'store_id' => $store->id,
                'connection_id' => $connection->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reconcile local wallet connection status with BTCPay Lightning state.
     */
    public function syncStatusFromBtcpay(Store $store, WalletConnection $connection, User $user): bool
    {
        $owner = $store->user;
        if (! $owner instanceof User) {
            return false;
        }

        $userApiKey = $owner->btcpay_api_key;
        if (! filled($userApiKey)) {
            return false;
        }

        return $this->markConnectedIfBtcpayLightningActive($store, $connection, $user, $userApiKey);
    }
}
