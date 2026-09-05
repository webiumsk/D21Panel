<template>
  <div class="bg-gray-900/50 border border-gray-700 rounded-2xl p-8">
    <h3
      class="text-sm font-bold text-indigo-400 mb-6 uppercase tracking-wider"
    >
      {{ t("stores.current_connection") }}
    </h3>
    <div
      v-if="connection.configuration_source === 'samrock'"
      class="mb-6 p-4 rounded-xl border border-green-500/30 bg-green-500/10"
    >
      <p class="text-sm text-green-300">
        {{ t("stores.samrock_connected_note") }}
      </p>
    </div>
    <div
      v-else-if="connection.type === 'aqua_descriptor'"
      class="mb-6 p-4 rounded-xl border border-amber-500/30 bg-amber-500/10"
    >
      <p class="text-sm text-amber-400">
        {{ t("stores.aqua_warning_btcpay") }}
      </p>
      <p class="text-sm text-amber-400 mt-2">
        {{ t("stores.aqua_limits_warning") }}
      </p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 text-sm">
      <div>
        <span
          class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
          >{{ t("stores.type") }}</span
        >
        <WalletTypeIcon
          :type="connection.type"
          :brand="
            connection.type === 'aqua_descriptor'
              ? walletBrand
              : undefined
          "
          size="lg"
          :show-label="true"
          class="font-medium text-white"
        />
      </div>
      <div>
        <span
          class="block text-gray-500 text-xs uppercase tracking-wider mb-1"
          >{{ t("stores.status") }}</span
        >
        <span
          class="font-medium"
          :class="getStatusColorClass(connection.status)"
          >{{ formatStatus(connection.status) }}</span
        >
      </div>
    </div>
    <div
      v-if="connection.drift_detected_at"
      class="mt-6 rounded-xl border border-red-500/60 bg-red-500/10 p-4"
      role="alert"
    >
      <p class="text-sm font-semibold text-red-200">{{ t("stores.wallet_drift_title") }}</p>
      <p class="mt-1 text-sm text-red-100/90">
        {{ t("stores.wallet_drift_body", { date: new Date(connection.drift_detected_at).toLocaleString() }) }}
      </p>
      <p v-if="connection.drift_details" class="mt-1 text-xs font-mono text-red-200/80">
        {{ t("stores.wallet_drift_changed") }}:
        {{ [...connection.drift_details.changed, ...connection.drift_details.added, ...connection.drift_details.removed].join(", ") }}
      </p>
      <p class="mt-2 text-sm text-red-100/90">{{ t("stores.wallet_drift_action") }}</p>
    </div>
    <div class="mt-6 pt-6 border-t border-gray-700">
      <span
        class="block text-gray-500 text-xs uppercase tracking-wider mb-2"
        >{{ t("stores.masked_secret") }}</span
      >
      <div
        class="font-mono text-gray-300 text-sm break-all bg-gray-800/80 px-4 py-3 rounded-xl border border-gray-700/50"
      >
        {{ connection.masked_secret || "N/A" }}
      </div>
    </div>
    <div v-if="cashuFallbackAddress" class="mt-6 pt-6 border-t border-gray-700">
      <span class="block text-gray-500 text-xs uppercase tracking-wider mb-2">{{
        t("stores.cashu_fallback_active_label")
      }}</span>
      <p class="text-sm text-emerald-300">
        {{ cashuFallbackAddress }}
      </p>
      <p class="mt-1 text-xs text-gray-500 leading-relaxed">
        {{ t("stores.cashu_fallback_active_hint") }}
      </p>
    </div>
    <!-- Last change: date + user ID (integrated into main card) -->
    <div
      v-if="
        connection.secret_updated_at ||
        connection.submitted_by_user_id
      "
      class="mt-6 pt-6 border-t border-gray-700 flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm text-gray-400"
    >
      <span v-if="connection.secret_updated_at">
        <span class="text-gray-500 uppercase tracking-wider"
          >{{ t("stores.last_connection_change") }}:</span
        >
        <span class="ml-2 text-gray-300">{{
          formatLastChangeDate(connection.secret_updated_at)
        }}</span>
      </span>
      <span v-if="connection.submitted_by_user_id">
        <span class="text-gray-500 uppercase tracking-wider"
          >{{ t("stores.by_user_id") }}:</span
        >
        <span class="ml-2 font-mono text-indigo-300">{{
          connection.submitted_by_user_id
        }}</span>
      </span>
    </div>
  </div>

  <div class="mt-6 flex flex-wrap items-center gap-4">
      <button
        type="button"
        @click="$emit('change')"
        :disabled="revealing"
        class="inline-flex items-center px-6 py-3 border border-indigo-500 rounded-xl text-sm font-medium text-indigo-400 bg-indigo-500/10 hover:bg-indigo-500/20 transition-all disabled:opacity-50"
      >
        {{ t("stores.change_connection") }}
      </button>
      <button
        type="button"
        @click="$emit('cancel')"
        class="px-6 py-3 border border-transparent rounded-xl text-sm font-medium text-gray-400 hover:text-white bg-transparent hover:bg-gray-800 transition-all"
      >
        {{ t("common.cancel") }}
      </button>
    </div>
  <p v-if="passwordError" class="mt-4 text-sm text-red-400">
    {{ passwordError }}
  </p>

</template>

<script setup lang="ts">
import type { WalletConnectionDetails } from "../../../services/api";
import { useI18n } from "vue-i18n";
import WalletTypeIcon from "../../WalletTypeIcon.vue";
import type { AquaBoltzWalletBrand } from "../../../utils/aquaBoltzWalletBrand";

defineProps<{
  connection: WalletConnectionDetails;
  walletBrand: AquaBoltzWalletBrand;
  /** Disables the change button while a reveal is running in the parent. */
  revealing?: boolean;
  /** Reveal error surfaced by the parent (recovery-only accounts reveal from here). */
  passwordError?: string;
  /** CashuMelt parallel fallback payout address, when configured. */
  cashuFallbackAddress?: string | null;
}>();

defineEmits<{
  change: [];
  cancel: [];
}>();

const { t } = useI18n();

function formatStatus(status: string): string {
  const statusKeys: Record<string, string> = {
    pending: "stores.pending",
    needs_support: "stores.needs_support",
    connected: "stores.connected",
  };
  return statusKeys[status] ? t(statusKeys[status]) : status;
}

function getStatusColorClass(status: string): string {
  switch (status) {
    case "connected":
      return "text-green-400";
    case "needs_support":
      return "text-blue-400";
    case "pending":
      return "text-yellow-400";
    default:
      return "text-gray-400";
  }
}

function formatLastChangeDate(dateString: string): string {
  if (!dateString) return "";
  return new Date(dateString).toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  });
}
</script>
