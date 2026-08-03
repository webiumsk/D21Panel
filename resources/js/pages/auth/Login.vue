<template>
  <div
    class="min-h-screen flex items-center justify-center bg-gray-900 py-12 px-4 sm:px-6 lg:px-8 relative overflow-hidden"
  >
    <!-- Background Effects -->
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
          {{ t("auth.welcome_back") }}
        </h2>
        <p class="mt-2 text-center text-sm text-gray-400">
          {{
            seedFirst
              ? t("auth.seed_first_login_subtitle")
              : t("auth.sign_in_to_account")
          }}
        </p>
      </div>

      <div
        class="bg-gray-800/50 backdrop-blur-xl border border-gray-700/50 rounded-2xl p-8 shadow-xl"
      >
        <div
          v-if="route.query.email_verified === '1'"
          class="mb-6 rounded-lg bg-green-500/10 border border-green-500/20 p-4"
        >
          <div class="flex items-start">
            <svg
              class="w-5 h-5 text-green-400 mt-0.5 mr-3 flex-shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M5 13l4 4L19 7"
              ></path>
            </svg>
            <p class="text-sm text-green-400">
              {{ t("auth.email_verified") }}
            </p>
          </div>
        </div>

        <div
          v-if="!seedFirst"
          class="grid grid-cols-2 gap-1 mb-6 rounded-xl border border-gray-600 p-1"
        >
          <button
            type="button"
            class="py-2.5 px-1 text-xs sm:text-sm font-semibold rounded-lg transition-colors text-center leading-tight"
            :class="
              authMethodTab === 'guest'
                ? 'bg-indigo-600 text-white shadow'
                : 'text-gray-400 hover:text-white hover:bg-gray-700/60'
            "
            @click="authMethodTab = 'guest'"
          >
            {{ t("auth.tab_seed") }}
          </button>
          <button
            type="button"
            class="py-2.5 px-1 text-xs sm:text-sm font-semibold rounded-lg transition-colors text-center leading-tight"
            :class="
              authMethodTab === 'email'
                ? 'bg-indigo-600 text-white shadow'
                : 'text-gray-400 hover:text-white hover:bg-gray-700/60'
            "
            @click="authMethodTab = 'email'"
          >
            {{ t("auth.tab_email") }}
          </button>
        </div>

        <div v-show="showSeedPanel" class="mb-2">
          <button
            v-if="passkeyLoginSupported"
            type="button"
            :disabled="passkeyLoginLoading"
            class="w-full flex items-center justify-center gap-2 py-3 px-4 mb-3 text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900 disabled:opacity-50 transition-all shadow-lg"
            :class="
              passkeyOwnerSwitchImpact
                ? 'bg-amber-600 hover:bg-amber-500 focus:ring-amber-500 shadow-amber-600/20'
                : 'bg-indigo-600 hover:bg-indigo-500 focus:ring-indigo-500 shadow-indigo-600/20'
            "
            @click="handlePasskeyLogin"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" /></svg>
            {{
              passkeyOwnerSwitchImpact
                ? t("auth.guest_restore_owner_switch_confirm")
                : t("auth.passkey_login_button")
            }}
          </button>
          <div
            v-if="passkeyOwnerSwitchImpact"
            class="mb-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 space-y-2"
          >
            <p class="text-sm font-medium text-amber-300">
              {{ t("auth.guest_restore_owner_switch_title") }}
            </p>
            <p class="text-sm text-gray-300">
              {{
                t("auth.guest_restore_owner_switch_body", {
                  documents: passkeyOwnerSwitchImpact.documents,
                  contacts: passkeyOwnerSwitchImpact.contacts,
                  companies: passkeyOwnerSwitchImpact.companies,
                })
              }}
            </p>
          </div>
          <AuthSeedGuestPanel
            variant="login"
            :hierarchy="passkeyLoginSupported ? 'secondary' : 'primary'"
            :primary-label="t('auth.restore_guest_session')"
            @primary="showGuestRestoreModal = true"
          />
          <p v-if="seedFirst" class="text-center text-sm text-gray-400 mt-4">
            <button
              type="button"
              class="font-medium text-indigo-400 hover:text-indigo-300 transition-colors"
              @click="showLegacyEmailLogin = true"
            >
              {{ t("auth.legacy_email_login_link") }}
            </button>
          </p>
        </div>

        <form
          v-show="showEmailForm"
          class="space-y-6"
          @submit.prevent="handleLogin"
        >
          <p
            v-if="seedFirst"
            class="text-xs text-amber-200/90 leading-relaxed rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2"
            role="note"
          >
            {{ t("auth.legacy_email_login_notice") }}
          </p>
          <button
            v-if="seedFirst"
            type="button"
            class="text-sm text-gray-400 hover:text-white transition-colors"
            @click="showLegacyEmailLogin = false"
          >
            {{ t("auth.back_to_recovery_phrase") }}
          </button>
          <div>
            <label
              for="email"
              class="block text-sm font-medium text-gray-300"
              >{{ t("auth.email") }}</label
            >
            <div class="mt-1">
              <input
                id="email"
                v-model="form.email"
                name="email"
                type="email"
                autocomplete="email"
                required
                class="appearance-none block w-full px-4 py-2 border border-gray-600 rounded-lg shadow-sm placeholder-gray-500 text-white bg-gray-700/50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm transition-colors"
                :placeholder="t('auth.email_placeholder')"
              />
            </div>
          </div>

          <div>
            <label
              for="password"
              class="block text-sm font-medium text-gray-300"
              >{{ t("auth.password") }}</label
            >
            <div class="mt-1">
              <input
                id="password"
                v-model="form.password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                class="appearance-none block w-full px-4 py-2 border border-gray-600 rounded-lg shadow-sm placeholder-gray-500 text-white bg-gray-700/50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm transition-colors"
                :placeholder="t('auth.password_placeholder')"
              />
            </div>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <input
                id="remember-me"
                v-model="form.remember"
                name="remember-me"
                type="checkbox"
                class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-600 rounded bg-gray-700"
              />
              <label for="remember-me" class="ml-2 block text-sm text-gray-300">
                {{ t("auth.remember_me") }}
              </label>
            </div>

            <div class="text-sm">
              <router-link
                to="/password/reset"
                class="font-medium text-indigo-400 hover:text-indigo-300 transition-colors"
              >
                {{ t("auth.forgot_password") }}
              </router-link>
            </div>
          </div>

          <div class="space-y-4">
            <button
              type="submit"
              :disabled="loading"
              class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-900 disabled:opacity-50 transition-all shadow-lg shadow-indigo-600/20"
            >
              {{ loading ? t("auth.signing_in") : t("auth.sign_in") }}
            </button>
          </div>

          <div class="text-center text-sm">
            <span class="text-gray-400">{{ t("auth.dont_have_account") }}</span>
            <router-link
              to="/register"
              class="font-medium text-indigo-400 hover:text-indigo-300 ml-1 transition-colors"
            >
              {{ t("auth.sign_up") }}
            </router-link>
          </div>
        </form>
      </div>
    </div>

    <GuestRestoreModal
      :open="showGuestRestoreModal"
      @close="showGuestRestoreModal = false"
      @success="redirectAfterGuestRestore"
    />
  </div>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref, computed, watch, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "../../store/auth";
import { useStoresStore } from "../../store/stores";
import { useFlashStore } from "../../store/flash";
import GuestRestoreModal from "../../components/auth/GuestRestoreModal.vue";
import AuthSeedGuestPanel from "../../components/auth/AuthSeedGuestPanel.vue";
import { isPublicMarketingPath, navigateToAppPath } from "../../utils/publicMarketingRoutes";
import { isSeedFirstRegistration } from "../../config/auth";
import { usePasskeyAccountLogin } from "../../composables/usePasskeyAccountLogin";

const { t } = useI18n();

/**
 * One gesture on ANY device: the passkey's PRF output decrypts the
 * server-held ciphertext envelope into the recovery phrase, and the phrase
 * signs the existing guest-recovery challenge for the session (shared flow
 * with GuestRestoreModal, incl. the owner-switch two-click confirm).
 */
const {
  supported: passkeyLoginSupported,
  loading: passkeyLoginLoading,
  ownerSwitchImpact: passkeyOwnerSwitchImpact,
  probeSupport: probePasskeySupport,
  run: handlePasskeyLogin,
} = usePasskeyAccountLogin({
  onRestore: async (recoveryPhrase) => {
    await authStore.restoreGuestFromMnemonic(recoveryPhrase);
    redirectAfterGuestRestore({});
  },
  onError: (messageKey) => {
    flashStore.error(t(messageKey));
  },
});

onMounted(() => {
  applyAuthTabFromQuery();
  void probePasskeySupport();
});

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const flashStore = useFlashStore();

const form = ref({
  email: "",
  password: "",
  remember: false,
});

const loading = ref(false);
const seedFirst = computed(() => isSeedFirstRegistration());
const authMethodTab = ref<"guest" | "email">("guest");
const showLegacyEmailLogin = ref(false);
const showGuestRestoreModal = ref(false);

const showSeedPanel = computed(
  () =>
    authMethodTab.value === "guest" &&
    (!seedFirst.value || !showLegacyEmailLogin.value),
);

const showEmailForm = computed(
  () =>
    authMethodTab.value === "email" ||
    (seedFirst.value && showLegacyEmailLogin.value),
);

function applyAuthTabFromQuery() {
  const legacyRaw = route.query.legacy;
  const legacy = Array.isArray(legacyRaw) ? legacyRaw[0] : legacyRaw;
  if (legacy === "1" || legacy === "true") {
    showLegacyEmailLogin.value = true;
  }

  const raw = route.query.tab;
  const q = Array.isArray(raw) ? raw[0] : raw;
  if (!q || typeof q !== "string") return;
  if (q === "email") {
    authMethodTab.value = "email";
    if (seedFirst.value) {
      showLegacyEmailLogin.value = true;
    }
    return;
  }
  if (q === "guest") {
    authMethodTab.value = "guest";
  }
}

watch(
  () => route.query.tab,
  () => applyAuthTabFromQuery(),
);

function redirectAfterAuth(target?: string | null) {
  const path = target && target.trim() !== "" ? target : "/dashboard";
  if (!isPublicMarketingPath(path)) {
    navigateToAppPath(path);
    return;
  }
  router.push(path);
}

async function handleLogin() {
  loading.value = true;

  try {
    await authStore.login(
      form.value.email,
      form.value.password,
      form.value.remember,
    );
    const redirect = router.currentRoute.value.query.redirect as string;
    redirectAfterAuth(redirect);
  } catch (rawError) {
    const err = asApiError(rawError);
    flashStore.error(
      err.response?.data?.message || "Login failed. Please try again.",
    );
  } finally {
    loading.value = false;
  }
}

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
</script>
