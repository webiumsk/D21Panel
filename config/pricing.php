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
    | Packs with sats <= 0 are not purchasable and are hidden from the API.
    | Anchors: single slot = 1/3 of Pro yearly (well under a second Pro
    | account at 210k/yr); 5-pack -20%, 10-pack -36% per slot.
    */

    'company_slot_packs' => [
        ['slots' => 1, 'sats' => 70_000],
        ['slots' => 5, 'sats' => 280_000],
        ['slots' => 10, 'sats' => 450_000],
    ],

];
