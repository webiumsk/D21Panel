<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\BtcPay\BtcPayFileResolver;
use App\Services\BtcPay\TicketService;
use App\Services\Tickets\EventBundleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function __construct(
        protected TicketService $ticketService,
        protected BtcPayFileResolver $btcPayFileResolver,
        protected EventBundleService $eventBundleService,
    ) {}

    // ──────────────────────────────────────────────────
    //  EVENTS
    // ──────────────────────────────────────────────────

    /**
     * List all events for a store.
     */
    public function listEvents(Request $request, Store $store)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();
        $expired = $request->boolean('expired', false);
        // Store admin must see disabled events; BTCPay plugin may filter them out unless includeInactive is sent.
        $includeInactive = $request->boolean('includeInactive', true);

        $events = $this->ticketService->listEvents(
            $store->btcpay_store_id,
            $userApiKey,
            $expired,
            $includeInactive
        );

        $events = array_map([$this, 'normalizeEventLogo'], $events);

        return response()->json(['data' => $events]);
    }

    /**
     * Get a single event.
     */
    public function getEvent(Store $store, string $eventId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $event = $this->ticketService->getEvent(
            $store->btcpay_store_id,
            $eventId,
            $userApiKey
        );

        return response()->json(['data' => $this->normalizeEventLogo($event)]);
    }

    /**
     * Create a new event.
     * Free plan: max 1 event per store. PRO / admin / support: unlimited.
     */
    public function createEvent(Request $request, Store $store)
    {
        $user = $store->user;
        $maxEvents = $user->getMaxEventsPerStore();
        if ($maxEvents !== null) {
            $userApiKey = $user->getBtcPayApiKeyOrFail();
            $existing = $this->ticketService->listEvents($store->btcpay_store_id, $userApiKey, false, true);
            $count = is_array($existing) ? count($existing) : 0;
            if ($count >= $maxEvents) {
                return response()->json([
                    'message' => __('messages.tickets_event_limit_free', ['max' => $maxEvents]),
                ], 403);
            }
        }

        $request->validate(array_merge($this->baseEventValidationRules($request), $this->eventBundleService->validationRules()));

        if ($response = $this->eventBundleService->validateForStore($request, $store)) {
            return $response;
        }

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        // Build request data - include eventLogoFileId (plugin 1.5), optional eventLogoUrl, and enable (default true = Active)
        $data = $this->eventBundleService->filterPayload($request->only([
            'title',
            'description',
            'eventType',
            'location',
            'startDate',
            'endDate',
            'currency',
            'redirectUrl',
            'emailSubject',
            'emailBody',
            'hasMaximumCapacity',
            'maximumEventCapacity',
            'eventLogoUrl',
            'eventLogoFileId',
            'enable',
            'bundledRaffleId',
            'bundledRaffleTicketsPerAdmission',
        ]));
        // Default enable to true so new events are Active and visible in the list immediately
        if (! array_key_exists('enable', $data)) {
            $data['enable'] = true;
        }
        if ($request->has('eventLogoFileId')) {
            $data['eventLogoFileId'] = $request->input('eventLogoFileId');
        }
        $this->ensureEventLogoUrl($data);

        $event = $this->ticketService->createEvent(
            $store->btcpay_store_id,
            $data,
            $userApiKey
        );

        return response()->json(['data' => $this->normalizeEventLogo($event)], 201);
    }

    /**
     * Update an event.
     */
    public function updateEvent(Request $request, Store $store, string $eventId)
    {
        $request->validate(array_merge($this->baseEventValidationRules($request, forUpdate: true), $this->eventBundleService->validationRules()));

        if ($response = $this->eventBundleService->validateForStore($request, $store)) {
            return $response;
        }

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $data = $this->eventBundleService->filterPayload($request->only([
            'title',
            'description',
            'eventType',
            'location',
            'startDate',
            'endDate',
            'currency',
            'redirectUrl',
            'emailSubject',
            'emailBody',
            'hasMaximumCapacity',
            'maximumEventCapacity',
            'eventLogoUrl',
            'eventLogoFileId',
            'bundledRaffleId',
            'bundledRaffleTicketsPerAdmission',
        ]));
        if ($request->has('eventLogoFileId')) {
            $data['eventLogoFileId'] = $request->input('eventLogoFileId');
        }
        $this->ensureEventLogoUrl($data);

        $event = $this->ticketService->updateEvent(
            $store->btcpay_store_id,
            $eventId,
            $data,
            $userApiKey
        );

        return response()->json(['data' => $this->normalizeEventLogo($event)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseEventValidationRules(Request $request, bool $forUpdate = false): array
    {
        $rules = [
            'description' => ['sometimes', 'nullable', 'string'],
            'eventType' => ['sometimes', 'string', 'in:Physical,Virtual'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'endDate' => ['sometimes', 'nullable', 'string'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'redirectUrl' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'emailSubject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emailBody' => ['sometimes', 'nullable', 'string'],
            'hasMaximumCapacity' => ['sometimes', 'boolean'],
            'maximumEventCapacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'eventLogoUrl' => ['sometimes', 'nullable', 'string', 'max:500', Rule::when($request->filled('eventLogoUrl'), ['url'])],
            'eventLogoFileId' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        if ($forUpdate) {
            $rules['title'] = ['sometimes', 'required', 'string', 'max:255'];
            $rules['startDate'] = ['sometimes', 'required', 'string'];
        } else {
            $rules['title'] = ['required', 'string', 'max:255'];
            $rules['startDate'] = ['required', 'string'];
            $rules['enable'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * When payload has eventLogoFileId but no eventLogoUrl, resolve URL from BTCPay Files API (GET file)
     * so the plugin receives a display URL (plugin may not persist eventLogoFileId).
     */
    protected function ensureEventLogoUrl(array &$data): void
    {
        $fileId = trim((string) ($data['eventLogoFileId'] ?? ''));
        if ($fileId === '') {
            return;
        }
        if (trim((string) ($data['eventLogoUrl'] ?? '')) !== '') {
            return;
        }
        $url = $this->btcPayFileResolver->resolveEventLogoUrl($fileId);
        if ($url !== null) {
            $data['eventLogoUrl'] = $url;
            $data['logoUrl'] = $url;
        }
    }

    /**
     * Normalize event response: eventLogoUrl (and logoUrl) and eventLogoFileId for frontend.
     * If plugin returns only eventLogoFileId, build eventLogoUrl from BTCPay base URL so image displays.
     */
    protected function normalizeEventLogo(array $event): array
    {
        if (! isset($event['eventLogoUrl']) && ! empty($event['logoUrl'])) {
            $event['eventLogoUrl'] = $event['logoUrl'];
        }
        $event['eventLogoFileId'] = $event['eventLogoFileId'] ?? '';
        $fileId = trim((string) ($event['eventLogoFileId'] ?? ''));
        if (($event['eventLogoUrl'] ?? '') === '' && $fileId !== '') {
            $baseUrl = rtrim(config('services.btcpay.base_url', ''), '/');
            $event['eventLogoUrl'] = $baseUrl.'/LocalStorage/'.$fileId;
        }

        return $event;
    }

    /**
     * Delete an event. Cannot delete if the event has any sold tickets.
     */
    public function deleteEvent(Store $store, string $eventId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $event = $this->ticketService->getEvent($store->btcpay_store_id, $eventId, $userApiKey);
        $ticketsSold = (int) ($event['ticketsSold'] ?? 0);
        if ($ticketsSold > 0) {
            return response()->json(['message' => __('messages.tickets_cannot_delete_event_with_sold_tickets')], 422);
        }

        $this->ticketService->deleteEvent(
            $store->btcpay_store_id,
            $eventId,
            $userApiKey
        );

        return response()->json(['message' => 'Event deleted successfully']);
    }

    /**
     * Toggle event status (Active/Disabled).
     * Events with sold tickets cannot be deactivated.
     */
    public function toggleEvent(Store $store, string $eventId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        try {
            $current = $this->ticketService->getEvent(
                $store->btcpay_store_id,
                $eventId,
                $userApiKey
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch ticket event before toggle', [
                'store_id' => $store->id,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('messages.tickets_event_toggle_precheck_failed'),
            ], 502);
        }

        // If the event is currently active, we're attempting to deactivate it.
        // Allow deactivation with existing ticket types as long as nothing was sold yet
        // (aligns with BTCPay Satflux Tickets plugin behavior).
        if (($current['eventState'] ?? '') === 'Active') {
            $ticketsSold = (int) ($current['ticketsSold'] ?? 0);
            if ($ticketsSold > 0) {
                return response()->json([
                    'message' => __('messages.tickets_cannot_deactivate_event_with_sold_tickets'),
                ], 422);
            }
        }

        $event = $this->ticketService->toggleEvent(
            $store->btcpay_store_id,
            $eventId,
            $userApiKey
        );

        return response()->json(['data' => $event]);
    }

    // ──────────────────────────────────────────────────
    //  TICKET TYPES
    // ──────────────────────────────────────────────────

    /**
     * List ticket types for an event.
     */
    public function listTicketTypes(Request $request, Store $store, string $eventId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $ticketTypes = $this->ticketService->listTicketTypes(
            $store->btcpay_store_id,
            $eventId,
            $userApiKey,
            $request->input('sortBy', 'Name'),
            $request->input('sortDir', 'asc')
        );

        return response()->json(['data' => $ticketTypes]);
    }

    /**
     * Create a ticket type.
     * Unlimited capacity is at event level (hasMaximumCapacity = false). When event has no max capacity
     * and quantity is omitted or < 1, we send a high quantity so QuantityAvailable stays correct.
     */
    public function createTicketType(Request $request, Store $store, string $eventId)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'description' => ['sometimes', 'nullable', 'string'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'isDefault' => ['sometimes', 'boolean'],
        ]);

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $event = $this->ticketService->getEvent($store->btcpay_store_id, $eventId, $userApiKey);
        $hasMaxCapacity = $event['hasMaximumCapacity'] ?? false;

        $data = array_filter($request->only([
            'name',
            'price',
            'description',
            'quantity',
        ]), fn ($v) => $v !== null && $v !== '');
        $data['isDefault'] = $request->boolean('isDefault');
        $q = array_key_exists('quantity', $data) ? (int) $data['quantity'] : null;
        if ($hasMaxCapacity && ($q === null || $q < 1)) {
            return response()->json(['message' => __('messages.tickets_quantity_required_when_capacity')], 422);
        }
        if (! array_key_exists('quantity', $data) || $q < 1) {
            $data['quantity'] = TicketService::UNLIMITED_QUANTITY;
        }

        if ($data['isDefault']) {
            $existing = $this->ticketService->listTicketTypes($store->btcpay_store_id, $eventId, $userApiKey);
            foreach ($existing as $tt) {
                $this->ticketService->updateTicketType($store->btcpay_store_id, $eventId, $tt['id'], ['isDefault' => false], $userApiKey);
            }
        }

        $ticketType = $this->ticketService->createTicketType(
            $store->btcpay_store_id,
            $eventId,
            $data,
            $userApiKey
        );

        return response()->json(['data' => $ticketType], 201);
    }

    /**
     * Update a ticket type.
     * Plugin requires full payload (name, price, quantity, description, isDefault) on every update.
     * Unlimited capacity is at event level; when event has no max capacity and quantity omitted or < 1,
     * we send UNLIMITED_QUANTITY so QuantityAvailable stays correct.
     */
    public function updateTicketType(Request $request, Store $store, string $eventId, string $ticketTypeId)
    {
        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'description' => ['sometimes', 'nullable', 'string'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'isDefault' => ['sometimes', 'boolean'],
        ]);

        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $event = $this->ticketService->getEvent($store->btcpay_store_id, $eventId, $userApiKey);
        $hasMaxCapacity = $event['hasMaximumCapacity'] ?? false;
        $current = $this->ticketService->getTicketType($store->btcpay_store_id, $eventId, $ticketTypeId, $userApiKey);

        $data = [
            'name' => $request->filled('name') ? $request->string('name')->toString() : ($current['name'] ?? ''),
            'price' => $request->filled('price') ? (float) $request->input('price') : (float) ($current['price'] ?? 0),
            'description' => $request->has('description') ? (string) $request->input('description') : ($current['description'] ?? ''),
            'isDefault' => $request->boolean('isDefault'),
        ];

        $q = $request->has('quantity') ? (int) $request->input('quantity') : (int) ($current['quantity'] ?? 0);
        if ($hasMaxCapacity && $q < 1) {
            return response()->json(['message' => __('messages.tickets_quantity_required_when_capacity')], 422);
        }
        $data['quantity'] = $q >= 1 ? $q : TicketService::UNLIMITED_QUANTITY;

        if ($data['isDefault']) {
            $existing = $this->ticketService->listTicketTypes($store->btcpay_store_id, $eventId, $userApiKey);
            foreach ($existing as $tt) {
                if (($tt['id'] ?? '') !== $ticketTypeId) {
                    $this->ticketService->updateTicketType($store->btcpay_store_id, $eventId, $tt['id'], [
                        'name' => $tt['name'] ?? '',
                        'price' => (float) ($tt['price'] ?? 0),
                        'quantity' => (int) ($tt['quantity'] ?? TicketService::UNLIMITED_QUANTITY),
                        'description' => $tt['description'] ?? '',
                        'isDefault' => false,
                    ], $userApiKey);
                }
            }
        }

        $ticketType = $this->ticketService->updateTicketType(
            $store->btcpay_store_id,
            $eventId,
            $ticketTypeId,
            $data,
            $userApiKey
        );

        return response()->json(['data' => $ticketType]);
    }

    /**
     * Delete a ticket type.
     */
    public function deleteTicketType(Store $store, string $eventId, string $ticketTypeId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $this->ticketService->deleteTicketType(
            $store->btcpay_store_id,
            $eventId,
            $ticketTypeId,
            $userApiKey
        );

        return response()->json(['message' => 'Ticket type deleted successfully']);
    }

    /**
     * Toggle ticket type status (Active/Disabled).
     */
    public function toggleTicketType(Store $store, string $eventId, string $ticketTypeId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $ticketType = $this->ticketService->toggleTicketType(
            $store->btcpay_store_id,
            $eventId,
            $ticketTypeId,
            $userApiKey
        );

        return response()->json(['data' => $ticketType]);
    }

    // ──────────────────────────────────────────────────
    //  TICKETS
    // ──────────────────────────────────────────────────

    /**
     * List settled tickets for an event.
     */
    public function listTickets(Request $request, Store $store, string $eventId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $tickets = $this->ticketService->listTickets(
            $store->btcpay_store_id,
            $eventId,
            $userApiKey,
            $request->input('searchText')
        );

        return response()->json(['data' => $tickets]);
    }

    /**
     * Check-in a ticket.
     */
    public function checkInTicket(Store $store, string $eventId, string $ticketNumber)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $result = $this->ticketService->checkInTicket(
            $store->btcpay_store_id,
            $eventId,
            $ticketNumber,
            $userApiKey
        );

        return response()->json(['data' => $result]);
    }

    // ──────────────────────────────────────────────────
    //  ORDERS
    // ──────────────────────────────────────────────────

    /**
     * List settled orders for an event.
     */
    public function listOrders(Request $request, Store $store, string $eventId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $orders = $this->ticketService->listOrders(
            $store->btcpay_store_id,
            $eventId,
            $userApiKey,
            $request->input('searchText')
        );

        return response()->json(['data' => $orders]);
    }

    /**
     * Send ticket reminder email.
     */
    public function sendReminder(Store $store, string $eventId, string $orderId, string $ticketId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $result = $this->ticketService->sendReminder(
            $store->btcpay_store_id,
            $eventId,
            $orderId,
            $ticketId,
            $userApiKey
        );

        return response()->json(['data' => $result]);
    }

    // ──────────────────────────────────────────────────
    //  PUBLIC CHECK-IN (no auth required, uses store owner's API key)
    // ──────────────────────────────────────────────────

    /**
     * Get minimal event info for the public check-in page.
     * Only returns non-sensitive data (title, dates, type, location, capacity).
     */
    public function publicEventInfo(Store $store, string $eventId)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $event = $this->ticketService->getEvent(
            $store->btcpay_store_id,
            $eventId,
            $userApiKey
        );

        // Return only non-sensitive fields
        return response()->json([
            'data' => [
                'id' => $event['id'] ?? $eventId,
                'title' => $event['title'] ?? '',
                'eventType' => $event['eventType'] ?? 'Physical',
                'location' => $event['location'] ?? null,
                'startDate' => $event['startDate'] ?? null,
                'endDate' => $event['endDate'] ?? null,
                'eventState' => $event['eventState'] ?? 'Disabled',
                'hasMaximumCapacity' => $event['hasMaximumCapacity'] ?? false,
                'maximumEventCapacity' => $event['maximumEventCapacity'] ?? null,
                'ticketsSold' => $event['ticketsSold'] ?? 0,
            ],
        ]);
    }

    /**
     * Public check-in endpoint. No auth required.
     */
    public function publicCheckIn(Store $store, string $eventId, string $ticketNumber)
    {
        $userApiKey = $store->user->getBtcPayApiKeyOrFail();

        $result = $this->ticketService->checkInTicket(
            $store->btcpay_store_id,
            $eventId,
            $ticketNumber,
            $userApiKey
        );

        return response()->json(['data' => $result]);
    }
}
