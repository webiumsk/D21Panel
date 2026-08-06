import { defineStore } from 'pinia';
import { ref } from 'vue';
import { storesApi, type CreateStorePayload } from '../services/api';
import type { BtcPayApp, StoreDashboardStats } from '../types/btcpay';

import type { AquaBoltzWalletBrand } from '../utils/aquaBoltzWalletBrand';

export interface BlinkMigrationAlert {
    active: boolean;
    snoozed_until?: string | null;
    dismissed_at?: string | null;
}

export interface Store {
    id: string;
    name: string;
    archived?: boolean;
    default_currency?: string;
    timezone?: string;
    wallet_type: 'blink' | 'blitz' | 'aqua_boltz' | 'cashu' | 'nwc' | null;
    cashu_fallback_enabled?: boolean;
    cashu_fallback_address?: string | null;
    /** Aqua vs Bull when wallet_type is aqua_boltz */
    wallet_brand?: AquaBoltzWalletBrand | null;
    blink_migration_alert?: BlinkMigrationAlert | null;
    created_at: string;
    updated_at: string;
    logo_url?: string | null;
    checklist_items?: ChecklistItem[];
    wallet_connection?: {
        id: string;
        type: 'blink' | 'blitz' | 'aqua_descriptor' | 'nwc';
        status: 'pending' | 'needs_support' | 'connected';
        brand?: AquaBoltzWalletBrand | null;
        masked_secret?: string;
        submitted_at?: string;
    };
}

export interface ChecklistItem {
    key: string;
    description: string;
    link: string | null;
    completed_at: string | null;
    is_completed: boolean;
}

export type InvoiceSource = 'pos' | 'pay_button' | 'ln_address' | 'tickets' | 'api' | 'other';

export type DashboardData = StoreDashboardStats;

export const useStoresStore = defineStore('stores', () => {
    const stores = ref<Store[]>([]);
    const currentStore = ref<Store | null>(null);
    const dashboard = ref<DashboardData | null>(null);
    /** Store the current dashboard belongs to - stats payload carries no id. */
    const dashboardStoreId = ref<string | null>(null);
    const apps = ref<BtcPayApp[]>([]);
    const loading = ref(false);

    async function fetchStores(): Promise<boolean> {
        loading.value = true;
        try {
            stores.value = await storesApi.list();
            return true;
        } catch {
            // Global error flash covers network/5xx; callers branch on the boolean.
            stores.value = [];
            return false;
        } finally {
            loading.value = false;
        }
    }

    let fetchStoreGeneration = 0;

    async function fetchStore(id: string): Promise<Store> {
        const generation = ++fetchStoreGeneration;
        loading.value = true;
        try {
            const store = await storesApi.get(id);
            if (generation === fetchStoreGeneration) {
                currentStore.value = store;
            }
            return store;
        } catch (e) {
            if (generation === fetchStoreGeneration) {
                // Avoid showing the previous store when navigation fails (404/403/network).
                currentStore.value = null;
            }
            throw e;
        } finally {
            if (generation === fetchStoreGeneration) {
                loading.value = false;
            }
        }
    }

    async function createStore(data: CreateStorePayload): Promise<Store> {
        loading.value = true;
        try {
            const store = await storesApi.create(data);
            stores.value.push(store);
            return store;
        } finally {
            loading.value = false;
        }
    }

    async function fetchDashboard(storeId: string, params?: { source?: string; refresh?: boolean }): Promise<DashboardData> {
        loading.value = true;
        try {
            const stats = await storesApi.dashboard(storeId, params);
            dashboard.value = stats;
            dashboardStoreId.value = storeId;
            return stats;
        } catch (error) {
            dashboard.value = null;
            dashboardStoreId.value = null;
            throw error;
        } finally {
            loading.value = false;
        }
    }

    async function fetchApps(storeId: string): Promise<BtcPayApp[]> {
        loading.value = true;
        try {
            apps.value = await storesApi.apps(storeId);
            return apps.value;
        } catch (error) {
            apps.value = [];
            throw error;
        } finally {
            loading.value = false;
        }
    }

    async function deleteStore(storeId: string): Promise<{ message?: string; btcpay_deleted?: boolean }> {
        loading.value = true;
        try {
            const result = await storesApi.delete(storeId);

            const index = stores.value.findIndex(s => s.id === storeId);
            if (index > -1) {
                stores.value.splice(index, 1);
            }
            if (currentStore.value?.id === storeId) {
                currentStore.value = null;
            }
            // Only wipe the dashboard when it belongs to the deleted store
            if (dashboardStoreId.value === storeId) {
                dashboard.value = null;
                dashboardStoreId.value = null;
            }

            return result;
        } finally {
            loading.value = false;
        }
    }

    return {
        stores,
        currentStore,
        dashboard,
        apps,
        loading,
        fetchStores,
        fetchStore,
        createStore,
        fetchDashboard,
        fetchApps,
        deleteStore,
    };
});







