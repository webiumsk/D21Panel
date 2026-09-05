<template>
  <div
    class="min-h-screen flex items-center justify-center bg-gray-900 py-12 px-4 sm:px-6 lg:px-8 relative overflow-hidden"
  >
    <div class="absolute inset-0 bg-gray-900">
      <div
        class="absolute top-0 -left-4 w-72 h-72 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-blob"
      />
      <div
        class="absolute bottom-0 -right-4 w-72 h-72 bg-purple-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-blob animation-delay-2000"
      />
    </div>

    <div class="max-w-lg w-full space-y-8 relative z-10">
      <div class="text-center">
        <router-link to="/" class="inline-block">
          <span
            class="text-3xl sm:text-4xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-500 tracking-tight"
            ><span class="uppercase">satflux</span>.io</span
          >
        </router-link>
        <div
          class="mx-auto mt-6 flex h-14 w-14 items-center justify-center rounded-full border border-amber-500/40 bg-amber-500/15"
        >
          <svg
            class="h-7 w-7 text-amber-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
            />
          </svg>
        </div>
        <h1 class="mt-5 text-2xl sm:text-3xl font-extrabold text-white">
          {{ t("account.pending_email_title") }}
        </h1>
        <p class="mt-3 text-sm text-gray-400 leading-relaxed">
          {{ pendingChallenge ? t("account.email_code_subtitle") : t("account.pending_email_subtitle") }}
        </p>
      </div>

      <div
        class="bg-gray-800/50 backdrop-blur-xl border border-gray-700/50 rounded-2xl p-6 sm:p-8 shadow-xl space-y-6"
      >
        <template v-if="pendingChallenge">
          <EmailCodeVerification
            :challenge="pendingChallenge"
            :confirm="confirmCode"
            :resend="resendCode"
            @change-email="changeStagedEmail"
          />
        </template>
        <template v-else>
        <div v-if="userEmail" class="rounded-xl border border-indigo-500/25 bg-indigo-500/10 px-4 py-3">
          <p class="text-xs font-medium text-indigo-200/90 uppercase tracking-wide">
            {{ t("account.pending_email_sent_to") }}
          </p>
          <p class="mt-1 text-sm sm:text-base text-white font-medium break-all">
            {{ userEmail }}
          </p>
        </div>

        <ol class="space-y-4 text-sm text-gray-300">
          <li class="flex gap-3">
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white"
              >1</span
            >
            <span class="pt-0.5 leading-relaxed">{{ t("account.pending_email_step1") }}</span>
          </li>
          <li class="flex gap-3">
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white"
              >2</span
            >
            <span class="pt-0.5 leading-relaxed">{{ t("account.pending_email_step2") }}</span>
          </li>
          <li class="flex gap-3">
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white"
              >3</span
            >
            <span class="pt-0.5 leading-relaxed">{{ t("account.pending_email_step3") }}</span>
          </li>
        </ol>

        <p
          v-if="stillUnverifiedHint"
          class="text-sm text-amber-200/95 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2"
          role="status"
        >
          {{ t("account.pending_email_still_unverified") }}
        </p>
        <p
          v-if="resendSuccess"
          class="text-sm text-emerald-300/95 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2"
          role="status"
        >
          {{ t("account.pending_email_resend_sent") }}
        </p>

        <div class="flex flex-col sm:flex-row gap-3">
          <button
            type="button"
            :disabled="continueLoading"
            class="flex-1 py-3 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold disabled:opacity-50 transition-colors"
            @click="handleContinue"
          >
            {{ continueLoading ? t("common.loading") : t("account.pending_email_continue") }}
          </button>
          <button
            type="button"
            :disabled="resendLoading || resendCooldownSeconds > 0"
            class="flex-1 py-3 px-4 rounded-lg border border-gray-600 text-gray-200 hover:bg-gray-700/50 text-sm font-semibold disabled:opacity-50 transition-colors"
            @click="handleResend"
          >
            <template v-if="resendCooldownSeconds > 0">
              {{ t("account.pending_email_resend_wait", { seconds: resendCooldownSeconds }) }}
            </template>
            <template v-else>
              {{ resendLoading ? t("common.loading") : t("account.pending_email_resend") }}
            </template>
          </button>
        </div>

        </template>

        <div class="pt-2 border-t border-gray-700/60 text-center">
          <button
            type="button"
            class="text-sm text-gray-500 hover:text-gray-300 underline-offset-2 hover:underline"
            @click="goToAccount"
          >
            {{ t("account.pending_email_go_account") }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import { useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "../../store/auth";
import { useFlashStore } from "../../store/flash";
import api from "../../services/api";
import EmailCodeVerification from "../../components/account/EmailCodeVerification.vue";
import { useGuestUpgradeSubmit } from "../../composables/useGuestUpgradeSubmit";

const RESEND_COOLDOWN_SEC = 60;

const { t } = useI18n();
const router = useRouter();
const authStore = useAuthStore();
const flashStore = useFlashStore();

const continueLoading = ref(false);
const resendLoading = ref(false);
const resendSuccess = ref(false);
const stillUnverifiedHint = ref(false);
const resendCooldownSeconds = ref(0);
let cooldownTimer: ReturnType<typeof setInterval> | null = null;

const userEmail = computed(() => authStore.user?.email ?? "");

// Code flow (guest -> Free staged upgrade): the staged challenge rides on
// /api/user, so a reload resumes here. Everything below the template branch
// is the legacy link flow kept for accounts that are still mid-link.
const { confirm: confirmCode, resend: resendCode, discardChallenge } = useGuestUpgradeSubmit();
const pendingChallenge = computed(
  () => (authStore.user?.is_guest ? authStore.user?.pending_email_challenge ?? null : null),
);

function startCooldown() {
  resendCooldownSeconds.value = RESEND_COOLDOWN_SEC;
  if (cooldownTimer) clearInterval(cooldownTimer);
  cooldownTimer = setInterval(() => {
    resendCooldownSeconds.value = Math.max(0, resendCooldownSeconds.value - 1);
    if (resendCooldownSeconds.value <= 0 && cooldownTimer) {
      clearInterval(cooldownTimer);
      cooldownTimer = null;
    }
  }, 1000);
}

async function ensureSessionUser() {
  if (!authStore.user) {
    await authStore.fetchUser();
  }
}

onMounted(async () => {
  await ensureSessionUser();
  if (!authStore.isAuthenticated) {
    router.replace({ name: "login", query: { redirect: "/account/check-email" } });
    return;
  }
  if (authStore.user?.is_guest) {
    if (!pendingChallenge.value) {
      // Nothing staged (expired / already done) - back to the upgrade form.
      router.replace({ name: "account" });
    }
    return;
  }
  if (authStore.user?.email_verified_at) {
    router.replace({ name: "account" });
  }
});

watch(
  () => authStore.user?.is_guest,
  (isGuest, wasGuest) => {
    if (wasGuest && isGuest === false) {
      router.replace({ name: "account" });
    }
  },
);

onUnmounted(() => {
  if (cooldownTimer) {
    clearInterval(cooldownTimer);
    cooldownTimer = null;
  }
});

async function handleContinue() {
  stillUnverifiedHint.value = false;
  continueLoading.value = true;
  try {
    await authStore.fetchUser();
    if (authStore.user?.email_verified_at) {
      flashStore.success(t("account.pending_email_verified"));
      await router.replace({ name: "account" });
    } else {
      stillUnverifiedHint.value = true;
    }
  } finally {
    continueLoading.value = false;
  }
}

async function handleResend() {
  const email = userEmail.value;
  if (!email || resendCooldownSeconds.value > 0 || resendLoading.value) return;
  resendLoading.value = true;
  resendSuccess.value = false;
  stillUnverifiedHint.value = false;
  try {
    await api.post("/auth/email/verification-notification", { email });
    resendSuccess.value = true;
    startCooldown();
  } catch (err: unknown) {
    const e = err as { response?: { data?: { errors?: { email?: string[] }; message?: string } } };
    const msg =
      e.response?.data?.errors?.email?.[0] ||
      e.response?.data?.message ||
      t("auth.failed_to_verify_email");
    flashStore.error(msg);
  } finally {
    resendLoading.value = false;
  }
}

function goToAccount() {
  router.push({ name: "account" });
}

/** "Use a different address": drop the staged code so the account page opens on the email form. */
function changeStagedEmail() {
  discardChallenge();
  goToAccount();
}
</script>
