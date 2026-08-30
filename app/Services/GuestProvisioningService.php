<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\BtcPayClient;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\StoreService;
use App\Services\BtcPay\UserService;
use App\Services\BtcPay\WebhookService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class GuestProvisioningService
{
    public function __construct(
        protected UserService $userService,
        protected StoreService $storeService,
        protected WebhookService $webhookService,
        protected GuestBtcPayDecommissioner $guestBtcPayDecommissioner,
        protected BtcPayClient $btcPayClient,
    ) {}

    public function attachRecoveryKeyToGuest(User $user, string $recoveryPkHex): User
    {
        return $this->attachRecoveryKey($user, $recoveryPkHex);
    }

    public function attachRecoveryKey(User $user, string $recoveryPkHex): User
    {
        if (! Schema::hasColumn('users', 'guest_recovery_public_key')
            || ! empty($user->guest_recovery_public_key)) {
            return $user;
        }

        if (User::where('guest_recovery_public_key', $recoveryPkHex)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'recovery_public_key' => ['This recovery key is already in use.'],
            ]);
        }

        $user->update([
            'guest_recovery_public_key' => $recoveryPkHex,
            'guest_recovery_enrolled_at' => now(),
        ]);

        return $user->fresh();
    }

    public function resolvePrimaryStoreId(User $user): ?string
    {
        return Store::where('user_id', $user->id)->value('id');
    }

    public function generateGuestEmail(): string
    {
        $guestToken = strtolower((string) Str::ulid());
        $guestEmailDomain = config('services.auth.guest_email_domain');
        $guestEmailDomain = is_string($guestEmailDomain) ? trim($guestEmailDomain) : '';
        if ($guestEmailDomain === '') {
            throw new InvalidArgumentException(
                'Guest email domain is not configured (services.auth.guest_email_domain is empty). Set GUEST_EMAIL_DOMAIN or APP_URL so a valid synthetic domain is available.'
            );
        }

        return "guest+{$guestToken}@{$guestEmailDomain}";
    }

    /**
     * Guest stores all used to be called "My Store", which made the BTCPay admin
     * store list impossible to navigate. Suffix the name with the random tail of
     * the guest email token (guest+<ulid>@...) so admins can match store <-> owner.
     */
    public function guestStoreName(string $guestEmail): string
    {
        $localPart = Str::before($guestEmail, '@');
        $token = preg_replace('/[^0-9a-z]/i', '', Str::after($localPart, 'guest+')) ?? '';
        $token = strtoupper(substr($token, -8));
        if (strlen($token) < 8) {
            $token = strtoupper(Str::random(8));
        }

        return "My Store - {$token}";
    }

    /**
     * @return array{0: User, 1: Store}
     */
    public function provisionGuest(?string $recoveryPkHex = null, ?string $guestEmail = null): array
    {
        $guestEmail ??= $this->generateGuestEmail();
        $guestPassword = Str::random(48);
        $defaultStoreName = $this->guestStoreName($guestEmail);

        $btcpayUserId = null;
        $btcpayStoreId = null;
        $createdPerUserApiKey = null;
        $webhookData = null;

        try {
            // Kept (encrypted) on the local user: current BTCPay requires it
            // as currentPassword when changing the account email via
            // PUT /api/v1/users/me (guest -> real email sync).
            $btcpayPassword = Str::random(32);

            $btcpayUser = $this->userService->createUser([
                'email' => $guestEmail,
                'password' => $btcpayPassword,
                'isAdministrator' => false,
                'sendInvitationEmail' => false,
            ]);
            $btcpayUserId = $btcpayUser['id'] ?? $btcpayUser['userId'] ?? null;
            if (! $btcpayUserId) {
                throw new \RuntimeException('BTCPay user ID missing after guest user creation.');
            }

            // Some BTCPay policies require a confirmed email before user API keys may call /stores.
            // The create-user response includes invitationUrl; accept it with the server API key.
            $this->ensureBtcPayGuestUserCanAuthenticate($btcpayUserId, $btcpayUser);

            $apiKeyData = $this->userService->createApiKey(
                $btcpayUserId,
                [],
                [],
                'satflux.io Guest API Key - '.$guestEmail
            );
            $createdPerUserApiKey = $apiKeyData['apiKey'] ?? null;
            if (! is_string($createdPerUserApiKey) || $createdPerUserApiKey === '') {
                throw new \RuntimeException(
                    'BTCPay did not return a merchant API key for the new guest user; guest provisioning cannot continue without it.'
                );
            }

            $btcpayApiKey = $createdPerUserApiKey;

            // Same as StoreController::store: server BTCPAY_API_KEY on the client, then POST /stores with null
            // (server key). Merchant key is only for the Laravel user + webhooks, not for store creation.
            $serverApiKey = (string) config('services.btcpay.api_key', env('BTCPAY_API_KEY') ?? '');
            if ($serverApiKey === '') {
                throw new \RuntimeException(
                    'Server-level BTCPay API key (BTCPAY_API_KEY) is not configured; guest store cannot be provisioned.'
                );
            }
            $this->btcPayClient->setApiKey($serverApiKey);

            $btcpayStore = $this->storeService->createStore([
                'name' => $defaultStoreName,
                'defaultCurrency' => 'EUR',
                'timeZone' => 'Europe/Vienna',
                'anyoneCanCreateInvoice' => false,
                'showRecommendedFee' => true,
                'recommendedFeeBlockTarget' => 1,
                'preferredExchange' => 'kraken',
            ], null);
            $btcpayStoreId = $btcpayStore['id'] ?? $btcpayStore['storeId'] ?? null;
            if (! $btcpayStoreId) {
                throw new \RuntimeException('BTCPay store ID missing after guest store creation.');
            }

            Cache::forget("btcpay:store:{$btcpayStoreId}:server");
            if ($btcpayApiKey) {
                Cache::forget("btcpay:store:{$btcpayStoreId}:".md5($btcpayApiKey));
            }

            $this->storeService->addUserToStore($btcpayStoreId, $btcpayUserId, 'Owner');

            $this->attachBtcpayServerKeyUserToGuestStore((string) $btcpayStoreId);

            try {
                $webhookData = $this->webhookService->replacePanelWebhookForStore($btcpayStoreId, $btcpayApiKey);
            } catch (\Throwable $e) {
                Log::warning('Guest store webhook provisioning failed', [
                    'btcpay_store_id' => $btcpayStoreId,
                    'error' => $e->getMessage(),
                ]);
            }

            [$user, $store] = DB::transaction(function () use (
                $guestEmail,
                $guestPassword,
                $btcpayPassword,
                $recoveryPkHex,
                $btcpayUserId,
                $btcpayStoreId,
                $defaultStoreName,
                $createdPerUserApiKey,
                $webhookData
            ) {
                $userData = [
                    'name' => 'Guest',
                    'email' => $guestEmail,
                    'password' => Hash::make($guestPassword),
                    'role' => 'free',
                    'email_verified_at' => now(),
                    'btcpay_user_id' => $btcpayUserId,
                ];

                if (Schema::hasColumn('users', 'is_guest')) {
                    $userData['is_guest'] = true;
                }

                $userData['btcpay_api_key'] = $createdPerUserApiKey;

                if (Schema::hasColumn('users', 'btcpay_password')) {
                    $userData['btcpay_password'] = $btcpayPassword;
                }

                if ($recoveryPkHex && Schema::hasColumn('users', 'guest_recovery_public_key')) {
                    if (User::where('guest_recovery_public_key', $recoveryPkHex)->exists()) {
                        throw ValidationException::withMessages([
                            'recovery_public_key' => ['This recovery key is already in use.'],
                        ]);
                    }
                    $userData['guest_recovery_public_key'] = $recoveryPkHex;
                    $userData['guest_recovery_enrolled_at'] = now();
                }

                // forceCreate: 'role' is not mass assignable (trusted internal flow)
                $user = User::forceCreate($userData);

                $store = new Store([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'name' => $defaultStoreName,
                    'default_currency' => 'EUR',
                    'timezone' => 'Europe/Vienna',
                    'preferred_exchange' => 'kraken',
                    'wallet_type' => null,
                    'btcpay_webhook_id' => $webhookData['id'] ?? null,
                    'webhook_secret' => $webhookData['secret'] ?? null,
                ]);
                $store->btcpay_store_id = $btcpayStoreId;
                $store->save();

                StoreChecklistService::ensureChecklistInitialized($store);

                return [$user->fresh(), $store->fresh()];
            });

            return [$user, $store];
        } catch (ValidationException $e) {
            $this->safeCleanupBtcPayResources($btcpayStoreId, $btcpayUserId, $createdPerUserApiKey, $e);
            throw $e;
        } catch (\Throwable $e) {
            $this->safeCleanupBtcPayResources($btcpayStoreId, $btcpayUserId, $createdPerUserApiKey, $e);
            throw $e;
        }
    }

    private function safeCleanupBtcPayResources(?string $btcpayStoreId, ?string $btcpayUserId, ?string $createdPerUserApiKey, \Throwable $original): void
    {
        try {
            $this->cleanupBtcPayResources($btcpayStoreId, $btcpayUserId, $createdPerUserApiKey);
        } catch (\Throwable $cleanupError) {
            Log::error('Guest provisioning: BTCPay cleanup failed after provisioning error', [
                'original_exception' => $original::class,
                'original_message' => $original->getMessage(),
                'cleanup_exception' => $cleanupError::class,
                'cleanup_message' => $cleanupError->getMessage(),
                'btcpay_store_id' => $btcpayStoreId,
                'btcpay_user_id' => $btcpayUserId,
            ]);
        }
    }

    private function cleanupBtcPayResources(?string $btcpayStoreId, ?string $btcpayUserId, ?string $createdPerUserApiKey): void
    {
        $this->guestBtcPayDecommissioner->decommissionPartial(
            $btcpayStoreId,
            $btcpayUserId,
            $createdPerUserApiKey,
        );
    }

    /**
     * When BTCPay enforces email confirmation, merchant API keys cannot authenticate until the
     * invitation is accepted or email is confirmed (server key can still manage users).
     */
    private function ensureBtcPayGuestUserCanAuthenticate(string $btcpayUserId, array $btcpayUser): void
    {
        if (! empty($btcpayUser['emailConfirmed'])) {
            return;
        }

        $invitationUrl = $btcpayUser['invitationUrl'] ?? null;
        if (is_string($invitationUrl) && $invitationUrl !== '') {
            if ($this->userService->acceptInvitation($invitationUrl)) {
                Log::info('Guest BTCPay user: invitation accepted so API key auth can proceed', [
                    'btcpay_user_id' => $btcpayUserId,
                ]);

                return;
            }
            Log::warning('Guest BTCPay user: invitation URL present but acceptInvitation failed; trying confirmUserEmail', [
                'btcpay_user_id' => $btcpayUserId,
            ]);
        }

        try {
            $this->userService->confirmUserEmail($btcpayUserId);
            Log::info('Guest BTCPay user: email confirmed via admin API', [
                'btcpay_user_id' => $btcpayUserId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Guest BTCPay user: could not confirm email or accept invitation', [
                'btcpay_user_id' => $btcpayUserId,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                'BTCPay requires email confirmation for new users; Satflux could not complete this automatically. Check server API key permissions and BTCPay policies.',
                0,
                $e
            );
        }
    }

    /**
     * Same as StoreController after store create: add the BTCPay user tied to the server Greenfield key (BTCPAY_API_KEY) as Owner.
     */
    private function attachBtcpayServerKeyUserToGuestStore(string $btcpayStoreId): void
    {
        try {
            $adminBtcPayUserId = $this->userService->getAdminBtcPayUserId();
            if (! $adminBtcPayUserId) {
                Log::error('Guest store: could not determine server API key BTCPay user ID', [
                    'btcpay_store_id' => $btcpayStoreId,
                ]);

                return;
            }

            $this->storeService->addUserToStore($btcpayStoreId, $adminBtcPayUserId, 'Owner');
            Log::info('Assigned server API key user to guest store as Owner', [
                'btcpay_store_id' => $btcpayStoreId,
                'admin_btcpay_user_id' => $adminBtcPayUserId,
            ]);
        } catch (BtcPayException $e) {
            Log::error('Failed to assign server API key user to guest store', [
                'btcpay_store_id' => $btcpayStoreId,
                'error' => $e->getMessage(),
                'error_type' => $e::class,
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error when assigning server API key user to guest store', [
                'btcpay_store_id' => $btcpayStoreId,
                'error' => $e->getMessage(),
                'error_type' => $e::class,
            ]);
        }
    }
}
