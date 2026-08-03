<template>
  <div
    v-if="open"
    class="fixed z-50 inset-0 overflow-y-auto"
    @click.self="$emit('close')"
  >
    <div class="fixed inset-0 bg-gray-900/90 backdrop-blur-sm" @click.self="$emit('close')" />
    <div class="flex min-h-full items-center justify-center p-4">
      <div
        class="relative w-full max-w-lg rounded-2xl border border-gray-700 bg-gray-800 shadow-xl"
        role="dialog"
        aria-modal="true"
      >
        <div class="p-6 sm:p-8 space-y-4">
          <div class="flex justify-between items-start gap-4">
            <h3 class="text-lg font-bold text-white">
              {{ t("auth.guest_restore_title") }}
            </h3>
            <button
              type="button"
              class="text-gray-400 hover:text-white text-sm"
              @click="$emit('close')"
            >
              {{ t("common.close") }}
            </button>
          </div>
          <template v-if="passkey.supported.value">
            <button
              type="button"
              :disabled="passkey.loading.value || loading"
              class="w-full flex items-center justify-center gap-2 py-3 px-4 text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 disabled:opacity-50 transition-all"
              :class="
                passkey.ownerSwitchImpact.value
                  ? 'bg-amber-600 hover:bg-amber-500 focus:ring-amber-500'
                  : 'bg-indigo-600 hover:bg-indigo-500 focus:ring-indigo-500'
              "
              @click="submitWithPasskey"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" /></svg>
              {{
                passkey.loading.value
                  ? t("common.loading")
                  : passkey.ownerSwitchImpact.value
                    ? t("auth.guest_restore_owner_switch_confirm")
                    : t("auth.passkey_login_button")
              }}
            </button>
            <div
              v-if="passkey.ownerSwitchImpact.value"
              class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 space-y-2"
            >
              <p class="text-sm font-medium text-amber-300">
                {{ t("auth.guest_restore_owner_switch_title") }}
              </p>
              <p class="text-sm text-gray-300">
                {{
                  t("auth.guest_restore_owner_switch_body", {
                    documents: passkey.ownerSwitchImpact.value.documents,
                    contacts: passkey.ownerSwitchImpact.value.contacts,
                    companies: passkey.ownerSwitchImpact.value.companies,
                  })
                }}
              </p>
            </div>
            <p class="text-center text-xs text-gray-500">
              {{ t("account.passkey_or_phrase_divider") }}
            </p>
          </template>
          <p class="text-sm text-gray-400">
            {{ t("auth.guest_restore_hint") }}
          </p>
          <SeedPhraseInput
            v-model="mnemonicInput"
            :placeholder="t('auth.guest_restore_placeholder')"
          />
          <div class="rounded-xl border border-gray-700 bg-gray-900/50 p-4 space-y-3">
            <label class="flex items-start gap-3 cursor-pointer select-none">
              <input
                v-model="rememberDevice"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-gray-500 bg-gray-800 text-indigo-600 focus:ring-indigo-500"
              />
              <span class="text-sm text-gray-200">
                {{ t("account.device_remember_label") }}
                <span class="block text-xs text-gray-500 mt-1">
                  {{ t("account.device_remember_hint") }}
                </span>
              </span>
            </label>
            <input
              v-if="rememberDevice"
              v-model="devicePassphrase"
              type="password"
              autocomplete="new-password"
              :aria-label="t('account.device_passphrase_new_placeholder')"
              class="w-full rounded-xl border border-gray-600 bg-gray-900/80 px-4 py-3 text-sm text-gray-200"
              :placeholder="t('account.device_passphrase_new_placeholder')"
            />
          </div>
          <div v-if="error" class="text-sm text-red-400">
            {{ error }}
          </div>
          <div
            v-if="ownerSwitchImpact"
            class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 space-y-2"
          >
            <p class="text-sm font-medium text-amber-300">
              {{ t("auth.guest_restore_owner_switch_title") }}
            </p>
            <p class="text-sm text-gray-300">
              {{
                t("auth.guest_restore_owner_switch_body", {
                  documents: ownerSwitchImpact.documents,
                  contacts: ownerSwitchImpact.contacts,
                  companies: ownerSwitchImpact.companies,
                })
              }}
            </p>
          </div>
          <button
            type="button"
            :disabled="loading || passkey.loading.value"
            class="w-full py-3 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
            :class="ownerSwitchImpact ? 'bg-amber-600 hover:bg-amber-500' : 'bg-indigo-600 hover:bg-indigo-500'"
            @click="submit"
          >
            {{
              loading
                ? t("common.loading")
                : ownerSwitchImpact
                  ? t("auth.guest_restore_owner_switch_confirm")
                  : t("auth.guest_restore_submit")
            }}
          </button>
        </div>
      </div>
    </div>
    <PasskeyEnrollOfferModal
      :open="showPasskeyOffer"
      context="restore"
      @done="finishPasskeyOffer"
      @skip="finishPasskeyOffer"
    />
  </div>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "../../store/auth";
import { storeGuestMnemonic } from "../../services/guestRecovery";
import { rememberDeviceWithPassphrase } from "../../services/deviceUnlock/provider";
import { isAcceptableDevicePassphrase } from "../../services/deviceUnlock/envelope";
import { previewOwnerSwitchImpact, type OwnerSwitchImpact } from "../../services/accountSeed";
import { usePasskeyAccountLogin } from "../../composables/usePasskeyAccountLogin";
import { shouldOfferPasskeyEnrollment } from "../../services/passkeyEnrollOffer";
import { trackEvent } from "../../services/analytics";
import PasskeyEnrollOfferModal from "./PasskeyEnrollOfferModal.vue";
import SeedPhraseInput from "./SeedPhraseInput.vue";
import { useFlashStore } from "../../store/flash";

const props = defineProps<{ open: boolean }>();

const emit = defineEmits<{
  close: [];
  success: [payload: { store_id?: string | null }];
}>();

const { t } = useI18n();
const authStore = useAuthStore();

const flashStore = useFlashStore();

const mnemonicInput = ref("");
const loading = ref(false);
const error = ref("");
const rememberDevice = ref(false);
const devicePassphrase = ref("");
const ownerSwitchImpact = ref<Extract<OwnerSwitchImpact, { switches: true }> | null>(null);
const ownerSwitchConfirmedFor = ref("");

// The passkey path shares the seed path's finish (restore + remember +
// emit); its owner-switch confirm lives in the composable so the seed
// submit button never turns amber for a passkey-initiated switch.
const passkey = usePasskeyAccountLogin({
  onRestore: (recoveryPhrase) =>
    finishRestore(recoveryPhrase, rememberSnapshot.value, passphraseSnapshot.value, "passkey"),
  onError: (messageKey) => {
    error.value = t(messageKey);
  },
});

// Post-restore enrollment nudge (typed-seed path only): success/close are
// deferred while the offer is open, never dropped.
const showPasskeyOffer = ref(false);
const pendingSuccessPayload = ref<{ store_id?: string | null } | null>(null);

// Snapshots taken when a restore attempt starts: edits made while the
// passkey prompt or restore request is in flight must not change what was
// validated and what gets persisted.
const rememberSnapshot = ref(false);
const passphraseSnapshot = ref("");

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      mnemonicInput.value = "";
      error.value = "";
      rememberDevice.value = false;
      devicePassphrase.value = "";
      ownerSwitchImpact.value = null;
      ownerSwitchConfirmedFor.value = "";
      showPasskeyOffer.value = false;
      pendingSuccessPayload.value = null;
      passkey.reset();
      void passkey.probeSupport();
    }
  },
  { immediate: true },
);

// Editing the phrase after the warning was shown invalidates the confirmation.
watch(mnemonicInput, (value) => {
  if (ownerSwitchImpact.value && value !== ownerSwitchConfirmedFor.value) {
    ownerSwitchImpact.value = null;
    ownerSwitchConfirmedFor.value = "";
  }
});

/**
 * Both restore paths converge here once the phrase is accepted: session via
 * the Ed25519 challenge, then the opt-in device envelope, then success.
 */
async function finishRestore(
  mnemonic: string,
  remember: boolean,
  passphrase: string,
  source: "seed" | "passkey",
): Promise<void> {
  const data = await authStore.restoreGuestFromMnemonic(mnemonic);
  storeGuestMnemonic(mnemonic);
  if (remember) {
    // Best-effort: the phrase is already session-bound; remembering failing
    // must not block the login.
    try {
      await rememberDeviceWithPassphrase(mnemonic, passphrase);
    } catch {
      flashStore.warning(t("account.device_remember_failed"));
    }
  }
  const payload = { store_id: data?.store_id ?? null };
  if (source === "seed") {
    trackEvent("auth", "seed_login_success");
  }
  // The session is unlocked right now - the one moment enrolling an account
  // passkey needs nothing extra. Only for typed phrases (a passkey sign-in
  // proves an envelope already exists) and never blocking: any check failure
  // falls through to the normal success.
  if (source === "seed" && (await shouldOfferPasskeyEnrollment())) {
    pendingSuccessPayload.value = payload;
    showPasskeyOffer.value = true;
    return;
  }
  emit("success", payload);
  emit("close");
}

function finishPasskeyOffer(): void {
  showPasskeyOffer.value = false;
  const payload = pendingSuccessPayload.value ?? { store_id: null };
  pendingSuccessPayload.value = null;
  emit("success", payload);
  emit("close");
}

/**
 * Snapshot the remember-device inputs and fail fast on a weak device
 * passphrase BEFORE any prompt/authentication.
 */
function snapshotRememberInputs(): boolean {
  rememberSnapshot.value = rememberDevice.value;
  passphraseSnapshot.value = devicePassphrase.value;
  if (rememberSnapshot.value && !isAcceptableDevicePassphrase(passphraseSnapshot.value)) {
    error.value = t("account.device_passphrase_too_weak");
    return false;
  }
  return true;
}

async function submitWithPasskey() {
  error.value = "";
  if (!snapshotRememberInputs()) {
    return;
  }
  try {
    await passkey.run();
  } catch (rawError) {
    // Passkey-phase failures went through onError; what reaches here is the
    // restore step (network/server) - same message source as the seed path.
    const e = asApiError(rawError);
    error.value = e?.response?.data?.message || t("auth.guest_restore_error");
  }
}

async function submit() {
  error.value = "";
  // Snapshot the input: edits made while the restore request is in flight
  // must not change what was validated and what gets persisted.
  const mnemonic = mnemonicInput.value;
  if (!snapshotRememberInputs()) {
    return;
  }
  loading.value = true;
  try {
    // Data-loss guard (P1): restoring with a different phrase switches the
    // local Evolu owner and re-links existing local data to the new account.
    // Show the impact once; the second click on the amber button confirms.
    if (!ownerSwitchImpact.value || ownerSwitchConfirmedFor.value !== mnemonic) {
      const impact = await previewOwnerSwitchImpact(mnemonic);
      // The phrase was edited while the preview ran - the result no longer
      // describes the current input. Drop it and let the user resubmit.
      if (mnemonicInput.value !== mnemonic) {
        loading.value = false;
        return;
      }
      if (impact.switches) {
        ownerSwitchImpact.value = impact;
        ownerSwitchConfirmedFor.value = mnemonic;
        loading.value = false;
        return;
      }
    }
    await finishRestore(mnemonic, rememberSnapshot.value, passphraseSnapshot.value, "seed");
  } catch (rawError) {
    const e = asApiError(rawError);
    error.value =
      e?.response?.data?.message || t("auth.guest_restore_error");
  } finally {
    loading.value = false;
  }
}
</script>
