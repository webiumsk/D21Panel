<template>
  <section
    v-if="showPanel"
    class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50/60 p-4"
    data-testid="efaktura-inbox-panel"
  >
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm font-medium text-indigo-950">
        {{ t('invoicing.efaktura_inbox_title') }}
        <span v-if="items.length" class="ml-1 text-xs font-normal text-indigo-800">({{ items.length }})</span>
      </p>
      <button
        type="button"
        class="invoicing-btn-secondary shrink-0 text-sm"
        :disabled="loading"
        @click="refresh"
      >
        {{ t('invoicing.efaktura_inbox_refresh') }}
      </button>
    </div>

    <p v-if="error" class="mt-3 text-sm text-red-700">{{ error }}</p>
    <p v-if="notice" class="mt-3 text-xs text-amber-800">{{ notice }}</p>

    <ul v-if="items.length" class="mt-3 space-y-2">
      <li
        v-for="item in items"
        :key="item.inbox_id"
        class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-indigo-100 bg-white px-3 py-2"
      >
        <div class="min-w-0">
          <p class="text-sm font-medium text-gray-900 truncate">
            {{ item.summary.supplier_name || t('invoicing.efaktura_inbox_unknown_supplier') }}
            <span v-if="isSelfBilledInboxEntry(item)" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 align-middle">
              {{ t('invoicing.efaktura_inbox_self_billed_badge') }}
            </span>
          </p>
          <p class="text-xs text-gray-600 mt-0.5">
            <span v-if="item.summary.external_number">{{ item.summary.external_number }} · </span>
            <span v-if="item.summary.total">{{ item.summary.total }} {{ item.summary.currency }}</span>
            <span v-if="item.created_at"> · {{ formatDate(item.created_at) }}</span>
          </p>
        </div>
        <div class="flex shrink-0 gap-2">
          <button
            type="button"
            class="invoicing-btn-secondary"
            :disabled="busyId === item.inbox_id"
            @click="dismissItem(item)"
          >
            {{ t('invoicing.efaktura_inbox_dismiss') }}
          </button>
          <span v-if="isSelfBilledInboxEntry(item)" class="self-center text-xs text-amber-700 max-w-[16rem]">
            {{ t('invoicing.efaktura_inbox_self_billed_note') }}
          </span>
          <button
            v-else
            type="button"
            class="invoicing-btn-primary"
            :disabled="busyId === item.inbox_id"
            @click="importItem(item)"
          >
            {{ t('invoicing.efaktura_inbox_import') }}
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { asApiError } from '../../utils/apiError';
import { useInvoicingEvolu } from '@/evolu/client';
import {
  dismissEfakturaInboxItem,
  importEfakturaInboxEntry,
  isSelfBilledInboxEntry,
  type EfakturaInboxEntry,
} from '@/evolu/efakturaInboxImport';
import { refreshEfakturaInboxLive, useEfakturaInboxLive } from '@/evolu/efakturaInboxLive';

/**
 * Received e-invoices waiting on the bridge company for this local-first
 * company. Renders only while there is something to act on (or an error to
 * show) - the live loop in efakturaInboxLive.ts keeps `items` fresh.
 */
const props = defineProps<{
  companyId: string;
}>();

const emit = defineEmits<{
  imported: [];
}>();

const { t, locale } = useI18n();
const evolu = useInvoicingEvolu();
const { entries: items, bridgeCompanyId } = useEfakturaInboxLive();

const loading = ref(false);
const error = ref('');
const notice = ref('');
const busyId = ref<string | null>(null);

const showPanel = computed(() => items.value.length > 0 || error.value !== '' || notice.value !== '');

function formatDate(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString(locale.value);
}

function describeError(rawError: unknown): string {
  const e = asApiError(rawError);
  return e?.response?.data?.message ?? (rawError instanceof Error ? rawError.message : t('common.error_generic'));
}

async function refresh(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await refreshEfakturaInboxLive(true, { throwOnError: true });
  } catch (rawError) {
    error.value = describeError(rawError);
  } finally {
    loading.value = false;
  }
}

async function importItem(item: EfakturaInboxEntry): Promise<void> {
  if (!bridgeCompanyId.value) return;
  busyId.value = item.inbox_id;
  error.value = '';
  notice.value = '';
  try {
    const result = await importEfakturaInboxEntry(evolu, props.companyId, bridgeCompanyId.value, item);
    if (!result.ok) {
      error.value = t(`invoicing.efaktura_inbox_error_${result.error}`);
      return;
    }
    if (result.attachmentSkipped) {
      notice.value = t('invoicing.efaktura_inbox_attachment_skipped');
    }
    items.value = items.value.filter((row) => row.inbox_id !== item.inbox_id);
    emit('imported');
  } catch (rawError) {
    error.value = describeError(rawError);
  } finally {
    busyId.value = null;
  }
}

async function dismissItem(item: EfakturaInboxEntry): Promise<void> {
  if (!bridgeCompanyId.value) return;
  busyId.value = item.inbox_id;
  error.value = '';
  try {
    await dismissEfakturaInboxItem(bridgeCompanyId.value, item.inbox_id);
    items.value = items.value.filter((row) => row.inbox_id !== item.inbox_id);
  } catch (rawError) {
    error.value = describeError(rawError);
  } finally {
    busyId.value = null;
  }
}
</script>
