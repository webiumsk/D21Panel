<template>
  <section class="rounded-lg border border-indigo-200 bg-indigo-50/50 p-3 text-sm space-y-2">
    <h3 class="font-medium text-indigo-900 text-xs uppercase tracking-wide">
      {{ t('invoicing.efaktura_panel_title') }}
    </h3>

    <p v-if="!configured" class="text-xs text-gray-700">
      {{ t('invoicing.efaktura_panel_not_configured') }}
      <RouterLink v-if="settingsTo" :to="settingsTo" class="text-indigo-700 hover:text-indigo-900 underline">
        {{ t('invoicing.efaktura_panel_configure_link') }}
      </RouterLink>
    </p>

    <p v-else-if="!eligibleContact" class="text-xs text-gray-600">
      {{ t('invoicing.efaktura_panel_not_eligible') }}
    </p>

    <template v-else>
      <div v-if="loading" class="text-xs text-gray-500">
        {{ t('common.loading') }}
      </div>

      <div v-else class="space-y-1">
        <div v-if="peppolRow" class="flex items-center gap-2">
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass">
            {{ statusLabel }}
          </span>
        </div>
        <p v-else class="text-xs text-gray-600">
          {{ t('invoicing.efaktura_panel_not_sent') }}
        </p>

        <p v-if="peppolRow?.external_id" class="text-xs text-gray-600 font-mono break-all">
          {{ t('invoicing.efaktura_panel_external_id') }}: {{ peppolRow.external_id }}
        </p>

        <p v-if="peppolRow?.submitted_at" class="text-xs text-gray-500">
          {{ t('invoicing.efaktura_panel_submitted_at', { date: formatDate(peppolRow.submitted_at) }) }}
        </p>

        <p v-if="storedDetailMessage" class="text-xs text-red-700 whitespace-pre-wrap">
          {{ storedDetailMessage }}
        </p>
      </div>

      <!-- Preflight: the send would fail server-side without a resolvable
           buyer Peppol ID - explain it upfront instead of a failed submit. -->
      <p v-if="preflightMissingIds" class="text-xs text-amber-600">
        {{ t('invoicing.efaktura_preflight_missing_ids') }}
      </p>

      <div class="flex flex-col gap-2">
        <button
          v-if="canSend && !preflightMissingIds"
          type="button"
          class="w-full invoicing-btn-secondary text-sm"
          :disabled="busy"
          @click="send"
        >
          {{ sendButtonLabel }}
        </button>
        <button
          v-if="canRefreshStatus"
          type="button"
          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-white bg-white/80"
          :disabled="busy"
          @click="refreshStatus"
        >
          {{ busy ? t('invoicing.efaktura_panel_refreshing') : t('invoicing.efaktura_panel_refresh_status') }}
        </button>
      </div>

      <p v-if="panelError" class="text-xs text-red-600">{{ panelError }}</p>
      <p v-if="panelSuccess" class="text-xs text-emerald-700">{{ panelSuccess }}</p>
    </template>
  </section>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  isEfakturaConfigured,
  isSkDomesticContact,
} from '../../composables/useCompanyEfakturaSettings';
import { resolvePeppolEndpoint } from '../../utils/peppolEndpoint';
import {
  fetchEphemeralEfakturaBridge,
  fetchEphemeralEfakturaStatus,
  refreshEphemeralEfakturaStatus,
  sendEphemeralEfaktura,
  type EphemeralSnapshotPayload,
} from '../../evolu/ephemeralBridge';
import { invoicingApi } from '../../services/api';

type ComplianceRow = {
  id: string;
  provider: string;
  status: string;
  external_id: string | null;
  submitted_at: string | null;
  response_payload?: Record<string, unknown> | null;
};

const props = defineProps<{
  companyId: string;
  documentId: string;
  company: Record<string, unknown> | null;
  selectedContact: Record<string, unknown> | null;
  localFirst?: boolean;
  ephemeralSnapshot?: EphemeralSnapshotPayload | null;
  evoluDocumentId?: string;
  bridgeCompanyId?: string | null;
}>();

const emit = defineEmits<{
  sent: [];
}>();

const { t, locale } = useI18n();

const loading = ref(false);
const busy = ref(false);
const panelError = ref('');
const panelSuccess = ref('');
const rows = ref<ComplianceRow[]>([]);
const resolvedBridgeCompanyId = ref<string | null>(null);

const configured = computed(() => isEfakturaConfigured(props.company));

// Server backstop stays (gateway fails with a clear message), but the
// missing-IDs case is fully detectable client-side before the round-trip.
const preflightMissingIds = computed(
  () =>
    props.selectedContact !== null
    && resolvePeppolEndpoint({
      peppol_participant_id: props.selectedContact.peppol_participant_id as string | null,
      tax_id: props.selectedContact.tax_id as string | null,
      registration_number: props.selectedContact.registration_number as string | null,
      country: props.selectedContact.country as string | null,
    }) === null,
);

const effectiveBridgeCompanyId = computed(
  () => props.bridgeCompanyId ?? resolvedBridgeCompanyId.value,
);

function setLocalFirstBridgeError(): void {
  panelError.value = t('invoicing.efaktura_panel_bridge_not_configured');
}

function localFirstBridgeReady(requireSnapshot = false): boolean {
  if (!props.localFirst) {
    return true;
  }
  if (!props.evoluDocumentId) {
    setLocalFirstBridgeError();
    return false;
  }
  if (requireSnapshot && !props.ephemeralSnapshot) {
    panelError.value = t('common.error_generic');
    return false;
  }
  if (!effectiveBridgeCompanyId.value) {
    setLocalFirstBridgeError();
    return false;
  }
  return true;
}

const settingsTo = computed(() => {
  const companyId = props.companyId;

  if (!companyId) {
    return null;
  }

  return {
    name: 'invoicing-company',
    params: { companyId },
  };
});

const peppolRow = computed(() => rows.value.find((row) => row.provider === 'peppol') ?? rows.value[0] ?? null);

const statusLabel = computed(() => {
  const status = peppolRow.value?.status ?? 'pending';
  const key = `invoicing.efaktura_panel_status_${status}`;
  const translated = t(key);
  return translated === key ? status : translated;
});

const statusBadgeClass = computed(() => {
  switch (peppolRow.value?.status) {
    case 'approved':
      return 'bg-emerald-100 text-emerald-800';
    case 'submitted':
      return 'bg-blue-100 text-blue-800';
    case 'failed':
    case 'rejected':
      return 'bg-red-100 text-red-800';
    case 'skipped':
      return 'bg-gray-100 text-gray-700';
    default:
      return 'bg-amber-100 text-amber-900';
  }
});

const canRefreshStatus = computed(() => {
  if (!configured.value || !eligibleContact.value || !peppolRow.value) {
    return false;
  }

  return peppolRow.value.status === 'submitted';
});

const canSend = computed(() => {
  if (!configured.value || !eligibleContact.value) {
    return false;
  }

  const status = peppolRow.value?.status;
  if (!status) {
    return true;
  }

  return ['pending', 'failed', 'rejected', 'skipped'].includes(status);
});

const sendButtonLabel = computed(() => {
  if (busy.value) {
    return t('invoicing.efaktura_panel_sending');
  }

  return peppolRow.value
    ? t('invoicing.efaktura_panel_resend')
    : t('invoicing.efaktura_panel_send');
});

const storedDetailMessage = computed(() => complianceDetailMessage(peppolRow.value));

const eligibleContact = computed(() => isSkDomesticContact(props.selectedContact));

watch(
  () => [props.documentId, props.companyId, props.localFirst, props.evoluDocumentId] as const,
  () => {
    panelError.value = '';
    panelSuccess.value = '';
    void bootstrap();
  },
  { immediate: true },
);

async function bootstrap() {
  if (props.localFirst) {
    try {
      const bridge = await fetchEphemeralEfakturaBridge();
      resolvedBridgeCompanyId.value = bridge.bridge_company_id;
    } catch {
      resolvedBridgeCompanyId.value = null;
    }
  }

  await loadCompliance();
}

async function loadCompliance() {
  if (!props.documentId || !configured.value) {
    rows.value = [];
    return;
  }

  loading.value = true;
  try {
    if (props.localFirst) {
      if (!props.evoluDocumentId) {
        rows.value = [];
        return;
      }
      rows.value = await fetchEphemeralEfakturaStatus(props.evoluDocumentId);
      return;
    }

    rows.value = await invoicingApi.documents.efaktura.compliance<ComplianceRow>(props.companyId, props.documentId);
  } catch {
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function refreshStatus() {
  if (!props.documentId || busy.value) {
    return;
  }

  busy.value = true;
  panelError.value = '';
  panelSuccess.value = '';

  try {
    if (props.localFirst) {
      if (!localFirstBridgeReady()) {
        return;
      }
      rows.value = await refreshEphemeralEfakturaStatus(
        props.evoluDocumentId!,
        effectiveBridgeCompanyId.value,
      );
    } else {
      await invoicingApi.documents.efaktura.refresh(props.companyId, props.documentId);
      await loadCompliance();
    }
    panelSuccess.value = t('invoicing.efaktura_panel_refresh_success');
  } catch (rawError) {
    const e = asApiError(rawError);
    panelError.value = extractError(e);
  } finally {
    busy.value = false;
  }
}

async function send() {
  if (!props.documentId || busy.value) {
    return;
  }

  busy.value = true;
  panelError.value = '';
  panelSuccess.value = '';

  try {
    if (props.localFirst) {
      if (!localFirstBridgeReady(true)) {
        return;
      }
      const data = await sendEphemeralEfaktura(
        props.ephemeralSnapshot!,
        props.evoluDocumentId!,
        effectiveBridgeCompanyId.value,
      );
      panelSuccess.value = t('invoicing.efaktura_panel_send_success');
      if (typeof data.message === 'string' && data.message) {
        panelSuccess.value = data.message;
      }
      rows.value = data ? [data] : [];
    } else {
      const data = await invoicingApi.documents.efaktura.send<{ message?: string }>(props.companyId, props.documentId);
      panelSuccess.value = t('invoicing.efaktura_panel_send_success');
      if (typeof data.message === 'string' && data.message) {
        panelSuccess.value = data.message;
      }
      await loadCompliance();
    }
    emit('sent');
  } catch (rawError) {
    const e = asApiError(rawError);
    panelError.value = extractError(e);
    await loadCompliance();
  } finally {
    busy.value = false;
  }
}

function complianceDetailMessage(row: ComplianceRow | null): string {
  if (!row || !['failed', 'rejected', 'skipped'].includes(row.status)) {
    return '';
  }

  const payload = row.response_payload;
  if (payload && typeof payload === 'object') {
    for (const key of ['message', 'error', 'detail']) {
      const value = payload[key];
      if (typeof value === 'string' && value.trim() !== '') {
        return value;
      }
    }
  }

  return '';
}

function extractError(rawError: unknown) {
  const e = asApiError(rawError);
  const errors = e.response?.data?.errors;
  if (errors && typeof errors === 'object') {
    for (const value of Object.values(errors)) {
      if (Array.isArray(value) && typeof value[0] === 'string') {
        return value[0];
      }
    }
  }

  return e.response?.data?.message || t('common.error');
}

function formatDate(iso: string) {
  const d = new Date(iso.includes('T') ? iso : `${iso}T12:00:00`);
  return d.toLocaleString(locale.value);
}
</script>
