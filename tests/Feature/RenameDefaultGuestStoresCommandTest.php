<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenameDefaultGuestStoresCommandTest extends TestCase
{
    use RefreshDatabase;

    private function guestStore(string $token, string $name = 'My Store', bool $isGuest = true): Store
    {
        $user = User::factory()->create([
            'email' => "guest+{$token}@guest.example.com",
            'is_guest' => $isGuest,
            'btcpay_api_key' => 'merchant-key',
        ]);

        $store = Store::factory()->create(['user_id' => $user->id, 'name' => $name]);
        $store->btcpay_store_id = "btcpay-{$token}";
        $store->save();

        return $store;
    }

    public function test_dry_run_lists_without_changing(): void
    {
        $store = $this->guestStore('01j5x8z2ka9fbn3d4e6wpq3grt');

        $storeService = $this->createMock(StoreService::class);
        $storeService->expects($this->never())->method('updateStore');
        $this->app->instance(StoreService::class, $storeService);

        $this->artisan('guests:rename-default-stores', ['--dry-run' => true])
            ->expectsOutputToContain('My Store - 6WPQ3GRT')
            ->assertSuccessful();

        $this->assertSame('My Store', $store->fresh()->name);
    }

    public function test_renames_legacy_guest_stores_in_btcpay_and_locally(): void
    {
        $legacy = $this->guestStore('01j5x8z2ka9fbn3d4e6wpq3grt');
        $alreadyRenamed = $this->guestStore('01j5x8z2ka9fbn3d4e6wpqaaaa', 'My Store - WPQAAAA1');
        $nonGuest = $this->guestStore('01j5x8z2ka9fbn3d4e6wpqbbbb', 'My Store', false);

        $storeService = $this->createMock(StoreService::class);
        $storeService->expects($this->once())->method('updateStore')
            ->with('btcpay-01j5x8z2ka9fbn3d4e6wpq3grt', ['name' => 'My Store - 6WPQ3GRT'], 'merchant-key')
            ->willReturn([]);
        $this->app->instance(StoreService::class, $storeService);

        $this->artisan('guests:rename-default-stores')->assertSuccessful();

        $this->assertSame('My Store - 6WPQ3GRT', $legacy->fresh()->name);
        $this->assertSame('My Store - WPQAAAA1', $alreadyRenamed->fresh()->name);
        $this->assertSame('My Store', $nonGuest->fresh()->name);
    }

    public function test_btcpay_failure_keeps_local_name_and_reports_failure(): void
    {
        $store = $this->guestStore('01j5x8z2ka9fbn3d4e6wpq3grt');

        $storeService = $this->createMock(StoreService::class);
        $storeService->method('updateStore')->willThrowException(new \RuntimeException('boom'));
        $this->app->instance(StoreService::class, $storeService);

        $this->artisan('guests:rename-default-stores')->assertFailed();

        $this->assertSame('My Store', $store->fresh()->name);
    }
}
