<template>
  <Teleport to="body">
  <div
    v-if="open"
    class="fixed z-[100] inset-0 overflow-y-auto"
  >
    <div class="fixed inset-0 bg-gray-900/90 backdrop-blur-sm" />
    <div class="flex min-h-full items-center justify-center p-4">
      <div
        class="relative w-full max-w-lg rounded-2xl border border-gray-700 bg-gray-800 shadow-xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="passkey-offer-title"
        @keydown.esc="skip"
      >
        <div class="p-6 sm:p-8 space-y-4">
          <h3 id="passkey-offer-title" class="text-lg font-bold text-white">
            {{ t("auth.passkey_offer_title") }}
          </h3>
          <p class="text-sm text-gray-400">
            {{ t("auth.passkey_offer_body") }}
          </p>
          <div class="space-y-1">
            <label for="passkey-offer-label" class="block text-xs font-medium text-gray-400">
              {{ t("account.passkey_label_placeholder") }}
            </label>
            <input
              id="passkey-offer-label"
              v-model="labelInput"
              type="text"
              autocomplete="off"
              class="w-full rounded-xl border border-gray-600 bg-gray-900/80 px-4 py-3 text-sm text-gray-200 placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              :aria-describedby="error ? 'passkey-offer-error' : undefined"
            />
          </div>
          <div v-if="error" id="passkey-offer-error" class="text-sm text-red-400">
            {{ error }}
          </div>
          <button
            ref="createButton"
            type="button"
            :disabled="busy || prfBlocked"
            class="w-full flex items-center justify-center gap-2 py-3 px-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-800 disabled:opacity-50 transition-all"
            @click="submit"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" /></svg>
            {{ busy ? t("common.loading") : t("auth.passkey_offer_create") }}
          </button>
          <button
            type="button"
            :disabled="busy"
            class="w-full py-2 text-sm font-medium text-gray-400 hover:text-white rounded-lg transition-colors disabled:opacity-50"
            @click="skip"
          >
            {{ t("auth.passkey_offer_skip") }}
          </button>
        </div>
      </div>
    </div>
  </div>
  </Teleport>
</template>

<script setup lang="ts">
import { nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
  addAccountPasskeyFromSession,
  PasskeyEnvelopeUploadError,
  upgradeAccountPasskey,
} from "../../services/deviceUnlock/provider";
import {
  PasskeyCancelledError,
  PasskeyPrfUnsupportedError,
  PasskeyUnsupportedError,
} from "../../services/deviceUnlock/passkeyPrf";
import { snoozePasskeyOffer } from "../../services/passkeyEnrollOffer";
import { trackEvent } from "../../services/analytics";
import { useFlashStore } from "../../store/flash";

const props = defineProps<{
  open: boolean;
  /** "register" = right after enrollment; "restore" = after a typed-seed sign-in. */
  context: "register" | "restore";
}>();

const emit = defineEmits<{
  done: [];
  skip: [];
}>();

const { t } = useI18n();
const flashStore = useFlashStore();

const labelInput = ref("");
const error = ref("");
const busy = ref(false);
const createButton = ref<HTMLButtonElement | null>(null);
/** Credential minted but its envelope upload failed - retry against it, never create() again. */
const pendingCredentialIdB64 = ref<string | null>(null);
/** The authenticator ignored PRF: another create() can only mint more unusable credentials. */
const prfBlocked = ref(false);
let previouslyFocused: HTMLElement | null = null;

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      labelInput.value = "";
      error.value = "";
      previouslyFocused =
        document.activeElement instanceof HTMLElement ? document.activeElement : null;
      trackEvent("auth", "passkey_offer_shown", props.context);
      void nextTick(() => {
        createButton.value?.focus();
      });
    } else {
      previouslyFocused?.focus();
      previouslyFocused = null;
    }
  },
  { immediate: true },
);

/**
 * The offer must NEVER block the sign-in/registration flow: cancelling the
 * platform prompt keeps the modal open silently ("not now" stays one click
 * away), every other failure shows an inline message with the same escape.
 */
async function submit(): Promise<void> {
  error.value = "";
  busy.value = true;
  try {
    const label = labelInput.value.trim() || t("account.passkey_default_label");
    if (pendingCredentialIdB64.value) {
      await upgradeAccountPasskey(pendingCredentialIdB64.value, label);
    } else {
      await addAccountPasskeyFromSession(label);
    }
    pendingCredentialIdB64.value = null;
    flashStore.success(t("account.passkey_added"));
    trackEvent("auth", "passkey_offer_accepted", props.context);
    emit("done");
  } catch (rawError) {
    if (rawError instanceof PasskeyCancelledError) {
      return;
    }
    if (rawError instanceof PasskeyEnvelopeUploadError) {
      pendingCredentialIdB64.value = rawError.credentialIdB64;
      error.value = t("auth.passkey_offer_error");
      return;
    }
    if (rawError instanceof PasskeyPrfUnsupportedError) {
      prfBlocked.value = true;
      error.value = t("auth.passkey_browser_no_prf");
      return;
    }
    if (rawError instanceof PasskeyUnsupportedError) {
      error.value = t("account.passkey_unsupported");
      return;
    }
    error.value = t("auth.passkey_offer_error");
  } finally {
    busy.value = false;
  }
}

function skip(): void {
  // Escape routes here too - never bail out mid-enrollment.
  if (busy.value) {
    return;
  }
  // Only an explicit "not now" after a sign-in nudge snoozes; the one-time
  // post-registration offer never suppresses the later nudges.
  if (props.context === "restore") {
    snoozePasskeyOffer();
  }
  trackEvent("auth", "passkey_offer_skipped", props.context);
  emit("skip");
}
</script>
