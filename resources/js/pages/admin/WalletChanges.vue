<template>
  <div
    class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar"
  >
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="mb-8 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 class="text-3xl font-bold text-white">{{ t("admin.wallet_changes.title") }}</h1>
          <p class="text-gray-400 mt-1">{{ t("admin.wallet_changes.description") }}</p>
        </div>
        <button
          type="button"
          class="text-sm font-medium rounded-md px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-white disabled:opacity-50"
          :disabled="loading"
          @click="reload"
        >
          {{ loading ? t("common.loading") : t("admin.wallet_changes.refresh") }}
        </button>
      </div>

      <!-- Active drifts -->
      <section class="mb-8">
        <h2 class="text-lg font-semibold text-white mb-3">
          {{ t("admin.wallet_changes.drifts_title") }}
          <span
            class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="drifts.length ? 'bg-red-500/20 text-red-300' : 'bg-emerald-500/20 text-emerald-300'"
            >{{ drifts.length }}</span
          >
        </h2>
        <p v-if="!drifts.length" class="text-sm text-gray-400 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4">
          {{ t("admin.wallet_changes.no_drifts") }}
        </p>
        <div v-else class="space-y-3">
          <div
            v-for="d in drifts"
            :key="d.id"
            class="rounded-xl border border-red-500/50 bg-red-500/10 p-4 flex flex-wrap items-start justify-between gap-3"
          >
            <div class="min-w-0">
              <p class="text-white font-semibold">
                {{ d.store?.name || d.store?.id }}
                <span class="text-gray-400 font-normal text-sm">· {{ d.owner_email }}</span>
              </p>
              <p class="text-xs text-red-200/90 mt-1">
                {{ t("admin.wallet_changes.drift_since") }} {{ formatDate(d.drift_detected_at) }}
                · {{ t("admin.wallet_changes.last_check") }} {{ formatDate(d.config_verified_at) }}
              </p>
              <p class="text-xs font-mono text-gray-300 mt-1 break-all">
                {{ d.type }} · {{ d.masked_secret }}
              </p>
              <p v-if="d.drift_details" class="text-xs font-mono text-red-200 mt-1">
                {{ diffSummary(d.drift_details) }}
              </p>
            </div>
            <div class="flex gap-2 shrink-0">
              <RouterLink
                :to="`/support/wallet-connections`"
                class="text-xs font-medium rounded-md px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-white"
              >
                {{ t("admin.wallet_changes.open_support") }}
              </RouterLink>
              <button
                type="button"
                class="text-xs font-medium rounded-md px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-white disabled:opacity-50"
                :disabled="busyId === d.id"
                @click="verifyNow(d.id)"
              >
                {{ t("admin.wallet_changes.verify_now") }}
              </button>
              <button
                type="button"
                class="text-xs font-medium rounded-md px-3 py-1.5 bg-amber-600 hover:bg-amber-500 text-white disabled:opacity-50"
                :disabled="busyId === d.id"
                @click="rebaseline(d.id)"
              >
                {{ t("admin.wallet_changes.accept_config") }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Filters -->
      <section class="mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
        <input
          v-model="filters.q"
          type="search"
          class="rounded-lg border border-gray-600 bg-gray-800 text-white px-3 py-2 text-sm"
          :placeholder="t('admin.wallet_changes.search_placeholder')"
          @keyup.enter="reload"
        />
        <select
          v-model="filters.action"
          class="rounded-lg border border-gray-600 bg-gray-800 text-white px-3 py-2 text-sm"
          @change="reload"
        >
          <option value="">{{ t("admin.wallet_changes.all_actions") }}</option>
          <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
        </select>
        <input
          v-model="filters.from"
          type="date"
          class="rounded-lg border border-gray-600 bg-gray-800 text-white px-3 py-2 text-sm"
          @change="reload"
        />
        <input
          v-model="filters.to"
          type="date"
          class="rounded-lg border border-gray-600 bg-gray-800 text-white px-3 py-2 text-sm"
          @change="reload"
        />
      </section>

      <!-- Log table -->
      <div class="overflow-x-auto rounded-xl border border-gray-700">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-800 text-gray-400 text-xs uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left">{{ t("admin.wallet_changes.col_time") }}</th>
              <th class="px-3 py-2 text-left">{{ t("admin.wallet_changes.col_action") }}</th>
              <th class="px-3 py-2 text-left">{{ t("admin.wallet_changes.col_store") }}</th>
              <th class="px-3 py-2 text-left">{{ t("admin.wallet_changes.col_actor") }}</th>
              <th class="px-3 py-2 text-left">{{ t("admin.wallet_changes.col_ip") }}</th>
              <th class="px-3 py-2 text-left">{{ t("admin.wallet_changes.col_details") }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-700/70 bg-gray-900/40">
            <tr v-if="!loading && !rows.length">
              <td colspan="6" class="px-3 py-6 text-center text-gray-400">{{ t("admin.wallet_changes.empty") }}</td>
            </tr>
            <tr v-for="row in rows" :key="row.id" :class="rowClass(row.action)">
              <td class="px-3 py-2 whitespace-nowrap text-gray-300">{{ formatDate(row.created_at) }}</td>
              <td class="px-3 py-2 whitespace-nowrap">
                <span class="rounded-md px-2 py-0.5 text-xs font-semibold" :class="badgeClass(row.action)">
                  {{ actionLabel(row.action) }}
                </span>
              </td>
              <td class="px-3 py-2 text-gray-200">
                <button
                  v-if="row.store"
                  type="button"
                  class="hover:underline text-left"
                  @click="filterStore(row.store.id)"
                >
                  {{ row.store.name || row.store.id }}
                </button>
              </td>
              <td class="px-3 py-2 text-gray-300">{{ row.user?.email || t("admin.wallet_changes.system") }}</td>
              <td class="px-3 py-2 text-gray-400 font-mono text-xs" :title="row.user_agent || ''">{{ row.ip_address || "-" }}</td>
              <td class="px-3 py-2 text-gray-400 font-mono text-xs break-all max-w-md">{{ detailText(row) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-gray-400">
        <button
          type="button"
          class="rounded-md px-3 py-1.5 bg-gray-800 hover:bg-gray-700 disabled:opacity-40"
          :disabled="meta.current_page <= 1"
          @click="loadPage(meta.current_page - 1)"
        >
          {{ t("messages.previous") }}
        </button>
        <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button
          type="button"
          class="rounded-md px-3 py-1.5 bg-gray-800 hover:bg-gray-700 disabled:opacity-40"
          :disabled="meta.current_page >= meta.last_page"
          @click="loadPage(meta.current_page + 1)"
        >
          {{ t("messages.next") }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import api from "../../services/api";
import { useFlashStore } from "../../store/flash";

interface DriftDiff {
  changed: string[];
  added: string[];
  removed: string[];
}

interface LogRow {
  id: string;
  action: string;
  created_at: string | null;
  user: { id: number; email: string | null } | null;
  store: { id: string; name: string | null } | null;
  target_type: string | null;
  target_id: string | null;
  ip_address: string | null;
  user_agent: string | null;
  metadata: Record<string, unknown>;
}

interface DriftRow {
  id: string;
  store: { id: string; name: string | null } | null;
  owner_email: string | null;
  type: string;
  masked_secret: string | null;
  drift_detected_at: string | null;
  config_verified_at: string | null;
  drift_details: DriftDiff | null;
}

const { t } = useI18n();
const flash = useFlashStore();

const loading = ref(false);
const rows = ref<LogRow[]>([]);
const drifts = ref<DriftRow[]>([]);
const actions = ref<string[]>([]);
const meta = reactive({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
const filters = reactive({ q: "", action: "", store_id: "", from: "", to: "" });
const busyId = ref<string | null>(null);

async function loadPage(page = 1) {
  loading.value = true;
  try {
    const params: Record<string, string | number> = { page, per_page: 50 };
    if (filters.q) params.q = filters.q;
    if (filters.action) params.action = filters.action;
    if (filters.store_id) params.store_id = filters.store_id;
    if (filters.from) params.from = filters.from;
    if (filters.to) params.to = filters.to;
    const { data } = await api.get("/admin/wallet-changes", { params });
    rows.value = data.data ?? [];
    actions.value = data.actions ?? [];
    Object.assign(meta, data.meta ?? {});
  } finally {
    loading.value = false;
  }
}

async function loadDrifts() {
  const { data } = await api.get("/admin/wallet-changes/drifts");
  drifts.value = data.data ?? [];
}

async function reload() {
  await Promise.all([loadPage(1), loadDrifts()]);
}

function filterStore(storeId: string) {
  filters.store_id = filters.store_id === storeId ? "" : storeId;
  void loadPage(1);
}

async function verifyNow(id: string) {
  busyId.value = id;
  try {
    const { data } = await api.post(`/admin/wallet-connections/${id}/verify-config`);
    flash.success(t("admin.wallet_changes.verify_result", { status: data.data?.status ?? "?" }));
    await reload();
  } finally {
    busyId.value = null;
  }
}

async function rebaseline(id: string) {
  busyId.value = id;
  try {
    await api.post(`/admin/wallet-connections/${id}/rebaseline`);
    flash.success(t("admin.wallet_changes.accepted"));
    await reload();
  } catch {
    flash.error(t("admin.wallet_changes.accept_failed"));
  } finally {
    busyId.value = null;
  }
}

function diffSummary(diff: DriftDiff | null): string {
  if (!diff) return "";
  const parts: string[] = [];
  if (diff.changed.length) parts.push(`${t("admin.wallet_changes.changed")}: ${diff.changed.join(", ")}`);
  if (diff.added.length) parts.push(`${t("admin.wallet_changes.added")}: ${diff.added.join(", ")}`);
  if (diff.removed.length) parts.push(`${t("admin.wallet_changes.removed")}: ${diff.removed.join(", ")}`);
  return parts.join(" · ");
}

function actionLabel(action: string): string {
  const key = `admin.wallet_changes.actions.${action.replace(/\./g, "_")}`;
  const label = t(key);
  return label === key ? action : label;
}

function detailText(row: LogRow): string {
  const m = row.metadata ?? {};
  const diff = m.diff as DriftDiff | undefined;
  if (diff) return diffSummary(diff);
  const bits: string[] = [];
  if (typeof m.type === "string") bits.push(m.type);
  if (typeof m.reason === "string") bits.push(m.reason);
  if (Array.isArray(m.methods)) bits.push((m.methods as string[]).join(", "));
  if (typeof m.challenge_id === "string") bits.push(`challenge ${m.challenge_id.slice(-8)}`);
  if (typeof m.success === "boolean") bits.push(m.success ? "success" : "failed");
  return bits.join(" · ");
}

function rowClass(action: string): string {
  if (action === "wallet_connection.drift_detected") return "bg-red-500/10";
  if (action === "wallet_connection.drift_resolved") return "bg-emerald-500/5";
  return "";
}

function badgeClass(action: string): string {
  if (action === "wallet_connection.drift_detected") return "bg-red-500/20 text-red-300";
  if (action === "wallet_connection.drift_resolved" || action === "wallet_connection.config_baselined") return "bg-emerald-500/20 text-emerald-300";
  if (action === "wallet_connection.revealed") return "bg-amber-500/20 text-amber-300";
  if (action.startsWith("wallet_connection.change_")) return "bg-indigo-500/20 text-indigo-300";
  return "bg-gray-700 text-gray-200";
}

function formatDate(iso: string | null): string {
  if (!iso) return "-";
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

onMounted(() => {
  void reload();
});
</script>
