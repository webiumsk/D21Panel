<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
    role="dialog"
    aria-modal="true"
    @click.self="$emit('close')"
  >
    <div class="bg-white rounded-lg shadow-xl w-full max-w-xl p-6 relative max-h-[90vh] overflow-y-auto">
      <button
        type="button"
        class="absolute top-3 right-3 text-gray-400 hover:text-gray-700 text-2xl leading-none"
        :title="t('common.close')"
        @click="$emit('close')"
      >
        ×
      </button>

      <h3 class="text-lg font-semibold text-gray-900 pr-8">{{ t('invoicing.company_slots_title') }}</h3>
      <p class="mt-3 text-sm text-gray-600 leading-relaxed">
        {{ t('invoicing.company_slots_body', { included: includedCompanies, extra: extraSlots }) }}
      </p>

      <div v-if="purchased" class="mt-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3">
        <p class="text-sm font-medium text-green-900">{{ t('invoicing.company_slots_success') }}</p>
      </div>

      <div v-else-if="packs.length" class="mt-5 space-y-3">
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="pack in packs"
            :key="pack.slots"
            type="button"
            class="rounded-lg border border-gray-200 p-3 text-center hover:border-indigo-400 hover:bg-indigo-50 transition-colors"
            :disabled="purchasing"
            @click="purchase(pack.slots)"
          >
            <span class="block text-lg font-semibold text-gray-900">{{ pack.slots }}</span>
            <span class="block text-xs text-gray-500">{{ t('invoicing.company_slots_unit', pack.slots) }}</span>
            <span class="block text-sm font-medium text-indigo-700 mt-1">{{ formatSats(pack.sats) }}</span>
          </button>
        </div>
        <p v-if="purchaseError" class="text-sm text-red-600">{{ purchaseError }}</p>
        <p v-if="waitingForPayment" class="text-xs text-gray-600">
          {{ t('invoicing.company_slots_waiting') }}
        </p>
        <p class="text-xs text-gray-500">{{ t('invoicing.company_slots_pay_btcpay') }}</p>
        <p class="text-xs text-gray-500">{{ t('invoicing.company_slots_fine_print') }}</p>
      </div>

      <p v-else class="mt-5 text-sm text-gray-600">
        {{ t('invoicing.company_slots_unavailable') }}
      </p>

      <div class="mt-6 flex justify-end">
        <button type="button" class="invoicing-btn-secondary" @click="$emit('close')">
          {{ t('common.close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { invoicingApi } from '@/services/api';
import { usePricing } from '@/composables/usePricing';
import { useAuthStore } from '@/store/auth';
import { asApiError } from '@/utils/apiError';

const props = defineProps<{
  open: boolean;
}>();

const emit = defineEmits<{
  close: [];
  purchased: [];
}>();

const { t } = useI18n();
const { pricing, formatSats, load } = usePricing();
const authStore = useAuthStore();

const purchasing = ref(false);
const purchaseError = ref('');
const waitingForPayment = ref(false);
const purchased = ref(false);
let pollTimer: ReturnType<typeof setInterval> | null = null;
let baselineMax: number | null = null;

const packs = computed(() => pricing.value.company_slot_packs);
const includedCompanies = computed(() => authStore.user?.plan?.included_companies ?? 0);
const extraSlots = computed(() => authStore.user?.plan?.extra_company_slots ?? 0);

watch(
  () => props.open,
  (open) => {
    if (open) {
      purchased.value = false;
      purchaseError.value = '';
      waitingForPayment.value = false;
      void load();
    } else {
      stopPolling();
    }
  },
);

async function purchase(slots: number): Promise<void> {
  purchasing.value = true;
  purchaseError.value = '';
  // Open the tab synchronously within the user activation so popup blockers
  // allow it (noopener would return null, so the opener is cleared manually);
  // the checkout URL is assigned once the API call resolves.
  const checkoutWindow = window.open('', '_blank');
  if (checkoutWindow) checkoutWindow.opener = null;
  try {
    const result = await invoicingApi.companySlots.purchase<{ checkoutLink?: string }>(slots);
    if (result?.checkoutLink) {
      baselineMax = authStore.user?.plan?.max_companies ?? null;
      if (checkoutWindow) {
        checkoutWindow.location.href = result.checkoutLink;
      } else {
        window.open(result.checkoutLink, '_blank', 'noopener');
      }
      startPolling();
    } else {
      checkoutWindow?.close();
    }
  } catch (rawError) {
    checkoutWindow?.close();
    const e = asApiError(rawError);
    let message = '';
    const fieldErrors = e.response?.data?.errors;
    if (fieldErrors && typeof fieldErrors === 'object') {
      for (const messages of Object.values(fieldErrors)) {
        if (Array.isArray(messages) && messages[0]) {
          message = String(messages[0]);
          break;
        }
        if (typeof messages === 'string' && messages) {
          message = messages;
          break;
        }
      }
    }
    purchaseError.value = message || e.response?.data?.message || t('common.error');
  } finally {
    purchasing.value = false;
  }
}

function startPolling(): void {
  stopPolling();
  waitingForPayment.value = true;
  pollTimer = setInterval(async () => {
    try {
      await authStore.fetchUser();
    } catch {
      return;
    }
    const max = authStore.user?.plan?.max_companies ?? null;
    if (typeof max === 'number' && baselineMax !== null && max > baselineMax) {
      purchased.value = true;
      stopPolling();
      emit('purchased');
    }
  }, 5000);
}

function stopPolling(): void {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
  waitingForPayment.value = false;
}

onBeforeUnmount(stopPolling);
</script>
