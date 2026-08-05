<template>
  <div class="space-y-6">
    <div
      class="bg-gray-800 shadow-xl rounded-2xl border border-gray-700"
    >
      <div
        class="px-6 py-5 border-b border-gray-700 bg-gray-800/50 flex flex-col md:flex-row md:items-center justify-between gap-4"
      >
        <div>
          <h1 class="text-2xl font-bold text-white">
            {{ t("stores.cashu_payments_title") }}
          </h1>
          <p class="text-sm text-gray-400 mt-1">
            {{ t("stores.cashu_payments_subtitle") }}
          </p>
        </div>
        <button
          type="button"
          class="inline-flex items-center justify-center px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:pointer-events-none transition-colors shadow-lg shadow-indigo-900/20 shrink-0"
          :disabled="loading"
          @click="fetchPayments()"
        >
          {{ t("stores.cashu_payments_refresh") }}
        </button>
      </div>

      <div class="px-6 py-5">
        <div
          class="mb-6 flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4"
        >
          <div class="flex flex-col gap-1.5 sm:min-w-[12rem]">
            <label
              class="block text-xs font-medium text-gray-400 mb-1 uppercase tracking-wider"
              for="cashu-settlement-filter"
            >
              {{ t("stores.cashu_settlement_filter") }}
            </label>
            <Select
              id="cashu-settlement-filter"
              v-model="settlementState"
              :options="settlementStateOptions"
              :placeholder="t('stores.cashu_filter_all')"
              :disabled="loading"
              @change="onSettlementFilterChange"
            />
          </div>
        </div>

        <div
          v-if="loading && payments.items.length === 0"
          class="text-center py-16"
        >
          <svg
            class="animate-spin mx-auto h-10 w-10 text-indigo-500 mb-4"
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
            />
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
          </svg>
          <p class="text-gray-400 text-sm">
            {{ t("stores.cashu_payments_loading") }}
          </p>
        </div>

        <div
          v-else-if="error"
          class="rounded-xl border border-red-500/25 bg-red-500/10 p-4 mb-4"
        >
          <div class="flex items-start gap-3">
            <svg
              class="h-5 w-5 text-red-400 flex-shrink-0 mt-0.5"
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
            <p class="text-sm text-red-200 leading-relaxed">{{ error }}</p>
          </div>
        </div>

        <template v-else>
          <div
            v-if="retryNotice"
            class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 mb-4 text-sm text-amber-200"
          >
            {{ retryNotice }}
          </div>

          <div class="overflow-x-auto rounded-xl border border-gray-700">
            <table class="min-w-full divide-y divide-gray-700 text-sm">
              <thead class="bg-gray-700/50">
                <tr>
                  <th
                    class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("stores.cashu_col_created") }}
                  </th>
                  <th
                    class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("stores.cashu_col_amount") }}
                  </th>
                  <th
                    class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("stores.cashu_col_settlement") }}
                  </th>
                  <th
                    class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("stores.cashu_col_mint_poll") }}
                  </th>
                  <th
                    class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("stores.cashu_col_error") }}
                  </th>
                  <th
                    class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("stores.cashu_col_action") }}
                  </th>
                </tr>
              </thead>
              <tbody class="bg-gray-800 divide-y divide-gray-700">
                <tr
                  v-for="p in payments.items"
                  :key="p.quote_id"
                  class="hover:bg-gray-800/60 transition-colors"
                >
                  <td
                    class="px-4 py-3 text-gray-300 whitespace-nowrap tabular-nums"
                  >
                    {{ formatDate(p.created_at) }}
                  </td>
                  <td
                    class="px-4 py-3 text-white font-medium whitespace-nowrap tabular-nums"
                  >
                    {{ formatAmount(p.amount_sats) }} sat
                  </td>
                  <td class="px-4 py-3">
                    <span :class="settlementPillClass(p.settlement_state)">
                      {{ settlementStateLabel(p.settlement_state) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 align-top">
                    <div
                      v-if="p.mint_quote_poll_url"
                      class="flex flex-wrap items-center gap-2"
                    >
                      <a
                        :href="p.mint_quote_poll_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-indigo-400 hover:text-indigo-300 text-xs font-semibold underline"
                      >
                        {{ t("stores.cashu_mint_poll_link") }}
                      </a>
                      <button
                        type="button"
                        class="px-2 py-1 rounded-md text-xs font-medium border border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors"
                        :title="t('common.copy_url')"
                        @click="copyMintPollUrl(p)"
                      >
                        {{
                          copiedPollQuoteId === p.quote_id
                            ? t("common.copied")
                            : t("common.copy_url")
                        }}
                      </button>
                    </div>
                    <span v-else class="text-gray-600 text-sm">-</span>
                  </td>
                  <td class="px-4 py-3 break-all max-w-xs text-sm">
                    <div
                      v-if="
                        p.failure_reason_code ||
                        p.settlement_error ||
                        p.needs_manual_review
                      "
                      class="space-y-1"
                    >
                      <span
                        v-if="p.needs_manual_review"
                        class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold uppercase tracking-wide bg-amber-500/20 text-amber-300 border border-amber-500/40"
                      >
                        {{ t("stores.cashu_needs_review_badge") }}
                      </span>
                      <p
                        v-if="p.failure_reason_code"
                        class="text-red-300/90 font-medium"
                      >
                        {{ failureReasonLabel(p.failure_reason_code) }}
                      </p>
                      <p
                        v-if="p.settlement_error"
                        class="text-red-300/60 text-xs"
                      >
                        {{ p.settlement_error }}
                      </p>
                      <p
                        v-if="(p.retry_count ?? 0) > 0"
                        class="text-gray-500 text-xs"
                      >
                        {{
                          t("stores.cashu_retry_count_label", {
                            count: p.retry_count,
                          })
                        }}
                      </p>
                    </div>
                    <span v-else class="text-gray-500">-</span>
                  </td>
                  <td class="px-4 py-3">
                    <button
                      v-if="canRetry(p)"
                      type="button"
                      class="px-3 py-1.5 rounded-lg text-xs font-semibold border border-red-500/35 bg-red-500/10 text-red-300 hover:bg-red-500/20 disabled:opacity-50 transition-colors"
                      :disabled="retryingQuoteIds.has(p.quote_id)"
                      @click="retryPayment(p.quote_id)"
                    >
                      {{
                        retryingQuoteIds.has(p.quote_id)
                          ? t("stores.cashu_payments_retrying")
                          : t("stores.cashu_payments_retry")
                      }}
                    </button>
                    <span v-else class="text-gray-600 text-sm">-</span>
                  </td>
                </tr>
                <tr v-if="payments.items.length === 0">
                  <td
                    colspan="6"
                    class="px-4 py-14 text-center text-gray-500 text-sm"
                  >
                    {{ t("stores.cashu_payments_empty") }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4 mt-6 pt-4 border-t border-gray-700"
          >
            <button
              type="button"
              class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white disabled:opacity-40 disabled:pointer-events-none transition-colors"
              :disabled="offset <= 0 || loading"
              @click="changeOffset(-limit)"
            >
              {{ t("common.previous") }}
            </button>
            <div
              class="text-sm text-gray-500 text-center order-first sm:order-none"
            >
              {{
                t("stores.cashu_payments_showing", {
                  start: payments.total === 0 ? 0 : offset + 1,
                  end: Math.min(offset + limit, payments.total),
                  total: payments.total,
                })
              }}
            </div>
            <button
              type="button"
              class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white disabled:opacity-40 disabled:pointer-events-none transition-colors"
              :disabled="offset + limit >= payments.total || loading"
              @click="changeOffset(limit)"
            >
              {{ t("common.next") }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import api from "../../services/api";
import Select from "../ui/Select.vue";

const { t } = useI18n();

const props = defineProps<{
  store: { id: string };
}>();

type SettlementState = "SETTLED" | "PENDING" | "FAILED" | "MELT_COMPLETE";

interface CashuPayment {
  quote_id: string;
  created_at?: string | null;
  amount_sats?: number | null;
  settlement_state?: SettlementState | string | null;
  settlement_error?: string | null;
  mint_quote_poll_url?: string | null;
  retry_count?: number | null;
  needs_manual_review?: boolean | null;
  failure_reason_code?: string | null;
}

/** Failure reason codes emitted by the CashuMelt plugin (CashuMeltFailureReasons). */
const FAILURE_REASON_KEYS: Record<string, string> = {
  mint_poll_error: "stores.cashu_reason_mint_poll_error",
  trusted_mint_violation: "stores.cashu_reason_trusted_mint_violation",
  mint_proof_failed: "stores.cashu_reason_mint_proof_failed",
  keyset_conflict: "stores.cashu_reason_keyset_conflict",
  ln_address_unresolvable: "stores.cashu_reason_ln_address_unresolvable",
  melt_quote_failed: "stores.cashu_reason_melt_quote_failed",
  fee_too_high: "stores.cashu_reason_fee_too_high",
  melt_failed: "stores.cashu_reason_melt_failed",
  amount_too_small: "stores.cashu_reason_amount_too_small",
  max_retries_exceeded: "stores.cashu_reason_max_retries_exceeded",
};

interface CashuPaymentsResponse {
  total: number;
  offset: number;
  limit: number;
  items: CashuPayment[];
}

const limit = 50;
const offset = ref(0);
const settlementState = ref<string>("");

const settlementStateOptions = computed(() => [
  { label: t("stores.cashu_filter_all"), value: "" },
  { label: t("stores.cashu_settlement_option_settled"), value: "SETTLED" },
  { label: t("stores.cashu_settlement_option_pending"), value: "PENDING" },
  { label: t("stores.cashu_settlement_option_melt_complete"), value: "MELT_COMPLETE" },
  { label: t("stores.cashu_settlement_option_failed"), value: "FAILED" },
]);

const payments = ref<CashuPaymentsResponse>({
  total: 0,
  offset: 0,
  limit,
  items: [],
});

const loading = ref(false);
const error = ref<string | null>(null);
const retryNotice = ref<string | null>(null);
const retryingQuoteIds = ref<Set<string>>(new Set());
const copiedPollQuoteId = ref<string | null>(null);
let copyPollResetTimer: ReturnType<typeof setTimeout> | null = null;

function storeId(): string {
  return props.store.id;
}

function formatDate(dateString?: string | null): string {
  if (!dateString) return "-";
  try {
    return new Date(dateString).toLocaleString(undefined, {
      dateStyle: "medium",
      timeStyle: "short",
    });
  } catch {
    return dateString;
  }
}

function formatAmount(amountSats?: number | null): string {
  if (amountSats == null) return "-";
  const n = typeof amountSats === "string" ? Number(amountSats) : amountSats;
  if (!Number.isFinite(n)) return String(amountSats);
  return Math.round(n).toLocaleString();
}

function settlementStateLabel(state?: string | null): string {
  const s = (state ?? "").toString().toUpperCase();
  switch (s) {
    case "SETTLED":
      return t("stores.cashu_settlement_label_settled");
    case "PENDING":
      return t("stores.cashu_settlement_label_pending");
    case "FAILED":
      return t("stores.cashu_settlement_label_failed");
    case "MELT_COMPLETE":
      return t("stores.cashu_settlement_label_melt_complete");
    default:
      return state && String(state).trim() !== "" ? String(state) : "-";
  }
}

function settlementPillClass(state?: string | null): string {
  const base =
    "inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold border";
  switch (state) {
    case "SETTLED":
      return `${base} uppercase tracking-wide bg-emerald-500/15 text-emerald-300 border-emerald-500/30`;
    case "PENDING":
      return `${base} uppercase tracking-wide bg-gray-700/90 text-gray-300 border-gray-600`;
    case "MELT_COMPLETE":
      return `${base} tracking-wide bg-amber-500/15 text-amber-200 border-amber-500/35`;
    case "FAILED":
      return `${base} uppercase tracking-wide bg-red-500/15 text-red-300 border-red-500/35`;
    default:
      return `${base} uppercase tracking-wide bg-gray-700/90 text-gray-400 border-gray-600`;
  }
}

async function copyMintPollUrl(p: CashuPayment) {
  const quoteId = p.quote_id;
  const url = p.mint_quote_poll_url;
  if (!quoteId || !url) return;
  try {
    await navigator.clipboard.writeText(url);
    copiedPollQuoteId.value = quoteId;
    if (copyPollResetTimer) clearTimeout(copyPollResetTimer);
    copyPollResetTimer = setTimeout(() => {
      copiedPollQuoteId.value = null;
      copyPollResetTimer = null;
    }, 2000);
  } catch {
    error.value = t("common.copy_failed");
  }
}

function failureReasonLabel(code?: string | null): string {
  if (!code) return "";
  const key = FAILURE_REASON_KEYS[code];
  return key ? t(key) : code;
}

function canRetry(p: CashuPayment): boolean {
  if (!p.quote_id) return false;
  const s = (p.settlement_state ?? "").toString().toUpperCase();
  if (s === "FAILED") {
    const err = (p.settlement_error ?? "").toString();
    return !err.toLowerCase().includes("proofs are not available");
  }
  // The plugin also retries PENDING (stuck settlement) and MELT_COMPLETE
  // (merchant already paid - only the BTCPay accounting record is retried).
  return s === "PENDING" || s === "MELT_COMPLETE";
}

async function fetchPayments() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, unknown> = {
      limit,
      offset: offset.value,
    };
    if (settlementState.value) {
      params.settlementState = settlementState.value;
    }

    const response = await api.get(`/stores/${storeId()}/cashu/payments`, {
      params,
    });
    const d = response.data?.data ?? {};

    payments.value = {
      total: Number(d.total ?? 0),
      offset: Number(d.offset ?? offset.value),
      limit: Number(d.limit ?? limit),
      items: (d.items ?? []) as CashuPayment[],
    };
  } catch (err: unknown) {
    const e = err as {
      response?: { data?: { message?: string } };
      message?: string;
    };
    error.value =
      e.response?.data?.message ||
      e.message ||
      t("stores.cashu_payments_load_error");
  } finally {
    loading.value = false;
  }
}

async function retryPayment(quoteId: string) {
  if (!quoteId) return;

  retryNotice.value = null;
  retryingQuoteIds.value.add(quoteId);
  retryingQuoteIds.value = new Set(retryingQuoteIds.value);
  try {
    const response = await api.post(
      `/stores/${storeId()}/cashu/payments/${quoteId}/retry`,
    );
    const d = response.data?.data ?? {};
    if (d.settled) {
      retryNotice.value = t("stores.cashu_retry_settled");
    } else if (d.retry_after_seconds != null) {
      retryNotice.value = t("stores.cashu_retry_in_progress", {
        seconds: d.retry_after_seconds,
      });
    }
    await fetchPayments();
  } catch (err: unknown) {
    const e = err as {
      response?: { data?: { message?: string } };
      message?: string;
    };
    error.value =
      e.response?.data?.message ||
      e.message ||
      t("stores.cashu_payments_retry_error");
  } finally {
    retryingQuoteIds.value.delete(quoteId);
    retryingQuoteIds.value = new Set(retryingQuoteIds.value);
  }
}

function changeOffset(delta: number) {
  offset.value = Math.max(0, offset.value + delta);
  fetchPayments();
}

function onSettlementFilterChange() {
  offset.value = 0;
  fetchPayments();
}

watch(
  () => props.store.id,
  () => {
    offset.value = 0;
    settlementState.value = "";
    fetchPayments();
  },
);

onMounted(async () => {
  await fetchPayments();
});
</script>
