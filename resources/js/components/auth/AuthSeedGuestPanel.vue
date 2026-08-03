<template>
  <div class="space-y-4" :class="className">
    <p class="text-sm text-gray-300 leading-relaxed">
      {{ t("auth.seed_mode_short_intro") }}
    </p>
    <div
      class="rounded-lg border border-amber-500/25 bg-amber-950/30 px-3 py-2.5"
      role="note"
    >
      <p class="text-xs font-semibold text-amber-200/95 mb-1">
        {{ t("auth.guest_mode_limits_heading") }}
      </p>
      <p class="text-xs text-amber-100/85 leading-relaxed">
        {{ t("auth.guest_mode_limitations") }}
      </p>
    </div>

    <div
      class="rounded-xl border border-indigo-400/50 bg-gradient-to-r from-indigo-500/15 to-purple-500/15 p-4 shadow-inner"
    >
      <button
        type="button"
        :disabled="primaryDisabled"
        class="w-full inline-flex items-center justify-center gap-2 py-3 px-4 text-sm font-bold rounded-lg disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2 focus:ring-offset-gray-800"
        :class="
          hierarchy === 'secondary'
            ? 'border border-indigo-500/40 text-indigo-200 bg-indigo-500/10 hover:bg-indigo-500/20'
            : 'bg-indigo-600 hover:bg-indigo-500 text-white'
        "
        @click="emit('primary')"
      >
        <svg
          v-if="primaryIcon === 'restore'"
          class="w-4 h-4 shrink-0"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
        <svg
          v-else-if="primaryIcon === 'create'"
          class="w-4 h-4 shrink-0"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 4v16m8-8H4"
          />
        </svg>
        {{ primaryLabel }}
      </button>

      <div class="mt-4 pt-4 border-t border-indigo-400/25 space-y-2">
        <template v-if="variant === 'login'">
          <router-link
            to="/register"
            class="block w-full text-center py-2 text-sm font-medium text-indigo-200 hover:text-white rounded-lg border border-transparent hover:border-indigo-400/30 hover:bg-indigo-500/10 transition-colors"
          >
            {{ t("auth.register_short") }}
          </router-link>
          <p class="text-xs text-gray-400 text-center leading-relaxed">
            {{ t("auth.guest_cta_hint") }}
          </p>
        </template>
        <template v-else>
          <button
            type="button"
            class="w-full py-2 text-sm font-medium text-indigo-200 hover:text-white rounded-lg border border-transparent hover:border-indigo-400/30 hover:bg-indigo-500/10 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-400/50"
            @click="emit('secondary')"
          >
            {{ t("auth.restore_with_recovery_phrase") }}
          </button>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = withDefaults(
  defineProps<{
    variant: "login" | "register";
    primaryLabel: string;
    primaryDisabled?: boolean;
    className?: string;
    /** "secondary" de-emphasizes the primary button when a passkey CTA sits above the panel. */
    hierarchy?: "primary" | "secondary";
  }>(),
  {
    primaryDisabled: false,
    className: "",
    hierarchy: "primary",
  },
);

const emit = defineEmits<{
  primary: [];
  secondary: [];
}>();

const { t } = useI18n();

const primaryIcon = computed(() =>
  props.variant === "login" ? "restore" : "create",
);
</script>
