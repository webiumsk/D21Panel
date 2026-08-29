<template>
  <div class="space-y-6">
    <div class="bg-gray-900/50 p-6 rounded-2xl border border-gray-700 text-center">
      <h3 class="text-lg font-semibold text-white mb-2">
        {{ t("create_store.bot_wait_title") }}
      </h3>
      <p class="text-sm text-gray-400 mb-6">
        {{ t("create_store.bot_wait_hint") }}
      </p>

      <div v-if="phase === 'polling'" class="space-y-6">
        <div class="flex justify-center">
          <svg class="animate-spin h-12 w-12 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
        </div>
        <p class="text-2xl font-mono text-indigo-300">{{ secondsRemaining }}s</p>
        <p class="text-xs text-gray-500">{{ t("create_store.bot_wait_polling") }}</p>
      </div>

      <div v-else-if="phase === 'success'" class="space-y-4">
        <p class="text-green-400 font-medium">{{ t("create_store.bot_result_success") }}</p>
        <button
          type="button"
          @click="$emit('continue')"
          class="inline-flex justify-center py-3 px-8 border border-transparent rounded-xl text-white bg-indigo-600 hover:bg-indigo-500"
        >
          {{ t("create_store.continue_to_store") }}
        </button>
      </div>

      <div v-else class="space-y-4">
        <p class="text-amber-400">{{ errorMessage }}</p>
        <p v-if="failureDetail" class="text-sm text-gray-400">{{ failureDetail }}</p>
        <button
          type="button"
          @click="$emit('continue')"
          class="inline-flex justify-center py-3 px-8 border border-transparent rounded-xl text-white bg-indigo-600 hover:bg-indigo-500"
        >
          {{ t("create_store.continue_to_store") }}
        </button>
      </div>
    </div>

    <div v-if="phase === 'polling'" class="flex justify-start">
      <button type="button" @click="onCancel" class="text-sm text-gray-400 hover:text-white">
        {{ t("common.cancel") }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { walletApi } from "@/services/api";

/**
 * Wallet-provisioning wait card: polls the store's wallet connection until it
 * turns "connected" (or "needs_support"), with a visible countdown. Extracted
 * from the store-create wizard so the standalone wallet-connection page gets
 * the same waiting UX instead of a form that silently does nothing.
 */
const props = defineProps<{
  storeId: string;
}>();

const emit = defineEmits<{
  /** Connection turned "connected" - fired once, alongside the success state. */
  success: [];
  /** User clicked continue (after success, support-needed, or timeout). */
  continue: [];
  /** User cancelled while still polling (timers already stopped). */
  cancel: [];
}>();

const WAIT_TOTAL_SECONDS = 120;
const POLL_MS = 15000;

const { t } = useI18n();

const phase = ref<"polling" | "success" | "error">("polling");
const secondsRemaining = ref(WAIT_TOTAL_SECONDS);
const errorMessage = ref("");
const failureDetail = ref("");
let countdownInterval: ReturnType<typeof setInterval> | null = null;
let pollInterval: ReturnType<typeof setInterval> | null = null;

function stopTimers() {
  if (countdownInterval != null) {
    clearInterval(countdownInterval);
    countdownInterval = null;
  }
  if (pollInterval != null) {
    clearInterval(pollInterval);
    pollInterval = null;
  }
}

async function pollOnce() {
  try {
    const data = await walletApi.connection.get(props.storeId);
    const status = data?.status ?? "";
    if (status === "connected") {
      stopTimers();
      phase.value = "success";
      emit("success");
    } else if (status === "needs_support") {
      stopTimers();
      phase.value = "error";
      errorMessage.value = t("create_store.bot_result_support");
      failureDetail.value = data?.bot_failure_message ?? "";
    }
  } catch {
    /* transient poll failure - the next tick retries */
  }
}

function onCancel() {
  stopTimers();
  emit("cancel");
}

onMounted(() => {
  void pollOnce();
  pollInterval = setInterval(() => {
    void pollOnce();
  }, POLL_MS);
  countdownInterval = setInterval(() => {
    secondsRemaining.value -= 1;
    if (secondsRemaining.value <= 0) {
      stopTimers();
      if (phase.value === "polling") {
        phase.value = "error";
        errorMessage.value = t("create_store.bot_result_timeout");
        failureDetail.value = "";
      }
    }
  }, 1000);
});

onBeforeUnmount(stopTimers);
</script>
