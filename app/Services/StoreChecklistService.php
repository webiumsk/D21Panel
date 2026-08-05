<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreChecklist;

class StoreChecklistService
{
    /**
     * Get checklist items for a wallet type.
     */
    public static function getChecklistItems(string $walletType): array
    {
        $items = self::getAllChecklistItems();

        return $items[$walletType] ?? [];
    }

    /**
     * Get all checklist item definitions.
     * Note: All configuration should be done via satflux.io UI, not BTCPay UI.
     * Links to BTCPay UI are removed as merchants should not access BTCPay directly.
     */
    public static function getAllChecklistItems(): array
    {
        return [
            'blink' => [
                'connect_wallet' => [
                    'key' => 'connect_wallet',
                    'description' => 'Confirm Blink wallet connection is active (Wallet connection - status should show configured or pending → connected)',
                    'link' => null, // No direct BTCPay UI links - configure via satflux.io
                    'order' => 1,
                ],
                'enable_lightning' => [
                    'key' => 'enable_lightning',
                    'description' => 'Confirm Lightning is enabled for this store once configuration has finished',
                    'link' => null,
                    'order' => 2,
                ],
                'test_invoice' => [
                    'key' => 'test_invoice',
                    'description' => 'Create a small test invoice or PoS payment to verify payouts',
                    'link' => null,
                    'order' => 3,
                ],
            ],
            'aqua_boltz' => [
                'configure_wallet' => [
                    'key' => 'configure_wallet',
                    'description' => 'Confirm Aqua/Bull (Boltz) wallet connection is saved (Wallet connection)',
                    'link' => null,
                    'order' => 1,
                ],
                'enable_boltz_plugin' => [
                    'key' => 'enable_boltz_plugin',
                    'description' => 'Confirm Boltz plugin is enabled for the store (contact support if needed)',
                    'link' => null,
                    'order' => 2,
                ],
                'connect_aqua_wallet' => [
                    'key' => 'connect_aqua_wallet',
                    'description' => 'Confirm Aqua or Bull Bitcoin is routed via Boltz as expected (support can help)',
                    'link' => null,
                    'order' => 3,
                ],
                'verify_swap_routing' => [
                    'key' => 'verify_swap_routing',
                    'description' => 'Verify swap routing works for a small test amount',
                    'link' => null,
                    'order' => 4,
                ],
                'test_lightning_invoice' => [
                    'key' => 'test_lightning_invoice',
                    'description' => 'Create and pay a test Lightning invoice',
                    'link' => null,
                    'order' => 5,
                ],
            ],
            'cashu' => [
                'verify_mint_ln' => [
                    'key' => 'verify_mint_ln',
                    'description' => 'Confirm mint URL and Lightning address in Cashu settings match the wallet you use',
                    'link' => null,
                    'order' => 1,
                ],
                'confirm_cashu_enabled' => [
                    'key' => 'confirm_cashu_enabled',
                    'description' => 'Confirm Cashu is enabled for this store in settings',
                    'link' => null,
                    'order' => 2,
                ],
                'test_cashu_payment' => [
                    'key' => 'test_cashu_payment',
                    'description' => 'Run a small test invoice or PoS payment to verify ecash / Lightning flow',
                    'link' => null,
                    'order' => 3,
                ],
            ],
            'blitz' => [
                'connect_wallet' => [
                    'key' => 'connect_wallet',
                    'description' => 'Confirm Blitz Wallet connection is active (Wallet connection - status should show configured or pending → connected)',
                    'link' => null,
                    'order' => 1,
                ],
                'enable_lightning' => [
                    'key' => 'enable_lightning',
                    'description' => 'Confirm Lightning is enabled for this store once configuration has finished',
                    'link' => null,
                    'order' => 2,
                ],
                'test_invoice' => [
                    'key' => 'test_invoice',
                    'description' => 'Create a small test invoice or PoS payment to verify payouts arrive in Blitz',
                    'link' => null,
                    'order' => 3,
                ],
            ],
            'nwc' => [
                'connect_nwc' => [
                    'key' => 'connect_nwc',
                    'description' => 'Confirm NWC (Lightning) wallet connection is saved and status shows connected or pending',
                    'link' => null,
                    'order' => 1,
                ],
                'test_lightning_invoice' => [
                    'key' => 'test_lightning_invoice',
                    'description' => 'Create and pay a small test Lightning invoice',
                    'link' => null,
                    'order' => 2,
                ],
            ],
        ];
    }

    /**
     * Initialize checklist items for a store.
     */
    public static function initializeChecklist(string $storeId, string $walletType): void
    {
        $items = self::getChecklistItems($walletType);

        foreach ($items as $item) {
            StoreChecklist::create([
                'store_id' => $storeId,
                'item_key' => $item['key'],
            ]);
        }
    }

    /**
     * Create missing checklist rows for the store's current wallet type (idempotent).
     * Fixes stores that never had initializeChecklist run and adds new wallet types (e.g. cashu).
     */
    public static function ensureChecklistInitialized(Store $store): void
    {
        $walletType = $store->wallet_type;
        if ($walletType === null || $walletType === '') {
            return;
        }

        $definitions = self::getChecklistItems($walletType);
        if ($definitions === []) {
            return;
        }

        $existingKeys = $store->checklistItems()->pluck('item_key')->all();

        foreach ($definitions as $item) {
            if (! in_array($item['key'], $existingKeys, true)) {
                StoreChecklist::create([
                    'store_id' => $store->id,
                    'item_key' => $item['key'],
                ]);
            }
        }
    }
}
