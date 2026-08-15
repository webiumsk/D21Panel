<template>
  <div
    class="min-h-screen flex items-center justify-center bg-gray-900 py-12 px-4 sm:px-6 lg:px-8 relative overflow-hidden"
  >
    <div class="absolute inset-0 bg-gray-900">
      <div
        class="absolute top-0 -left-4 w-72 h-72 bg-purple-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-blob"
      ></div>
      <div
        class="absolute bottom-0 -right-4 w-72 h-72 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-blob animation-delay-2000"
      ></div>
    </div>

    <div class="max-w-md w-full space-y-8 relative z-10">
      <div class="text-center">
        <router-link to="/" class="inline-block">
          <span
            class="text-4xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-500 tracking-tight"
            ><span class="uppercase">satflux</span>.io</span
          >
        </router-link>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-white">
          {{ t("auth.create_account") }}
        </h2>
        <p class="mt-2 text-center text-sm text-gray-400">
          {{ t("auth.seed_first_register_subtitle") }}
        </p>
      </div>

      <div
        class="bg-gray-800/50 backdrop-blur-xl border border-gray-700/50 rounded-2xl p-8 shadow-xl space-y-4"
      >
        <AuthSeedGuestPanel
          variant="register"
          :primary-label="
            guestLoading
              ? t('auth.starting_guest_session')
              : t('auth.create_with_recovery_phrase')
          "
          :primary-disabled="guestLoading"
          @primary="showGuestBackupWizard = true"
          @secondary="showGuestRestoreModal = true"
        />
        <p class="text-center text-sm text-gray-400 pt-1">
          {{ t("auth.already_have_email_account") }}
          <router-link
            to="/login?legacy=1"
            class="font-medium text-indigo-400 hover:text-indigo-300"
          >
            {{ t("auth.sign_in") }}
          </router-link>
        </p>
      </div>
    </div>

    <GuestBackupWizardModal
      :open="showGuestBackupWizard"
      @close="showGuestBackupWizard = false"
      @done="handleGuestEnrolled"
    />
    <GuestRestoreModal
      :open="showGuestRestoreModal"
      @close="showGuestRestoreModal = false"
      @success="redirectAfterGuestRestore"
    />
    <PasskeyEnrollOfferModal
      :open="showPasskeyOffer"
      context="register"
      @done="finishPasskeyOffer"
      @skip="finishPasskeyOffer"
    />
  </div>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref } from "vue";
import { useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import GuestBackupWizardModal from "../../components/auth/GuestBackupWizardModal.vue";
import GuestRestoreModal from "../../components/auth/GuestRestoreModal.vue";
import PasskeyEnrollOfferModal from "../../components/auth/PasskeyEnrollOfferModal.vue";
import AuthSeedGuestPanel from "../../components/auth/AuthSeedGuestPanel.vue";
import { useAuthStore } from "../../store/auth";
import { useStoresStore } from "../../store/stores";
import { useFlashStore } from "../../store/flash";
import api from "../../services/api";
import { storeGuestMnemonic } from "../../services/guestRecovery";
import {
  initEvoluFromAccountSeedIfNeeded,
  isEvoluUnavailableError,
} from "../../services/accountSeed";
import { isPasskeyPrfSupported } from "../../services/deviceUnlock/passkeyPrf";

const { t } = useI18n();
const router = useRouter();
const authStore = useAuthStore();
const flashStore = useFlashStore();
const guestLoading = ref(false);
const showGuestBackupWizard = ref(false);
const showGuestRestoreModal = ref(false);
const showPasskeyOffer = ref(false);
const passkeyOfferRedirectPath = ref("");

function redirectAfterGuestRestore(_payload: { store_id?: string | null }) {
  // A restored account is not a fresh guest: it may already have a connected
  // wallet or be fully upgraded. Go home and let the router's guest hard-gate
  // decide - it redirects to the wallet connection only when the account is
  // still a guest AND the primary store's wallet is not ready.
  // Path-based on purpose: the public marketing router has no "home" route
  // (a named location throws in matcher.resolve before any guard runs), but
  // its guard turns unknown paths into a full page load of the app bundle.
  void useStoresStore()
    .fetchStores()
    .finally(() => {
      router.replace("/dashboard");
    });
}

async function handleGuestEnrolled(payload: {
  recoveryPublicKeyHex: string;
  mnemonic: string;
}) {
  showGuestBackupWizard.value = false;
  guestLoading.value = true;
  try {
    const response = await authStore.continueAsGuest(payload.recoveryPublicKeyHex);
    storeGuestMnemonic(payload.mnemonic);
    try {
      await initEvoluFromAccountSeedIfNeeded(payload.mnemonic);
    } catch (evoluError) {
      // The session is valid; a dead local-first storage (private window
      // without OPFS) only disables invoicing, it must not fail registration.
      if (!isEvoluUnavailableError(evoluError)) {
        throw evoluError;
      }
    }
    let storeId = response?.store_id ?? response?.data?.store_id ?? null;

    if (!storeId) {
      try {
        const storesRes = await api.get("/stores");
        const firstStoreId = storesRes?.data?.data?.[0]?.id;
        storeId =
          typeof firstStoreId === "string" || typeof firstStoreId === "number"
            ? String(firstStoreId)
            : null;
      } catch {
        storeId = null;
      }
    }

    await redirectOrOfferPasskey(
      storeId ? `/stores/${storeId}/wallet-connection` : "/stores/create",
    );
  } catch (rawError) {
    const err = asApiError(rawError);
    flashStore.error(
      err.response?.data?.message || "Unable to start session.",
    );
  } finally {
    guestLoading.value = false;
  }
}

/**
 * First authenticated instant after registration = the one moment the
 * session phrase is guaranteed unlocked, so offer the account passkey now
 * ("sign in without your 24 words next time"). The redirect is only
 * deferred, never blocked: done and skip both land on the same target.
 */
async function redirectOrOfferPasskey(targetPath: string): Promise<void> {
  let offerPasskey = false;
  try {
    offerPasskey = await isPasskeyPrfSupported();
  } catch {
    offerPasskey = false;
  }
  if (!offerPasskey) {
    router.replace(targetPath);
    return;
  }
  passkeyOfferRedirectPath.value = targetPath;
  showPasskeyOffer.value = true;
}

function finishPasskeyOffer(): void {
  showPasskeyOffer.value = false;
  router.replace(passkeyOfferRedirectPath.value || "/stores/create");
}
</script>
