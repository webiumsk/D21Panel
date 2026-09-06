<template>
  <div
    v-if="securityUnread > 0"
    class="shrink-0 border-b border-red-500/50 bg-red-600/20 px-4 py-2 text-sm text-red-100"
    role="alert"
  >
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-2">
        <svg class="h-5 w-5 shrink-0 text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
          />
        </svg>
        <span class="font-medium">{{ t("messages.security_banner", { count: securityUnread }) }}</span>
      </div>
      <RouterLink
        to="/messages"
        class="rounded-md bg-red-500/30 px-3 py-1 text-xs font-semibold text-white hover:bg-red-500/50"
      >
        {{ t("messages.security_banner_cta") }}
      </RouterLink>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { useMessageCounts } from "../../composables/useMessageCounts";

/**
 * Red bar under the header while the user has unread security messages
 * (wallet replaced / secret revealed / config drift) so they never get lost
 * among payment notifications. Reads the counter the header already loads -
 * no request of its own.
 */
const { t } = useI18n();
const { securityUnread } = useMessageCounts();
</script>
