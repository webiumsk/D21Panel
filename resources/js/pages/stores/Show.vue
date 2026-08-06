<template>
  <div v-if="loading && !store" class="flex items-center justify-center h-full bg-gray-900">
    <div class="flex flex-col items-center">
        <svg class="animate-spin h-10 w-10 text-indigo-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-gray-400">{{ $t('stores.loading_store') }}</p>
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
      @open-setup-wizard="showSetupWizard = true"
    />

    <!-- Main Content -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden border-l border-gray-800 bg-gray-900">
      <AppScrollPane>
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <ArchivedStoreBanner :store="store" />

        <!-- Settings View -->
        <div v-if="showSettings" class="max-w-4xl mx-auto">
           <StoreSettings :store="store" @update-store="handleStoreUpdate" />
        </div>

        <!-- Invoices View -->
        <div v-else-if="showInvoices" class="max-w-7xl">
           <StoreInvoices :store="store" />
        </div>

        <!-- Cashu settlements (wallet_type=cashu) -->
        <div v-else-if="showCashu" class="max-w-7xl">
          <StoreCashuPayments
            v-if="store.wallet_type === 'cashu' || store.cashu_fallback_enabled"
            :store="store"
          />
          <div
            v-else
            class="rounded-2xl border border-amber-500/25 bg-amber-500/10 px-6 py-5 text-sm text-amber-100"
          >
            {{ t('stores.cashu_section_requires_cashu_wallet') }}
          </div>
        </div>

        <!-- Reports View -->
        <div v-else-if="showReports" class="max-w-7xl">
           <StoreReports :store="store" />
        </div>

        <!-- Dashboard View -->
        <div v-else class="space-y-8">
          <!-- Store Info Card - Always visible -->
          <div class="bg-gradient-to-br from-gray-800 to-gray-900 shadow-xl rounded-2xl border border-gray-700 p-6 relative overflow-hidden">
            <!-- Decorative Background -->
            <div class="absolute top-0 right-0 -mr-16 -mt-16 w-64 h-64 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-blob"></div>

            <div class="flex flex-col md:flex-row items-center justify-between mb-6 relative z-10">
              <div class="flex items-center mb-4 md:mb-0">
                  <div v-if="store.logo_url" class="mr-6 bg-white/5 p-2 rounded-xl backdrop-blur-sm border border-white/10">
                    <img :src="store.logo_url" :alt="`${store.name} logo`" class="h-20 w-20 object-contain" />
                  </div>
                  <div v-else class="mr-6 h-20 w-20 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-3xl font-bold shadow-lg shadow-indigo-600/20">
                    {{ store.name.charAt(0).toUpperCase() }}
                  </div>
                  <div>
                    <h1 class="text-3xl font-bold text-white mb-1">{{ store.name }}</h1>
                    <p class="text-sm text-gray-400">{{ t('stores.store_id') }}: <span class="font-mono text-gray-500">{{ store.id }}</span></p>
                  </div>
              </div>
              
              <div class="flex flex-col items-end gap-2">
                 <div class="flex items-center bg-gray-800/80 rounded-lg px-3 py-1.5 border border-gray-700">
                    <span class="text-xs text-gray-500 uppercase tracking-wider mr-2 font-semibold">{{ t('stores.wallet_type') }}</span>
                    <WalletTypeIcon
                      :type="store.wallet_type"
                      :brand="resolveStoreWalletBrand(store)"
                      size="lg"
                      :show-label="store.wallet_type === 'cashu' || store.wallet_type === 'aqua_boltz'"
                      :fallback-text="t('stores.not_configured')"
                      class="text-indigo-400"
                    />
                 </div>
                 <div class="flex items-center bg-gray-800/80 rounded-lg px-3 py-1.5 border border-gray-700">
                    <span class="text-xs text-gray-500 uppercase tracking-wider mr-2 font-semibold">{{ t('stores.connection') }}</span>
                    <span class="text-sm font-bold" :class="getWalletConnectionStatusClass(store)">
                       {{ t(getWalletConnectionStatusKey(store)) }}
                    </span>
                 </div>
              </div>
            </div>
          </div>

          <!-- Loading state for dashboard -->
          <div v-if="loading && !dashboard" class="flex justify-center py-12">
              <svg class="animate-spin h-10 w-10 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
          </div>

          <!-- Dashboard content -->
          <div v-else-if="dashboard" class="space-y-8">
            <!-- Store Status Banner -->
            <!-- Cashu (always connected) -->
            <div v-if="store.wallet_type === 'cashu'" class="bg-green-500/10 border border-green-500/20 rounded-xl p-4 flex items-start">
              <svg class="h-5 w-5 text-green-400 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
              <div>
                <h3 class="text-sm font-medium text-green-400">{{ t('stores.connection_active') }}</h3>
                <p class="text-sm text-green-500/80 mt-1">{{ t('stores.connection_active_description') }}</p>
              </div>
            </div>

            <!-- Connected -->
            <div v-else-if="store.wallet_connection?.status === 'connected'" class="bg-green-500/10 border border-green-500/20 rounded-xl p-4 flex items-start">
              <svg class="h-5 w-5 text-green-400 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
              <div>
                <h3 class="text-sm font-medium text-green-400">{{ t('stores.connection_active') }}</h3>
                <p class="text-sm text-green-500/80 mt-1">{{ t('stores.connection_active_description') }}</p>
              </div>
            </div>
            
            <!-- Needs Support -->
            <div v-else-if="store.wallet_connection?.status === 'needs_support'" class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start">
              <svg class="h-5 w-5 text-blue-400 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>
               <div>
                <h3 class="text-sm font-medium text-blue-400">{{ t('stores.configuration_in_progress') }}</h3>
                <p class="text-sm text-blue-500/80 mt-1">{{ t('stores.configuration_in_progress_description') }}</p>
              </div>
            </div>
            
            <!-- Pending (same message as needs_support: being configured, notified when ready) -->
            <div v-else-if="store.wallet_connection?.status === 'pending'" class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 flex items-start">
              <svg class="h-5 w-5 text-yellow-400 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
               <div>
                <h3 class="text-sm font-medium text-yellow-400">{{ t('stores.configuration_in_progress') }}</h3>
                <p class="text-sm text-yellow-500/80 mt-1">{{ t('stores.configuration_in_progress_description') }}</p>
              </div>
            </div>
            
            <!-- Not Configured -->
            <div v-else class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 flex items-start">
              <svg class="h-5 w-5 text-red-400 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>
               <div>
                <h3 class="text-sm font-medium text-red-400">{{ t('stores.wallet_not_configured') }}</h3>
                <p class="text-sm text-red-500/80 mt-1">{{ t('stores.wallet_not_configured_description') }} <router-link :to="`/stores/${store.id}/wallet-connection`" class="underline hover:text-red-300">{{ t('stores.wallet_connection') }}</router-link>.</p>
              </div>
            </div>

            <!-- Boltz readiness (informational snapshot; authoritative check happens in BTCPay) -->
            <BoltzReadinessCard v-if="store.wallet_type === 'aqua_boltz'" :store-id="store.id" />

            <!-- Pro: Filter by payment method + Refresh -->
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div v-if="dashboard?.can_filter_by_source" class="flex flex-wrap items-center gap-3">
                <label class="text-sm font-medium text-gray-400">{{ t('stores.filter_by_payment_method') }}</label>
                <select
                :value="dashboardSourceFilter"
                @change="onDashboardSourceChange"
                class="rounded-lg border border-gray-600 bg-gray-800 text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
              >
                <option value="all">{{ t('stores.payment_method_all') }}</option>
                <option value="pos">{{ t('stores.payment_method_pos') }}</option>
                <option value="pay_button">{{ t('stores.payment_method_pay_button') }}</option>
                <option value="ln_address">{{ t('stores.payment_method_ln_address') }}</option>
                <option value="tickets">{{ t('stores.payment_method_tickets') }}</option>
                <option value="api">{{ t('stores.payment_method_api') }}</option>
                <option value="other">{{ t('stores.payment_method_other') }}</option>
              </select>
              </div>
              <button
                type="button"
                :disabled="dashboardRefreshing"
                :title="t('dashboard.refresh_stats')"
                class="p-2 rounded-lg border border-gray-600 bg-gray-800 text-gray-400 hover:text-white hover:border-indigo-500/50 hover:bg-gray-750 disabled:opacity-50 disabled:cursor-not-allowed transition-colors ml-auto"
                @click="handleRefreshDashboard"
              >
                <svg class="w-5 h-5" :class="{ 'animate-spin': dashboardRefreshing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>
            </div>

            <!-- Dashboard Statistics -->
            <DashboardStats
              v-if="dashboard"
              :stats="{
                paid_invoices_last_7d: dashboard.paid_invoices_last_7d || 0,
                total_invoices: dashboard.total_invoices || 0,
                total_revenue_sats: dashboard.total_revenue_sats ?? 0,
                total_revenue_default_currency: dashboard.total_revenue_default_currency ?? 0,
                default_currency: dashboard.default_currency || 'eur'
              }"
              @view-invoices="handleViewInvoices"
            />

            <!-- Sales Chart and Top Items -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
              <SalesChart
                v-if="dashboard.sales"
                :store-name="store.name"
                :sales7d="dashboard.sales?.last_7_days || []"
                :sales30d="dashboard.sales?.last_30_days || []"
                :total-sales7d="dashboard.sales?.total_7d || 0"
                :total-sales30d="dashboard.sales?.total_30d || 0"
                :manage-url="`/stores/${store.id}/apps`"
              />
              <TopItems :items="dashboard.top_items || []" />
            </div>

            <!-- Recent Invoices -->
            <div v-if="dashboard.recent_invoices.length > 0">
              <RecentInvoices
                :invoices="dashboard.recent_invoices"
                @view-all="handleViewAllInvoices"
                @view-invoice="handleViewInvoice"
              />
            </div>

            <!-- Empty State for Invoices -->
            <div v-else-if="!loading" class="bg-gray-800 shadow-xl rounded-2xl border border-gray-700 p-12 text-center">
              <div class="w-16 h-16 bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                 <svg class="h-8 w-8 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
              </div>
              <h3 class="text-lg font-medium text-white mb-2">{{ t('stores.no_invoices_yet') }}</h3>
              <p class="text-gray-400 mb-6">{{ t('stores.create_first_invoice') }}</p>
              <!-- <button class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-500">
                Create Invoice
              </button> -->
            </div>
          </div>
          
          <!-- Dashboard load failed -->
          <div v-else-if="!loading" class="bg-gray-800 shadow-xl rounded-2xl border border-gray-700 p-12 text-center">
            <p class="text-red-400 mb-2 font-medium">{{ t('stores.failed_to_load_dashboard') }}</p>
            <button 
              @click="storesStore.fetchDashboard(store.id)" 
              class="mt-4 px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600 transition-colors"
            >
              {{ t('common.retry') }}
            </button>
          </div>
        </div>
      </div>
      </AppScrollPane>
    </div>
  </div>

  <div v-else class="flex items-center justify-center h-full bg-gray-900">
    <div class="text-center">
        <h2 class="text-xl font-bold text-white mb-2">{{ t('stores.store_not_found') }}</h2>
        <router-link to="/stores" class="text-indigo-400 hover:text-indigo-300">{{ t('stores.return_to_stores') }}</router-link>
    </div>
  </div>

  <!-- Setup wizard modal (sprievodca nastavením) -->
  <SetupWizardModal
    v-if="store && showSetupWizard"
    :store-id="store.id"
    :store="store"
    @close="handleCloseSetupWizard"
    @restart-pos-tour="handleRestartPosTour"
  />

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
import type { Store } from '../../store/stores';
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useStoresStore } from '../../store/stores';
import { useAppsStore } from '../../store/apps';
import StoreSidebar from '../../components/stores/StoreSidebar.vue';
import AppScrollPane from '../../components/layout/AppScrollPane.vue';
import WalletTypeIcon from '../../components/WalletTypeIcon.vue';
import { resolveStoreWalletBrand } from '../../utils/storeWalletBrand';
import DashboardStats from '../../components/stores/DashboardStats.vue';
import RecentInvoices from '../../components/stores/RecentInvoices.vue';
import SalesChart from '../../components/stores/SalesChart.vue';
import TopItems from '../../components/stores/TopItems.vue';
import StoreSettings from '../../components/stores/StoreSettings.vue';
import BoltzReadinessCard from '../../components/stores/BoltzReadinessCard.vue';
import ArchivedStoreBanner from '../../components/stores/ArchivedStoreBanner.vue';
import StoreInvoices from '../../components/stores/StoreInvoices.vue';
import StoreCashuPayments from '../../components/stores/StoreCashuPayments.vue';
import StoreReports from '../../components/stores/StoreReports.vue';
import SetupWizardModal from '../../components/stores/SetupWizardModal.vue';
import BlinkMigrationAlertModal from '../../components/stores/BlinkMigrationAlertModal.vue';
import { useBlinkMigrationAlert } from '../../composables/useBlinkMigrationAlert';
import { useOnboardingStore } from '../../store/onboarding';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const storesStore = useStoresStore();
const appsStore = useAppsStore();

const loading = ref(false);
const store = ref<Store | null>(null);

const {
  modalOpen: blinkAlertModalOpen,
  loading: blinkAlertLoading,
  syncModalFromStore,
  acknowledge: blinkAlertAcknowledge,
  dismissOutsideEu: blinkAlertDismissOutsideEu,
} = useBlinkMigrationAlert(store);
const showSettings = ref(false);
const showInvoices = ref(false);
const showCashu = ref(false);
const showReports = ref(false);
const showSetupWizard = ref(false);
const dashboardSourceFilter = ref<string>('all');
const dashboardRefreshing = ref(false);

const dashboard = computed(() => storesStore.dashboard);

async function handleRefreshDashboard() {
  const storeId = route.params.id as string;
  if (!storeId) return;
  dashboardRefreshing.value = true;
  try {
    await storesStore.fetchDashboard(storeId, { refresh: true });
  } finally {
    dashboardRefreshing.value = false;
  }
}

async function onDashboardSourceChange(e: Event) {
  const value = (e.target as HTMLSelectElement).value;
  dashboardSourceFilter.value = value;
  if (!store.value?.id) return;
  try {
    await storesStore.fetchDashboard(store.value.id, { source: value === 'all' ? undefined : value });
  } catch (err) {
    console.error('Failed to filter dashboard', err);
  }
}
const allApps = computed(() => appsStore.apps);

// Watch route params for store changes (when switching stores in dropdown)
watch(() => route.params.id, async (newStoreId, oldStoreId) => {
  if (newStoreId && newStoreId !== oldStoreId) {
    loading.value = true;
    dashboardSourceFilter.value = 'all';
    try {
      store.value = await storesStore.fetchStore(newStoreId as string);
      syncModalFromStore();
      // Load appropriate view based on query
      const currSection = route.query.section as string;
      updateSection(currSection);

      if (!currSection) {
        await storesStore.fetchDashboard(newStoreId as string);
        await appsStore.fetchApps(newStoreId as string);
      }
    } finally {
      loading.value = false;
    }
  }
}, { immediate: false });

// Watch route query for section changes
watch(() => route.query.section, async (newSection, oldSection) => {
  if (newSection === oldSection) return;
  updateSection(newSection as string);
  
  // If switching to dashboard (empty section), ensure data is loaded
  if (!newSection && store.value) {
     const storeId = route.params.id as string;
     loading.value = true;
     try {
       await storesStore.fetchDashboard(storeId);
       await appsStore.fetchApps(storeId);
     } finally {
       loading.value = false;
     }
  }
});

function updateSection(section: string) {
  showSettings.value = section === 'settings';
  showInvoices.value = section === 'invoices';
  showCashu.value = section === 'cashu';
  showReports.value = section === 'reports' || section === 'exports'; // support legacy exports query
}

const SETUP_WIZARD_DISMISSED_KEY = 'setup_wizard_dismissed';

onMounted(async () => {
  const storeId = route.params.id as string;
  loading.value = true;
  try {
    store.value = await storesStore.fetchStore(storeId);
    syncModalFromStore();
    const section = route.query.section as string;
    updateSection(section);
    
    if (!section) {
      await storesStore.fetchDashboard(storeId);
      await appsStore.fetchApps(storeId);
    }

    // Auto-open setup wizard when coming from store creation (?setup=1) or when store has no PoS yet and user never dismissed
    const querySetup = route.query.setup === '1';
    const queryOpenPosTour = route.query.openPosTour === '1';
    const dismissed = typeof localStorage !== 'undefined' && localStorage.getItem(`${SETUP_WIZARD_DISMISSED_KEY}_${storeId}`);
    if (querySetup) {
      showSetupWizard.value = true;
      const q = { ...route.query };
      delete q.setup;
      router.replace({ name: 'stores-show', params: { id: storeId }, query: q });
    } else if (queryOpenPosTour) {
      const onboarding = useOnboardingStore();
      onboarding.reset();
      const q = { ...route.query };
      delete q.openPosTour;
      router.replace({ name: 'stores-show', params: { id: storeId }, query: Object.keys(q).length ? q : undefined });
    } else if (!dismissed && store.value && !section) {
      const hasPos = (appsStore.apps || []).some((a: { app_type?: string; archived?: boolean }) => a.app_type === 'PointOfSale' && !a.archived);
      if (!hasPos) {
        showSetupWizard.value = true;
      }
    }
  } catch (e) {
      console.error("Error loading store", e);
  } finally {
    loading.value = false;
  }
});

function handleCloseSetupWizard() {
  showSetupWizard.value = false;
  if (store.value && typeof localStorage !== 'undefined') {
    localStorage.setItem(`${SETUP_WIZARD_DISMISSED_KEY}_${store.value.id}`, '1');
  }
}

function handleRestartPosTour() {
  const onboarding = useOnboardingStore();
  onboarding.reset();
  showSetupWizard.value = false;
}

/** Store id for navigation - falls back to the route param before load completes. */
function storeRouteId(): string {
  return store.value?.id ?? (route.params.id as string);
}

function handleCreateApp() {
  router.push({ name: 'stores-apps-create', params: { id: storeRouteId() } });
}

function handleShowSettings() {
  handleShowSection('settings');
}

function handleShowSection(section: string) {
  updateSection(section);
  router.push({ name: 'stores-show', params: { id: storeRouteId() }, query: { section } });
}

function handleViewInvoices(filters?: Record<string, unknown>) {
  router.push({ name: 'stores-show', params: { id: storeRouteId() }, query: { section: 'invoices', ...filters } });
}

function handleViewAllInvoices() {
  handleViewInvoices();
}

function handleViewInvoice(invoice: { id: string; invoice_id: string }) {
   const invoiceId = invoice?.invoice_id || invoice?.id;
   if (!invoiceId) return;
   router.push(`/stores/${route.params.id}/invoices/${invoiceId}`);
}

// Handler for when store is updated in Settings component
async function handleStoreUpdate() {
   const storeId = route.params.id as string;
   store.value = await storesStore.fetchStore(storeId);
}

function getWalletConnectionStatusClass(store: Store): string {
  if (store.wallet_type === 'cashu') {
    return 'text-green-400';
  }
  if (!store.wallet_connection) {
    return 'text-red-400';
  }
  
  const status = store.wallet_connection.status;
  switch (status) {
    case 'connected':
      return 'text-green-400';
    case 'needs_support':
      return 'text-blue-400';
    case 'pending':
    default:
      return 'text-gray-400';
  }
}

function getWalletConnectionStatusKey(store: Store): string {
  if (store.wallet_type === 'cashu') {
    return 'stores.connected';
  }
  if (!store.wallet_connection) {
    return 'stores.not_config';
  }
  const status = store.wallet_connection.status;
  switch (status) {
    case 'connected':
      return 'stores.connected';
    case 'needs_support':
      return 'stores.support';
    case 'pending':
      return 'stores.pending';
    default:
      return 'stores.unknown';
  }
}
</script>
