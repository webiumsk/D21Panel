<template>
  <RafflesPageLayout
    :store="store"
    :apps="apps"
    :error="error"
    @retry="loadStore"
    @show-settings="goSettings"
    @show-section="goSection"
  >
    <div class="flex-1 min-h-0 overflow-y-auto">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        <div>
          <h1 class="text-2xl font-bold text-white">{{ t("sepa.title") }}</h1>
          <p class="mt-1 text-sm text-gray-400">{{ t("sepa.subtitle") }}</p>
        </div>

        <div v-if="pageLoading" class="text-center py-12">
          <p class="text-gray-400">{{ t("common.loading") }}</p>
        </div>

        <div
          v-else-if="pluginUnavailable"
          class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 text-sm text-amber-300"
        >
          <p>{{ t("sepa.plugin_unavailable") }}</p>
          <button
            type="button"
            class="mt-2 text-indigo-400 hover:text-indigo-300"
            @click="reload(true)"
          >
            {{ t("common.retry") }}
          </button>
        </div>

        <template v-else>
          <!-- Settings -->
          <form
            class="bg-gray-800/50 border border-gray-700 rounded-xl p-6 space-y-5"
            @submit.prevent="saveSettings"
          >
            <h2 class="text-lg font-semibold text-white">
              {{ t("sepa.settings_title") }}
            </h2>

            <label class="flex items-center gap-3 text-sm text-gray-300">
              <input
                v-model="form.enabled"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-indigo-500 focus:ring-indigo-500"
              />
              {{ t("sepa.enabled_label") }}
            </label>

            <div>
              <label for="sepa-profile" class="block text-sm font-medium text-gray-300 mb-1">
                {{ t("sepa.country_profile") }}
              </label>
              <select
                id="sepa-profile"
                v-model="form.country_profile"
                class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
              >
                <option value="SK">{{ t("sepa.profile_sk") }}</option>
                <option value="CZ">{{ t("sepa.profile_cz") }}</option>
                <option value="EU">{{ t("sepa.profile_eu") }}</option>
              </select>
              <p class="mt-1.5 text-xs text-gray-500">{{ t("sepa.country_profile_help") }}</p>
            </div>

            <div v-if="form.country_profile === 'SK'">
              <label for="sepa-variant" class="block text-sm font-medium text-gray-300 mb-1">
                {{ t("sepa.sk_qr_variant") }}
              </label>
              <select
                id="sepa-variant"
                v-model="form.sk_qr_variant"
                class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
              >
                <option value="payme">{{ t("sepa.variant_payme") }}</option>
                <option value="bysquare">{{ t("sepa.variant_bysquare") }}</option>
              </select>
              <p class="mt-1.5 text-xs text-gray-500">{{ t("sepa.sk_qr_variant_help") }}</p>
            </div>

            <div>
              <label for="sepa-iban" class="block text-sm font-medium text-gray-300 mb-1">
                {{ t("sepa.iban") }} <span class="text-red-400">*</span>
              </label>
              <input
                id="sepa-iban"
                v-model="form.iban"
                type="text"
                required
                placeholder="SK68 0720 0002 8919 8742 6353"
                class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono text-sm"
              />
              <p v-if="fieldErrors.iban" class="mt-1 text-sm text-red-400">{{ fieldErrors.iban }}</p>
            </div>

            <div>
              <label for="sepa-beneficiary" class="block text-sm font-medium text-gray-300 mb-1">
                {{ t("sepa.beneficiary") }} <span class="text-red-400">*</span>
              </label>
              <input
                id="sepa-beneficiary"
                v-model="form.beneficiary"
                type="text"
                required
                maxlength="70"
                class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
              />
              <p v-if="fieldErrors.beneficiary" class="mt-1 text-sm text-red-400">{{ fieldErrors.beneficiary }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="sepa-bic" class="block text-sm font-medium text-gray-300 mb-1">
                  {{ t("sepa.bic") }}
                </label>
                <input
                  id="sepa-bic"
                  v-model="form.bic"
                  type="text"
                  maxlength="11"
                  class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono text-sm"
                />
              </div>
              <div>
                <label for="sepa-tolerance" class="block text-sm font-medium text-gray-300 mb-1">
                  {{ t("sepa.amount_tolerance") }}
                </label>
                <input
                  id="sepa-tolerance"
                  v-model.number="form.amount_tolerance"
                  type="number"
                  step="0.01"
                  min="0"
                  max="10"
                  class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                />
                <p class="mt-1.5 text-xs text-gray-500">{{ t("sepa.amount_tolerance_help") }}</p>
              </div>
            </div>

            <div>
              <label for="sepa-message" class="block text-sm font-medium text-gray-300 mb-1">
                {{ t("sepa.message") }}
              </label>
              <input
                id="sepa-message"
                v-model="form.message"
                type="text"
                maxlength="60"
                :placeholder="t('sepa.message_placeholder')"
                class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
              />
            </div>

            <div>
              <label for="sepa-backend" class="block text-sm font-medium text-gray-300 mb-1">
                {{ t("sepa.confirmation_backend") }}
              </label>
              <select
                id="sepa-backend"
                v-model="form.confirmation_backend"
                class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
              >
                <option value="manual">{{ t("sepa.backend_manual") }}</option>
                <option value="nop-mqtt">{{ t("sepa.backend_nop_mqtt") }}</option>
                <option value="nop-rest">{{ t("sepa.backend_nop_rest") }}</option>
              </select>
              <p class="mt-1.5 text-xs text-gray-500">{{ t("sepa.confirmation_backend_help") }}</p>
              <p v-if="fieldErrors.confirmation_backend" class="mt-1 text-sm text-red-400">
                {{ fieldErrors.confirmation_backend }}
              </p>
            </div>

            <div class="flex justify-end">
              <button
                type="submit"
                :disabled="saving"
                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium disabled:opacity-60 disabled:cursor-not-allowed"
              >
                {{ saving ? t("common.saving") : t("common.save") }}
              </button>
            </div>
          </form>

          <!-- NOP certificate -->
          <div class="bg-gray-800/50 border border-gray-700 rounded-xl p-6 space-y-5">
            <h2 class="text-lg font-semibold text-white">{{ t("sepa.certificate_title") }}</h2>

            <div
              v-if="settings?.nopCertSet"
              class="bg-green-500/10 border border-green-500/30 rounded-xl p-4 text-sm text-green-300"
            >
              <p>
                {{ t("sepa.certificate_uploaded") }}
                <span class="font-mono">{{ settings?.nopVatsk }}</span>
                /
                <span class="font-mono">POKLADNICA-{{ settings?.nopPokladnica }}</span>
              </p>
              <button
                type="button"
                :disabled="certWorking"
                class="mt-2 text-red-400 hover:text-red-300 disabled:opacity-60"
                @click="clearCertificate"
              >
                {{ t("sepa.certificate_clear") }}
              </button>
            </div>
            <p v-else class="text-sm text-gray-400">{{ t("sepa.certificate_hint") }}</p>

            <div>
              <label for="sepa-env" class="block text-sm font-medium text-gray-300 mb-1">
                {{ t("sepa.nop_environment") }}
              </label>
              <select
                id="sepa-env"
                v-model="certForm.nop_environment"
                class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
              >
                <option value="INT">{{ t("sepa.env_int") }}</option>
                <option value="PROD">{{ t("sepa.env_prod") }}</option>
              </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="sepa-pfx" class="block text-sm font-medium text-gray-300 mb-1">
                  {{ t("sepa.certificate_pfx") }}
                </label>
                <input
                  id="sepa-pfx"
                  ref="pfxInput"
                  type="file"
                  accept=".p12,.pfx"
                  class="block w-full text-sm text-gray-300 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-700 file:px-3 file:py-2 file:text-sm file:text-gray-200"
                  @change="onFileChange('pfx', $event)"
                />
              </div>
              <div>
                <label for="sepa-pfx-password" class="block text-sm font-medium text-gray-300 mb-1">
                  {{ t("sepa.certificate_pfx_password") }}
                </label>
                <input
                  id="sepa-pfx-password"
                  v-model="certForm.pfx_password"
                  type="password"
                  autocomplete="new-password"
                  class="block w-full px-4 py-3 rounded-xl border border-gray-600 bg-gray-700/50 text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                />
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="sepa-cert-pem" class="block text-sm font-medium text-gray-300 mb-1">
                  {{ t("sepa.certificate_pem") }}
                </label>
                <input
                  id="sepa-cert-pem"
                  ref="certPemInput"
                  type="file"
                  accept=".pem,.crt"
                  class="block w-full text-sm text-gray-300 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-700 file:px-3 file:py-2 file:text-sm file:text-gray-200"
                  @change="onFileChange('cert', $event)"
                />
              </div>
              <div>
                <label for="sepa-key-pem" class="block text-sm font-medium text-gray-300 mb-1">
                  {{ t("sepa.certificate_pem_key") }}
                </label>
                <input
                  id="sepa-key-pem"
                  ref="keyPemInput"
                  type="file"
                  accept=".pem,.key"
                  class="block w-full text-sm text-gray-300 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-700 file:px-3 file:py-2 file:text-sm file:text-gray-200"
                  @change="onFileChange('key', $event)"
                />
              </div>
            </div>

            <div class="flex justify-end">
              <button
                type="button"
                :disabled="certWorking || (!certFiles.pfx && !(certFiles.cert && certFiles.key))"
                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium disabled:opacity-60 disabled:cursor-not-allowed"
                @click="uploadCertificate"
              >
                {{ certWorking ? t("common.saving") : t("sepa.certificate_upload") }}
              </button>
            </div>
          </div>

          <!-- Backend test -->
          <div class="bg-gray-800/50 border border-gray-700 rounded-xl p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white">{{ t("sepa.test_title") }}</h2>
            <p class="text-sm text-gray-400">{{ t("sepa.test_help") }}</p>
            <div class="flex items-center gap-4">
              <button
                type="button"
                :disabled="testing"
                class="px-5 py-2.5 rounded-xl border border-gray-600 text-gray-200 hover:bg-gray-700 text-sm font-medium disabled:opacity-60"
                @click="runTest"
              >
                {{ testing ? t("sepa.testing") : t("sepa.test_button") }}
              </button>
              <p
                v-if="testResult"
                class="text-sm"
                :class="testResult.ok ? 'text-green-400' : 'text-red-400'"
              >
                {{ testResult.message || (testResult.ok ? t("sepa.test_passed") : t("sepa.test_failed")) }}
              </p>
            </div>
          </div>

          <!-- Needs review -->
          <div
            v-if="reviewRequests.length > 0"
            class="bg-gray-800/50 border border-amber-500/30 rounded-xl p-6 space-y-4"
          >
            <h2 class="text-lg font-semibold text-white">{{ t("sepa.review_title") }}</h2>
            <p class="text-sm text-gray-400">{{ t("sepa.review_help") }}</p>
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-gray-500">
                  <th class="py-2 pr-4">{{ t("sepa.column_reference") }}</th>
                  <th class="py-2 pr-4">{{ t("sepa.column_due") }}</th>
                  <th class="py-2 pr-4">{{ t("sepa.column_reason") }}</th>
                  <th class="py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in reviewRequests"
                  :key="row.reference"
                  class="border-t border-gray-700 text-gray-300"
                >
                  <td class="py-2 pr-4 font-mono text-xs">{{ row.reference }}</td>
                  <td class="py-2 pr-4">{{ formatAmount(row.amountDue) }} {{ row.currency }}</td>
                  <td class="py-2 pr-4">{{ row.reviewReason }}</td>
                  <td class="py-2 text-right">
                    <button
                      type="button"
                      :disabled="confirming === row.reference"
                      class="px-3 py-1.5 rounded-lg border border-green-500/40 text-green-400 hover:bg-green-500/10 text-xs disabled:opacity-60"
                      @click="confirmRequest(row.reference)"
                    >
                      {{ t("sepa.mark_paid") }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Awaiting payment -->
          <div class="bg-gray-800/50 border border-gray-700 rounded-xl p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white">{{ t("sepa.pending_title") }}</h2>
            <p class="text-sm text-gray-400">{{ t("sepa.pending_help") }}</p>
            <p v-if="pendingRequests.length === 0" class="text-sm text-gray-500">
              {{ t("sepa.pending_empty") }}
            </p>
            <table v-else class="w-full text-sm">
              <thead>
                <tr class="text-left text-gray-500">
                  <th class="py-2 pr-4">{{ t("sepa.column_reference") }}</th>
                  <th class="py-2 pr-4">{{ t("sepa.column_due") }}</th>
                  <th class="py-2 pr-4">{{ t("sepa.column_created") }}</th>
                  <th class="py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in pendingRequests"
                  :key="row.reference"
                  class="border-t border-gray-700 text-gray-300"
                >
                  <td class="py-2 pr-4 font-mono text-xs">{{ row.reference }}</td>
                  <td class="py-2 pr-4">{{ formatAmount(row.amountDue) }} {{ row.currency }}</td>
                  <td class="py-2 pr-4">{{ formatDate(row.createdAt) }}</td>
                  <td class="py-2 text-right">
                    <button
                      type="button"
                      :disabled="confirming === row.reference"
                      class="px-3 py-1.5 rounded-lg border border-green-500/40 text-green-400 hover:bg-green-500/10 text-xs disabled:opacity-60"
                      @click="confirmRequest(row.reference)"
                    >
                      {{ t("sepa.mark_paid") }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </div>
  </RafflesPageLayout>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import RafflesPageLayout from "../../components/stores/RafflesPageLayout.vue";
import { useStorePageShell } from "../../composables/useStorePageShell";
import { useAppsStore } from "../../store/apps";
import { useFlashStore } from "../../store/flash";
import { getApiErrorMessage } from "../../composables/useApiError";
import api from "../../services/api";

interface SepaSettings {
  configured: boolean;
  enabled: boolean;
  countryProfile: string;
  iban: string;
  beneficiary: string;
  bic: string | null;
  message: string | null;
  confirmationBackend: string;
  skQrVariant: string;
  amountTolerance: number;
  nopEnvironment: string;
  nopCertSet: boolean;
  nopVatsk: string | null;
  nopPokladnica: string | null;
}

interface SepaPaymentRequest {
  reference: string;
  invoiceId: string;
  state: string;
  amountDue: number;
  currency: string;
  createdAt: string;
  reviewReason: string | null;
}

const { t } = useI18n();
const flashStore = useFlashStore();
const appsStore = useAppsStore();
const { storeId, store, error, loadStore, goSettings, goSection } = useStorePageShell();
const apps = computed(() => appsStore.apps);

const pageLoading = ref(true);
const pluginUnavailable = ref(false);
const settings = ref<SepaSettings | null>(null);
const saving = ref(false);
const certWorking = ref(false);
const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string | null } | null>(null);
const confirming = ref<string | null>(null);
const requests = ref<SepaPaymentRequest[]>([]);
const fieldErrors = reactive<Record<string, string>>({});

const form = reactive({
  enabled: false,
  country_profile: "SK",
  sk_qr_variant: "payme",
  iban: "",
  beneficiary: "",
  bic: "",
  message: "",
  confirmation_backend: "manual",
  amount_tolerance: 0,
});

const certForm = reactive({ pfx_password: "", nop_environment: "INT" });
const certFiles = reactive<{ pfx: File | null; cert: File | null; key: File | null }>({
  pfx: null,
  cert: null,
  key: null,
});
const pfxInput = ref<HTMLInputElement | null>(null);
const certPemInput = ref<HTMLInputElement | null>(null);
const keyPemInput = ref<HTMLInputElement | null>(null);

const pendingRequests = computed(() => requests.value.filter((r) => r.state === "PENDING"));
const reviewRequests = computed(() => requests.value.filter((r) => r.state === "MANUAL_REVIEW"));

function applySettings(data: SepaSettings) {
  settings.value = data;
  if (!data.configured) return;
  form.enabled = data.enabled;
  form.country_profile = data.countryProfile;
  form.sk_qr_variant = data.skQrVariant;
  form.iban = data.iban;
  form.beneficiary = data.beneficiary;
  form.bic = data.bic ?? "";
  form.message = data.message ?? "";
  form.confirmation_backend = data.confirmationBackend;
  form.amount_tolerance = data.amountTolerance;
  certForm.nop_environment = data.nopEnvironment || "INT";
}

async function reload(refreshProbe = false) {
  pageLoading.value = true;
  pluginUnavailable.value = false;
  try {
    const probe = await api.get(`/stores/${storeId.value}/sepa/status${refreshProbe ? "?refresh=1" : ""}`);
    if (!probe.data?.data?.available) {
      pluginUnavailable.value = true;
      return;
    }
    const [settingsRes, requestsRes] = await Promise.all([
      api.get(`/stores/${storeId.value}/sepa/settings`),
      api.get(`/stores/${storeId.value}/sepa/payment-requests`),
    ]);
    applySettings(settingsRes.data?.data ?? settingsRes.data);
    requests.value = requestsRes.data?.data ?? [];
  } catch (err: unknown) {
    flashStore.error(getApiErrorMessage(err, t("sepa.loading_failed")));
  } finally {
    pageLoading.value = false;
  }
}

async function saveSettings() {
  saving.value = true;
  Object.keys(fieldErrors).forEach((k) => delete fieldErrors[k]);
  try {
    const payload = {
      enabled: form.enabled,
      country_profile: form.country_profile,
      sk_qr_variant: form.sk_qr_variant,
      iban: form.iban,
      beneficiary: form.beneficiary,
      bic: form.bic || null,
      message: form.message || null,
      confirmation_backend: form.confirmation_backend,
      amount_tolerance: form.amount_tolerance || 0,
      nop_environment: certForm.nop_environment,
    };
    const res = await api.put(`/stores/${storeId.value}/sepa/settings`, payload);
    applySettings(res.data?.data ?? res.data);
    flashStore.success(t("sepa.settings_saved"));
  } catch (err: unknown) {
    const response = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })
      .response;
    if (response?.status === 422 && response.data?.errors) {
      for (const [key, messages] of Object.entries(response.data.errors)) {
        fieldErrors[key] = messages[0] ?? "";
      }
    }
    flashStore.error(getApiErrorMessage(err, t("sepa.settings_save_failed")));
  } finally {
    saving.value = false;
  }
}

function onFileChange(kind: "pfx" | "cert" | "key", event: Event) {
  const input = event.target as HTMLInputElement;
  certFiles[kind] = input.files?.[0] ?? null;
}

function resetCertInputs() {
  certFiles.pfx = null;
  certFiles.cert = null;
  certFiles.key = null;
  certForm.pfx_password = "";
  if (pfxInput.value) pfxInput.value.value = "";
  if (certPemInput.value) certPemInput.value.value = "";
  if (keyPemInput.value) keyPemInput.value.value = "";
}

async function uploadCertificate() {
  certWorking.value = true;
  try {
    const formData = new FormData();
    if (certFiles.pfx) {
      formData.append("pfx_file", certFiles.pfx);
      if (certForm.pfx_password) formData.append("pfx_password", certForm.pfx_password);
    } else if (certFiles.cert && certFiles.key) {
      formData.append("cert_pem_file", certFiles.cert);
      formData.append("key_pem_file", certFiles.key);
    }
    formData.append("nop_environment", certForm.nop_environment);
    const res = await api.post(`/stores/${storeId.value}/sepa/certificate`, formData);
    applySettings(res.data?.data ?? res.data);
    resetCertInputs();
    flashStore.success(t("sepa.certificate_uploaded_flash"));
  } catch (err: unknown) {
    flashStore.error(getApiErrorMessage(err, t("sepa.certificate_upload_failed")));
  } finally {
    certWorking.value = false;
  }
}

async function clearCertificate() {
  certWorking.value = true;
  try {
    const res = await api.delete(`/stores/${storeId.value}/sepa/certificate`);
    applySettings(res.data?.data ?? res.data);
    flashStore.success(t("sepa.certificate_cleared"));
  } catch (err: unknown) {
    flashStore.error(getApiErrorMessage(err, t("sepa.certificate_clear_failed")));
  } finally {
    certWorking.value = false;
  }
}

async function runTest() {
  testing.value = true;
  testResult.value = null;
  try {
    const res = await api.post(`/stores/${storeId.value}/sepa/test`, {});
    const data = res.data?.data ?? res.data;
    testResult.value = { ok: !!data.ok, message: data.message ?? null };
  } catch (err: unknown) {
    testResult.value = { ok: false, message: getApiErrorMessage(err, t("sepa.test_failed")) };
  } finally {
    testing.value = false;
  }
}

async function confirmRequest(reference: string) {
  confirming.value = reference;
  try {
    await api.post(`/stores/${storeId.value}/sepa/payment-requests/${encodeURIComponent(reference)}/confirm`, {});
    flashStore.success(t("sepa.payment_confirmed"));
    const res = await api.get(`/stores/${storeId.value}/sepa/payment-requests`);
    requests.value = res.data?.data ?? [];
  } catch (err: unknown) {
    flashStore.error(getApiErrorMessage(err, t("sepa.payment_confirm_failed")));
  } finally {
    confirming.value = null;
  }
}

function formatAmount(value: number): string {
  return Number(value).toFixed(2);
}

function formatDate(value: string): string {
  return new Date(value).toLocaleString();
}

void reload();
</script>
