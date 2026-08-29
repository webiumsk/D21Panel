<template>
  <section
    id="invoicing"
    class="relative scroll-mt-20 py-24 bg-gray-900 overflow-hidden"
    aria-labelledby="landing-invoicing-heading"
  >
    <div
      class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-emerald-500/35 to-transparent"
      aria-hidden="true"
    />
    <div
      class="pointer-events-none absolute -right-24 top-16 h-72 w-72 rounded-full bg-emerald-500/10 blur-3xl"
      aria-hidden="true"
    />
    <div
      class="pointer-events-none absolute -left-16 bottom-0 h-64 w-64 rounded-full bg-indigo-500/10 blur-3xl"
      aria-hidden="true"
    />

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-3xl mx-auto mb-14">
        <p
          class="font-mono text-[11px] sm:text-xs uppercase tracking-[0.28em] text-emerald-400/90 mb-4"
        >
          {{ t('landing.invoicing_section.kicker') }}
        </p>
        <h2
          id="landing-invoicing-heading"
          class="text-3xl sm:text-4xl font-bold text-white mb-4 text-balance"
        >
          {{ t('landing.invoicing_section.title') }}
        </h2>
        <p class="text-lg text-gray-400 text-balance">
          {{ t('landing.invoicing_section.subtitle') }}
        </p>
      </div>

      <div class="grid lg:grid-cols-2 gap-8 mb-16">
        <div
          class="rounded-2xl border border-gray-700/80 bg-gray-800/40 p-6 sm:p-8"
        >
          <h3 class="text-xl font-semibold text-white mb-4">
            {{ t('landing.invoicing_section.features_title') }}
          </h3>
          <ul class="space-y-3">
            <li
              v-for="key in featureBullets"
              :key="key"
              class="flex items-start gap-3 text-gray-300"
            >
              <span
                class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-400"
                aria-hidden="true"
              >
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2.5"
                    d="M5 13l4 4L19 7"
                  />
                </svg>
              </span>
              <span>{{ t(key) }}</span>
            </li>
          </ul>
          <p class="mt-6 text-sm text-gray-500">
            {{ t('landing.invoicing_section.pro_note', { days: pricing.trial_days }) }}
          </p>
        </div>

        <div
          class="rounded-2xl border border-indigo-500/25 bg-gradient-to-br from-indigo-950/50 via-gray-900 to-gray-900 p-6 sm:p-8"
        >
          <h3 class="text-xl font-semibold text-white mb-3">
            {{ t('landing.invoicing_section.positioning_title') }}
          </h3>
          <p class="text-gray-400 mb-4 leading-relaxed">
            {{ t('landing.invoicing_section.positioning_text') }}
          </p>
          <ul class="space-y-2 text-sm text-gray-400">
            <li>{{ t('landing.invoicing_section.positioning_bullet_1') }}</li>
            <li>{{ t('landing.invoicing_section.positioning_bullet_2') }}</li>
            <li>{{ t('landing.invoicing_section.positioning_bullet_3') }}</li>
          </ul>
          <router-link
            :to="invoicingCtaTo"
            class="mt-6 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
          >
            {{ invoicingCtaLabel }}
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
            </svg>
          </router-link>
        </div>
      </div>

      <div>
        <div class="text-center mb-8">
          <h3 class="text-2xl font-bold text-white mb-2">
            {{ t('landing.invoicing_section.compare.title') }}
          </h3>
          <p class="text-gray-400 max-w-2xl mx-auto">
            {{ t('landing.invoicing_section.compare.subtitle') }}
          </p>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-gray-700/80">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b border-gray-700 bg-gray-800/80">
                <th
                  scope="col"
                  class="px-4 py-4 font-semibold text-gray-300 min-w-[12rem]"
                >
                  {{ t('landing.invoicing_section.compare.col_feature') }}
                </th>
                <th
                  scope="col"
                  class="px-4 py-4 font-semibold text-emerald-300 min-w-[7rem]"
                >
                  {{ t('landing.invoicing_section.compare.col_satflux') }}
                </th>
                <th
                  scope="col"
                  class="px-4 py-4 font-semibold text-gray-300 min-w-[7rem]"
                >
                  {{ t('landing.invoicing_section.compare.col_zaprite') }}
                </th>
                <th
                  scope="col"
                  class="px-4 py-4 font-semibold text-gray-300 min-w-[7rem]"
                >
                  {{ t('landing.invoicing_section.compare.col_eu') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, index) in compareRows"
                :key="row.id"
                :class="index % 2 === 0 ? 'bg-gray-900/40' : 'bg-gray-900/20'"
                class="border-b border-gray-800/80 last:border-0"
              >
                <th
                  scope="row"
                  class="px-4 py-3.5 font-medium text-gray-200 align-top"
                >
                  <span>{{ t(row.labelKey) }}</span>
                  <p
                    v-if="row.noteKey"
                    class="mt-1 text-xs font-normal text-gray-500"
                  >
                    {{ t(row.noteKey) }}
                  </p>
                </th>
                <td class="px-4 py-3.5 align-top">
                  <InvoicingCompareCell :value="row.satflux" highlight />
                </td>
                <td class="px-4 py-3.5 align-top">
                  <InvoicingCompareCell :value="row.zaprite" />
                </td>
                <td class="px-4 py-3.5 align-top">
                  <InvoicingCompareCell :value="row.euAccounting" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="mt-4 text-xs text-gray-500 text-center max-w-3xl mx-auto">
          {{ t('landing.invoicing_section.compare.disclaimer') }}
        </p>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBusinessInvoicing } from '../../composables/useBusinessInvoicing';
import { useCurrentPlan } from '../../composables/useCurrentPlan';
import {
  INVOICING_COMPARE_ROWS,
  INVOICING_FEATURE_BULLETS,
} from '../../constants/invoicingComparison';
import InvoicingCompareCell from './InvoicingCompareCell.vue';
import { usePricing } from '../../composables/usePricing';

const { t } = useI18n();
const { canUse: canUseInvoicing } = useBusinessInvoicing();
const { canUpgradeToPro } = useCurrentPlan();
const { pricing, load: loadPricing } = usePricing();
// Fetch the real trial_days (module-level cache - a no-op when another
// landing section already loaded pricing).
void loadPricing();

const invoicingCtaTo = computed(() => {
  if (canUseInvoicing.value) return '/invoicing';
  if (canUpgradeToPro.value) return '/#pricing';
  return '/account';
});

const invoicingCtaLabel = computed(() => {
  if (canUseInvoicing.value) {
    return t('landing.invoicing_section.cta_active');
  }
  if (canUpgradeToPro.value) {
    return t('landing.invoicing_section.cta');
  }
  return t('landing.invoicing_section.cta_account');
});

const featureBullets = INVOICING_FEATURE_BULLETS;
const compareRows = INVOICING_COMPARE_ROWS;
</script>
