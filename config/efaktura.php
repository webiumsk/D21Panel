<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Slovak e-faktura (digitálny poštár / Peppol) - global switch
    |--------------------------------------------------------------------------
    | Per-company credentials and auto-send live in company app_settings.
    | See docs/SK_EFAKTURA.md.
    */

    'enabled' => filter_var(env('EFAKTURA_ENABLED', false), FILTER_VALIDATE_BOOL),

    'mandatory_from' => '2027-01-01',

    'default_provider' => env('EFAKTURA_PROVIDER', 'sapi_sk'),

    'queue' => env('EFAKTURA_QUEUE', 'default'),

    'inbound_poll_limit' => max(1, min(100, (int) env('EFAKTURA_INBOUND_POLL_LIMIT', 20))),

    // Local-first inbox: received documents wait server-side (parsed draft +
    // encrypted UBL) until the client imports them into Evolu. Rows still
    // pending after this many days are purged by efaktura:purge-inbound-inbox.
    'inbound_inbox_retention_days' => max(1, (int) env('EFAKTURA_INBOUND_INBOX_RETENTION_DAYS', 60)),

    'allowed_sapi_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('EFAKTURA_SAPI_ALLOWED_HOSTS', '')),
    ))),

    'providers' => [
        'sapi_sk' => [
            // Optional global fallback for local dev; merchants set efaktura_sapi_base_url per company.
            'base_url' => rtrim((string) env('EFAKTURA_SAPI_BASE_URL', ''), '/'),
            'token_path' => '/sapi/v1/auth/token',
            'send_path' => '/sapi/v1/document/send',
            // Optional; CPDS-specific. When set, stale submitted rows are refreshed via scheduler.
            'send_detail_path' => env('EFAKTURA_SAPI_SEND_DETAIL_PATH', ''),
            'receive_path' => '/sapi/v1/document/receive',
            'receive_detail_path' => '/sapi/v1/document/receive/{id}',
            'acknowledge_path' => '/sapi/v1/document/receive/{id}/acknowledge',
            'timeout_seconds' => (int) env('EFAKTURA_SAPI_TIMEOUT', 30),
        ],
    ],

];
