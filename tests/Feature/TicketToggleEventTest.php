<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketToggleEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_event_with_ticket_types_and_no_sales_can_be_deactivated(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);

        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $toggleCalled = false;
        $ticketTypesCalled = false;

        Http::fake(function (Request $request) use ($baseUrl, &$toggleCalled, &$ticketTypesCalled) {
            $url = (string) $request->url();

            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_contains($url, '/satflux-tickets/events/evt-1')) {
                return Http::response([
                    'id' => 'evt-1',
                    'title' => 'My event',
                    'eventState' => 'Active',
                    'ticketsSold' => 0,
                ], 200);
            }

            if ($request->method() === 'PUT' && str_contains($url, '/satflux-tickets/events/evt-1/toggle')) {
                $toggleCalled = true;

                return Http::response([
                    'id' => 'evt-1',
                    'title' => 'My event',
                    'eventState' => 'Disabled',
                    'ticketsSold' => 0,
                ], 200);
            }

            if (str_contains($url, '/ticket-types')) {
                $ticketTypesCalled = true;
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/stores/{$store->id}/tickets/events/evt-1/toggle");

        $response->assertOk();
        $response->assertJsonPath('data.eventState', 'Disabled');
        $this->assertTrue($toggleCalled, 'Toggle endpoint should be called when no tickets are sold.');
        $this->assertFalse($ticketTypesCalled, 'Ticket types should not be used to block deactivation.');
    }

    public function test_active_event_with_sold_tickets_cannot_be_deactivated(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-2',
        ]);

        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $toggleCalled = false;

        Http::fake(function (Request $request) use ($baseUrl, &$toggleCalled) {
            $url = (string) $request->url();

            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_contains($url, '/satflux-tickets/events/evt-2')) {
                return Http::response([
                    'id' => 'evt-2',
                    'title' => 'Sold event',
                    'eventState' => 'Active',
                    'ticketsSold' => 3,
                ], 200);
            }

            if ($request->method() === 'PUT' && str_contains($url, '/satflux-tickets/events/evt-2/toggle')) {
                $toggleCalled = true;
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/stores/{$store->id}/tickets/events/evt-2/toggle");

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('messages.tickets_cannot_deactivate_event_with_sold_tickets'));
        $this->assertFalse($toggleCalled, 'Toggle endpoint must not be called when sold tickets exist.');
    }

    public function test_toggle_fails_closed_when_event_precheck_fails(): void
    {
        $user = User::factory()->create(['btcpay_api_key' => 'merchant-key']);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'btcpay_store_id' => 'btcpay-store-3',
        ]);

        $baseUrl = rtrim(config('services.btcpay.base_url', 'http://localhost'), '/');
        $toggleCalled = false;

        Http::fake(function (Request $request) use ($baseUrl, &$toggleCalled) {
            $url = (string) $request->url();

            if (! str_contains($url, $baseUrl)) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET' && str_contains($url, '/satflux-tickets/events/evt-3')) {
                return Http::response(['message' => 'BTCPay unavailable'], 500);
            }

            if ($request->method() === 'PUT' && str_contains($url, '/satflux-tickets/events/evt-3/toggle')) {
                $toggleCalled = true;
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/stores/{$store->id}/tickets/events/evt-3/toggle");

        $response->assertStatus(502);
        $response->assertJsonPath('message', __('messages.tickets_event_toggle_precheck_failed'));
        $this->assertFalse($toggleCalled, 'Toggle endpoint must not be called when precheck fails.');
    }
}
