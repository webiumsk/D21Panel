<template>
  <div class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold text-white">{{ t("messages.title") }}</h1>
          <p class="mt-1 text-sm text-gray-400">
            {{ t("messages.subtitle") }}
          </p>
        </div>
        <button
          v-if="available && unreadCount > 0 && messages.length > 0"
          type="button"
          :disabled="markingAll"
          class="inline-flex items-center px-4 py-2 text-sm font-medium text-indigo-400 hover:text-indigo-300 border border-indigo-500/40 rounded-lg hover:bg-indigo-500/10 transition-colors disabled:opacity-50"
          @click="markAllAsRead"
        >
          <svg
            v-if="markingAll"
            class="animate-spin -ml-1 mr-2 h-4 w-4 text-indigo-400"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          {{ t("messages.mark_all_read") }}
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex justify-center py-12">
        <svg
          class="animate-spin h-10 w-10 text-indigo-500"
          fill="none"
          viewBox="0 0 24 24"
        >
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
      </div>

      <!-- Not available (no BTCPay API key or old key without notification permissions) -->
      <div
        v-else-if="!available"
        class="bg-gray-800/50 border border-amber-500/30 rounded-2xl p-12 text-center"
      >
        <div class="flex justify-center mb-4">
          <div class="w-16 h-16 rounded-full bg-amber-500/10 flex items-center justify-center">
            <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
        <h3 class="text-lg font-medium text-white mb-2">{{ t("messages.not_available_title") }}</h3>
        <p class="text-gray-400 text-sm">{{ t("messages.not_available_subtitle") }}</p>
      </div>

      <!-- Empty state -->
      <div
        v-else-if="messages.length === 0"
        class="bg-gray-800/50 border border-gray-700 rounded-2xl p-12 text-center"
      >
        <div class="flex justify-center mb-4">
          <div class="w-16 h-16 rounded-full bg-gray-700/50 flex items-center justify-center">
            <svg class="w-8 h-8 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
          </div>
        </div>
        <h3 class="text-lg font-medium text-white mb-2">{{ t("messages.empty_title") }}</h3>
        <p class="text-gray-400 text-sm">{{ t("messages.empty_subtitle") }}</p>
      </div>

      <!-- Message list -->
      <div v-else class="space-y-3">
        <div
          v-for="msg in messages"
          :key="msg.id"
          :class="[
            'rounded-xl border p-4 transition-colors cursor-pointer',
            msg.type === 'security' && !msg.read_at
              ? 'bg-red-500/10 border-red-500/60 hover:border-red-400 ring-1 ring-red-500/30'
              : msg.read_at
                ? 'bg-gray-800/50 border-gray-700 hover:border-gray-600'
                : 'bg-gray-800 border-indigo-500/30 hover:border-indigo-500/50',
          ]"
          @click="openMessage(msg)"
        >
          <div class="flex items-start gap-4">
            <div
              :class="[
                'flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center',
                getTypeIconBg(msg.type),
              ]"
            >
              <svg class="w-5 h-5" :class="getTypeIconColor(msg.type)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getTypeIconPath(msg.type)" />
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span
                  v-if="msg.type === 'security'"
                  class="inline-flex items-center rounded-md bg-red-500/20 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-red-300"
                  >{{ t("messages.security_label") }}</span
                >
                <h3 class="text-sm font-semibold text-white">{{ msg.title }}</h3>
                <span
                  v-if="!msg.read_at"
                  class="inline-block w-2 h-2 rounded-full"
                  :class="msg.type === 'security' ? 'bg-red-500' : 'bg-indigo-500'"
                />
              </div>
              <p
                v-if="msg.body"
                class="mt-1 text-sm"
                :class="msg.type === 'security' ? 'text-red-100/90' : 'text-gray-400 line-clamp-2'"
              >{{ msg.body }}</p>
              <div class="mt-2 flex items-center justify-between gap-2">
                <span class="text-xs text-gray-500">{{ formatDate(msg.created_at) }}</span>
                <a
                  v-if="msg.link"
                  :href="msg.link"
                  class="text-xs font-medium text-indigo-400 hover:text-indigo-300"
                  @click.stop
                >
                  {{ msg.link_text || t("messages.view") }}
                </a>
              </div>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="meta.last_page > 1" class="flex justify-center gap-2 pt-4">
          <button
            :disabled="meta.current_page <= 1"
            class="px-3 py-1.5 text-sm font-medium rounded-lg border border-gray-600 text-gray-400 hover:text-white hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="loadPage(meta.current_page - 1)"
          >
            {{ t("messages.previous") }}
          </button>
          <span class="px-3 py-1.5 text-sm text-gray-400">
            {{ meta.current_page }} / {{ meta.last_page }}
          </span>
          <button
            :disabled="meta.current_page >= meta.last_page"
            class="px-3 py-1.5 text-sm font-medium rounded-lg border border-gray-600 text-gray-400 hover:text-white hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="loadPage(meta.current_page + 1)"
          >
            {{ t("messages.next") }}
          </button>
        </div>
      </div>
    </div>
  </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { useRouter } from "vue-router";
import api from "../services/api";

const { t } = useI18n();
const router = useRouter();

interface Message {
  id: string | number;
  type: string;
  title: string;
  body: string | null;
  link: string | null;
  link_text: string | null;
  read_at: string | null;
  created_at: string;
}

const loading = ref(true);
const markingAll = ref(false);
const messages = ref<Message[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
const unreadCount = ref(0);
const available = ref(true);

const loadMessages = async (page = 1) => {
  loading.value = true;
  try {
    const { data } = await api.get("/messages", { params: { page, per_page: 20 } });
    messages.value = data.data ?? [];
    meta.value = data.meta ?? meta.value;
    available.value = data.available !== false;
    window.dispatchEvent(new CustomEvent("messages-updated"));
  } catch (e) {
    console.error("Failed to load messages:", e);
  } finally {
    loading.value = false;
  }
};

const loadMessageCount = async () => {
  try {
    const { data } = await api.get("/messages/count");
    unreadCount.value = data.data?.unread ?? 0;
    available.value = data.available !== false;
  } catch {
    unreadCount.value = 0;
  }
};

const openMessage = async (msg: Message) => {
  if (msg.link) {
    const sameOrigin = msg.link.startsWith(window.location.origin + "/");
    if (sameOrigin) {
      // Satflux-local messages (security alerts) point into this app: stay in the SPA.
      void router.push(msg.link.slice(window.location.origin.length));
    } else {
      window.open(msg.link, "_blank", "noopener,noreferrer");
    }
  }
  if (!msg.read_at) {
    try {
      await api.patch(`/messages/${msg.id}/read`);
      msg.read_at = new Date().toISOString();
      unreadCount.value = Math.max(0, unreadCount.value - 1);
      window.dispatchEvent(new CustomEvent("messages-updated"));
    } catch (e) {
      console.error("Failed to mark message as read:", e);
    }
  }
};

const markAllAsRead = async () => {
  markingAll.value = true;
  try {
    await api.post("/messages/mark-all-read");
    messages.value = messages.value.map((m: Message) => ({ ...m, read_at: m.read_at ?? new Date().toISOString() }));
    unreadCount.value = 0;
    window.dispatchEvent(new CustomEvent("messages-updated"));
  } catch (e) {
    console.error("Failed to mark all as read:", e);
  } finally {
    markingAll.value = false;
  }
};

const loadPage = (page: number) => {
  loadMessages(page);
};

const formatDate = (dateStr: string) => {
  const d = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
  if (diffDays === 0) {
    return d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
  }
  if (diffDays === 1) return t("messages.yesterday");
  if (diffDays < 7) return d.toLocaleDateString(undefined, { weekday: "long" });
  return d.toLocaleDateString();
};

const getTypeIconBg = (type: string) => {
  const map: Record<string, string> = {
    success: "bg-green-500/20",
    warning: "bg-amber-500/20",
    invoice: "bg-orange-500/20",
    subscription: "bg-purple-500/20",
    support: "bg-blue-500/20",
    security: "bg-red-500/20",
  };
  return map[type] ?? "bg-indigo-500/20";
};

const getTypeIconColor = (type: string) => {
  const map: Record<string, string> = {
    success: "text-green-400",
    warning: "text-amber-400",
    invoice: "text-orange-400",
    subscription: "text-purple-400",
    support: "text-blue-400",
    security: "text-red-400",
  };
  return map[type] ?? "text-indigo-400";
};

const getTypeIconPath = (type?: string) => {
  if (type === "security") {
    // Shield with exclamation
    return "M12 9v2m0 4h.01M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z";
  }
  // Bell icon path
  return "M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9";
};

onMounted(async () => {
  await Promise.all([loadMessages(), loadMessageCount()]);
});
</script>
