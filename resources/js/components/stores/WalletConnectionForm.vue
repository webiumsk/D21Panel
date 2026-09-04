<template>
  <CashuConnectionSection
    v-if="isCashuFlow"
    :store-id="storeId"
    :wallet-type="walletType"
    v-model:switch-intent="switchToCashuIntent"
    :paste-detection="walletPasteDetection"
    @switch-to-lightning="switchToLightningIntent = true"
    @submitted="$emit('submitted', 'cashu')"
    @cancel="$emit('cancel')"
  />

  <div v-else class="space-y-8">
    <!-- Read-only: Current Connection + Change button (when connection exists and not editing) -->
    <template v-if="existingConnection && viewMode === 'readonly'">
      <ConnectionReadonlyCard
        :connection="existingConnection"
        :wallet-brand="readonlyAquaWalletBrand"
        :revealing="revealing"
        :password-error="passwordError"
        :cashu-fallback-address="cashuFallbackAddress"
        @change="startWalletConnectionEdit"
        @cancel="$emit('cancel')"
      />
    </template>

    <!-- Password step: before revealing secret -->
    <template v-else-if="existingConnection && viewMode === 'password'">
      <div
        class="bg-gray-900/50 border border-gray-700 rounded-xl p-6 max-w-md"
      >
        <p class="text-sm text-gray-400 mb-4">
          {{ t("stores.confirm_password_to_change") }}
        </p>
        <form @submit.prevent="handleConfirmPassword" class="space-y-4">
          <div>
            <label
              for="wc-password"
              class="block text-sm font-medium text-gray-300 mb-1"
              >{{ t("account.current_password") }}</label
            >
            <input
              id="wc-password"
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
                viewMode = 'readonly';
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

    <!-- Email-code step: a connected wallet is only replaced after the code -->
    <template v-else-if="existingConnection && viewMode === 'code' && changeChallenge">
      <div class="bg-gray-900/50 border border-gray-700 rounded-xl p-6 max-w-md space-y-4">
        <p class="text-sm text-gray-400">
          {{ t("stores.wallet_change_code_intro") }}
        </p>
        <EmailCodeVerification
          :challenge="changeChallenge"
          :confirm="handleConfirmChangeCode"
          :resend="handleResendChangeCode"
          :allow-change-email="false"
        />
        <button
          type="button"
          class="text-sm text-gray-400 hover:text-white"
          @click="cancelChangeCode"
        >
          {{ t("common.cancel") }}
        </button>
      </div>
    </template>

    <!-- Unified wallet setup (paste first, SamRock second - same as create store) -->
    <template v-else-if="showWalletSetupForm">
      <div class="space-y-6">
      <div v-if="walletType === 'cashu'">
        <button
          type="button"
          class="text-sm font-medium text-indigo-400 hover:text-indigo-300"
          @click="switchToLightningIntent = false"
        >
          ← {{ t("stores.wallet_connection_back_to_cashu") }}
        </button>
      </div>
      <div
        v-if="showWalletSetupTabs"
        role="tablist"
        class="flex gap-6 border-b border-gray-700/60"
        @keydown.left.prevent="focusAdjacentWalletTab(-1)"
        @keydown.right.prevent="focusAdjacentWalletTab(1)"
      >
        <button
          v-for="tab in walletSetupTabsList"
          :id="`wallet-setup-tab-${tab.id}`"
          :key="tab.id"
          type="button"
          role="tab"
          :aria-controls="`wallet-setup-panel-${tab.id}`"
          :aria-selected="walletSetupTab === tab.id"
          :tabindex="walletSetupTab === tab.id ? 0 : -1"
          class="pb-3 -mb-px text-sm font-medium border-b-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded-t-sm"
          :class="
            walletSetupTab === tab.id
              ? 'border-indigo-500 text-white'
              : 'border-transparent text-gray-400 hover:text-gray-200 hover:border-gray-600'
          "
          @click="walletSetupTab = tab.id"
        >
          {{ t(tab.labelKey) }}
        </button>
      </div>

      <div
        id="wallet-setup-panel-ln"
        v-show="showWalletSetupTabs && walletSetupTab === 'ln'"
        role="tabpanel"
        aria-labelledby="wallet-setup-tab-ln"
      >
        <LightningAddressQuickConnect
          :store-id="storeId"
          @submitted="onQuickConnectSubmitted"
        />
      </div>

      <div
        id="wallet-setup-panel-advanced"
        v-show="!showWalletSetupTabs || walletSetupTab === 'advanced'"
        role="tabpanel"
        aria-labelledby="wallet-setup-tab-advanced"
        class="space-y-6"
      >
        <WalletConnectionSmartPaste
          v-model="form.secret"
          v-model:connection-type="form.type"
          input-id="wallet-connection-smart-paste"
          @detect-cashu="onDetectCashuFromPaste"
        />

        <div v-if="form.secret.trim()" class="max-w-md">
          <template v-if="advancedFallbackDerivable">
            <p class="text-sm text-gray-500">
              {{ t("stores.wallet_fallback_derived_note") }}
            </p>
          </template>
          <template v-else>
            <label
              for="wallet-advanced-fallback"
              class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
            >
              {{ t("stores.wallet_fallback_address_label") }}
            </label>
            <input
              id="wallet-advanced-fallback"
              v-model="advancedFallbackAddress"
              type="text"
              autocomplete="off"
              spellcheck="false"
              class="block w-full rounded-xl border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3"
              :placeholder="t('stores.ln_quick_address_placeholder')"
            />
            <p class="mt-2 text-sm text-gray-500 leading-relaxed">
              {{ t("stores.wallet_fallback_address_hint") }}
            </p>
            <p v-if="errors.fallback_lightning_address" class="mt-2 text-sm text-red-400">
              {{ errors.fallback_lightning_address }}
            </p>
          </template>
        </div>

        <div
          v-if="
            viewMode === 'editing' &&
            existingConnection &&
            existingConnection.status === 'connected' &&
            walletType === 'aqua_boltz'
          "
          class="border-l-2 border-green-500/40 pl-3 text-sm space-y-1"
        >
          <p class="font-medium text-green-400">
            {{ t("stores.aqua_wallet_connected_title") }}
          </p>
          <p class="text-gray-400 leading-relaxed">
            {{ t("stores.aqua_wallet_connected_samrock_hint") }}
          </p>
        </div>

        <div
          v-if="showAquaDescriptorWarnings"
          class="border-l-2 border-amber-500/40 pl-3 space-y-1"
        >
          <p class="text-sm text-amber-300/90">
            {{ t("stores.aqua_warning_btcpay") }}
          </p>
          <p class="text-sm text-amber-300/70">
            {{ t("stores.aqua_limits_warning") }}
          </p>
        </div>

        <p v-if="errors.type" class="text-sm text-red-400">{{ errors.type }}</p>
        <p v-if="errors.secret" class="text-sm text-red-400">{{ errors.secret }}</p>

        <div
          v-if="testResult"
          class="rounded-xl p-4 border"
          :class="
            testResult.success
              ? 'bg-green-500/10 border-green-500/20'
              : 'bg-red-500/10 border-red-500/20'
          "
        >
          <div class="flex">
            <div class="flex-shrink-0">
              <svg
                v-if="testResult.success"
                class="h-5 w-5 text-green-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                />
              </svg>
              <svg
                v-else
                class="h-5 w-5 text-red-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
            </div>
            <div class="ml-3">
              <p
                class="text-sm font-medium"
                :class="testResult.success ? 'text-green-400' : 'text-red-400'"
              >
                {{ testResult.message }}
              </p>
              <p
                v-if="testResult.requires_manual_config"
                class="mt-1 text-sm text-gray-400"
              >
                {{ t("stores.manual_config_required") }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <div
        id="wallet-setup-panel-samrock"
        v-show="showWalletSetupTabs && walletSetupTab === 'samrock'"
        role="tabpanel"
        aria-labelledby="wallet-setup-tab-samrock"
        class="bg-gray-900/50 border border-indigo-500/30 rounded-2xl p-8 space-y-4"
      >
        <div
          v-if="
            viewMode === 'editing' &&
            existingConnection &&
            existingConnection.status === 'connected' &&
            walletType === 'aqua_boltz'
          "
          class="p-4 rounded-xl border border-green-500/25 bg-green-500/10 text-sm text-green-100 space-y-1"
        >
          <p class="font-medium text-green-400">
            {{ t("stores.aqua_wallet_connected_title") }}
          </p>
          <p class="text-gray-300 leading-relaxed">
            {{ t("stores.aqua_wallet_connected_samrock_hint") }}
          </p>
        </div>
        <h3 class="text-sm font-bold text-indigo-400 uppercase tracking-wider">
          {{ t("stores.samrock_title") }}
        </h3>
        <p class="text-sm text-gray-400 leading-relaxed">
          {{ t("stores.samrock_description") }}
        </p>
        <p
          v-if="samrockErrorMessage && !samrockQrObjectUrl"
          class="text-red-400 text-sm"
        >
          {{ samrockErrorMessage }}
        </p>

        <div v-if="!samrockOtp && !samrockBusy" class="space-y-4">
          <div class="max-w-md">
            <label
              for="wallet-samrock-fallback"
              class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
            >
              {{ t("stores.wallet_fallback_address_label") }}
            </label>
            <input
              id="wallet-samrock-fallback"
              v-model="samrockFallbackAddress"
              type="text"
              autocomplete="off"
              spellcheck="false"
              class="block w-full rounded-xl border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3"
              :placeholder="t('stores.ln_quick_address_placeholder')"
            />
            <p class="mt-2 text-sm text-gray-500 leading-relaxed">
              {{ t("stores.wallet_fallback_address_hint") }}
            </p>
          </div>
          <div class="flex flex-wrap gap-3">
          <button
            type="button"
            class="px-6 py-3 rounded-xl text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50"
            :disabled="samrockBusy || !samrockFallbackValid"
            @click="startSamRockPairing"
          >
            {{ t("stores.samrock_generate_qr") }}
          </button>
          <button
            v-if="samrockErrorMessage"
            type="button"
            class="px-6 py-3 rounded-xl text-sm font-medium border border-gray-600 text-gray-300 hover:bg-gray-800"
            @click="
              samrockErrorMessage = '';
              startSamRockPairing();
            "
          >
            {{ t("stores.samrock_try_again") }}
          </button>
          </div>
        </div>

        <div
          v-else-if="samrockBusy && !samrockQrObjectUrl"
          class="flex items-center gap-3 py-6 text-gray-400"
        >
          <svg
            class="animate-spin h-6 w-6 text-indigo-500"
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
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
            ></path>
          </svg>
          <span>{{ t("common.loading") }}</span>
        </div>

        <div v-else-if="samrockQrObjectUrl" class="space-y-4">
          <p class="text-sm text-gray-300">{{ t("stores.samrock_waiting_scan") }}</p>
          <div class="flex flex-col sm:flex-row gap-6 items-start">
            <img
              :src="samrockQrObjectUrl"
              alt="SamRock QR"
              class="w-48 h-48 rounded-xl border border-gray-600 bg-white p-2"
            />
            <div class="text-sm space-y-2">
              <p v-if="samrockExpiresAt" class="text-gray-500">
                {{ t("stores.samrock_expires") }}:
                {{ formatSamRockExpiry(samrockExpiresAt) }}
              </p>
              <p v-if="samrockPollStatus" class="font-mono text-indigo-300">
                {{ samrockPollStatus }}
              </p>
              <p v-if="samrockErrorMessage" class="text-red-400">
                {{ samrockErrorMessage }}
              </p>
            </div>
          </div>
          <button
            type="button"
            class="px-4 py-2 rounded-xl text-sm border border-gray-600 text-gray-300 hover:bg-gray-800"
            @click="cancelSamRockPairing"
          >
            {{ t("stores.samrock_cancel") }}
          </button>
        </div>
      </div>

      <WalletConnectionTypeGuide
        :highlight-kind="
          walletPasteDetection.kind === 'unknown' ? null : walletPasteDetection.kind
        "
        :highlight-brand="walletPasteDetection.brand"
        :highlight-ln-address-brand="walletPasteDetection.lnAddressBrand"
      />

      <div
        class="flex flex-col-reverse sm:flex-row justify-between items-center gap-4 pt-2"
      >
        <div class="flex w-full sm:w-auto gap-4">
          <button
            type="button"
            @click="handleTestConnection"
            :disabled="testing || !form.secret.trim()"
            class="w-full sm:w-auto px-6 py-3 border border-gray-600 rounded-xl shadow-sm text-sm font-medium text-gray-300 bg-gray-800 hover:bg-gray-700 hover:text-white disabled:opacity-50 disabled:cursor-not-allowed transition-all"
          >
            {{ testing ? t("stores.testing") : t("stores.test_connection") }}
          </button>
        </div>
        <div class="flex w-full sm:w-auto gap-4">
          <button
            v-if="existingConnection && viewMode === 'editing'"
            type="button"
            @click="handleCancelEdit"
            class="w-full sm:w-auto px-6 py-3 border border-transparent rounded-xl text-sm font-medium text-gray-400 hover:text-white bg-transparent hover:bg-gray-800 transition-all"
          >
            {{ t("common.cancel") }}
          </button>
          <button
            v-else
            type="button"
            @click="$emit('cancel')"
            class="w-full sm:w-auto px-6 py-3 border border-transparent rounded-xl text-sm font-medium text-gray-400 hover:text-white bg-transparent hover:bg-gray-800 transition-all"
          >
            {{ t("common.cancel") }}
          </button>
          <button
            type="button"
            :disabled="submitting"
            class="w-full sm:w-auto px-6 py-3 border border-transparent rounded-xl shadow-lg shadow-indigo-600/20 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
            @click="handleSubmit"
          >
            {{ submitting ? t("common.loading") : t("stores.save_connection") }}
          </button>
        </div>
      </div>
      </div>
    </template>

    <div
      v-else
      class="rounded-xl border border-amber-500/25 bg-amber-500/10 p-6 text-sm text-gray-200 space-y-2"
    >
      <p class="font-medium text-amber-200">
        {{ t("stores.wallet_connection_unsupported_title") }}
      </p>
      <p class="text-gray-400">
        {{
          t("stores.wallet_connection_unsupported_body", {
            type: walletTypeLabel,
          })
        }}
      </p>
    </div>
  </div>

</template>

<script setup lang="ts">
import type { WalletConnectionDetails } from "../../services/api";
import { asApiError } from "../../utils/apiError";
import { ref, reactive, computed, watch, nextTick } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import { useAuthStore, type EmailChallengeSummary } from "../../store/auth";
import { useGuestUpgradeModal } from "../../composables/useGuestUpgradeModal";
import EmailCodeVerification from "../account/EmailCodeVerification.vue";
import { useSamRockPairing } from "../../composables/useSamRockPairing";
import CashuConnectionSection from "./wallet-connection/CashuConnectionSection.vue";
import ConnectionReadonlyCard from "./wallet-connection/ConnectionReadonlyCard.vue";
import { walletApi } from "../../services/api";
import WalletConnectionSmartPaste from "./WalletConnectionSmartPaste.vue";
import LightningAddressQuickConnect from "./wallet-connection/LightningAddressQuickConnect.vue";
import WalletConnectionTypeGuide from "./WalletConnectionTypeGuide.vue";
import { isValidAquaBoltzDescriptor } from "../../utils/aquaBoltzDescriptor";
import { detectWalletConnectionInput, isValidCashuLightningAddress } from "../../utils/detectWalletConnectionInput";
import {
  fallbackAddressDerivableFromSecret,
  isCashuWalletNwcUri,
  normalizeBlinkConnectionString,
  normalizeBlitzConnectionString,
  normalizeFlashConnectionString,
  normalizeNwcUri,
  validateBlinkConnectionString,
  validateBlitzConnectionString,
  validateFlashConnectionString,
  validateNwcUri,
} from "../../utils/walletNwcHelpers";
import {
  normalizeLnAddressConnectionString,
  validateLnAddressConnectionString,
  type LnAddressWalletBrand,
} from "../../utils/lnAddressWalletBrands";
import {
  detectWalletBrandFromDescriptor,
  type AquaBoltzWalletBrand,
} from "../../utils/aquaBoltzWalletBrand";

interface Props {
  storeId: string;
  existingConnection?: WalletConnectionDetails | null;
  walletType?: "blink" | "blitz" | "flash" | "lnaddress" | "aqua_boltz" | "cashu" | "nwc" | string | null | undefined;
  /** When wallet_type is aqua_boltz: Aqua vs Bull (from API) */
  walletBrand?: AquaBoltzWalletBrand | LnAddressWalletBrand | null | undefined;
  /** After create-store redirect: auto-open SamRock QR flow */
  autoSamrock?: boolean;
  /** CashuMelt parallel fallback payout address, when configured on the store. */
  cashuFallbackAddress?: string | null;
}

const props = defineProps<Props>();

const switchToCashuIntent = ref(false);
/** Setup tabs: Lightning address quick-connect (default) / Aqua-Bull SamRock / advanced strings. */
type WalletSetupTabId = "ln" | "samrock" | "advanced";
const walletSetupTabsList: ReadonlyArray<{ id: WalletSetupTabId; labelKey: string }> = [
  { id: "ln", labelKey: "create_store.tab_lightning_address" },
  { id: "samrock", labelKey: "create_store.tab_samrock" },
  { id: "advanced", labelKey: "create_store.tab_advanced" },
];
const walletSetupTab = ref<WalletSetupTabId>("ln");

/** CashuMelt parallel fallback: every store keeps at least one Lightning address. */
const samrockFallbackAddress = ref("");
const advancedFallbackAddress = ref("");

const samrockFallbackValid = computed(() =>
  isValidCashuLightningAddress(samrockFallbackAddress.value),
);

const advancedFallbackDerivable = computed(() =>
  fallbackAddressDerivableFromSecret(form.secret ?? ""),
);

const advancedFallbackOk = computed(
  () =>
    advancedFallbackDerivable.value ||
    isValidCashuLightningAddress(advancedFallbackAddress.value),
);

const isUnsetWalletType = computed(() => {
  const w = props.walletType;
  return w === null || w === undefined || w === "";
});

/** Cashu store wants to switch to a Lightning wallet (shows the LN setup form). */
const switchToLightningIntent = ref(false);

const isCashuFlow = computed(() => {
  if (props.walletType === "cashu") return !switchToLightningIntent.value;
  return switchToCashuIntent.value;
});

const showWalletSetupForm = computed(() => {
  if (viewMode.value !== "create" && viewMode.value !== "editing") return false;
  if (isCashuFlow.value) return false;
  const wt = props.walletType;
  if (wt === "cashu") return switchToLightningIntent.value;
  if (wt === "aqua_boltz" || wt === "blink" || wt === "blitz" || wt === "flash" || wt === "lnaddress" || wt === "nwc") return true;
  if (isUnsetWalletType.value) return true;
  return false;
});

/**
 * SamRock can (re)provision Aqua/Bull for any non-Cashu store (backend allows the
 * Blink/NWC → Aqua switch). While a Cashu store is switching to Lightning the tab is
 * hidden: SamRockController rejects Cashu stores until the LN connection is saved.
 */
const showWalletSetupTabs = computed(
  () => !isCashuFlow.value && props.walletType !== "cashu",
);

const showAquaDescriptorWarnings = computed(() => {
  if (props.walletType === "nwc" || props.walletType === "blink" || props.walletType === "blitz" || props.walletType === "flash" || props.walletType === "lnaddress") return false;
  if (props.walletType === "aqua_boltz") return true;
  return form.type === "aqua_descriptor";
});

const emit = defineEmits<{
  /** target = the connection type that was saved; undefined only for flows that are already connected when they emit. */
  submitted: [target?: string];
  cancel: [];
}>();

const { t } = useI18n();

const walletTypeLabel = computed(() => {
  const w = props.walletType;
  if (w === null || w === undefined || w === "")
    return t("stores.wallet_type_not_set");
  if (w === "aqua_boltz") {
    const brand = props.walletBrand ?? readonlyAquaWalletBrand.value;
    return brand === "bull"
      ? t("create_store.wallet_type_bull")
      : t("create_store.wallet_type_aqua");
  }
  if (w === "blink") return t("create_store.wallet_type_blink");
  if (w === "blitz") return t("create_store.wallet_type_blitz");
  if (w === "flash") return t("create_store.wallet_type_flash");
  if (w === "lnaddress") {
    if (props.walletBrand === "blitz") return t("create_store.wallet_type_blitz");
    if (props.walletBrand === "flash") return t("create_store.wallet_type_flash");
    if (props.walletBrand === "coinos") return t("create_store.wallet_type_coinos");
    return t("create_store.wallet_type_lnaddress");
  }
  if (w === "cashu") return t("create_store.wallet_type_cashu");
  if (w === "nwc") return t("create_store.wallet_type_nwc");
  return String(w);
});

const authStore = useAuthStore();
const route = useRoute();
const router = useRouter();

type ViewMode = "readonly" | "password" | "code" | "editing" | "create";

type WalletConnectionFormType = "blink" | "blitz" | "flash" | "lnaddress" | "aqua_descriptor" | "nwc";

function defaultWalletConnectionType(
  walletType: Props["walletType"],
  existing?: Props["existingConnection"],
): WalletConnectionFormType {
  if (existing?.type) {
    return existing.type as WalletConnectionFormType;
  }
  if (walletType === "nwc") return "nwc";
  if (walletType === "blink") return "blink";
  if (walletType === "blitz") return "blitz";
  if (walletType === "flash") return "flash";
  if (walletType === "lnaddress") return "lnaddress";
  return "aqua_descriptor";
}

/** Pending = user can still use SamRock QR or finish manual descriptor; do not lock to readonly. */
function initialWalletViewMode(conn: Props["existingConnection"]): ViewMode {
  if (!conn) {
    return "create";
  }
  if (conn.status === "pending") {
    return "create";
  }

  return "readonly";
}

const viewMode = ref<ViewMode>(initialWalletViewMode(props.existingConnection));

const passwordInput = ref("");
const passwordError = ref("");
const revealing = ref(false);
/** Staged email-code challenge for replacing a CONNECTED wallet (WalletChangeConfirmationGuard). */
const changeChallenge = ref<EmailChallengeSummary | null>(
  props.existingConnection?.change_confirmation?.pending ?? null,
);
const { openGuestUpgradeModal } = useGuestUpgradeModal();

/** Legacy email/password accounts; recovery-phrase users have no password. */
const canUsePasswordLogin = computed(
  () => authStore.user?.can_use_password_login ?? true,
);
/** Shared post-reveal handoff: prefill the setup form and switch to editing. */
function applyRevealedConnection(reveal: { secret?: string; type?: string }) {
  form.type = (reveal.type ||
    props.existingConnection?.type ||
    defaultWalletConnectionType(
      props.walletType,
      props.existingConnection,
    )) as WalletConnectionFormType;
  form.secret = reveal.secret || "";
  sanitizeSecretForDeclaredType();
  syncWalletFormAquaTabAfterReveal();
  viewMode.value = "editing";
}

const autoSamrockConsumed = ref(false);

const {
  samrockOtp,
  samrockExpiresAt,
  samrockQrObjectUrl,
  samrockBusy,
  samrockPollStatus,
  samrockErrorMessage,
  cancelSamRockPairing,
  startSamRockPairing: startSamRockPairingCore,
} = useSamRockPairing(() => props.storeId);

function formatSamRockExpiry(iso: string) {
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

function focusAdjacentWalletTab(direction: -1 | 1) {
  const ids = walletSetupTabsList.map((tab) => tab.id);
  const current = ids.indexOf(walletSetupTab.value);
  const next = ids[(current + direction + ids.length) % ids.length]!;
  walletSetupTab.value = next;
  void nextTick(() => {
    document.getElementById(`wallet-setup-tab-${next}`)?.focus();
  });
}

/** QuickConnect saved the wallet - forward WHERE it landed so the parent can wait for provisioning. */
function onQuickConnectSubmitted(target: "blink" | "blitz" | "cashu" | "lnaddress") {
  emit("submitted", target);
}

function startSamRockPairing() {
  if (!samrockFallbackValid.value) return;
  if (route.query.samrock) {
    const q = { ...route.query };
    delete q.samrock;
    router.replace({ path: route.path, query: q });
  }
  void startSamRockPairingCore(() => {
    // SamRock pairing resolves only once the wallet is connected - no wait needed.
    emit("submitted", "samrock");
  }, samrockFallbackAddress.value);
}

const submitting = ref(false);
const testing = ref(false);
const errors = reactive<Record<string, string>>({});
const testResult = ref<{
  success: boolean;
  message: string;
  requires_manual_config?: boolean;
} | null>(null);

const form = reactive({
  type: defaultWalletConnectionType(
    props.walletType,
    props.existingConnection,
  ),
  secret: "",
});

const aquaWalletBrand = ref<AquaBoltzWalletBrand>("aqua");

const detectedAquaWalletBrand = computed((): AquaBoltzWalletBrand | null =>
  detectWalletBrandFromDescriptor(form.secret),
);

const walletPasteDetection = computed(() =>
  detectWalletConnectionInput(form.secret ?? ""),
);

/** Stable brand for read-only view (no mutable chip state). */
const readonlyAquaWalletBrand = computed((): AquaBoltzWalletBrand => {
  if (props.walletBrand === "bull" || props.walletBrand === "aqua") {
    return props.walletBrand;
  }
  if (
    props.existingConnection?.brand === "bull" ||
    props.existingConnection?.brand === "aqua"
  ) {
    return props.existingConnection.brand;
  }
  if (props.existingConnection?.configuration_source === "samrock") {
    return "aqua";
  }
  return detectedAquaWalletBrand.value ?? "aqua";
});

watch(detectedAquaWalletBrand, (detected) => {
  if (detected && viewMode.value !== "readonly") {
    aquaWalletBrand.value = detected;
  }
});

function startSwitchToCashu() {
  switchToCashuIntent.value = true;
}

watch(
  () => props.walletType,
  (wt) => {
    if (wt === "aqua_boltz") {
      form.type = "aqua_descriptor";
    }
  },
  { immediate: true },
);

watch(
  () => props.storeId,
  () => {
    switchToCashuIntent.value = false;
    switchToLightningIntent.value = false;
    walletSetupTab.value = "ln";
  },
);

watch(
  () => props.existingConnection,
  (conn) => {
    if (!conn) {
      viewMode.value = "create";

      return;
    }
    if (viewMode.value !== "editing") {
      form.type = (conn.type || "blink") as WalletConnectionFormType;
    }
    if (conn.status === "pending") {
      viewMode.value = "create";
    } else if (viewMode.value === "create") {
      viewMode.value = "readonly";
    }
  },
  { immediate: true },
);

watch(
  () => [props.autoSamrock, showWalletSetupForm.value, showWalletSetupTabs.value] as const,
  async () => {
    if (!props.autoSamrock || autoSamrockConsumed.value) return;
    if (!showWalletSetupForm.value || !showWalletSetupTabs.value) return;
    autoSamrockConsumed.value = true;
    // Open the tab only - QR generation now needs the fallback Lightning address first.
    walletSetupTab.value = "samrock";
    await nextTick();
  },
  { flush: "post" },
);

const changeNeedsGuestUpgrade = computed(
  () =>
    props.existingConnection?.change_confirmation?.guest_upgrade_required === true ||
    (props.existingConnection?.change_confirmation?.required === true && authStore.user?.is_guest === true),
);

function promptGuestUpgradeForWalletChange() {
  passwordError.value = t("stores.wallet_change_guest_required");
  openGuestUpgradeModal("stores.wallet_change_feature_label");
}

function startWalletConnectionEdit() {
  passwordError.value = "";
  passwordInput.value = "";
  if (changeNeedsGuestUpgrade.value) {
    // Guests have no inbox for the code: upgrade to Free first.
    promptGuestUpgradeForWalletChange();
    return;
  }
  if (changeChallenge.value && props.existingConnection?.change_confirmation?.required) {
    // A code is already on its way (e.g. reload mid-flow).
    viewMode.value = "code";
    return;
  }
  if (!canUsePasswordLogin.value) {
    void handleConfirmPassword();
    return;
  }
  viewMode.value = "password";
}

/**
 * Password step. For a CONNECTED wallet the server answers with an email-code
 * challenge (the secret is revealed once the code is confirmed); otherwise it
 * reports required=false and we fall back to the plain reveal.
 */
async function handleConfirmPassword() {
  if (canUsePasswordLogin.value && !passwordInput.value.trim()) return;
  passwordError.value = "";
  revealing.value = true;
  try {
    const payload = canUsePasswordLogin.value
      ? { password: passwordInput.value }
      : {};
    const change = await walletApi.connection.change.request(props.storeId, payload);
    passwordInput.value = "";
    if (change.required && change.challenge) {
      changeChallenge.value = change.challenge;
      viewMode.value = "code";
      return;
    }
    const reveal = await walletApi.connection.reveal(props.storeId, payload);
    applyRevealedConnection(reveal);
  } catch (rawError) {
    const err = asApiError(rawError);
    if (err.response?.data?.code === "guest_upgrade_required") {
      viewMode.value = "readonly";
      promptGuestUpgradeForWalletChange();
      return;
    }
    const msg =
      err.response?.data?.errors?.password?.[0] ||
      err.response?.data?.message ||
      t("stores.invalid_password");
    passwordError.value = msg;
  } finally {
    revealing.value = false;
  }
}

async function handleConfirmChangeCode(code: string) {
  const grant = await walletApi.connection.change.confirm(props.storeId, code);
  changeChallenge.value = null;
  applyRevealedConnection(grant);
}

async function handleResendChangeCode() {
  const next = await walletApi.connection.change.resend(props.storeId);
  if (next) changeChallenge.value = next;
  return next;
}

function cancelChangeCode() {
  viewMode.value = "readonly";
  passwordError.value = "";
}

function handleCancelEdit() {
  switchToCashuIntent.value = false;
  walletSetupTab.value = "ln";
  form.secret = "";
  if (props.existingConnection) {
    form.type = defaultWalletConnectionType(
      props.walletType,
      props.existingConnection,
    );
  }
  viewMode.value = "readonly";
  testResult.value = null;
  Object.keys(errors).forEach((k) => delete errors[k]);
}

function validateDescriptor(descriptor: string): boolean {
  if (!isValidAquaBoltzDescriptor(descriptor)) {
    return false;
  }
  const detected = detectWalletBrandFromDescriptor(descriptor);
  if (detected && detected !== aquaWalletBrand.value) {
    return false;
  }
  return true;
}

/** Drop Blink-shaped secrets when the form expects a descriptor (and vice versa). */
function sanitizeSecretForDeclaredType() {
  const raw = form.secret.trim();
  if (!raw) return;
  if (form.type === "aqua_descriptor") {
    if (
      validateBlinkConnectionString(raw) ||
      raw.toLowerCase().startsWith("type=blink")
    ) {
      form.secret = "";
    }
  } else if (form.type === "blink" && validateDescriptor(raw)) {
    form.secret = "";
  }
}

function syncWalletFormAquaTabAfterReveal() {
  if (form.type !== "aqua_descriptor") {
    walletSetupTab.value = "advanced";
    return;
  }
  const hasDescriptor = validateDescriptor(form.secret);
  walletSetupTab.value =
    hasDescriptor || !showWalletSetupTabs.value ? "advanced" : "samrock";
}

watch(
  () => form.type,
  (newType, oldType) => {
    if (oldType === undefined || !oldType || newType === oldType) return;
    if (newType === "aqua_descriptor" && oldType === "blink") {
      const s = form.secret.trim();
      if (
        s &&
        (s.toLowerCase().startsWith("type=blink") ||
          validateBlinkConnectionString(s))
      ) {
        form.secret = "";
      }
    } else if (newType === "blink" && oldType === "aqua_descriptor") {
      const s = form.secret.trim();
      if (s && validateDescriptor(s)) {
        form.secret = "";
      }
    }
    if (newType === "aqua_descriptor" && showWalletSetupTabs.value) {
      walletSetupTab.value = "advanced";
    }
  },
);

async function handleTestConnection() {
  if (!form.secret.trim()) {
    testResult.value = {
      success: false,
      message: "Please enter a connection string or descriptor first.",
    };
    return;
  }
  if (form.type === "blink" && !validateBlinkConnectionString(form.secret)) {
    testResult.value = {
      success: false,
      message: "Invalid Blink connection string format.",
    };
    return;
  }
  if (form.type === "blitz" && !validateBlitzConnectionString(form.secret)) {
    testResult.value = {
      success: false,
      message: t("stores.blitz_invalid_connection"),
    };
    return;
  }
  if (form.type === "flash" && !validateFlashConnectionString(form.secret)) {
    testResult.value = {
      success: false,
      message: t("stores.flash_invalid_connection"),
    };
    return;
  }
  if (form.type === "lnaddress" && !validateLnAddressConnectionString(form.secret)) {
    testResult.value = {
      success: false,
      message: t("stores.lnaddress_invalid_connection"),
    };
    return;
  }
  if (form.type === "aqua_descriptor" && !validateDescriptor(form.secret)) {
    testResult.value = {
      success: false,
      message: t("create_store.invalid_descriptor_format"),
    };
    return;
  }
  if (form.type === "nwc" && isCashuWalletNwcUri(form.secret)) {
    testResult.value = {
      success: false,
      message: t("stores.wallet_cashu_nwc_rejected"),
    };
    return;
  }
  if (form.type === "nwc" && !validateNwcUri(form.secret)) {
    testResult.value = {
      success: false,
      message: t("stores.nwc_invalid_connection"),
    };
    return;
  }
  testing.value = true;
  testResult.value = null;
  try {
    const result = await walletApi.connection.test(props.storeId, {
      connection_string: formatConnectionStringForApi(form.secret, form.type),
      crypto_code: "BTC",
    });
    testResult.value = {
      success: result.success ?? false,
      message: result.message || "Connection test completed",
      requires_manual_config: result.requires_manual_config ?? false,
    };
  } catch (rawError) {
    const err = asApiError(rawError);
    testResult.value = {
      success: false,
      message:
        err.response?.data?.message ||
        "Failed to test connection. Please try again.",
    };
  } finally {
    testing.value = false;
  }
}

function formatConnectionStringForApi(value: string, type: string): string {
  if (type === "blink") {
    return normalizeBlinkConnectionString(value);
  }
  if (type === "blitz") {
    return normalizeBlitzConnectionString(value);
  }
  if (type === "flash") {
    return normalizeFlashConnectionString(value);
  }
  if (type === "lnaddress") {
    return normalizeLnAddressConnectionString(value);
  }
  if (type !== "nwc") {
    return value.trim();
  }
  const trimmed = value.trim();
  if (trimmed.toLowerCase().startsWith("type=nwc;")) {
    return trimmed;
  }
  const normalized = trimmed.replace(
    "nostr+walletconnect://",
    "nostr+walletconnect:",
  );
  return `type=nwc;key=${normalized}`;
}

function onDetectCashuFromPaste(_payload: {
  mintUrl: string;
  lightningAddress: string | null;
}) {
  if (walletPasteDetection.value.kind === "cashu_wallet_nwc") {
    return;
  }
  if (props.walletType === "cashu") {
    // Already a Cashu store (mid-switch to Lightning): pasted Cashu content means "stay".
    switchToLightningIntent.value = false;
    return;
  }
  startSwitchToCashu();
}

async function handleSubmit() {
  submitting.value = true;
  Object.keys(errors).forEach((k) => delete errors[k]);
  testResult.value = null;

  if (form.type === "blink" && !validateBlinkConnectionString(form.secret)) {
    errors.secret = "Invalid Blink connection string format.";
    submitting.value = false;
    return;
  }
  if (form.type === "blitz" && !validateBlitzConnectionString(form.secret)) {
    errors.secret = t("stores.blitz_invalid_connection");
    submitting.value = false;
    return;
  }
  if (form.type === "flash" && !validateFlashConnectionString(form.secret)) {
    errors.secret = t("stores.flash_invalid_connection");
    submitting.value = false;
    return;
  }
  if (form.type === "lnaddress" && !validateLnAddressConnectionString(form.secret)) {
    errors.secret = t("stores.lnaddress_invalid_connection");
    submitting.value = false;
    return;
  }
  if (form.type === "aqua_descriptor" && !validateDescriptor(form.secret)) {
    errors.secret = t("create_store.invalid_descriptor_format");
    submitting.value = false;
    return;
  }
  if (form.type === "nwc" && isCashuWalletNwcUri(form.secret)) {
    errors.secret = t("stores.wallet_cashu_nwc_rejected");
    submitting.value = false;
    return;
  }
  if (form.type === "nwc" && !validateNwcUri(form.secret)) {
    errors.secret = t("stores.nwc_invalid_connection");
    submitting.value = false;
    return;
  }

  if (!advancedFallbackOk.value) {
    errors.fallback_lightning_address = t("stores.wallet_fallback_address_invalid");
    submitting.value = false;
    return;
  }

  try {
    const secretPayload =
      form.type === "nwc"
        ? normalizeNwcUri(form.secret)
        : form.type === "blink"
          ? normalizeBlinkConnectionString(form.secret)
          : form.type === "blitz"
            ? normalizeBlitzConnectionString(form.secret)
            : form.type === "flash"
              ? normalizeFlashConnectionString(form.secret)
              : form.type === "lnaddress"
                ? normalizeLnAddressConnectionString(form.secret)
                : form.secret.trim();

    await walletApi.connection.create(props.storeId, {
      type: form.type,
      secret: secretPayload,
      fallback_lightning_address: advancedFallbackDerivable.value
        ? undefined
        : advancedFallbackAddress.value.trim(),
    });
    emit("submitted", form.type);
  } catch (rawError) {
    const err = asApiError(rawError);
    const code = err.response?.data?.code;
    if (code === "wallet_change_confirmation_required") {
      // Grant expired (or never granted): back through the confirmation step.
      errors.general = t("stores.wallet_change_grant_expired");
      changeChallenge.value = null;
      startWalletConnectionEdit();
      return;
    }
    if (code === "guest_upgrade_required") {
      viewMode.value = "readonly";
      promptGuestUpgradeForWalletChange();
      return;
    }
    if (err.response?.status === 422) {
      const validationErrors = err.response?.data?.errors ?? {};
      Object.keys(validationErrors).forEach((key) => {
        const v = validationErrors[key];
        errors[key] = (Array.isArray(v) ? v[0] : v) ?? "";
      });
    } else {
      errors.general =
        err.response?.data?.message || "Failed to save wallet connection";
    }
  } finally {
    submitting.value = false;
  }
}
</script>
