<template>
  <div
    class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar"
  >
    <div class="max-w-7xl mx-auto py-8 px-4">
      <div class="mb-6">
        <h1 class="text-3xl font-bold text-white">
          Wallet Connections Needing Support
        </h1>
        <p class="mt-2 text-sm text-gray-400">
          Review and configure wallet connections submitted by merchants
        </p>
      </div>

      <!-- Filters and Search -->
      <div
        class="mb-6 bg-gray-800 border border-gray-700 rounded-lg p-4 shadow-lg"
      >
        <div class="flex flex-col sm:flex-row gap-4">
          <!-- Search -->
          <div class="flex-1">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Search by store name or user email..."
              class="block w-full rounded-lg border border-gray-600 bg-gray-700/50 text-white placeholder-gray-500 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-4 py-2"
            />
          </div>
          <!-- Status Filter -->
          <div>
            <Select
              v-model="statusFilter"
              :options="statusOptions"
              placeholder="Status"
              class="min-w-[160px]"
            />
          </div>
        </div>
      </div>

      <div v-if="loading" class="text-center py-8">
        <div
          class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-400"
        ></div>
      </div>

      <div
        v-else-if="error"
        class="bg-red-500/10 border border-red-500/20 rounded-lg p-4 mb-6"
      >
        <p class="text-sm text-red-400">{{ error }}</p>
      </div>

      <div
        v-else-if="filteredConnections.length === 0"
        class="bg-gray-800 border border-gray-700 rounded-lg p-8 text-center"
      >
        <p class="text-gray-400">
          {{
            connections.length === 0
              ? "No wallet connections need support at this time."
              : "No connections match your filters."
          }}
        </p>
      </div>

      <div v-else>
        <!-- Results count -->
        <div
          class="px-6 py-3 bg-gray-800 border-x border-t border-gray-700 rounded-t-lg"
        >
          <p class="text-sm text-gray-400">
            Showing {{ filteredConnections.length }} of
            {{ connections.length }} connection(s)
          </p>
        </div>

        <!-- Desktop Table View -->
        <div
          class="hidden md:block bg-gray-800 border border-gray-700 shadow-lg rounded-b-lg overflow-hidden"
        >
          <table class="min-w-full divide-y divide-gray-700">
            <thead class="bg-gray-700/50">
              <tr>
                <th
                  class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider"
                >
                  Store
                </th>
                <th
                  class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider"
                >
                  Type
                </th>
                <th
                  class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider"
                >
                  Status
                </th>
                <th
                  class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider"
                >
                  Secret (Masked)
                </th>
                <th
                  class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider"
                >
                  Submitted
                </th>
                <th
                  class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider"
                >
                  Bot failure
                </th>
                <th
                  class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider"
                >
                  Actions
                </th>
              </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
              <tr
                v-for="connection in filteredConnections"
                :key="connection.id"
              >
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm font-medium text-white">
                    {{ connection.store_name }}
                  </div>
                  <div class="text-sm text-gray-400">
                    {{ connection.submitted_by }}
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="px-2 py-1 inline-flex items-center gap-1.5 text-xs leading-5 font-semibold rounded-full"
                    :class="
                      connection.type === 'blink'
                        ? 'bg-blue-500/20 text-blue-400'
                        : 'bg-green-500/20 text-green-400'
                    "
                  >
                    <WalletTypeIcon
                      :type="connection.type"
                      :brand="connection.type === 'aqua_descriptor' || connection.type === 'lnaddress' ? (connection.brand ?? undefined) : undefined"
                      size="lg"
                    />
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                    :class="getStatusBadgeClass(connection.status)"
                  >
                    {{ formatStatus(connection.status) }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <code
                    class="text-sm text-gray-300 font-mono bg-gray-700/50 px-2 py-1 rounded"
                    >{{ connection.masked_secret }}</code
                  >
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                  {{ connection.submitted_at ? formatDate(connection.submitted_at) : '-' }}
                </td>
                <td class="px-6 py-4 max-w-xs">
                  <span
                    v-if="
                      connection.status === 'needs_support' &&
                      connection.bot_failure_message
                    "
                    class="text-xs text-amber-400"
                    :title="connection.bot_failure_message"
                  >
                    {{
                      connection.bot_failed_at
                        ? formatDate(connection.bot_failed_at) + ": "
                        : ""
                    }}{{ connection.bot_failure_message.slice(0, 80)
                    }}{{
                      connection.bot_failure_message.length > 80 ? "…" : ""
                    }}
                  </span>
                  <span v-else class="text-gray-500 text-xs">-</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <div class="flex items-center space-x-3">
                    <button
                      @click="revealSecret(connection)"
                      class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-800"
                    >
                      <svg
                        class="w-3 h-3 mr-1.5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                        />
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                        />
                      </svg>
                      Reveal & Configure
                    </button>
                    <button
                      v-if="connection.status === 'needs_support'"
                      @click="markConnected(connection)"
                      class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-green-600 hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 focus:ring-offset-gray-800"
                    >
                      <svg
                        class="w-3 h-3 mr-1.5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M5 13l4 4L19 7"
                        />
                      </svg>
                      Mark Connected
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card View -->
        <div class="md:hidden space-y-4">
          <div
            v-for="connection in filteredConnections"
            :key="connection.id"
            class="bg-gray-800 border border-gray-700 rounded-lg p-4 shadow-lg"
          >
            <div class="flex justify-between items-start mb-4">
              <div>
                <div class="text-lg font-bold text-white">
                  {{ connection.store_name }}
                </div>
                <div class="text-sm text-gray-400">
                  {{ connection.submitted_by }}
                </div>
              </div>
              <div class="flex flex-col items-end space-y-2">
                <span
                  class="px-2 py-1 inline-flex items-center gap-1.5 text-xs leading-5 font-semibold rounded-full"
                  :class="
                    connection.type === 'blink'
                      ? 'bg-blue-500/20 text-blue-400'
                      : 'bg-green-500/20 text-green-400'
                  "
                >
                  <WalletTypeIcon
                    :type="connection.type"
                    :brand="connection.type === 'aqua_descriptor' || connection.type === 'lnaddress' ? (connection.brand ?? undefined) : undefined"
                    size="lg"
                  />
                </span>
                <span
                  class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                  :class="getStatusBadgeClass(connection.status)"
                >
                  {{ formatStatus(connection.status) }}
                </span>
              </div>
            </div>

            <div
              v-if="
                connection.status === 'needs_support' &&
                connection.bot_failure_message
              "
              class="mb-3 p-2 bg-amber-500/10 border border-amber-500/20 rounded text-xs text-amber-400"
            >
              <span class="font-medium">Bot failure:</span>
              {{ connection.bot_failure_message }}
            </div>
            <div class="space-y-3">
              <div class="flex flex-col">
                <span
                  class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1"
                  >Secret (Masked)</span
                >
                <code
                  class="text-sm text-gray-300 font-mono bg-gray-700/50 px-2 py-1 rounded w-full break-all"
                  >{{ connection.masked_secret }}</code
                >
              </div>

              <div class="flex justify-between items-center text-sm">
                <span class="text-gray-500">Submitted:</span>
                <span class="text-gray-400">{{
                  connection.submitted_at ? formatDate(connection.submitted_at) : '-'
                }}</span>
              </div>

              <div
                class="pt-3 border-t border-gray-700 flex flex-col space-y-3"
              >
                <button
                  @click="revealSecret(connection)"
                  class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-800"
                >
                  <svg
                    class="w-4 h-4 mr-2"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                    />
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                    />
                  </svg>
                  Reveal & Configure
                </button>
                <button
                  v-if="connection.status === 'needs_support'"
                  @click="markConnected(connection)"
                  class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 focus:ring-offset-gray-800"
                >
                  <svg
                    class="w-4 h-4 mr-2"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M5 13l4 4L19 7"
                    />
                  </svg>
                  Mark Connected
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <RevealSecretModal
        v-if="showRevealModal && selectedConnection"
        :connection="selectedConnection"
        @close="handleModalClose"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { SupportWalletConnection } from "../../services/api";
import { asApiError } from "../../utils/apiError";
import { ref, onMounted, computed, watch } from "vue";
import api from "../../services/api";
import RevealSecretModal from "../../components/support/RevealSecretModal.vue";
import WalletTypeIcon from "../../components/WalletTypeIcon.vue";
import Select from "../../components/ui/Select.vue";
import { getApiErrorMessage } from "../../composables/useApiError";

const loading = ref(true);
const error = ref<string | null>(null);
const connections = ref<SupportWalletConnection[]>([]);
const showRevealModal = ref(false);
const selectedConnection = ref<SupportWalletConnection | null>(null);
const searchQuery = ref("");
const statusFilter = ref("needs_support");

const statusOptions = [
  { label: "Needs Support", value: "needs_support" },
  { label: "All Statuses", value: "all" },
  { label: "Pending", value: "pending" },
  { label: "Connected", value: "connected" },
];

async function loadConnections() {
  loading.value = true;
  error.value = null;
  try {
    const status = statusFilter.value || "needs_support";
    const response = await api.get("/support/wallet-connections", {
      params: { status },
    });
    connections.value = response.data.data || [];
  } catch (err) {
    error.value = getApiErrorMessage(err, "Failed to load wallet connections");
  } finally {
    loading.value = false;
  }
}

function formatDate(dateString: string): string {
  if (!dateString) return "N/A";
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return "N/A";

  // European format: DD.MM.YYYY HH:mm
  const day = String(date.getDate()).padStart(2, "0");
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const year = date.getFullYear();
  const hours = String(date.getHours()).padStart(2, "0");
  const minutes = String(date.getMinutes()).padStart(2, "0");
  return `${day}.${month}.${year} ${hours}:${minutes}`;
}

function formatStatus(status: string): string {
  const statusMap: Record<string, string> = {
    pending: "Pending",
    needs_support: "Needs Support",
    connected: "Connected",
  };
  return statusMap[status] || status;
}

function getStatusBadgeClass(status: string): string {
  const classMap: Record<string, string> = {
    pending: "bg-yellow-500/20 text-yellow-400",
    needs_support: "bg-blue-500/20 text-blue-400",
    connected: "bg-green-500/20 text-green-400",
  };
  return classMap[status] || "bg-gray-500/20 text-gray-400";
}

// Refetch when status filter changes (API returns filtered data)
watch(statusFilter, () => {
  loadConnections();
});

// Filtered connections based on search (status filter is applied by API)
const filteredConnections = computed(() => {
  let filtered = connections.value;

  // Apply search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    filtered = filtered.filter(
      (conn) =>
        conn.store_name?.toLowerCase().includes(query) ||
        conn.submitted_by?.toLowerCase().includes(query) ||
        conn.masked_secret?.toLowerCase().includes(query),
    );
  }

  return filtered;
});

function revealSecret(connection: SupportWalletConnection) {
  selectedConnection.value = connection;
  showRevealModal.value = true;
}

function handleModalClose() {
  showRevealModal.value = false;
  // Refresh connections list when modal closes (e.g., after marking as connected)
  loadConnections();
}

async function markConnected(connection: SupportWalletConnection) {
  if (!confirm("Mark this wallet connection as connected?")) {
    return;
  }

  try {
    await api.put(
      `/support/wallet-connections/${connection.id}/mark-connected`,
    );
    await loadConnections();
  } catch (rawError) {
    const err = asApiError(rawError);
    alert(
      err.response?.data?.message || "Failed to mark connection as connected",
    );
  }
}

onMounted(() => {
  loadConnections();
});
</script>
