<?php

/**
 * Create a local Store row for the BTCPay subscription store (Greenfield) that already exists on BTCPay Server.
 *
 * Copy to scripts/create-subscription-store.php (gitignored), then:
 *   Set SUBSCRIPTION_STORE_ID in .env to the BTCPay store id, then:
 *   php scripts/create-subscription-store.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

echo "Creating subscription store record...\n\n";

$btcpayStoreId = trim((string) env('SUBSCRIPTION_STORE_ID', ''));
if ($btcpayStoreId === '') {
    fwrite(STDERR, "Set SUBSCRIPTION_STORE_ID in .env to your BTCPay Greenfield store id, then run again.\n");
    exit(1);
}

// Check if store already exists
$existingStore = Store::where('btcpay_store_id', $btcpayStoreId)->first();

if ($existingStore) {
    echo "✓ Store already exists!\n";
    echo "UUID: {$existingStore->id}\n";
    echo "Name: {$existingStore->name}\n";
    echo "BTCPay Store ID: {$existingStore->btcpay_store_id}\n";
    echo "\n.env should include SUBSCRIPTION_STORE_ID={$btcpayStoreId} (BTCPay id).\n";
    echo "Local Laravel store UUID (for reference): {$existingStore->id}\n";
    exit(0);
}

// Get user (use first user or admin user)
$user = User::first();

if (! $user) {
    echo "❌ Error: No users found in database. Please create a user first.\n";
    exit(1);
}

echo "Using user: {$user->email} (ID: {$user->id})\n\n";

// Create store record
// Use only minimal required fields - let database defaults handle the rest
try {
    $storeData = [
        'user_id' => $user->id,
        'btcpay_store_id' => $btcpayStoreId,
        'name' => 'Subscription Store',
    ];

    // Only add optional fields if they exist in the schema
    $columns = Schema::getColumnListing('stores');
    if (in_array('default_currency', $columns)) {
        $storeData['default_currency'] = 'EUR';
    }
    if (in_array('timezone', $columns)) {
        $storeData['timezone'] = 'UTC';
    }

    $store = Store::create($storeData);

    echo "✓ Store created successfully!\n\n";
    echo "UUID: {$store->id}\n";
    echo "Name: {$store->name}\n";
    echo "BTCPay Store ID: {$store->btcpay_store_id}\n";
    echo "\nEnsure .env has:\n";
    echo "  SUBSCRIPTION_STORE_ID={$btcpayStoreId}\n";
    echo "  SUBSCRIPTION_OFFERING_ID=<from BTCPay offering>\n";
    echo "  SUBSCRIPTION_PLAN_PRO_ID=<from BTCPay plan>\n";
    echo "\nLocal Laravel store UUID: {$store->id}\n";

} catch (Exception $e) {
    echo "❌ Error creating store: {$e->getMessage()}\n";
    exit(1);
}
