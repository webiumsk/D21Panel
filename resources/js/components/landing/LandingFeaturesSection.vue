<template>
  <section id="features" class="relative scroll-mt-20 py-24 bg-gray-900">
    <div
      class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-indigo-500/40 to-transparent"
      aria-hidden="true"
    ></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex flex-col items-center text-center mb-16 md:mb-20">
        <h2
          class="mb-4 max-w-4xl text-balance text-3xl font-bold md:text-5xl"
        >
          <span
            class="bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90"
          >
            {{ t("landing.everything_you_need") }}
          </span>
        </h2>
        <p class="text-xl text-gray-400 max-w-2xl mx-auto leading-relaxed">
          {{ t("landing.everything_you_need_description") }}
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Multi-Store (Large Card) -->
        <div
          class="md:col-span-2 bg-gradient-to-br from-gray-800 to-gray-900 rounded-3xl p-8 border border-gray-700 shadow-xl overflow-hidden relative group transition-all duration-300 hover:-translate-y-1 hover:border-indigo-500/35 hover:shadow-2xl hover:shadow-indigo-950/50"
        >
          <div
            class="absolute top-0 right-0 p-8 opacity-10 group-hover:opacity-20 transition-opacity"
          >
            <svg
              class="w-48 h-48 text-indigo-500"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
              />
            </svg>
          </div>
          <div class="relative z-10">
            <h3
              class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
            >
              {{ t("landing.multi_store_management") }}
            </h3>
            <p class="text-gray-400 text-lg mb-6 max-w-md">
              {{ t("landing.multi_store_description") }}
            </p>
            <a
              href="#how-it-works"
              class="inline-flex items-center text-indigo-400 font-semibold group-hover:translate-x-1 transition-transform"
            >
              {{ t("landing.learn_more") }}
              <svg
                class="w-4 h-4 ml-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </a>
          </div>
        </div>

        <!-- POS Apps -->
        <div
          class="bg-gray-800 rounded-3xl p-8 border border-gray-700 shadow-xl relative group transition-all duration-300 hover:-translate-y-1 hover:border-indigo-500/30 hover:shadow-2xl hover:shadow-black/40"
        >
          <div
            class="absolute inset-0 bg-indigo-600/5 rounded-3xl opacity-0 group-hover:opacity-100 transition-opacity"
          ></div>
          <div
            class="w-12 h-12 bg-indigo-500/20 rounded-xl flex items-center justify-center mb-6 text-indigo-400"
          >
            <svg
              class="w-6 h-6"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"
              />
            </svg>
          </div>
          <h3
            class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-xl font-bold"
          >
            {{ t("landing.point_of_sale") }}
          </h3>
          <p class="text-gray-400">
            {{ t("landing.point_of_sale_description") }}
          </p>
        </div>

        <!-- Invoice Management -->
        <div
          class="bg-gray-800 rounded-3xl p-8 border border-gray-700 shadow-xl relative group transition-all duration-300 hover:-translate-y-1 hover:border-indigo-500/30 hover:shadow-2xl hover:shadow-black/40"
        >
          <div
            class="absolute inset-0 bg-indigo-600/5 rounded-3xl opacity-0 group-hover:opacity-100 transition-opacity"
          ></div>
          <div
            class="w-12 h-12 bg-indigo-500/20 rounded-xl flex items-center justify-center mb-6 text-indigo-400"
          >
            <svg
              class="w-6 h-6"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
          </div>
          <h3
            class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-xl font-bold"
          >
            {{ t("landing.invoicing") }}
          </h3>
          <p class="text-gray-400">
            {{ t("landing.invoicing_description") }}
          </p>
        </div>

        <!-- Lightning & Cashu (Large Card) -->
        <div
          class="md:col-span-2 bg-gradient-to-br from-amber-950/40 via-gray-900 to-indigo-950/50 rounded-3xl p-8 border border-gray-700 shadow-xl relative overflow-hidden group transition-all duration-300 hover:-translate-y-1 hover:border-amber-500/25 hover:shadow-2xl hover:shadow-amber-950/20"
        >
          <div
            class="absolute z-0 right-0 bottom-0 w-72 h-72 bg-amber-500/10 rounded-full blur-3xl"
          ></div>
          <div
            class="absolute z-0 left-1/3 top-0 w-48 h-48 bg-indigo-500/10 rounded-full blur-3xl"
          ></div>
          <div
            class="relative z-10 flex flex-col md:flex-row items-start md:items-center gap-8"
          >
            <div class="flex-1">
              <h3
                class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
              >
                {{ t("landing.feature_lightning_ecash_title") }}
              </h3>
              <p class="text-gray-400 text-lg mb-6 leading-relaxed">
                {{ t("landing.feature_lightning_ecash_description") }}
              </p>
              <ul class="space-y-2 text-sm text-gray-300 mb-6">
                <li class="flex items-start gap-2">
                  <span
                    class="text-amber-400 mt-0.5 shrink-0"
                    aria-hidden="true"
                    >✓</span
                  >
                  <span>{{
                    t("landing.feature_lightning_ecash_bullet_ln")
                  }}</span>
                </li>
                <li class="flex items-start gap-2">
                  <span
                    class="text-amber-400 mt-0.5 shrink-0"
                    aria-hidden="true"
                    >✓</span
                  >
                  <span>{{
                    t("landing.feature_lightning_ecash_bullet_cashu")
                  }}</span>
                </li>
                <li class="flex items-start gap-2">
                  <span
                    class="text-amber-400 mt-0.5 shrink-0"
                    aria-hidden="true"
                    >✓</span
                  >
                  <span>{{
                    t("landing.feature_lightning_ecash_bullet_control")
                  }}</span>
                </li>
              </ul>
              <div class="flex flex-wrap gap-2">
                <span
                  class="px-3 py-1 rounded-md bg-gray-800/80 border border-amber-500/30 text-xs font-medium text-amber-200/90"
                  >Lightning</span
                >
                <span
                  class="px-3 py-1 rounded-md bg-gray-800/80 border border-indigo-500/30 text-xs font-medium text-indigo-200/90"
                  >Cashu</span
                >
                <span
                  class="px-3 py-1 rounded-md bg-gray-800/80 border border-gray-600 text-xs font-medium text-gray-300"
                  >SamRock</span
                >
              </div>
            </div>
            <div class="flex shrink-0 gap-3 self-center md:self-auto">
              <div
                class="w-14 h-14 bg-indigo-500/20 rounded-2xl flex items-center justify-center text-indigo-400 ring-1 ring-indigo-500/30"
              >
                <svg
                  class="w-7 h-7"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13 10V3L4 14h7v7l9-11h-7z"
                  />
                </svg>
              </div>
              <div
                class="w-14 h-14 bg-amber-500/15 rounded-2xl flex items-center justify-center text-amber-400 ring-1 ring-amber-500/25"
              >
                <svg
                  class="w-7 h-7"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <!-- Tickets, LN addresses, Pay Button -->
        <div
          class="md:col-span-2 bg-gray-800 rounded-3xl p-8 border border-gray-700 shadow-xl relative group transition-all duration-300 hover:-translate-y-1 hover:border-emerald-500/25 hover:shadow-2xl hover:shadow-emerald-950/20"
        >
          <div
            class="absolute inset-0 bg-emerald-600/[0.04] rounded-3xl opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"
          ></div>
          <div class="relative z-10">
            <div
              class="w-12 h-12 bg-emerald-500/15 rounded-xl flex items-center justify-center mb-6 text-emerald-400 ring-1 ring-emerald-500/25"
            >
              <svg
                class="w-6 h-6"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"
                />
              </svg>
            </div>
            <h3
              class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
            >
              {{ t("landing.feature_checkout_tools_title") }}
            </h3>
            <p class="text-gray-400 text-lg mb-6 leading-relaxed">
              {{ t("landing.feature_checkout_tools_intro") }}
            </p>
            <ul class="space-y-2 text-sm text-gray-300">
              <li class="flex items-start gap-2">
                <span
                  class="text-emerald-400 mt-0.5 shrink-0"
                  aria-hidden="true"
                  >✓</span
                >
                <span>{{
                  t("landing.feature_checkout_tools_bullet_tickets")
                }}</span>
              </li>
              <li class="flex items-start gap-2">
                <span
                  class="text-emerald-400 mt-0.5 shrink-0"
                  aria-hidden="true"
                  >✓</span
                >
                <span>{{
                  t("landing.feature_checkout_tools_bullet_ln")
                }}</span>
              </li>
              <li class="flex items-start gap-2">
                <span
                  class="text-emerald-400 mt-0.5 shrink-0"
                  aria-hidden="true"
                  >✓</span
                >
                <span>{{
                  t("landing.feature_checkout_tools_bullet_pay")
                }}</span>
              </li>
              <li class="flex items-start gap-2">
                <span
                  class="text-emerald-400 mt-0.5 shrink-0"
                  aria-hidden="true"
                  >✓</span
                >
                <span>{{
                  t("landing.feature_checkout_tools_bullet_raffle")
                }}</span>
              </li>
            </ul>
          </div>
        </div>

        <!-- Crowdfund (coming soon) -->
        <div
          class="relative bg-gradient-to-br from-gray-800/90 to-gray-900 rounded-3xl p-8 border border-dashed border-violet-500/35 shadow-xl overflow-hidden group transition-all duration-300 hover:-translate-y-1 hover:border-violet-400/45 hover:shadow-2xl hover:shadow-violet-950/25"
        >
          <div
            class="absolute inset-0 bg-violet-600/[0.04] pointer-events-none"
          ></div>
          <div class="relative z-10 pt-2">
            <div
              class="w-12 h-12 bg-violet-500/15 rounded-xl flex items-center justify-center mb-6 text-violet-300 ring-1 ring-violet-500/25"
            >
              <svg
                class="w-6 h-6"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"
                />
              </svg>
            </div>
            <h3
              class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-xl font-bold"
            >
              {{ t("landing.feature_crowdfund_title") }}
            </h3>
            <p class="text-gray-400 leading-relaxed">
              {{ t("landing.feature_crowdfund_description") }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from "vue-i18n";

const { t } = useI18n();
</script>
