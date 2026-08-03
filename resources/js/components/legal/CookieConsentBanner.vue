<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="fixed bottom-0 inset-x-0 z-50 p-4 sm:p-6"
      role="dialog"
      aria-labelledby="cookie-consent-title"
    >
      <div
        class="mx-auto max-w-3xl rounded-xl border border-gray-700 bg-gray-900/95 backdrop-blur-md shadow-2xl p-4 sm:p-5"
      >
        <h2 id="cookie-consent-title" class="text-sm font-semibold text-white mb-2">
          {{ t('legal.cookies.title') }}
        </h2>
        <p class="text-sm text-gray-400 leading-relaxed">
          {{ t('legal.cookies.description') }}
          <router-link
            to="/legal/privacy"
            class="text-indigo-400 hover:text-indigo-300 underline"
          >{{ t('legal.nav.privacy') }}</router-link>
        </p>
        <p
          v-if="localFirst"
          class="text-sm text-gray-400 leading-relaxed mt-2"
        >
          {{ t('legal.cookies.local_first_notice') }}
        </p>
        <div class="mt-4 flex flex-wrap gap-3">
          <button
            type="button"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
            @click="accept"
          >
            {{ t('legal.cookies.accept') }}
          </button>
          <button
            type="button"
            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-medium text-gray-300 hover:bg-gray-800"
            @click="acceptEssentialOnly"
          >
            {{ t('legal.cookies.essential_only') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { hasCookieConsent, setCookieConsent } from '../../composables/useCookieConsent';
import { isInvoicingLocalFirst } from '../../evolu/flags';

const { t } = useI18n();
const visible = ref(false);
const localFirst = isInvoicingLocalFirst();

onMounted(() => {
  visible.value = !hasCookieConsent();
});

function accept() {
  setCookieConsent('all');
  visible.value = false;
  void import('../../services/analytics')
    .then(({ onAnalyticsConsentGranted }) => onAnalyticsConsentGranted())
    .catch(() => {});
}

function acceptEssentialOnly() {
  setCookieConsent('essential');
  visible.value = false;
  void import('../../services/analytics')
    .then(({ onAnalyticsConsentWithdrawn }) => onAnalyticsConsentWithdrawn())
    .catch(() => {});
}
</script>
