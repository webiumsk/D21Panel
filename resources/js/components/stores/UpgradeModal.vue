<template>
  <div
    v-if="show"
    class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50"
    @click.self="close"
  >
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
      <div class="mt-3">
        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-medium text-gray-900">
            {{ t('upgrade_modal.title') }}
          </h3>
          <button
            @click="close"
            class="text-gray-400 hover:text-gray-500"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="mb-6">
          <div class="flex items-center justify-center w-12 h-12 mx-auto mb-4 bg-yellow-100 rounded-full">
            <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          
          <p class="text-center text-gray-700 mb-4">
            {{ message || t('upgrade_modal.default_message') }}
          </p>

          <div v-if="limits.length > 0" class="bg-gray-50 rounded-lg p-4 mb-4">
            <p class="text-sm text-gray-600 mb-2">
              <strong>{{ t('upgrade_modal.current_limits') }}</strong>
            </p>
            <ul class="text-sm text-gray-600 space-y-1">
              <li v-for="limit in limits" :key="limit.feature" class="flex justify-between">
                <span>{{ limitLabel(limit) }}:</span>
                <span class="font-medium">{{ limit.current }}/{{ limit.max === null ? t('upgrade_modal.unlimited') : limit.max }}</span>
              </li>
            </ul>
          </div>

          <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mb-4">
            <p class="text-sm font-medium text-indigo-900 mb-2">
              {{ t('upgrade_modal.unlock_more') }}
            </p>
            <ul class="text-sm text-indigo-700 space-y-1">
              <li
                v-for="item in benefitItems"
                :key="item.key"
                class="flex items-start"
                :class="item.highlight ? 'font-medium text-indigo-900' : ''"
              >
                <svg class="w-4 h-4 text-indigo-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                {{ item.label }}
              </li>
            </ul>
          </div>

          <p
            v-if="recommendedPlan === 'pro' && proTrialHint"
            class="text-center text-sm text-gray-600 mb-4"
          >
            {{ proTrialHint }}
          </p>
        </div>

        <!-- Actions -->
        <div class="flex flex-col space-y-2">
          <button
            @click="handleUpgrade"
            :disabled="upgrading"
            class="w-full px-4 py-2 bg-indigo-600 text-white font-medium rounded-md hover:bg-indigo-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ upgrading ? t('upgrade_modal.processing') : (upgradeButtonText || t('upgrade_modal.upgrade_now')) }}
          </button>
          
          <button
            @click="close"
            :disabled="upgrading"
            class="w-full px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50 transition-colors disabled:opacity-50"
          >
            {{ t('upgrade_modal.maybe_later') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../../services/api';
import { useFlashStore } from '../../store/flash';
import { usePlanFeatures } from '../../composables/usePlanFeatures';
import { usePricing } from '../../composables/usePricing';
import { useCurrentPlan } from '../../composables/useCurrentPlan';

const { t } = useI18n();
const { planFeatures, isInvoicingFeature, load: loadPlanFeatures } = usePlanFeatures();
const { pricing, formatSats, load: loadPricing } = usePricing();
const { canStartTrial } = useCurrentPlan();

interface LimitRow {
  feature: string;
  current: number;
  max: number | null;
}

interface Props {
  show: boolean;
  message?: string;
  limits?: LimitRow[];
  recommendedPlan?: 'pro' | 'enterprise';
  upgradeButtonText?: string;
}

const props = withDefaults(defineProps<Props>(), {
  message: '',
  limits: () => [],
  recommendedPlan: 'pro',
  upgradeButtonText: '',
});

const emit = defineEmits<{
  close: [];
}>();

const upgrading = ref(false);
const flashStore = useFlashStore();

onMounted(() => {
  loadPlanFeatures();
  loadPricing();
});

function limitLabel(limit: LimitRow): string {
  const key = `upgrade_modal.limits.${limit.feature}`;
  const translated = t(key);
  return translated !== key ? translated : limit.feature;
}

const benefitItems = computed(() => {
  const plan = props.recommendedPlan === 'enterprise'
    ? planFeatures.value.enterprise
    : planFeatures.value.pro;
  return plan.feature_keys.map((key: string) => ({
    key,
    label: t('plans.features.' + key),
    highlight: props.recommendedPlan === 'pro' && isInvoicingFeature(key),
  }));
});

const proTrialHint = computed(() => {
  if (props.recommendedPlan !== 'pro') return '';
  if (!canStartTrial.value) return '';
  const p = pricing.value.pro;
  return t('upgrade_modal.pro_trial_hint', {
    days: pricing.value.trial_days,
    yearly: formatSats(p.sats_per_year),
  });
});

function close() {
  emit('close');
}

async function handleUpgrade() {
  upgrading.value = true;
  flashStore.clear();
  try {
    const response = await api.post('/subscriptions/checkout', {
      plan: props.recommendedPlan,
    });

    if (response.data.checkoutUrl) {
      window.location.href = response.data.checkoutUrl;
    } else {
      flashStore.error(t('upgrade_modal.checkout_error'));
      upgrading.value = false;
    }
  } catch (rawError) {
    const err = asApiError(rawError);
    flashStore.error(err.response?.data?.message || t('upgrade_modal.checkout_error'));
    upgrading.value = false;
  }
}
</script>

