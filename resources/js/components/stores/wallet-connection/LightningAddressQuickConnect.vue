<template>
  <div class="space-y-5">
    <div>
      <label
        for="ln-quick-address"
        class="block text-sm font-medium text-gray-500 mb-2 uppercase tracking-wider"
      >
        {{ t("stores.ln_quick_address_label") }}
      </label>
      <input
        id="ln-quick-address"
        v-model="address"
        type="text"
        autocomplete="off"
        spellcheck="false"
        class="block w-full rounded-xl border-gray-600 bg-gray-900/50 text-white placeholder-gray-600 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-3"
        :placeholder="t('stores.ln_quick_address_placeholder')"
      />
      <p class="mt-2 text-sm text-gray-500 leading-relaxed">
        {{ t("stores.ln_quick_address_hint") }}
      </p>
    </div>

    <!-- Detected target chip -->
    <div
      v-if="route"
      class="flex items-center gap-2 rounded-xl border border-gray-700 bg-gray-900/40 px-4 py-3"
    >
      <span class="text-xs text-gray-500 uppercase tracking-wider">{{
        t("stores.wallet_detected_label")
      }}</span>
      <WalletTypeIcon
        :type="effectiveTarget === 'probe' ? 'lnaddress' : effectiveTarget"
        :brand="route.brand"
        size="sm"
        show-label
      />
      <span v-if="effectiveTarget === 'cashu'" class="text-xs text-gray-500">
        {{ t("stores.ln_quick_cashu_note") }}
      </span>
      <span v-else-if="effectiveTarget === 'probe'" class="text-xs text-gray-500">
        {{ t("stores.ln_quick_probe_note") }}
      </span>
    </div>
    <p
      v-else-if="address.trim() !== ''"
      class="text-sm text-amber-300/90"
    >
      {{ t("stores.ln_quick_invalid_address") }}
    </p>

    <!-- Probe outcome: unknown wallet without LUD-21 falls back to the Cashu path -->
    <p
      v-if="effectiveTarget === 'cashu' && probeState === 'cashu'"
      class="text-sm text-gray-400 leading-relaxed"
    >
      {{ t("stores.ln_quick_probe_cashu_fallback") }}
    </p>

    <!-- Compact Cashu beta consent (only when routed to CashuMelt) -->
    <label
      v-if="effectiveTarget === 'cashu'"
      class="flex items-start gap-3 cursor-pointer select-none rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3"
    >
      <input
        v-model="cashuConsent"
        type="checkbox"
        class="mt-1 h-4 w-4 rounded border-gray-500 bg-gray-800 text-indigo-600 focus:ring-indigo-500"
      />
      <span class="text-sm text-amber-100 leading-relaxed">{{
        t("stores.ln_quick_cashu_consent")
      }}</span>
    </label>

    <div
      v-if="errorMessage"
      class="rounded-xl border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-300"
    >
      {{ errorMessage }}
    </div>

    <button
      type="button"
      :disabled="!canSubmit || submitting"
      class="px-6 py-3 border border-transparent rounded-xl shadow-lg shadow-indigo-600/20 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed transition-all w-full sm:w-auto"
      @click="submit"
    >
      <span v-if="probing">{{ t("stores.ln_quick_probe_checking") }}</span>
      <span v-else-if="submitting">{{ t("common.loading") }}</span>
      <span v-else>{{ t("stores.ln_quick_connect_button") }}</span>
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { walletApi } from "../../../services/api";
import { DEFAULT_CASHU_MINT_URL } from "../../../constants/cashu";
import {
  routeLightningAddress,
  type LightningAddressTarget,
} from "../../../utils/lightningAddressRouting";
import { normalizeLnAddressConnectionString } from "../../../utils/lnAddressWalletBrands";
import { getApiErrorMessage } from "../../../composables/useApiError";
import { asApiError } from "../../../utils/apiError";
import WalletTypeIcon from "../../WalletTypeIcon.vue";

const props = defineProps<{
  storeId: string;
}>();

const emit = defineEmits<{
  /** Fired after a successful save; target tells the parent where the store landed. */
  submitted: [target: Exclude<LightningAddressTarget, "probe">];
}>();

const { t } = useI18n();

const address = ref("");
const cashuConsent = ref(false);
const submitting = ref(false);
const probing = ref(false);
const errorMessage = ref("");

/** Result of the server-side LUD-21 probe for the current address. */
const probeState = ref<"idle" | "lnaddress" | "cashu">("idle");

/** Bumped on every address edit so an in-flight probe can detect it is stale. */
let addressRevision = 0;

const route = computed(() => routeLightningAddress(address.value));

// A new address invalidates the previous probe verdict and its consent.
watch(address, () => {
  addressRevision += 1;
  probeState.value = "idle";
  cashuConsent.value = false;
});

/**
 * Where the current address would land: probe results upgrade 'probe' to
 * 'lnaddress' (LUD-21 supported) or downgrade it to 'cashu'.
 */
const effectiveTarget = computed((): LightningAddressTarget | null => {
  if (!route.value) return null;
  if (route.value.target !== "probe") return route.value.target;
  if (probeState.value === "lnaddress") return "lnaddress";
  if (probeState.value === "cashu") return "cashu";
  return "probe";
});

const canSubmit = computed(() => {
  if (!effectiveTarget.value) return false;
  if (effectiveTarget.value === "cashu" && !cashuConsent.value) return false;
  return true;
});

async function submit() {
  const r = route.value;
  if (!r || submitting.value) return;

  submitting.value = true;
  errorMessage.value = "";
  try {
    let target = effectiveTarget.value;

    if (target === "probe") {
      const revision = addressRevision;
      probing.value = true;
      try {
        const probe = await walletApi.connection.lnaddressProbe(props.storeId, r.address);
        // The address changed while the probe was in flight - drop the stale
        // verdict instead of applying it to the new address.
        if (revision !== addressRevision) {
          return;
        }
        probeState.value = probe.lud21 ? "lnaddress" : "cashu";
        target = probeState.value;
      } finally {
        probing.value = false;
      }
      // No LUD-21 → the Cashu consent checkbox appears; the merchant confirms
      // and submits again.
      if (target === "cashu" && !cashuConsent.value) {
        return;
      }
    }

    if (target === "cashu") {
      await walletApi.cashu.updateSettings(props.storeId, {
        mint_url: DEFAULT_CASHU_MINT_URL,
        lightning_address: r.address,
        enabled: true,
      });
    } else if (target === "lnaddress") {
      await walletApi.connection.create(props.storeId, {
        type: "lnaddress",
        secret: r.connectionSecret ?? normalizeLnAddressConnectionString(r.address),
      });
    } else if (target === "blink") {
      await walletApi.connection.create(props.storeId, {
        type: "blink",
        secret: r.connectionSecret!,
      });
    } else {
      return;
    }
    emit("submitted", target);
  } catch (rawError) {
    const err = asApiError(rawError);
    const validationErrors = err.response?.data?.errors;
    if (err.response?.status === 422 && validationErrors) {
      const first = Object.values(validationErrors)[0];
      errorMessage.value = (Array.isArray(first) ? first[0] : first) ?? "";
    }
    if (!errorMessage.value) {
      errorMessage.value = getApiErrorMessage(
        rawError,
        t("stores.ln_quick_connect_error"),
      );
    }
  } finally {
    submitting.value = false;
  }
}
</script>
