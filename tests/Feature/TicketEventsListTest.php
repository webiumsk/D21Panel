<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketEventsListTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_events_requests_include_inactive_and_returns_disabled_events(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $btcpayStoreId = $store->btcpay_store_id;

        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');

        $includeInactiveSeen = false;
        $includeInactiveSnakeSeen = false;
        Http::fake(function (Request $request) use ($baseUrl, $btcpayStoreId, &$includeInactiveSeen, &$includeInactiveSnakeSeen) {
            $url = (string) $request->url();
            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET'
                && str_contains($url, "/api/v1/stores/{$btcpayStoreId}/satflux-tickets/events")
                && ! str_contains($url, '/satflux-tickets/events/')
            ) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $includeInactiveSeen = isset($q['includeInactive']) && $q['includeInactive'] === 'true';
                $includeInactiveSnakeSeen = isset($q['include_inactive']) && $q['include_inactive'] === 'true';

                return Http::response([
                    [
                        'id' => 'evt-active',
                        'storeId' => $btcpayStoreId,
                        'title' => 'Active show',
                        'eventState' => 'Active',
                        'eventType' => 'Physical',
                        'startDate' => '2030-01-15T12:00:00Z',
                        'hasMaximumCapacity' => false,
                    ],
                    [
                        'id' => 'evt-disabled',
                        'storeId' => $btcpayStoreId,
                        'title' => 'Disabled show',
                        'eventState' => 'Disabled',
                        'eventType' => 'Physical',
                        'startDate' => '2030-02-15T12:00:00Z',
                        'hasMaximumCapacity' => false,
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/tickets/events");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $titles = collect($response->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Active show', 'Disabled show'], $titles);

        $this->assertTrue($includeInactiveSeen, 'BTCPay list events request should include includeInactive=true');
        $this->assertTrue($includeInactiveSnakeSeen, 'BTCPay list events request should include include_inactive=true');
    }
}
