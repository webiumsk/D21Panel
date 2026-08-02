<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketEventRaffleBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_event_forwards_raffle_bundle_fields_to_btcpay(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $raffleId = '11111111-1111-1111-1111-111111111111';

        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $postSeen = false;
        $postedBundleRaffleId = null;
        $postedBundlePerAdmission = null;

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $raffleId, &$postSeen, &$postedBundleRaffleId, &$postedBundlePerAdmission) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/raffle")) {
                return Http::response([
                    [
                        'id' => $raffleId,
                        'name' => 'Open bundle raffle',
                        'status' => 'Open',
                        'ticketsSold' => 0,
                    ],
                ], 200);
            }

            if ($request->method() === 'GET'
                && str_contains($url, "/api/v1/stores/{$btcpayStoreId}/satflux-tickets/events")
                && ! str_contains($url, '/satflux-tickets/events/')
            ) {
                return Http::response([], 200);
            }

            if ($request->method() === 'POST'
                && str_contains($url, "/api/v1/stores/{$btcpayStoreId}/satflux-tickets/events")
            ) {
                $postSeen = true;
                $body = $request->data();
                $postedBundleRaffleId = $body['bundledRaffleId'] ?? null;
                $postedBundlePerAdmission = $body['bundledRaffleTicketsPerAdmission'] ?? null;

                return Http::response([
                    'id' => 'evt-bundle',
                    'storeId' => $btcpayStoreId,
                    'title' => 'Bundle show',
                    'eventState' => 'Active',
                    'eventType' => 'Physical',
                    'startDate' => '2030-06-01T12:00:00Z',
                    'hasMaximumCapacity' => false,
                    'bundledRaffleId' => $raffleId,
                    'bundledRaffleTicketsPerAdmission' => 2,
                ], 201);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/stores/{$store->id}/tickets/events", [
            'title' => 'Bundle show',
            'startDate' => '2030-06-01T12:00:00Z',
            'bundledRaffleId' => $raffleId,
            'bundledRaffleTicketsPerAdmission' => 2,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.bundledRaffleTicketsPerAdmission', 2);
        $this->assertTrue($postSeen, 'Create event should POST bundle fields to BTCPay');
        $this->assertSame($raffleId, $postedBundleRaffleId);
        $this->assertSame(2, $postedBundlePerAdmission);
    }

    public function test_update_event_forwards_explicit_null_bundled_raffle_id(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;
        $eventId = 'evt-clear-bundle';
        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $postedBundleRaffleId = 'unset';

        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, $eventId, &$postedBundleRaffleId) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'PUT'
                && str_ends_with($url, "/api/v1/stores/{$btcpayStoreId}/satflux-tickets/events/{$eventId}")
            ) {
                $body = $request->data();
                $postedBundleRaffleId = array_key_exists('bundledRaffleId', $body)
                    ? $body['bundledRaffleId']
                    : 'missing';

                return Http::response([
                    'id' => $eventId,
                    'title' => 'Cleared bundle',
                    'eventState' => 'Active',
                    'bundledRaffleId' => null,
                    'bundledRaffleTicketsPerAdmission' => 0,
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/stores/{$store->id}/tickets/events/{$eventId}", [
            'title' => 'Cleared bundle',
            'startDate' => '2030-06-01T12:00:00Z',
            'bundledRaffleId' => null,
            'bundledRaffleTicketsPerAdmission' => 0,
        ]);

        $response->assertOk();
        $this->assertNull($postedBundleRaffleId);
    }
}
