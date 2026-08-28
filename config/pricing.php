<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription pricing (single source of truth)
    |--------------------------------------------------------------------------
    | Change prices here only. Used by GET /api/pricing, Landing and Profile.
    | All amounts in sats. Payments are annual only for Pro.
    */

    'trial_days' => 30,
    'grace_days' => 30,

    'free' => [
        'sats_per_year' => 0,
    ],

    'pro' => [
        'sats_per_year' => 210_000,
        // List price shown struck through (21,000 x 12 = 252,000 sats/year)
        'sats_per_month_display' => 21_000,
        // Effective monthly when paid yearly: 210,000 / 12 = 17,500 sats (~16.7% off list monthly)
    ],

    'enterprise' => [
        // No fixed price - contact sales
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra invoicing company slots (one-off purchases, Pro only)
    |--------------------------------------------------------------------------
    | Slots raise the company limit on top of the plan's included count.
    | PLACEHOLDER prices: packs with sats <= 0 are not purchasable and are
    | hidden from the API - set real prices before launch.
    */

    'company_slot_packs' => [
        ['slots' => 1, 'sats' => 0],
        ['slots' => 5, 'sats' => 0],
        ['slots' => 10, 'sats' => 0],
    ],

];
