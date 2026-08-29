<template>
  <div class="space-y-8">
    <div v-if="!canUse">
      <h2 class="text-xl font-bold text-white">{{ t('dashboard.invoicing_overview_title') }}</h2>
      <p class="text-gray-400 mt-1 text-sm">{{ t('dashboard.invoicing_overview_subtitle') }}</p>
    </div>

    <!-- Guest: upgrade account -->
    <div
      v-if="isGuestUser"
      class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start"
    >
      <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
        <h3 class="text-lg font-semibold text-white mb-2">{{ t('dashboard.invoicing_guest_intro') }}</h3>
        <GuestUpgradeForm
          feature-label-key="header.invoicing"
          :show-default-desc="false"
          id-prefix="dashboard-invoicing-guest"
        />
        <button
          type="button"
          class="mt-4 w-full sm:w-auto px-4 py-2 text-sm font-medium text-indigo-400 hover:text-indigo-300 border border-indigo-500/40 rounded-lg hover:bg-indigo-500/10 transition-colors"
          @click="openGuestUpgradeModal('header.invoicing')"
        >
          {{ t('account.guest_upgrade_open_modal') }}
        </button>
      </div>
      <DashboardInvoicingFeaturesAside />
    </div>

    <!-- Free: PRO upgrade -->
    <div
      v-else-if="!canUse"
      class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start"
    >
      <div class="space-y-6">
        <div class="rounded-xl border border-amber-500/30 bg-amber-950/30 px-4 py-4">
          <p class="font-medium text-amber-200">{{ t('invoicing.pro_required') }}</p>
          <p class="text-sm mt-2 text-amber-200/80">{{ t('invoicing.pro_required_detail') }}</p>
        </div>

        <div class="border border-gray-600 rounded-xl p-6 bg-gray-800/80 hover:border-indigo-500 transition-colors relative group">
          <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/5 to-purple-600/5 rounded-xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
          <div class="relative z-10">
            <h3 class="text-lg font-bold text-white mb-2">{{ t('account.pro_plan') }}</h3>
            <p v-if="canStartTrial" class="text-xs font-semibold uppercase tracking-wide text-emerald-400 mb-2">
              {{ t('account.pro_trial_badge', { days: pricing.trial_days }) }}
            </p>
            <div class="text-2xl font-bold text-indigo-400 mb-1">
              <span
                v-if="proHasMonthlyDiscount(pricing.pro)"
                class="text-base font-normal text-gray-500 line-through mr-2"
              >{{ formatSats(pricing.pro.sats_per_month_display) }}</span>
              {{ formatSats(proEffectiveMonthlySats(pricing.pro)) }}<span class="text-base font-normal text-gray-500">{{ t('account.pro_price_period') }}</span>
            </div>
            <p class="text-sm text-gray-400 mb-4">
              {{ t('account.pro_yearly_price', { amount: formatSats(pricing.pro.sats_per_year) }) }}
            </p>
            <ul class="text-sm text-gray-400 space-y-2 mb-6">
              <li
                v-for="key in planFeatures.pro.feature_keys"
                :key="key"
                class="flex items-center"
                :class="isInvoicingFeature(key) ? 'text-indigo-200 font-medium' : ''"
              >
                <span
                  class="w-1.5 h-1.5 rounded-full mr-2"
                  :class="isInvoicingFeature(key) ? 'bg-indigo-400' : 'bg-indigo-500'"
                ></span>
                {{ t('plans.features.' + key) }}
              </li>
            </ul>
            <button
              type="button"
              :disabled="upgrading"
              class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold rounded-lg transition-all shadow-lg shadow-indigo-600/20 disabled:opacity-50 disabled:cursor-not-allowed"
              @click="upgradePlan('pro')"
            >
              {{ upgrading ? t('upgrade_modal.processing') : t('account.upgrade_to_pro') }}
            </button>
          </div>
        </div>

        <UpgradeModal :show="showUpgrade" @close="showUpgrade = false" />
      </div>
      <DashboardInvoicingFeaturesAside />
    </div>

    <!-- Pro: full invoicing index (page scroll, no nested scrollport) -->
    <div v-else class="rounded-2xl border border-gray-700 bg-white overflow-hidden">
      <DashboardInvoicingTabProShell />
    </div>

    <!-- Local-first footer (guest / free) -->
    <div v-if="!canUse" class="border-t border-gray-700 pt-8">
      <p class="text-sm text-gray-400 leading-relaxed">{{ t('dashboard.invoicing_local_first_intro') }}</p>
      <p class="text-sm text-gray-500 mt-3 leading-relaxed">
        {{ t('invoicing.local_first_data_notice') }}
        <router-link to="/legal/privacy" class="text-indigo-400 hover:text-indigo-300">{{ t('legal.nav.privacy') }}</router-link>.
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../../store/auth';
import { useBusinessInvoicing } from '../../composables/useBusinessInvoicing';
import { usePlanFeatures } from '../../composables/usePlanFeatures';
import {
  usePricing,
  proEffectiveMonthlySats,
  proHasMonthlyDiscount,
} from '../../composables/usePricing';
import api from '../../services/api';
import { useCurrentPlan } from '../../composables/useCurrentPlan';
import GuestUpgradeForm from '../account/GuestUpgradeForm.vue';
import UpgradeModal from '../stores/UpgradeModal.vue';
import DashboardInvoicingTabProShell from './DashboardInvoicingTabProShell.vue';
import DashboardInvoicingFeaturesAside from './DashboardInvoicingFeaturesAside.vue';
import { useGuestUpgradeStore } from '../../store/guestUpgrade';

const { t } = useI18n();
const authStore = useAuthStore();
const { canUse } = useBusinessInvoicing();
const { planFeatures, isInvoicingFeature, load: loadPlanFeatures } = usePlanFeatures();
const { pricing, formatSats, load: loadPricing } = usePricing();
const { canStartTrial } = useCurrentPlan();
const guestUpgradeStore = useGuestUpgradeStore();

const showUpgrade = ref(false);
const upgrading = ref(false);

const isGuestUser = computed(() => !!authStore.user?.is_guest);

function openGuestUpgradeModal(featureLabelKey: string): void {
  guestUpgradeStore.openGuestUpgradeModal(featureLabelKey);
}

async function upgradePlan(plan: string) {
  upgrading.value = true;
  try {
    const response = await api.post('/subscriptions/checkout', { plan });
    if (response.data.checkoutUrl) {
      window.location.href = response.data.checkoutUrl;
    } else {
      alert(t('account.checkout_failed'));
      upgrading.value = false;
    }
  } catch (error: unknown) {
    const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
    console.error('Failed to create checkout:', error);
    alert(message || t('account.checkout_failed'));
    upgrading.value = false;
  }
}

onMounted(() => {
  void loadPlanFeatures();
  void loadPricing();
});
</script>
