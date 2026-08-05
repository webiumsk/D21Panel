<template>
  <div v-if="storeLoading && !store" class="flex items-center justify-center h-full bg-gray-900">
     <div class="flex flex-col items-center">
        <svg class="animate-spin h-10 w-10 text-indigo-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-gray-400">{{ t('stores.loading_store') }}</p>
    </div>
  </div>

  <div v-else-if="store" class="flex min-h-0 flex-1 overflow-hidden bg-gray-900">
    <!-- Sidebar -->
    <StoreSidebar
      :store="store"
      :apps="allApps"
      @create-app="handleCreateApp"
      @show-settings="handleShowSettings"
      @show-section="handleShowSection"
    />

    <!-- Main Content -->
    <div
      class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto overscroll-y-contain custom-scrollbar border-l border-gray-800 bg-gray-900"
    >
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pb-16">
          <div class="mb-8">
            <h1 class="text-3xl font-bold text-white">
              {{
                store.wallet_type === 'cashu'
                  ? t('stores.cashu_settings_title')
                  : t('stores.wallet_connection')
              }}
            </h1>
            <p class="mt-2 text-sm text-gray-400">
              {{ store.wallet_type === 'cashu'
                ? t('stores.cashu_description')
                : t('create_store.wallet_paste_hint') }}
              <span v-if="store.wallet_type !== 'cashu'" class="text-indigo-400 font-semibold">{{ store.name }}</span>
            </p>
          </div>

          <div v-if="loading" class="text-center py-24">
             <svg class="animate-spin mx-auto h-12 w-12 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
             </svg>
          </div>

          <div v-else-if="error" class="bg-red-500/10 border border-red-500/20 rounded-xl p-6 mb-6">
            <div class="flex items-center">
                 <svg class="h-6 w-6 text-red-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                 <p class="text-red-400 font-medium">{{ error }}</p>
            </div>
          </div>

          <div
            v-else
            class="bg-gray-800 shadow-xl rounded-2xl border border-gray-700 p-8"
          >
            <WalletConnectionForm
              :store-id="storeId"
              :existing-connection="connection"
              :wallet-type="store?.wallet_type"
              :wallet-brand="resolveStoreWalletBrand(store)"
              :auto-samrock="route.query.samrock === '1'"
              :cashu-fallback-address="store?.cashu_fallback_enabled ? store?.cashu_fallback_address : null"
              @submitted="handleSubmitted"
              @cancel="handleCancel"
            />
          </div>
        </div>
    </div>
  </div>

  <BlinkMigrationAlertModal
    v-if="store"
    :open="blinkAlertModalOpen"
    :store-id="store.id"
    :store-name="store.name"
    :loading="blinkAlertLoading"
    @close="blinkAlertModalOpen = false"
    @acknowledge="blinkAlertAcknowledge"
    @outside-eu="blinkAlertDismissOutsideEu"
  />
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useStoresStore } from '../../store/stores';
import { useAppsStore } from '../../store/apps';
import { walletApi, type WalletConnectionDetails } from '../../services/api';
import type { Store } from '../../store/stores';
import WalletConnectionForm from '../../components/stores/WalletConnectionForm.vue';
import BlinkMigrationAlertModal from '../../components/stores/BlinkMigrationAlertModal.vue';
import StoreSidebar from '../../components/stores/StoreSidebar.vue';
import { useBlinkMigrationAlert } from '../../composables/useBlinkMigrationAlert';
import { resolveStoreWalletBrand } from '../../utils/storeWalletBrand';

const { t } = useI18n();

const route = useRoute();
const router = useRouter();
const storesStore = useStoresStore();
const appsStore = useAppsStore();

const storeId = computed(() => route.params.id as string);
const store = ref<Store | null>(null);

const {
  modalOpen: blinkAlertModalOpen,
  loading: blinkAlertLoading,
  syncModalFromStore,
  acknowledge: blinkAlertAcknowledge,
  dismissOutsideEu: blinkAlertDismissOutsideEu,
} = useBlinkMigrationAlert(store);

const storeLoading = ref(true);
const loading = ref(true);
const error = ref<string | null>(null);
const connection = ref<WalletConnectionDetails | null>(null);

const allApps = computed(() => appsStore.apps);

async function loadStore() {
    storeLoading.value = true;
    try {
        store.value = await storesStore.fetchStore(storeId.value);
        syncModalFromStore();
    } catch (err) {
        console.error('Failed to load store:', err);
    } finally {
        storeLoading.value = false;
    }
}

async function loadConnection() {
    loading.value = true;
    error.value = null;
    try {
        if (store.value?.wallet_type === 'cashu') {
            connection.value = null;
            return;
        }
        connection.value = await walletApi.connection.get(storeId.value);
    } catch (rawError) {
        const err = asApiError(rawError);
        if (err.response?.status !== 404) {
            error.value = err.response?.data?.message || 'Failed to load wallet connection';
        }
    } finally {
        loading.value = false;
    }
}

function handleSubmitted() {
    router.push({ name: 'stores-show', params: { id: storeId.value } });
}

function handleCancel() {
    router.push({ name: 'stores-show', params: { id: storeId.value } });
}

function handleCreateApp() {
    router.push({ name: 'stores-apps-create', params: { id: storeId.value } });
}

function handleShowSettings() {
    router.push({ name: 'stores-show', params: { id: storeId.value }, query: { section: 'settings' } });
}

function handleShowSection(section: string) {
    router.push({ name: 'stores-show', params: { id: storeId.value }, query: { section } });
}

onMounted(async () => {
    await loadStore();
    await loadConnection();
    await appsStore.fetchApps(storeId.value);
});

watch(storeId, async () => {
    await loadStore();
    await loadConnection();
    await appsStore.fetchApps(storeId.value);
});
</script>
