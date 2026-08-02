<?php

namespace App\Services\BtcPay;

use Illuminate\Support\Facades\Log;

class TicketService
{
    /** Sentinel quantity for "unlimited" when event has no max capacity (plugin has no ticket-type unlimited flag). */
    public const UNLIMITED_QUANTITY = 999999;

    protected BtcPayClient $client;

    public function __construct(BtcPayClient $client)
    {
        $this->client = $client;
    }

    // ──────────────────────────────────────────────────
    //  EVENTS
    // ──────────────────────────────────────────────────

    /**
     * List all events for a store.
     * Pass includeInactive=true when the caller needs disabled/inactive events (admin UI, accurate per-store counts).
     * Patched/custom BTCPay Satoshi Tickets builds may omit inactive events unless those query params are sent.
     */
    public function listEvents(string $storeId, ?string $userApiKey = null, bool $expired = false, bool $includeInactive = false): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $expired, $includeInactive) {
            $query = [];
            if ($expired) {
                $query['expired'] = 'true';
            }
            if ($includeInactive) {
                $query['includeInactive'] = 'true';
                // Some plugin forks bind snake_case query keys only.
                $query['include_inactive'] = 'true';
            }

            return $this->client->get("/api/v1/stores/{$storeId}/satflux-tickets/events", $query);
        });
    }

    /**
     * Get a single event.
     */
    public function getEvent(string $storeId, string $eventId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId) {
            return $this->client->get("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}");
        });
    }

    /**
     * Create a new event.
     * Sends both eventLogoUrl and logoUrl so the plugin accepts the image either way.
     */
    public function createEvent(string $storeId, array $data, ?string $userApiKey = null): array
    {
        $payload = $this->normalizeEventPayloadForBtcPay($data);
        Log::debug('TicketService createEvent payload', ['store_id' => $storeId, 'payload_keys' => array_keys($payload), 'eventLogoFileId' => $payload['eventLogoFileId'] ?? null]);
        $result = $this->withApiKey($userApiKey, function () use ($storeId, $payload) {
            return $this->client->post("/api/v1/stores/{$storeId}/satflux-tickets/events", $payload);
        });
        Log::debug('TicketService createEvent response', ['event_id' => $result['id'] ?? null, 'eventLogoUrl' => $result['eventLogoUrl'] ?? null, 'eventLogoFileId' => $result['eventLogoFileId'] ?? null]);

        return $result;
    }

    /**
     * Update an event.
     * Sends both eventLogoUrl and logoUrl so the plugin accepts the image either way.
     */
    public function updateEvent(string $storeId, string $eventId, array $data, ?string $userApiKey = null): array
    {
        $payload = $this->normalizeEventPayloadForBtcPay($data);
        Log::debug('TicketService updateEvent payload', ['store_id' => $storeId, 'event_id' => $eventId, 'eventLogoFileId' => $payload['eventLogoFileId'] ?? null, 'eventLogoUrl' => $payload['eventLogoUrl'] ?? null]);
        $result = $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $payload) {
            return $this->client->put("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}", $payload);
        });
        Log::debug('TicketService updateEvent response', ['eventLogoUrl' => $result['eventLogoUrl'] ?? null, 'eventLogoFileId' => $result['eventLogoFileId'] ?? null]);

        return $result;
    }

    /**
     * Delete an event.
     */
    public function deleteEvent(string $storeId, string $eventId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId) {
            return $this->client->delete("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}");
        });
    }

    /**
     * Toggle event status (Active/Disabled).
     */
    public function toggleEvent(string $storeId, string $eventId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId) {
            return $this->client->put("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/toggle", []);
        });
    }

    /**
     * Upload event logo via plugin endpoint (store-level permission only).
     * POST /api/v1/stores/{storeId}/satflux-tickets/events/{eventId}/logo
     * Returns updated EventData including eventLogoUrl and eventLogoFileId.
     */
    public function uploadEventLogo(string $storeId, string $eventId, $file, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $file) {
            return $this->client->postMultipart(
                "/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/logo",
                $file
            );
        });
    }

    /**
     * Delete event logo via plugin endpoint.
     * DELETE /api/v1/stores/{storeId}/satflux-tickets/events/{eventId}/logo
     * Returns updated EventData.
     */
    public function deleteEventLogo(string $storeId, string $eventId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId) {
            return $this->client->delete(
                "/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/logo"
            );
        });
    }

    // ──────────────────────────────────────────────────
    //  TICKET TYPES
    // ──────────────────────────────────────────────────

    /**
     * List ticket types for an event.
     */
    public function listTicketTypes(string $storeId, string $eventId, ?string $userApiKey = null, string $sortBy = 'Name', string $sortDir = 'asc'): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $sortBy, $sortDir) {
            return $this->client->get(
                "/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/ticket-types",
                ['sortBy' => $sortBy, 'sortDir' => $sortDir]
            );
        });
    }

    /**
     * Get a single ticket type.
     */
    public function getTicketType(string $storeId, string $eventId, string $ticketTypeId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $ticketTypeId) {
            return $this->client->get("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/ticket-types/{$ticketTypeId}");
        });
    }

    /**
     * Create a ticket type.
     */
    public function createTicketType(string $storeId, string $eventId, array $data, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $data) {
            return $this->client->post("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/ticket-types", $data);
        });
    }

    /**
     * Update a ticket type.
     */
    public function updateTicketType(string $storeId, string $eventId, string $ticketTypeId, array $data, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $ticketTypeId, $data) {
            return $this->client->put("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/ticket-types/{$ticketTypeId}", $data);
        });
    }

    /**
     * Delete a ticket type.
     */
    public function deleteTicketType(string $storeId, string $eventId, string $ticketTypeId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $ticketTypeId) {
            return $this->client->delete(
                "/api/v1/stores/{$storeId}/satflux-tickets/ticket-types/{$ticketTypeId}",
                ['eventId' => $eventId]
            );
        });
    }

    /**
     * Toggle ticket type status (Active/Disabled).
     */
    public function toggleTicketType(string $storeId, string $eventId, string $ticketTypeId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $ticketTypeId) {
            return $this->client->put(
                "/api/v1/stores/{$storeId}/satflux-tickets/ticket-types/{$ticketTypeId}/toggle",
                [],
                ['eventId' => $eventId]
            );
        });
    }

    // ──────────────────────────────────────────────────
    //  TICKETS
    // ──────────────────────────────────────────────────

    /**
     * List settled tickets for an event.
     */
    public function listTickets(string $storeId, string $eventId, ?string $userApiKey = null, ?string $searchText = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $searchText) {
            $query = $searchText ? ['searchText' => $searchText] : [];

            return $this->client->get("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/tickets", $query);
        });
    }

    /**
     * Check-in a ticket.
     */
    public function checkInTicket(string $storeId, string $eventId, string $ticketNumber, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $ticketNumber) {
            return $this->client->post("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/tickets/{$ticketNumber}/check-in", []);
        });
    }

    // ──────────────────────────────────────────────────
    //  ORDERS
    // ──────────────────────────────────────────────────

    /**
     * List settled orders for an event.
     */
    public function listOrders(string $storeId, string $eventId, ?string $userApiKey = null, ?string $searchText = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $searchText) {
            $query = $searchText ? ['searchText' => $searchText] : [];

            return $this->client->get("/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/orders", $query);
        });
    }

    /**
     * Send ticket reminder email.
     */
    public function sendReminder(string $storeId, string $eventId, string $orderId, string $ticketId, ?string $userApiKey = null): array
    {
        return $this->withApiKey($userApiKey, function () use ($storeId, $eventId, $orderId, $ticketId) {
            return $this->client->post(
                "/api/v1/stores/{$storeId}/satflux-tickets/events/{$eventId}/orders/{$orderId}/tickets/{$ticketId}/send-reminder",
                []
            );
        });
    }

    // ──────────────────────────────────────────────────
    //  HELPER
    // ──────────────────────────────────────────────────

    /**
     * Keys allowed in plugin CreateEventRequest / UpdateEventRequest (plugin ignores unknown fields).
     * Include eventLogoUrl and logoUrl: plugin create path often needs a display URL; file id alone may be ignored
     * until the event exists (upload-logo flow works on update only).
     * enable: when true, event is created as Active; when false, as Disabled (inactive).
     */
    protected const EVENT_PAYLOAD_KEYS = [
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
        'eventLogoFileId',
        'eventLogoUrl',
        'logoUrl',
        'enable',
        'bundledRaffleId',
        'bundledRaffleTicketsPerAdmission',
    ];

    /**
     * Normalize event payload for plugin: only send fields the plugin expects (UpdateEventRequest).
     * Sends eventLogoFileId plus eventLogoUrl/logoUrl when present (BtcPayFileResolver / TicketController).
     */
    protected function normalizeEventPayloadForBtcPay(array $data): array
    {
        $fileId = isset($data['eventLogoFileId']) ? ($data['eventLogoFileId'] === null ? '' : (string) $data['eventLogoFileId']) : null;
        if ($fileId !== null) {
            $data['eventLogoFileId'] = $fileId;
        }
        $payload = array_intersect_key($data, array_flip(self::EVENT_PAYLOAD_KEYS));
        foreach (['emailBody', 'emailSubject'] as $key) {
            if (array_key_exists($key, $payload) && is_string($payload[$key])) {
                $payload[$key] = $this->normalizeTicketEmailPlaceholders($payload[$key]);
            }
        }

        // Keep empty eventLogoFileId so update/create can clear logo (frontend sends ""; do not strip).
        foreach (['eventLogoUrl', 'logoUrl'] as $logoKey) {
            if (array_key_exists($logoKey, $payload) && trim((string) $payload[$logoKey]) === '') {
                unset($payload[$logoKey]);
            }
        }

        return $payload;
    }

    /**
     * SatoshiTickets plugin replaces only exact tokens without inner spaces (e.g. {{Name}}, not {{ Name }}).
     */
    protected function normalizeTicketEmailPlaceholders(string $text): string
    {
        $keys = ['Name', 'Email', 'Title', 'Location', 'Description', 'EventDate', 'Currency'];
        foreach ($keys as $key) {
            $text = preg_replace(
                '/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/',
                '{{'.$key.'}}',
                $text
            ) ?? $text;
        }

        return $text;
    }

    /**
     * Execute a callback with an optional user-level API key, restoring the
     * original key afterwards.
     */
    protected function withApiKey(?string $userApiKey, callable $callback): array
    {
        $originalApiKey = null;
        if ($userApiKey) {
            $originalApiKey = $this->client->getApiKey();
            $this->client->setApiKey($userApiKey);
        }

        try {
            return $callback();
        } finally {
            if ($userApiKey && $originalApiKey) {
                $this->client->setApiKey($originalApiKey);
            }
        }
    }
}
