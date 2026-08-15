<template>
  <form class="space-y-6 max-w-2xl" @submit.prevent="save">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50/40 p-4 text-sm text-gray-700 space-y-2">
      <p>{{ t('invoicing.efaktura_intro') }}</p>
      <p class="text-xs text-gray-600">{{ t('invoicing.efaktura_cpds_note') }}</p>
    </div>

    <label class="flex items-start gap-2 text-sm text-gray-800">
      <input
        v-model="form.efaktura_enabled"
        type="checkbox"
        class="mt-0.5 rounded border-gray-300 text-indigo-600"
      />
      <span>{{ t('invoicing.efaktura_enabled') }}</span>
    </label>

    <template v-if="form.efaktura_enabled">
      <!-- Step 1: choose the CPDS (digitalny postar) -->
      <section class="rounded-lg border border-gray-200 p-4 space-y-3">
        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
          <span :class="stepBadgeClass(step1Done)">{{ step1Done ? '✓' : '1' }}</span>
          {{ t('invoicing.efaktura_step1_title') }}
        </h3>
        <i18n-t keypath="invoicing.efaktura_step1_portal" tag="p" class="text-xs text-gray-600">
          <template #portal>
            <a
              href="https://www.financnasprava.sk"
              target="_blank"
              rel="noopener noreferrer"
              class="text-indigo-600 hover:text-indigo-500 underline"
            >www.financnasprava.sk</a>
          </template>
        </i18n-t>

        <div v-if="cpdsPresets.length > 0">
          <label for="efaktura-cpds-preset" class="invoicing-sf-label">{{ t('invoicing.efaktura_cpds_preset_label') }}</label>
          <select id="efaktura-cpds-preset" v-model="selectedPresetId" class="invoicing-sf-input" @change="applyPreset">
            <option value="">{{ t('invoicing.efaktura_cpds_preset_choose') }}</option>
            <option v-for="preset in cpdsPresets" :key="preset.id" :value="preset.id">{{ preset.name }}</option>
            <option value="custom">{{ t('invoicing.efaktura_cpds_preset_other') }}</option>
          </select>
        </div>

        <div v-if="cpdsPresets.length === 0 || selectedPresetId === 'custom'">
          <label for="efaktura-base-url" class="invoicing-sf-label">{{ t('invoicing.efaktura_sapi_base_url') }}</label>
          <input
            id="efaktura-base-url"
            v-model="form.efaktura_sapi_base_url"
            type="url"
            class="invoicing-sf-input font-mono text-sm"
            :placeholder="t('invoicing.efaktura_sapi_base_url_placeholder')"
            required
            @input="invalidateConnectionTest"
          />
          <p class="text-xs text-gray-500 mt-1">{{ t('invoicing.efaktura_sapi_base_url_hint') }}</p>
        </div>
        <p v-else-if="form.efaktura_sapi_base_url" class="text-xs text-gray-500 font-mono">
          {{ form.efaktura_sapi_base_url }}
        </p>
      </section>

      <!-- Step 2: credentials + connection test -->
      <section class="rounded-lg border border-gray-200 p-4 space-y-3">
        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
          <span :class="stepBadgeClass(step2Done)">{{ step2Done ? '✓' : '2' }}</span>
          {{ t('invoicing.efaktura_step2_title') }}
        </h3>

        <div>
          <label for="efaktura-client-id" class="invoicing-sf-label">{{ t('invoicing.efaktura_sapi_client_id') }}</label>
          <input
            id="efaktura-client-id"
            v-model="form.efaktura_sapi_client_id"
            type="text"
            class="invoicing-sf-input font-mono text-sm"
            autocomplete="off"
            required
            @input="invalidateConnectionTest"
          />
        </div>

        <div>
          <label for="efaktura-client-secret" class="invoicing-sf-label">{{ t('invoicing.efaktura_sapi_client_secret') }}</label>
          <input
            id="efaktura-client-secret"
            v-model="form.efaktura_sapi_client_secret"
            type="password"
            class="invoicing-sf-input font-mono text-sm"
            autocomplete="new-password"
            :placeholder="secretSet ? t('invoicing.efaktura_sapi_client_secret_placeholder_set') : t('invoicing.efaktura_sapi_client_secret_placeholder')"
            @input="invalidateConnectionTest"
          />
          <p v-if="secretSet && !form.efaktura_sapi_client_secret" class="text-xs text-emerald-700 mt-1">
            {{ t('invoicing.efaktura_sapi_client_secret_saved') }}
          </p>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            class="invoicing-btn-secondary text-sm"
            :disabled="testingConnection || saving"
            @click="testConnection"
          >
            {{ testingConnection ? t('invoicing.efaktura_testing_connection') : t('invoicing.efaktura_test_connection') }}
          </button>
          <p v-if="testMessage" class="text-xs" :class="testFailed ? 'text-red-600' : 'text-emerald-700'">
            {{ testMessage }}
          </p>
        </div>
        <p v-if="!testMessage && form.efaktura_connection_tested_at" class="text-xs text-gray-500">
          {{ t('invoicing.efaktura_connection_tested_at', { date: formatPollDate(form.efaktura_connection_tested_at) }) }}
        </p>
      </section>

      <!-- Step 3: options -->
      <section class="rounded-lg border border-gray-200 p-4 space-y-3">
        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
          <span :class="stepBadgeClass(false)">3</span>
          {{ t('invoicing.efaktura_step3_title') }}
        </h3>

        <div class="space-y-2">
          <label class="flex items-start gap-2 text-sm text-gray-700">
            <input
              v-model="form.efaktura_auto_send"
              type="checkbox"
              class="mt-0.5 rounded border-gray-300 text-indigo-600"
            />
            <span>{{ t('invoicing.efaktura_auto_send') }}</span>
          </label>
          <label class="flex items-start gap-2 text-sm text-gray-700">
            <input
              v-model="form.efaktura_inbound_enabled"
              type="checkbox"
              class="mt-0.5 rounded border-gray-300 text-indigo-600"
            />
            <span>{{ t('invoicing.efaktura_inbound_enabled') }}</span>
          </label>
          <p class="text-xs text-gray-500">{{ t('invoicing.efaktura_inbound_hint') }}</p>

          <div v-if="form.efaktura_inbound_enabled" class="rounded-lg border border-gray-200 bg-white p-3 space-y-2">
            <p v-if="form.efaktura_inbound_last_poll_at" class="text-xs text-gray-600">
              {{ t('invoicing.efaktura_inbound_last_poll', { date: formatPollDate(form.efaktura_inbound_last_poll_at) }) }}
            </p>
            <p v-else class="text-xs text-gray-500">{{ t('invoicing.efaktura_inbound_never_polled') }}</p>
            <p
              v-if="form.efaktura_inbound_last_poll_stats"
              class="text-xs text-gray-600"
            >
              {{
                t('invoicing.efaktura_inbound_last_poll_stats', {
                  imported: form.efaktura_inbound_last_poll_stats.imported,
                  acknowledged: form.efaktura_inbound_last_poll_stats.acknowledged,
                  skipped: form.efaktura_inbound_last_poll_stats.skipped,
                  failed: form.efaktura_inbound_last_poll_stats.failed,
                })
              }}
            </p>
            <button
              type="button"
              class="invoicing-btn-secondary text-sm"
              :disabled="pollingInbound || saving"
              @click="pollInboundNow"
            >
              {{ pollingInbound ? t('invoicing.efaktura_inbound_polling') : t('invoicing.efaktura_inbound_poll_now') }}
            </button>
            <p v-if="pollMessage" class="text-xs" :class="pollError ? 'text-red-600' : 'text-emerald-700'">
              {{ pollMessage }}
            </p>
          </div>
        </div>

        <!-- Advanced: explicit Peppol ID override; the DIC/ICO derivation
             covers the normal case, so merchants never see the syntax. -->
        <details class="pt-1">
          <summary class="text-xs text-gray-600 cursor-pointer select-none">
            {{ t('invoicing.efaktura_advanced') }}
          </summary>
          <div class="mt-2">
            <label for="efaktura-peppol-id" class="invoicing-sf-label">{{ t('invoicing.efaktura_peppol_participant_id') }}</label>
            <input
              id="efaktura-peppol-id"
              v-model="form.efaktura_peppol_participant_id"
              type="text"
              class="invoicing-sf-input font-mono text-sm"
              :placeholder="derivedParticipantId ?? t('invoicing.efaktura_peppol_participant_id_placeholder')"
            />
            <p v-if="derivedParticipantId && !form.efaktura_peppol_participant_id" class="text-xs text-emerald-700 mt-1">
              {{ t('invoicing.efaktura_peppol_derived_hint', { id: derivedParticipantId }) }}
            </p>
            <p v-else class="text-xs text-gray-500 mt-1">{{ t('invoicing.efaktura_peppol_participant_id_hint') }}</p>
          </div>
        </details>
      </section>
    </template>

    <div class="pt-2 border-t border-gray-100">
      <button type="submit" class="invoicing-btn-primary" :disabled="saving">
        {{ t('invoicing.save_my_details') }}
      </button>
      <p class="text-xs text-gray-500 mt-2">{{ t('invoicing.profile_save_note') }}</p>
      <p v-if="saveError" class="text-sm text-red-600 mt-2">{{ saveError }}</p>
    </div>
  </form>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { EfakturaInboundPollStats } from '../../composables/useCompanyEfakturaSettings';
import { asCompanyId } from '../../composables/useInvoicingCompany';
import { useEfakturaFeature } from '../../composables/useEfakturaFeature';
import { allCompaniesDetailQuery, useInvoicingEvolu } from '../../evolu/client';
import { evoluCompanyToApi, type EvoluCompanyRow } from '../../evolu/companyMap';
import { updateLocalEfakturaSettings } from '../../evolu/companySettingsCrud';
import { isInvoicingLocalFirst } from '../../evolu/flags';
import { invoicingApi } from '../../services/api';
import { useStoresStore } from '../../store/stores';
import { useInvoicingSaveFeedback } from '../../composables/useInvoicingSaveFeedback';
import { formatPeppolEndpoint, resolvePeppolEndpoint } from '../../utils/peppolEndpoint';
import {
  efakturaSecretIsSet,
  efakturaSettingsFromCompany,
  type CompanyEfakturaSettingsState,
} from '../../composables/useCompanyEfakturaSettings';

const props = defineProps<{
  companyId: string;
  company: Record<string, any> | null;
}>();

const emit = defineEmits<{
  updated: [company: Record<string, any>];
}>();

const { t, locale } = useI18n();
const { notifySaved } = useInvoicingSaveFeedback();
const localFirst = isInvoicingLocalFirst();
const evolu = localFirst ? useInvoicingEvolu() : null;
const storesStore = useStoresStore();
const efakturaFeature = useEfakturaFeature();
const cpdsPresets = efakturaFeature.cpdsPresets;
const saving = ref(false);
const saveError = ref('');
const secretSet = ref(false);
const pollingInbound = ref(false);
const pollMessage = ref('');
const pollError = ref(false);
const testingConnection = ref(false);
const testMessage = ref('');
const testFailed = ref(false);
const selectedPresetId = ref('');
const form = reactive<CompanyEfakturaSettingsState>(efakturaSettingsFromCompany(null));

const step1Done = computed(() => form.efaktura_sapi_base_url.trim() !== '');
const step2Done = computed(
  () =>
    form.efaktura_sapi_client_id.trim() !== ''
    && (secretSet.value || form.efaktura_sapi_client_secret !== '')
    && form.efaktura_connection_tested_at !== null,
);

/** Server payload carries the derived ID; local-first derives client-side. */
const derivedParticipantId = computed(() => {
  const raw = (props.company?.app_settings ?? {}) as Record<string, unknown>;
  const fromServer = raw.efaktura_peppol_participant_id_derived;
  if (typeof fromServer === 'string' && fromServer !== '') {
    return fromServer;
  }

  return formatPeppolEndpoint(
    resolvePeppolEndpoint({
      tax_id: props.company?.tax_id as string | null,
      registration_number: props.company?.registration_number as string | null,
      country: props.company?.country as string | null,
      jurisdiction: props.company?.jurisdiction as string | null,
    }),
  );
});

watch(
  () => props.company,
  (company) => {
    Object.assign(form, efakturaSettingsFromCompany(company));
    secretSet.value = efakturaSecretIsSet(company);
    syncSelectedPreset();
  },
  { immediate: true, deep: true }
);

// "The system takes care of the rest": a merchant switching the module on
// for the first time gets auto-send preselected (still unticked at will).
watch(
  () => form.efaktura_enabled,
  (enabled, wasEnabled) => {
    if (enabled && !wasEnabled && form.efaktura_sapi_base_url.trim() === '' && !form.efaktura_auto_send) {
      form.efaktura_auto_send = true;
    }
  },
);

function normalizeBaseUrl(url: string): string {
  return url.trim().replace(/\/$/, '');
}

function syncSelectedPreset() {
  const url = normalizeBaseUrl(form.efaktura_sapi_base_url);
  if (url === '') {
    selectedPresetId.value = '';
    return;
  }
  const match = cpdsPresets.value.find((preset) => normalizeBaseUrl(preset.base_url) === url);
  selectedPresetId.value = match ? match.id : 'custom';
}

function applyPreset() {
  if (selectedPresetId.value === '' || selectedPresetId.value === 'custom') {
    return;
  }
  const preset = cpdsPresets.value.find((row) => row.id === selectedPresetId.value);
  if (preset) {
    form.efaktura_sapi_base_url = preset.base_url;
    form.efaktura_connection_tested_at = null;
  }
}

/**
 * A URL/credential edit makes the last successful test stale. Wired as
 * `@input` (not a watcher) so the initial props.company synchronization
 * keeps the stored timestamp.
 */
function invalidateConnectionTest() {
  form.efaktura_connection_tested_at = null;
}

function stepBadgeClass(done: boolean): string {
  return [
    'inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold',
    done ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-100 text-indigo-700',
  ].join(' ');
}

function formatPollDate(iso: string) {
  const d = new Date(iso.includes('T') ? iso : `${iso}T12:00:00`);
  return d.toLocaleString(locale.value);
}

function applyInboundPollMeta(polledAt: string | null, stats: EfakturaInboundPollStats | null) {
  form.efaktura_inbound_last_poll_at = polledAt;
  form.efaktura_inbound_last_poll_stats = stats;
}

function buildPayload(): Partial<CompanyEfakturaSettingsState> {
  const payload: Partial<CompanyEfakturaSettingsState> = { ...form };
  delete payload.efaktura_inbound_last_poll_at;
  delete payload.efaktura_inbound_last_poll_stats;
  // The server stamps the tested-at timestamp itself; Evolu keeps its own.
  delete payload.efaktura_connection_tested_at;
  if (!payload.efaktura_sapi_client_secret) {
    delete payload.efaktura_sapi_client_secret;
  }
  return payload;
}

/** Stored client-side plaintext secret (local-first only) as test fallback. */
function storedLocalSecret(): string {
  const raw = (props.company?.app_settings ?? {}) as Record<string, unknown>;
  const plain = raw.efaktura_sapi_client_secret;
  return typeof plain === 'string' ? plain : '';
}

async function testConnection() {
  testingConnection.value = true;
  testMessage.value = '';
  testFailed.value = false;
  try {
    const payload: Record<string, unknown> = {
      efaktura_sapi_base_url: form.efaktura_sapi_base_url || null,
      efaktura_sapi_client_id: form.efaktura_sapi_client_id || null,
      efaktura_sapi_client_secret: form.efaktura_sapi_client_secret || null,
    };

    let result: { ok?: boolean; code?: string; message?: string; tested_at?: string | null };
    if (localFirst) {
      if (!payload.efaktura_sapi_client_secret) {
        payload.efaktura_sapi_client_secret = storedLocalSecret() || null;
      }
      result = await invoicingApi.efaktura.testConnectionEphemeral(payload);
    } else {
      result = await invoicingApi.efaktura.testConnection(props.companyId, payload);
    }

    if (result.ok) {
      testFailed.value = false;
      testMessage.value = t('invoicing.efaktura_test_ok');
      form.efaktura_connection_tested_at = result.tested_at ?? new Date().toISOString();
      if (localFirst && evolu) {
        // Persist the stamp into the local settings so the readiness
        // checklist survives a reload (server mode stamps server-side).
        updateLocalEfakturaSettings(evolu, asCompanyId(props.companyId), {
          efaktura_connection_tested_at: form.efaktura_connection_tested_at,
        });
      }
    } else {
      testFailed.value = true;
      const code = result.code ?? 'error';
      testMessage.value = t(`invoicing.efaktura_test_error_${code}`, { message: result.message ?? '' });
    }
  } catch (rawError) {
    const e = asApiError(rawError);
    testFailed.value = true;
    testMessage.value = e?.response?.data?.message ?? t('common.error_generic');
  } finally {
    testingConnection.value = false;
  }
}

function emitUpdatedFromEvoluRow() {
  if (!evolu) return;
  const row = evolu.getQueryRows(allCompaniesDetailQuery).find((c) => c.id === props.companyId);
  if (!row) return;
  emit(
    'updated',
    evoluCompanyToApi(row as EvoluCompanyRow, (storeId) => {
      const store = storesStore.stores.find((s) => s.id === storeId);
      return store
        ? {
            id: store.id,
            name: store.name,
            ...(store.default_currency !== undefined ? { default_currency: store.default_currency } : {}),
          }
        : undefined;
    }),
  );
}

async function pollInboundNow() {
  if (localFirst) {
    pollError.value = true;
    pollMessage.value = t('invoicing.local_first_efaktura_inbound_unavailable');
    return;
  }

  pollingInbound.value = true;
  pollMessage.value = '';
  pollError.value = false;
  try {
    const data = await invoicingApi.efaktura.pollInbound<{
      imported?: unknown; acknowledged?: unknown; skipped?: unknown; failed?: unknown; polled_at?: unknown;
    }>(props.companyId);
    const stats: EfakturaInboundPollStats = {
      imported: Number(data.imported ?? 0),
      acknowledged: Number(data.acknowledged ?? 0),
      skipped: Number(data.skipped ?? 0),
      failed: Number(data.failed ?? 0),
    };
    applyInboundPollMeta(data.polled_at ? String(data.polled_at) : new Date().toISOString(), stats);
    pollMessage.value = t('invoicing.efaktura_inbound_poll_result', stats);
    if (props.company) {
      emit('updated', {
        ...props.company,
        app_settings: {
          ...(props.company.app_settings as Record<string, unknown>),
          efaktura_inbound_last_poll_at: form.efaktura_inbound_last_poll_at,
          efaktura_inbound_last_poll_stats: stats,
        },
      });
    }
  } catch (rawError) {
    const e = asApiError(rawError);
    pollError.value = true;
    pollMessage.value = e?.response?.data?.message ?? t('common.error_generic');
  } finally {
    pollingInbound.value = false;
  }
}

async function save() {
  saving.value = true;
  saveError.value = '';
  try {
    const payload = buildPayload();

    if (localFirst && evolu) {
      const result = updateLocalEfakturaSettings(evolu, asCompanyId(props.companyId), payload);
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      form.efaktura_sapi_client_secret = '';
      emitUpdatedFromEvoluRow();
      const row = evolu.getQueryRows(allCompaniesDetailQuery).find((c) => c.id === props.companyId);
      if (row) {
        secretSet.value = efakturaSecretIsSet(
          evoluCompanyToApi(row as EvoluCompanyRow, (storeId) => {
            const store = storesStore.stores.find((s) => s.id === storeId);
            return store
              ? {
                  id: store.id,
                  name: store.name,
                  ...(store.default_currency !== undefined ? { default_currency: store.default_currency } : {}),
                }
              : undefined;
          }),
        );
      }
      notifySaved();
      return;
    }

    const updated = await invoicingApi.companies.updateAppSettings<Record<string, unknown>>(props.companyId, payload);
    emit('updated', updated);
    form.efaktura_sapi_client_secret = '';
    secretSet.value = efakturaSecretIsSet(updated);
    notifySaved();
  } catch (rawError) {
    const e = asApiError(rawError);
    saveError.value = e?.response?.data?.message ?? t('common.error_generic');
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  void efakturaFeature.load().then(() => syncSelectedPreset());
});
</script>
