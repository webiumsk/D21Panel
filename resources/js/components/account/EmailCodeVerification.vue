<template>
  <div class="space-y-4">
    <div class="rounded-xl border border-indigo-500/25 bg-indigo-500/10 px-4 py-3">
      <p class="text-xs font-medium text-indigo-200/90 uppercase tracking-wide">
        {{ t("account.email_code_sent_to") }}
      </p>
      <p class="mt-1 text-sm sm:text-base text-white font-medium break-all">
        {{ challenge.email }}
      </p>
    </div>

    <p class="text-sm text-gray-300 leading-relaxed">
      {{ t("account.email_code_instructions") }}
    </p>

    <div
      class="flex justify-between gap-1.5 sm:gap-2"
      role="group"
      :aria-label="t('account.email_code_input_aria')"
    >
      <input
        v-for="(_, index) in digits"
        :key="index"
        :ref="(el) => setInputRef(el, index)"
        v-model="digits[index]"
        type="text"
        inputmode="numeric"
        autocomplete="one-time-code"
        pattern="[0-9]*"
        maxlength="1"
        :disabled="confirming || locked"
        class="w-full h-12 sm:h-14 text-center text-xl sm:text-2xl font-mono font-semibold rounded-lg border bg-gray-900/70 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
        :class="errorMessage ? 'border-red-500/60' : 'border-gray-600'"
        @input="onInput(index, $event)"
        @keydown="onKeydown(index, $event)"
        @paste.prevent="onPaste"
        @focus="onFocus(index)"
      />
    </div>

    <p
      v-if="errorMessage"
      class="text-sm text-red-300/95 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2"
      role="alert"
    >
      {{ errorMessage }}
    </p>
    <p
      v-else-if="resendNotice"
      class="text-sm text-emerald-300/95 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2"
      role="status"
    >
      {{ resendNotice }}
    </p>

    <div class="flex flex-col sm:flex-row gap-3">
      <button
        type="button"
        :disabled="confirming || locked || code.length !== CODE_LENGTH"
        class="flex-1 py-2.5 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold disabled:opacity-50 transition-colors"
        @click="submitCode"
      >
        {{ confirming ? t("common.loading") : t("account.email_code_confirm") }}
      </button>
      <button
        type="button"
        :disabled="resending || resendCooldownSeconds > 0 || sendsExhausted"
        class="flex-1 py-2.5 px-4 rounded-lg border border-gray-600 text-gray-200 hover:bg-gray-700/50 text-sm font-semibold disabled:opacity-50 transition-colors"
        @click="resendCode"
      >
        <template v-if="resendCooldownSeconds > 0">
          {{ t("account.pending_email_resend_wait", { seconds: resendCooldownSeconds }) }}
        </template>
        <template v-else>
          {{ resending ? t("common.loading") : t("account.email_code_resend") }}
        </template>
      </button>
    </div>

    <div v-if="allowChangeEmail" class="pt-1 text-center">
      <button
        type="button"
        class="text-sm text-gray-400 hover:text-gray-200 underline-offset-2 hover:underline"
        @click="emit('change-email')"
      >
        {{ t("account.email_code_change_email") }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type ComponentPublicInstance } from "vue";
import { useI18n } from "vue-i18n";
import type { EmailChallengeSummary } from "../../store/auth";

/**
 * Six-digit email code entry shared by the guest -> Free upgrade and the
 * wallet-connection change. The parent owns the API calls (confirm / resend)
 * so the same UI works against different endpoints; this component owns the
 * input mechanics, the server-driven resend countdown and error mapping.
 */
const CODE_LENGTH = 6;

const props = withDefaults(
  defineProps<{
    challenge: EmailChallengeSummary;
    confirm: (code: string) => Promise<void>;
    resend: () => Promise<EmailChallengeSummary | null>;
    allowChangeEmail?: boolean;
    autofocus?: boolean;
  }>(),
  { allowChangeEmail: true, autofocus: true },
);

const emit = defineEmits<{
  "change-email": [];
  locked: [];
}>();

const { t } = useI18n();

const digits = ref<string[]>(Array(CODE_LENGTH).fill(""));
const inputs = ref<Array<HTMLInputElement | null>>(Array(CODE_LENGTH).fill(null));
const confirming = ref(false);
const resending = ref(false);
const locked = ref(false);
const errorMessage = ref("");
const resendNotice = ref("");
const resendCooldownSeconds = ref(0);
const sendsLeft = ref<number | null>(props.challenge.sends_left ?? null);
let cooldownTimer: ReturnType<typeof setInterval> | null = null;
let lastSubmittedCode = "";

const code = computed(() => digits.value.join(""));
const sendsExhausted = computed(() => sendsLeft.value !== null && sendsLeft.value <= 0);

function setInputRef(el: Element | ComponentPublicInstance | null, index: number) {
  inputs.value[index] = (el as HTMLInputElement | null) ?? null;
}

function focusIndex(index: number) {
  const el = inputs.value[Math.max(0, Math.min(CODE_LENGTH - 1, index))];
  el?.focus();
  el?.select();
}

function onFocus(index: number) {
  inputs.value[index]?.select();
}

function fillFrom(index: number, text: string) {
  const clean = text.replace(/\D+/g, "");
  if (!clean) return;
  let cursor = index;
  for (const ch of clean) {
    if (cursor >= CODE_LENGTH) break;
    digits.value[cursor] = ch;
    cursor++;
  }
  focusIndex(Math.min(cursor, CODE_LENGTH - 1));
}

function onInput(index: number, event: Event) {
  const target = event.target as HTMLInputElement;
  const raw = target.value;
  // Mobile keyboards / autofill may drop a whole code into one box.
  if (raw.length > 1) {
    digits.value[index] = "";
    fillFrom(index, raw);
    return;
  }
  const clean = raw.replace(/\D+/g, "");
  digits.value[index] = clean;
  if (clean && index < CODE_LENGTH - 1) {
    focusIndex(index + 1);
  }
}

function onKeydown(index: number, event: KeyboardEvent) {
  if (event.key === "Backspace" && !digits.value[index] && index > 0) {
    event.preventDefault();
    digits.value[index - 1] = "";
    focusIndex(index - 1);
  } else if (event.key === "ArrowLeft" && index > 0) {
    event.preventDefault();
    focusIndex(index - 1);
  } else if (event.key === "ArrowRight" && index < CODE_LENGTH - 1) {
    event.preventDefault();
    focusIndex(index + 1);
  } else if (event.key === "Enter") {
    event.preventDefault();
    void submitCode();
  }
}

function onPaste(event: ClipboardEvent) {
  const text = event.clipboardData?.getData("text") ?? "";
  digits.value = Array(CODE_LENGTH).fill("");
  fillFrom(0, text);
}

function clearDigits() {
  digits.value = Array(CODE_LENGTH).fill("");
  lastSubmittedCode = "";
  focusIndex(0);
}

/** Countdown derived from the server timestamp (survives reloads, no client drift). */
function startCooldownFrom(resendAvailableAt: string | null | undefined) {
  if (cooldownTimer) {
    clearInterval(cooldownTimer);
    cooldownTimer = null;
  }
  const target = resendAvailableAt ? Date.parse(resendAvailableAt) : NaN;
  const tick = () => {
    const remaining = Number.isFinite(target) ? Math.ceil((target - Date.now()) / 1000) : 0;
    resendCooldownSeconds.value = Math.max(0, remaining);
    if (resendCooldownSeconds.value <= 0 && cooldownTimer) {
      clearInterval(cooldownTimer);
      cooldownTimer = null;
    }
  };
  tick();
  if (resendCooldownSeconds.value > 0) {
    cooldownTimer = setInterval(tick, 1000);
  }
}

type ChallengeError = {
  response?: {
    status?: number;
    data?: { message?: string; code?: string; attempts_left?: number; retry_after?: number };
  };
};

function applyError(err: unknown, fallbackKey: string) {
  const e = err as ChallengeError;
  const data = e?.response?.data;
  const status = e?.response?.status;
  errorMessage.value = data?.message || t(fallbackKey);
  if (data?.code === "challenge_locked" || status === 423) {
    locked.value = true;
    emit("locked");
  } else if (data?.code === "challenge_expired" || data?.code === "challenge_missing" || status === 410) {
    // Nothing to guess against any more - steer to resend.
    resendCooldownSeconds.value = 0;
    if (cooldownTimer) {
      clearInterval(cooldownTimer);
      cooldownTimer = null;
    }
  } else if (data?.code === "resend_cooldown" && typeof data.retry_after === "number") {
    startCooldownFrom(new Date(Date.now() + data.retry_after * 1000).toISOString());
  } else if (data?.code === "send_limit_reached") {
    sendsLeft.value = 0;
  }
}

async function submitCode() {
  if (confirming.value || locked.value || code.value.length !== CODE_LENGTH) return;
  if (code.value === lastSubmittedCode) return;
  lastSubmittedCode = code.value;
  confirming.value = true;
  errorMessage.value = "";
  resendNotice.value = "";
  try {
    await props.confirm(code.value);
  } catch (err: unknown) {
    applyError(err, "account.email_code_confirm_failed");
    if (!locked.value) {
      // Keep the typed digits selectable but let the next attempt through.
      lastSubmittedCode = "";
      focusIndex(0);
    }
  } finally {
    confirming.value = false;
  }
}

async function resendCode() {
  if (resending.value || resendCooldownSeconds.value > 0 || sendsExhausted.value) return;
  resending.value = true;
  errorMessage.value = "";
  resendNotice.value = "";
  try {
    const next = await props.resend();
    locked.value = false;
    clearDigits();
    resendNotice.value = t("account.email_code_resent");
    if (next) {
      sendsLeft.value = next.sends_left ?? null;
      startCooldownFrom(next.resend_available_at);
    } else {
      startCooldownFrom(new Date(Date.now() + 60_000).toISOString());
    }
  } catch (err: unknown) {
    applyError(err, "account.email_code_resend_failed");
  } finally {
    resending.value = false;
  }
}

watch(code, (value) => {
  if (value.length === CODE_LENGTH && /^\d{6}$/.test(value)) {
    void submitCode();
  }
});

watch(
  () => props.challenge,
  (next) => {
    sendsLeft.value = next.sends_left ?? null;
    startCooldownFrom(next.resend_available_at);
  },
);

onMounted(() => {
  startCooldownFrom(props.challenge.resend_available_at);
  if (props.autofocus) focusIndex(0);
});

onBeforeUnmount(() => {
  if (cooldownTimer) clearInterval(cooldownTimer);
});
</script>
