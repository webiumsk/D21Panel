<template>
  <div class="space-y-2">
    <textarea
      :value="modelValue"
      rows="4"
      class="w-full rounded-xl border border-gray-600 bg-gray-900/80 px-4 py-3 text-sm text-gray-200 placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
      :placeholder="placeholder"
      autocomplete="off"
      autocapitalize="off"
      spellcheck="false"
      @input="onInput"
    />
    <div class="flex flex-wrap items-center gap-2">
      <span
        class="text-xs font-medium rounded-full px-2 py-0.5 border"
        :class="
          words.length === expectedWords && checksumOk
            ? 'border-green-500/40 text-green-300 bg-green-500/10'
            : 'border-gray-600 text-gray-400 bg-gray-900/50'
        "
      >
        {{ t("auth.seed_word_count", { count: words.length, total: expectedWords }) }}
      </span>
      <span
        v-for="invalid in invalidWords"
        :key="`${invalid.index}-${invalid.word}`"
        class="text-xs rounded-full px-2 py-0.5 border border-red-500/40 text-red-300 bg-red-500/10"
      >
        {{ t("auth.seed_word_invalid", { position: invalid.index + 1, word: invalid.word }) }}
      </span>
    </div>
    <div v-if="suggestions.length > 0" class="flex flex-wrap items-center gap-2">
      <button
        v-for="suggestion in suggestions"
        :key="suggestion"
        type="button"
        class="text-xs rounded-full px-2.5 py-1 border border-indigo-500/40 text-indigo-200 bg-indigo-500/10 hover:bg-indigo-500/20 transition-colors"
        @click="applySuggestion(suggestion)"
      >
        {{ suggestion }}
      </button>
    </div>
    <p v-if="showChecksumError" class="text-xs text-red-400">
      {{ t("auth.seed_checksum_invalid") }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { wordlist } from "@scure/bip39/wordlists/english.js";
import { validateGuestMnemonic } from "../../services/guestRecovery";

const props = withDefaults(
  defineProps<{
    modelValue: string;
    placeholder?: string;
    expectedWords?: number;
  }>(),
  {
    placeholder: "",
    expectedWords: 24,
  },
);

const emit = defineEmits<{
  "update:modelValue": [value: string];
}>();

const { t } = useI18n();

const wordSet = new Set<string>(wordlist);
const MAX_SUGGESTIONS = 5;
const MAX_INVALID_CHIPS = 4;

const words = computed(() =>
  props.modelValue.trim().toLowerCase().split(/\s+/).filter((word) => word.length > 0),
);

/** True while the caret is mid-word: the last word gets suggestions, not a red chip. */
const lastWordInProgress = computed(
  () => props.modelValue.length > 0 && !/\s$/.test(props.modelValue),
);

const invalidWords = computed(() => {
  const list = words.value
    .map((word, index) => ({ word, index }))
    .filter(({ word, index }) => {
      if (wordSet.has(word)) {
        return false;
      }
      // The word being typed is judged by the suggestion row instead.
      return !(lastWordInProgress.value && index === words.value.length - 1);
    });
  return list.slice(0, MAX_INVALID_CHIPS);
});

const suggestions = computed(() => {
  if (!lastWordInProgress.value || words.value.length === 0) {
    return [];
  }
  const prefix = words.value[words.value.length - 1];
  if (wordSet.has(prefix)) {
    return [];
  }
  return wordlist.filter((word) => word.startsWith(prefix)).slice(0, MAX_SUGGESTIONS);
});

const checksumOk = computed(
  () => words.value.length === props.expectedWords && validateGuestMnemonic(props.modelValue),
);

/**
 * Pre-validation only appears once every word is a real BIP39 word at full
 * length - anything earlier is still "typing", and the server stays the
 * final authority either way (submit is never disabled by this component).
 */
const showChecksumError = computed(
  () =>
    words.value.length === props.expectedWords &&
    invalidWords.value.length === 0 &&
    !lastWordInProgress.value &&
    !checksumOk.value,
);

function onInput(event: Event): void {
  emit("update:modelValue", (event.target as HTMLTextAreaElement).value);
}

function applySuggestion(suggestion: string): void {
  const completed = [...words.value.slice(0, -1), suggestion].join(" ") + " ";
  emit("update:modelValue", completed);
}
</script>
