<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local-first invoicing (server-side flag)
    |--------------------------------------------------------------------------
    | When true, WooCommerce order payloads are stored in integration_document_inbox
    | instead of BusinessDocument (unless the linked company uses server invoicing).
    */

    'local_first' => filter_var(env('INVOICING_LOCAL_FIRST', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Company SMTP - private host guard (SSRF protection)
    |--------------------------------------------------------------------------
    | Company SMTP settings accept an arbitrary host:port. By default hosts that
    | resolve to private/reserved IP ranges are rejected so the SMTP test endpoint
    | cannot be used to probe the internal network. Enable only for local dev
    | (Mailpit/Mailhog) or self-hosted setups with an internal mail relay.
    */

    'smtp_allow_private_hosts' => filter_var(env('SMTP_ALLOW_PRIVATE_HOSTS', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | WooCommerce inbox PoC
    |--------------------------------------------------------------------------
    | When true, all WooCommerce create-document calls enqueue to the inbox
    | instead of creating BusinessDocument records (backward compat: default false).
    */

    /*
    |--------------------------------------------------------------------------
    | Accountant export ("balík pre účtovníka")
    |--------------------------------------------------------------------------
    | Decoded attachment budget per ephemeral request (the UI chunks by month
    | above it) and the row cap per package for both server and ephemeral mode.
    */

    'accountant_export_max_attachment_bytes' => (int) env('INVOICING_ACCOUNTANT_EXPORT_MAX_ATTACHMENT_BYTES', 64 * 1024 * 1024),
    'accountant_export_max_rows' => (int) env('INVOICING_ACCOUNTANT_EXPORT_MAX_ROWS', 500),

    'woocommerce_inbox_mode' => filter_var(env('WOOCOMMERCE_INBOX_MODE', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Beta override for PRO company limit
    |--------------------------------------------------------------------------
    | When set (e.g. 5), PRO users can create up to this many invoicing companies
    | instead of the plan's max_companies in the database. Leave empty in production.
    */

    'beta_pro_max_companies' => env('INVOICING_BETA_PRO_MAX_COMPANIES') !== null && env('INVOICING_BETA_PRO_MAX_COMPANIES') !== ''
        ? (int) env('INVOICING_BETA_PRO_MAX_COMPANIES')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Expense ISDOC extract (paid feature, free trial)
    |--------------------------------------------------------------------------
    | Number of ISDOC extractions included per user. Enterprise plan feature
    | expense_isdoc_extract_unlimited removes the cap.
    */

    'expense_isdoc_extract_free_limit' => (int) env('INVOICING_EXPENSE_ISDOC_FREE_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | ISDOC extract packs (EUR incl. VAT, aligned with SuperFaktúra tiers)
    |--------------------------------------------------------------------------
    */

    'expense_isdoc_packs' => [
        ['credits' => 25, 'price_eur' => 11.07],
        ['credits' => 50, 'price_eur' => 18.45],
        ['credits' => 100, 'price_eur' => 28.29],
        ['credits' => 500, 'price_eur' => 92.25],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription billing (paid plan invoices in Invoicing)
    |--------------------------------------------------------------------------
    | When a subscription payment settles on SUBSCRIPTION_STORE_ID, a paid
    | business invoice is created in this company for the subscriber.
    */

    'subscription_billing' => [
        'company_id' => env('SUBSCRIPTION_BILLING_COMPANY_ID'),
        'eur_currency' => 'EUR',
        'line_names' => [
            'pro' => 'Satflux PRO - ročné predplatné',
            'enterprise' => 'Satflux Enterprise - ročné predplatné',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bank transaction direction hints (email / CSV reference text)
    |--------------------------------------------------------------------------
    */

    'bank_debit_hints' => [
        'debet na',
        'debetna',
        'debet ',
        'debet.',
        'debit',
        'dbit',
        'odchod',
        'odch.',
        'odchodz',
        'výdaj',
        'vydaj',
        'nákup pos',
        'nakup pos',
        'eur nákup',
        'eur nakup',
        'pos nákup',
        'pos nakup',
        'transakčná daň',
        'transakcna dan',
        'poplatok',
        'výber',
        'vyber',
        'platba kartou',
        'platba prevodom',
        'smerom von',
    ],

    'bank_credit_hints' => [
        'kredit na',
        'kredit ',
        'credit',
        'príjem',
        'prijem',
        'prijatie',
        'vklad',
        'pripis',
        'pripísan',
        'pripisan',
    ],

];
