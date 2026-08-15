<template>
  <div>
    <div v-if="switchIntent && walletType !== 'cashu'" class="mb-6">
      <button
        type="button"
        class="text-sm font-medium text-indigo-400 hover:text-indigo-300"
        @click="$emit('update:switchIntent', false)"
      >
        ← {{ t("stores.wallet_connection_back_from_cashu") }}
      </button>
    </div>

    <!-- Cashu store: read-only summary until user confirms password -->
    <template
      v-if="walletType === 'cashu' && cashuSectionMode === 'readonly'"
    >
      <h3
        class="text-sm font-bold text-indigo-400 mb-6 uppercase tracking-wider"
      >
        {{ t("stores.cashu_settings_title") }}
      </h3>
      <div
        class="mb-6 p-4 rounded-xl border border-green-500/20 bg-green-500/10 text-sm"
      >
        <span class="font-medium text-green-400">{{
          t("stores.connected")
        }}</span>
        <span class="text-gray-300 ml-2">{{
          t("stores.cashu_connected_via_plugin")
        }}</span>
      </div>
      <div class="space-y-5 text-sm mb-8">
        <div>
          <span
            class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
            >{{ t("stores.cashu_mint_url_label") }}</span
          >
          <p class="font-mono text-gray-200 break-all">
            {{ maskCashuMintUrl(cashuForm.mint_url) }}
          </p>
        </div>
        <div>
          <span
            class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
            >{{ t("stores.cashu_lightning_address_label") }}</span
          >
          <p class="text-gray-200">
            {{ maskCashuLightningAddress(cashuForm.lightning_address) }}
          </p>
        </div>
        <div>
          <span
            class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
            >{{ t("stores.cashu_unit_label") }}</span
          >
          <p class="text-gray-200 uppercase">{{ cashuForm.unit }}</p>
        </div>
        <div>
          <span
            class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
            >{{ t("stores.cashu_enabled_checkout_label") }}</span
          >
          <p
            :class="cashuForm.enabled ? 'text-emerald-300' : 'text-amber-300'"
          >
            {{
              cashuForm.enabled
                ? t("stores.cashu_enabled_on")
                : t("stores.cashu_enabled_off")
            }}
          </p>
        </div>
        <div>
          <span
            class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
            >{{ t("stores.cashu_trusted_mint_urls_label") }}</span
          >
          <p class="text-gray-200">
            {{
              (cashuForm.trusted_mint_urls || "").trim()
                ? t("stores.cashu_summary_configured")
                : "-"
            }}
          </p>
        </div>
        <div>
          <span
            class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
            >{{ t("stores.cashu_max_melt_fee_sats_label") }}</span
          >
          <p class="text-gray-200">
            {{ cashuMaxMeltSatsDisplay === "" ? "-" : cashuMaxMeltSatsDisplay }}
          </p>
        </div>
        <div>
          <span
            class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
            >{{ t("stores.cashu_max_melt_fee_percent_label") }}</span
          >
          <p class="text-gray-200">
            {{
              cashuMaxMeltPercentDisplay === ""
                ? "-"
                : cashuMaxMeltPercentDisplay
            }}
          </p>
        </div>
      </div>
      <p v-if="passwordError" class="mb-4 text-sm text-red-400">
        {{ passwordError }}
      </p>
      <div class="flex flex-wrap gap-3">
        <button
          type="button"
          class="inline-flex items-center px-6 py-3 border border-indigo-500 rounded-xl text-sm font-medium text-indigo-400 bg-indigo-500/10 hover:bg-indigo-500/20 transition-all disabled:opacity-50"
          :disabled="revealing"
          @click="startCashuConnectionEdit"
        >
          {{ t("stores.change_connection") }}
        </button>
        <button
          type="button"
          class="px-6 py-3 border border-gray-600 rounded-xl text-sm font-medium text-gray-300 bg-gray-800 hover:bg-gray-700 hover:text-white transition-all"
          @click="$emit('switchToLightning')"
        >
          ⚡ {{ t("stores.cashu_switch_to_lightning") }}
        </button>
        <button
          type="button"
          class="px-6 py-3 border border-transparent rounded-xl text-sm font-medium text-gray-400 hover:text-white bg-transparent hover:bg-gray-800 transition-all"
          @click="$emit('cancel')"
        >
          {{ t("common.cancel") }}
        </button>
      </div>
    </template>

    <!-- Cashu: confirm password / LNURL / Nostr before editing -->
    <template
      v-else-if="walletType === 'cashu' && cashuSectionMode === 'password'"
    >
      <h3
        class="text-sm font-bold text-indigo-400 mb-4 uppercase tracking-wider"
      >
        {{ t("stores.cashu_settings_title") }}
      </h3>
      <div
        class="bg-gray-900/50 border border-gray-700 rounded-xl p-6 max-w-md"
      >
        <p class="text-sm text-gray-400 mb-4">
          {{ t("stores.confirm_password_to_change") }}
        </p>
        <form @submit.prevent="handleCashuConfirmPassword" class="space-y-4">
          <div>
            <label
              for="cashu-wc-password"
              class="block text-sm font-medium text-gray-300 mb-1"
              >{{ t("account.current_password") }}</label
            >
            <input
              id="cashu-wc-password"
              v-model="passwordInput"
              type="password"
              autocomplete="current-password"
              :placeholder="t('stores.password_placeholder')"
              class="block w-full rounded-xl border border-gray-600 bg-gray-800 text-white placeholder-gray-500 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3"
            />
            <p v-if="passwordError" class="mt-2 text-sm text-red-400">
              {{ passwordError }}
            </p>
          </div>
          <div class="flex gap-3">
            <button
              type="button"
              @click="
                cashuSectionMode = 'readonly';
                passwordError = '';
                passwordInput = '';
              "
              class="px-5 py-2.5 border border-gray-600 rounded-xl text-sm font-medium text-gray-300 bg-gray-800 hover:bg-gray-700 transition-all"
            >
              {{ t("common.cancel") }}
            </button>
            <button
              type="submit"
              :disabled="revealing || !passwordInput.trim()"
              class="px-5 py-2.5 border border-transparent rounded-xl text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
            >
              <span v-if="revealing">{{ t("common.loading") }}</span>
              <span v-else>{{ t("common.confirm") }}</span>
            </button>
          </div>
        </form>
      </div>
    </template>

    <!-- Cashu: editing (first-time setup, after password, or switching from Lightning) -->
    <template v-else>
      <div v-if="walletType === 'cashu' && !switchIntent" class="mb-6">
        <button
          type="button"
          class="text-sm font-medium text-indigo-400 hover:text-indigo-300"
          @click="$emit('switchToLightning')"
        >
          ⚡ {{ t("stores.cashu_switch_to_lightning") }} →
        </button>
      </div>
      <h3
        class="text-sm font-bold text-indigo-400 mb-6 uppercase tracking-wider inline-flex items-center gap-2 flex-wrap"
      >
        {{ t("stores.cashu_settings_title") }}
        <span
          class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-md bg-amber-500/20 text-amber-300 border border-amber-500/40"
          >{{ t("stores.cashu_beta_badge") }}</span
        >
      </h3>

      <div
        v-if="
          walletType === 'cashu' &&
          (cashuForm.mint_url || '').trim() &&
          (cashuForm.lightning_address || '').trim()
        "
        class="mb-4 p-4 rounded-xl border border-green-500/20 bg-green-500/10 text-sm"
      >
        <span class="font-medium text-green-400">{{
          t("stores.connected")
        }}</span>
        <span class="text-gray-300 ml-2">{{
          t("stores.cashu_connected_via_plugin")
        }}</span>
      </div>

      <div
        class="mb-6 p-4 rounded-xl border border-amber-500/30 bg-amber-500/10 space-y-4"
      >
        <p class="text-sm text-amber-200/95 leading-relaxed">
          {{ t("stores.cashu_beta_notice_short") }}
        </p>
        <p class="text-sm font-medium text-amber-300">
          {{ t("stores.cashu_warning_title") }}
        </p>
        <ul class="text-sm text-amber-400 list-disc list-inside space-y-1.5">
          <li>{{ t("stores.cashu_warning_https") }}</li>
          <li>{{ t("stores.cashu_warning_mint_reachable") }}</li>
          <li>{{ t("stores.cashu_warning_ln_address") }}</li>
        </ul>
        <label class="flex items-start gap-3 cursor-pointer select-none">
          <input
            v-model="cashuBetaAccepted"
            type="checkbox"
            class="mt-1 h-4 w-4 rounded border-gray-500 bg-gray-800 text-indigo-600 focus:ring-indigo-500"
          />
          <span class="text-sm text-amber-100 leading-relaxed">{{
            t("stores.cashu_beta_consent_checkbox")
          }}</span>
        </label>
      </div>

      <div v-if="cashuLoading" class="flex items-center justify-center py-10">
        <svg
          class="animate-spin h-8 w-8 text-indigo-500"
          xmlns="http://www.w3.org/2000/svg"
          fill="none"
          viewBox="0 0 24 24"
        >
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          ></circle>
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
          ></path>
        </svg>
      </div>

      <form v-else-if="cashuBetaAccepted" @submit.prevent="handleSaveCashu" class="space-y-6">
        <div class="grid lg:grid-cols-2 gap-6">
        <div>
          <label
            for="cashu-mint-url"
            class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
          >
            {{ t("stores.cashu_mint_url_label") }}
          </label>
          <input
            id="cashu-mint-url"
            v-model="cashuForm.mint_url"
            type="text"
            class="block w-full rounded-xl border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3 font-mono"
            :placeholder="t('stores.cashu_mint_url_placeholder')"
          />
          <p
            class="mt-2 text-sm text-gray-500 leading-relaxed whitespace-pre-line"
          >
            {{ t("stores.cashu_mint_url_hint") }}
          </p>
          <p v-if="cashuErrors.mint_url" class="mt-2 text-sm text-red-400">
            {{ cashuErrors.mint_url }}
          </p>
        </div>

        <div>
          <label
            for="cashu-lightning-address"
            class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
          >
            {{ t("stores.cashu_lightning_address_label") }}
          </label>
          <input
            id="cashu-lightning-address"
            v-model="cashuForm.lightning_address"
            type="text"
            class="block w-full rounded-xl border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3"
            :placeholder="t('stores.cashu_lightning_address_placeholder')"
          />
          <p class="mt-2 text-sm text-gray-500 leading-relaxed">
            {{ t("stores.cashu_lightning_address_hint") }}
          </p>
          <p class="mt-2 text-sm text-gray-500 leading-relaxed">
            <span>{{ t("stores.cashu_lightning_address_coinos_prefix") }}</span>
            <a
              href="https://coinos.io"
              target="_blank"
              rel="noopener noreferrer"
              class="text-indigo-400 hover:text-indigo-300 underline font-medium"
              >coinos.io</a
            >
          </p>
          <p
            v-if="cashuErrors.lightning_address"
            class="mt-2 text-sm text-red-400"
          >
            {{ cashuErrors.lightning_address }}
          </p>
        </div>
        </div>

        <div>
          <label
            for="cashu-trusted-mints"
            class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
          >
            {{ t("stores.cashu_trusted_mint_urls_label") }}
          </label>
          <textarea
            id="cashu-trusted-mints"
            v-model="cashuForm.trusted_mint_urls"
            rows="4"
            class="block w-full rounded-xl border border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3 font-mono"
            :placeholder="t('stores.cashu_trusted_mint_urls_placeholder')"
          />
          <p
            class="mt-2 text-sm text-gray-500 leading-relaxed whitespace-pre-line"
          >
            {{ t("stores.cashu_trusted_mint_urls_hint") }}
          </p>
          <p
            v-if="cashuErrors.trusted_mint_urls"
            class="mt-2 text-sm text-red-400"
          >
            {{ cashuErrors.trusted_mint_urls }}
          </p>
        </div>

        <div class="grid sm:grid-cols-2 gap-6">
          <div>
            <label
              for="cashu-max-fee-sats"
              class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
            >
              {{ t("stores.cashu_max_melt_fee_sats_label") }}
            </label>
            <input
              id="cashu-max-fee-sats"
              v-model="cashuForm.max_melt_fee_reserve_sats"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              class="block w-full rounded-xl border border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3 tabular-nums"
              :placeholder="t('stores.cashu_optional_limit_placeholder')"
            />
            <p class="mt-2 text-sm text-gray-500">
              {{ t("stores.cashu_max_melt_fee_sats_hint") }}
            </p>
            <p
              v-if="cashuErrors.max_melt_fee_reserve_sats"
              class="mt-2 text-sm text-red-400"
            >
              {{ cashuErrors.max_melt_fee_reserve_sats }}
            </p>
          </div>
          <div>
            <label
              for="cashu-max-fee-pct"
              class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
            >
              {{ t("stores.cashu_max_melt_fee_percent_label") }}
            </label>
            <input
              id="cashu-max-fee-pct"
              v-model="cashuForm.max_melt_fee_reserve_percent_of_minted"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="block w-full rounded-xl border border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3 tabular-nums"
              :placeholder="t('stores.cashu_optional_limit_placeholder')"
            />
            <p class="mt-2 text-sm text-gray-500">
              {{ t("stores.cashu_max_melt_fee_percent_hint") }}
            </p>
            <p
              v-if="cashuErrors.max_melt_fee_reserve_percent_of_minted"
              class="mt-2 text-sm text-red-400"
            >
              {{ cashuErrors.max_melt_fee_reserve_percent_of_minted }}
            </p>
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-6">
          <div>
            <label
              for="cashu-unit"
              class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
            >
              {{ t("stores.cashu_unit_label") }}
            </label>
            <select
              id="cashu-unit"
              v-model="cashuForm.unit"
              class="block w-full rounded-xl border-gray-600 bg-gray-900/50 text-white focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3"
            >
              <option value="sat">{{ t("stores.cashu_unit_sat") }}</option>
              <option value="usd">{{ t("stores.cashu_unit_usd") }}</option>
            </select>
            <p class="mt-2 text-sm text-gray-500">
              {{ t("stores.cashu_unit_hint") }}
            </p>
          </div>
          <div class="sm:pt-9">
            <label class="flex items-start gap-3 cursor-pointer select-none">
              <input
                v-model="cashuForm.enabled"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-gray-500 bg-gray-800 text-indigo-600 focus:ring-indigo-500"
              />
              <span class="text-sm text-gray-300 leading-relaxed">{{
                t("stores.cashu_enabled_checkout_label")
              }}</span>
            </label>
          </div>
        </div>

        <div
          v-if="cashuErrorMessage"
          class="rounded-xl border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-300"
        >
          {{ cashuErrorMessage }}
        </div>

        <div
          class="flex flex-col-reverse sm:flex-row justify-between items-center gap-3 pt-4 border-t border-gray-700"
        >
          <button
            type="button"
            @click="$emit('cancel')"
            class="px-6 py-3 border border-transparent rounded-xl text-sm font-medium text-gray-400 hover:text-white bg-transparent hover:bg-gray-800 transition-all w-full sm:w-auto"
            :disabled="cashuSubmitting"
          >
            {{ t("common.cancel") }}
          </button>
          <button
            type="submit"
            :disabled="cashuSubmitting || !canSaveCashu"
            class="px-6 py-3 border border-transparent rounded-xl shadow-lg shadow-indigo-600/20 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed transition-all w-full sm:w-auto"
          >
            <span v-if="cashuSubmitting">{{ t("common.loading") }}</span>
            <span v-else>{{ t("stores.cashu_save_settings") }}</span>
          </button>
        </div>
      </form>
    </template>
  </div>

</template>

<script setup lang="ts">
import { asApiError } from "../../../utils/apiError";
import { computed, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "../../../store/auth";
import { walletApi } from "../../../services/api";
import { DEFAULT_CASHU_MINT_URL } from "../../../constants/cashu";
import { getApiErrorMessage } from '../../../composables/useApiError';
import {
  isValidCashuLightningAddress,
  type WalletConnectionDetection,
} from "../../../utils/detectWalletConnectionInput";

const props = defineProps<{
  storeId: string;
  walletType?: string | null | undefined;
  /** True while a non-Cashu store is switching to Cashu (shows the back button). */
  switchIntent: boolean;
  /** Smart-paste detection from the wallet setup form (prefills mint/LN address). */
  pasteDetection?: WalletConnectionDetection | null | undefined;
}>();

const emit = defineEmits<{
  submitted: [];
  cancel: [];
  switchToLightning: [];
  "update:switchIntent": [value: boolean];
}>();

const { t } = useI18n();
const authStore = useAuthStore();

const canUsePasswordLogin = computed(
  () => authStore.user?.can_use_password_login ?? true,
);

/** Native Cashu store: readonly summary -> password -> editing. */
const cashuSectionMode = ref<"readonly" | "password" | "editing">("editing");
const cashuInitializedFromFetch = ref(false);

const passwordInput = ref("");
const passwordError = ref("");
const revealing = ref(false);

const cashuLoading = ref(false);
const cashuSubmitting = ref(false);
const cashuErrorMessage = ref("");
const cashuErrors = reactive<Record<string, string>>({});
const cashuForm = reactive({
  mint_url: DEFAULT_CASHU_MINT_URL,
  lightning_address: "",
  enabled: true,
  unit: "sat",
  trusted_mint_urls: "",
  max_melt_fee_reserve_sats: "",
  max_melt_fee_reserve_percent_of_minted: "",
});
const cashuOriginal = reactive({
  mint_url: DEFAULT_CASHU_MINT_URL,
  lightning_address: "",
  enabled: true,
  unit: "sat",
  trusted_mint_urls: "",
  max_melt_fee_reserve_sats: "",
  max_melt_fee_reserve_percent_of_minted: "",
});

/** The plugin only supports sat and usd; anything else falls back to sat. */
function normalizeCashuUnit(unit: unknown): "sat" | "usd" {
  return unit === "usd" ? "usd" : "sat";
}

const cashuBetaAccepted = ref(false);

/** Apply Cashu mint/LN fields from smart-paste detection (same rules as Create.vue). */
function syncCashuFieldsFromPasteDetection() {
  const det = props.pasteDetection;
  if (!det || (det.kind !== "cashu" && det.kind !== "cashu_wallet_nwc")) {
    return;
  }
  if (det.cashuMintUrl) {
    cashuForm.mint_url = det.cashuMintUrl;
  } else if (!(cashuForm.mint_url ?? "").trim()) {
    cashuForm.mint_url = DEFAULT_CASHU_MINT_URL;
  }
  if (det.cashuLightningAddress) {
    cashuForm.lightning_address = det.cashuLightningAddress;
  }
}

watch(
  () => props.pasteDetection,
  (det) => {
    if (det?.kind === "cashu") {
      syncCashuFieldsFromPasteDetection();
    }
  },
);

const cashuMaxMeltSatsDisplay = computed(() =>
  String(cashuForm.max_melt_fee_reserve_sats ?? "").trim(),
);
const cashuMaxMeltPercentDisplay = computed(() =>
  String(cashuForm.max_melt_fee_reserve_percent_of_minted ?? "").trim(),
);

function cashuOptionalNumberPayload(
  val: string | number | null | undefined,
): number | null {
  if (val === null || val === undefined) return null;
  const s = String(val).trim();
  if (s === "") return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

const canSaveCashu = computed(() => {
  const mintUrl = (cashuForm.mint_url ?? "").trim();
  const lnAddress = (cashuForm.lightning_address ?? "").trim();
  if (!mintUrl || !mintUrl.startsWith("https://")) return false;
  if (!isValidCashuLightningAddress(lnAddress)) return false;
  if (!cashuBetaAccepted.value) return false;
  return true;
});

watch(
  () => props.switchIntent,
  (on) => {
    if (on) cashuBetaAccepted.value = false;
  },
);

watch(cashuSectionMode, (m, prev) => {
  if (m === "editing" && (prev === "password" || prev === "readonly")) {
    cashuBetaAccepted.value = false;
  }
});

function maskCashuMintUrl(url: string): string {
  const u = (url || "").trim();
  if (!u) return "-";
  try {
    const parsed = new URL(u);
    const path = parsed.pathname && parsed.pathname !== "/" ? " ···" : "";
    return `${parsed.hostname}${path}`;
  } catch {
    return "···";
  }
}

function maskCashuLightningAddress(addr: string): string {
  const a = (addr || "").trim();
  if (!a) return "-";
  const i = a.indexOf("@");
  if (i <= 0) return "···";
  const local = a.slice(0, i);
  const domain = a.slice(i + 1);
  const showLocal = local.length <= 2 ? "•••" : `${local.slice(0, 2)}···`;
  return `${showLocal}@${domain}`;
}

function resetCashuErrors() {
  Object.keys(cashuErrors).forEach((k) => delete cashuErrors[k]);
  cashuErrorMessage.value = "";
}

async function fetchCashuSettings() {
  cashuLoading.value = true;
  resetCashuErrors();

  try {
    const d = await walletApi.cashu.getSettings(props.storeId);

    const mintFromApi = (d.mint_url ?? "").trim();
    cashuForm.mint_url = mintFromApi || DEFAULT_CASHU_MINT_URL;
    cashuForm.lightning_address = d.lightning_address ?? "";
    cashuForm.enabled = d.enabled ?? true;
    cashuForm.unit = normalizeCashuUnit(d.unit);
    cashuForm.trusted_mint_urls =
      typeof d.trusted_mint_urls === "string" ? d.trusted_mint_urls : "";
    cashuForm.max_melt_fee_reserve_sats =
      d.max_melt_fee_reserve_sats != null && d.max_melt_fee_reserve_sats !== ""
        ? String(d.max_melt_fee_reserve_sats)
        : "";
    cashuForm.max_melt_fee_reserve_percent_of_minted =
      d.max_melt_fee_reserve_percent_of_minted != null &&
      d.max_melt_fee_reserve_percent_of_minted !== ""
        ? String(d.max_melt_fee_reserve_percent_of_minted)
        : "";

    cashuOriginal.mint_url = cashuForm.mint_url;
    cashuOriginal.lightning_address = cashuForm.lightning_address;
    cashuOriginal.enabled = cashuForm.enabled;
    cashuOriginal.unit = cashuForm.unit;
    cashuOriginal.trusted_mint_urls = cashuForm.trusted_mint_urls;
    cashuOriginal.max_melt_fee_reserve_sats =
      cashuForm.max_melt_fee_reserve_sats;
    cashuOriginal.max_melt_fee_reserve_percent_of_minted =
      cashuForm.max_melt_fee_reserve_percent_of_minted;

    if (
      props.walletType === "cashu" &&
      !props.switchIntent &&
      !cashuInitializedFromFetch.value
    ) {
      cashuInitializedFromFetch.value = true;
      const has =
        (cashuForm.mint_url || "").trim() &&
        (cashuForm.lightning_address || "").trim();
      cashuSectionMode.value = has ? "readonly" : "editing";
    }
  } catch (err) {
    cashuErrorMessage.value = getApiErrorMessage(err, "Failed to load Cashu settings");
  } finally {
    cashuLoading.value = false;
    syncCashuFieldsFromPasteDetection();
  }
}

async function handleSaveCashu() {
  if (!canSaveCashu.value) return;

  cashuSubmitting.value = true;
  resetCashuErrors();

  try {
    const payload = {
      mint_url: cashuForm.mint_url,
      lightning_address: cashuForm.lightning_address,
      enabled: cashuForm.enabled,
      unit: cashuForm.unit,
      trusted_mint_urls:
        (cashuForm.trusted_mint_urls ?? "").trim() === ""
          ? null
          : String(cashuForm.trusted_mint_urls).trim(),
      max_melt_fee_reserve_sats: cashuOptionalNumberPayload(
        cashuForm.max_melt_fee_reserve_sats,
      ),
      max_melt_fee_reserve_percent_of_minted: cashuOptionalNumberPayload(
        cashuForm.max_melt_fee_reserve_percent_of_minted,
      ),
    };

    const d = await walletApi.cashu.updateSettings(props.storeId, payload);

    cashuForm.mint_url = d.mint_url ?? cashuForm.mint_url;
    cashuForm.lightning_address =
      d.lightning_address ?? cashuForm.lightning_address;
    cashuForm.enabled = d.enabled ?? cashuForm.enabled;
    cashuForm.unit = normalizeCashuUnit(d.unit ?? cashuForm.unit);
    cashuForm.trusted_mint_urls =
      typeof d.trusted_mint_urls === "string" ? d.trusted_mint_urls : "";
    cashuForm.max_melt_fee_reserve_sats =
      d.max_melt_fee_reserve_sats != null && d.max_melt_fee_reserve_sats !== ""
        ? String(d.max_melt_fee_reserve_sats)
        : "";
    cashuForm.max_melt_fee_reserve_percent_of_minted =
      d.max_melt_fee_reserve_percent_of_minted != null &&
      d.max_melt_fee_reserve_percent_of_minted !== ""
        ? String(d.max_melt_fee_reserve_percent_of_minted)
        : "";

    cashuOriginal.mint_url = cashuForm.mint_url;
    cashuOriginal.lightning_address = cashuForm.lightning_address;
    cashuOriginal.enabled = cashuForm.enabled;
    cashuOriginal.unit = cashuForm.unit;
    cashuOriginal.trusted_mint_urls = cashuForm.trusted_mint_urls;
    cashuOriginal.max_melt_fee_reserve_sats =
      cashuForm.max_melt_fee_reserve_sats;
    cashuOriginal.max_melt_fee_reserve_percent_of_minted =
      cashuForm.max_melt_fee_reserve_percent_of_minted;

    emit("update:switchIntent", false);
    cashuInitializedFromFetch.value = true;
    cashuSectionMode.value = "readonly";
    emit("submitted");
  } catch (rawError) {
    const err = asApiError(rawError);
    if (err.response?.status === 422 && err.response?.data?.errors) {
      const validationErrors = err.response?.data?.errors ?? {};
      Object.keys(validationErrors).forEach((key) => {
        const v = validationErrors[key];
        const msg = (Array.isArray(v) ? v[0] : v) ?? "";
        if (key === "cashu") {
          cashuErrorMessage.value = msg;
        } else {
          cashuErrors[key] = msg;
        }
      });
    } else {
      cashuErrorMessage.value =
        err.response?.data?.message || "Failed to save Cashu settings";
    }
  } finally {
    cashuSubmitting.value = false;
  }
}

watch(
  () => [props.walletType, props.switchIntent] as const,
  ([wt, cashuIntent]) => {
    if (wt === "cashu" || cashuIntent) {
      fetchCashuSettings();
    }
  },
  { immediate: true },
);

watch(
  () => props.walletType,
  (wt) => {
    if (wt !== "cashu") {
      cashuInitializedFromFetch.value = false;
    }
  },
);

watch(
  () => props.storeId,
  () => {
    cashuSectionMode.value = "editing";
    cashuInitializedFromFetch.value = false;
    cashuBetaAccepted.value = false;
    passwordInput.value = "";
    passwordError.value = "";
  },
);

function startCashuConnectionEdit() {
  passwordError.value = "";
  passwordInput.value = "";
  if (!canUsePasswordLogin.value) {
    void handleCashuConfirmPassword();
    return;
  }
  cashuSectionMode.value = "password";
}

async function handleCashuConfirmPassword() {
  if (canUsePasswordLogin.value && !passwordInput.value.trim()) return;
  passwordError.value = "";
  revealing.value = true;
  try {
    const payload = canUsePasswordLogin.value
      ? { password: passwordInput.value }
      : {};
    await walletApi.cashu.confirmEdit(props.storeId, payload);
    passwordInput.value = "";
    cashuSectionMode.value = "editing";
  } catch (rawError) {
    const err = asApiError(rawError);
    const msg =
      err.response?.data?.errors?.password?.[0] ||
      err.response?.data?.message ||
      t("stores.invalid_password");
    passwordError.value = msg;
  } finally {
    revealing.value = false;
  }
}

</script>
