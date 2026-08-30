<?php

namespace Tests\Unit\Services;

use App\Services\BtcPay\BtcPayClient;
use App\Services\BtcPay\StoreService;
use App\Services\BtcPay\UserService;
use App\Services\BtcPay\WebhookService;
use App\Services\GuestBtcPayDecommissioner;
use App\Services\GuestProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_guest_aborts_when_btcpay_returns_no_api_key(): void
    {
        $userService = $this->createMock(UserService::class);
        $userService->method('createUser')->willReturn([
            'id' => 'btcpay-user-1',
            'emailConfirmed' => true,
        ]);
        $userService->expects($this->once())->method('createApiKey')->willReturn(['label' => 'ignored']);

        $storeService = $this->createMock(StoreService::class);
        $storeService->expects($this->never())->method('createStore');

        $webhookService = $this->createMock(WebhookService::class);
        $decommissioner = $this->createMock(GuestBtcPayDecommissioner::class);
        $decommissioner->expects($this->once())->method('decommissionPartial')
            ->with(null, 'btcpay-user-1', null);

        $btcPayClient = $this->createMock(BtcPayClient::class);

        $svc = new GuestProvisioningService(
            $userService,
            $storeService,
            $webhookService,
            $decommissioner,
            $btcPayClient,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('merchant API key');

        $svc->provisionGuest();
    }

    public function test_guest_store_name_is_derived_from_guest_email_token(): void
    {
        $svc = $this->makeServiceForHappyPath();

        $this->assertSame(
            'My Store - 6WPQ3GRT',
            $svc->guestStoreName('guest+01j5x8z2ka9fbn3d4e6wpq3grt@guest.example.com')
        );

        // Different tokens -> different names.
        $this->assertNotSame(
            $svc->guestStoreName($svc->generateGuestEmail()),
            $svc->guestStoreName($svc->generateGuestEmail())
        );

        // Unexpected email shape still yields a unique 8-char suffix.
        $this->assertMatchesRegularExpression('/^My Store - [0-9A-Z]{8}$/', $svc->guestStoreName('foo@bar.test'));
    }

    public function test_provision_guest_names_btcpay_and_local_store_with_unique_suffix(): void
    {
        config(['services.btcpay.api_key' => 'server-key']);

        $guestEmail = 'guest+01j5x8z2ka9fbn3d4e6wpq3grt@guest.example.com';
        $capturedName = null;

        $svc = $this->makeServiceForHappyPath(function (array $data) use (&$capturedName) {
            $capturedName = $data['name'];
        });

        [$user, $store] = $svc->provisionGuest(null, $guestEmail);

        $this->assertSame('My Store - 6WPQ3GRT', $capturedName);
        $this->assertSame('My Store - 6WPQ3GRT', $store->name);
        $this->assertSame($guestEmail, $user->email);
        $this->assertDatabaseHas('stores', ['id' => $store->id, 'name' => 'My Store - 6WPQ3GRT']);
    }

    private function makeServiceForHappyPath(?callable $onCreateStore = null): GuestProvisioningService
    {
        $userService = $this->createMock(UserService::class);
        $userService->method('createUser')->willReturn(['id' => 'btcpay-user-1', 'emailConfirmed' => true]);
        $userService->method('createApiKey')->willReturn(['apiKey' => 'merchant-key']);
        $userService->method('getAdminBtcPayUserId')->willReturn('admin-user');

        $storeService = $this->createMock(StoreService::class);
        $storeService->method('createStore')->willReturnCallback(function (array $data) use ($onCreateStore) {
            if ($onCreateStore) {
                $onCreateStore($data);
            }

            return ['id' => 'btcpay-store-1'];
        });
        $storeService->method('addUserToStore')->willReturn([]);

        $webhookService = $this->createMock(WebhookService::class);
        $webhookService->method('replacePanelWebhookForStore')->willReturn(['id' => 'wh-1', 'secret' => 'sec']);

        return new GuestProvisioningService(
            $userService,
            $storeService,
            $webhookService,
            $this->createMock(GuestBtcPayDecommissioner::class),
            $this->createMock(BtcPayClient::class),
        );
    }
}
