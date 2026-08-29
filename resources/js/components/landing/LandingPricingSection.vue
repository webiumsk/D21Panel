<template>
  <section
    id="pricing"
    class="relative scroll-mt-20 py-24 bg-gray-900 overflow-hidden"
  >
    <div
      class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-indigo-500/40 to-transparent z-10"
      aria-hidden="true"
    ></div>
    <div
      class="absolute inset-0 bg-indigo-900/10 skew-y-3 transform origin-bottom-right pointer-events-none z-0"
      aria-hidden="true"
    ></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
      <!-- Pricing Hero -->
      <div class="text-center mb-16">
        <h2
          class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
        >
          {{ t("landing.pricing_hero_headline") }}
        </h2>
        <p class="text-xl text-gray-400 max-w-2xl mx-auto">
          {{
            t("landing.pricing_hero_subheadline", {
              days: pricing.trial_days,
            })
          }}
        </p>
      </div>

      <div
        class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-5xl mx-auto items-start"
      >
        <!-- Free Plan -->
        <div
          class="bg-gray-800/80 backdrop-blur rounded-2xl p-8 border border-gray-700 hover:border-gray-500 transition-colors"
        >
          <h3
            class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-xl font-bold"
          >
            {{ t("landing.pricing_free_name") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("landing.pricing_free_tagline") }}
          </p>
          <div class="flex items-baseline mb-6">
            <span class="text-4xl font-extrabold text-white">{{
              formatSats(pricing.free.sats_per_year)
            }}</span>
            <span class="text-gray-400 ml-2">{{
              t("landing.pricing_free_price_period")
            }}</span>
          </div>
          <p class="text-gray-400 text-sm mb-6">
            {{ t("landing.pricing_free_description") }}
          </p>
          <ul class="space-y-3 mb-8">
            <li
              v-for="key in planFeatures.free.feature_keys"
              :key="key"
              class="flex items-start text-gray-300 text-sm"
            >
              <svg
                class="w-4 h-4 text-green-400 mr-3 mt-0.5 flex-shrink-0"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                ></path>
              </svg>
              <span>{{ t("plans.features." + key) }}</span>
            </li>
          </ul>
          <router-link
            v-if="!authStore.isAuthenticated"
            to="/register"
            class="block w-full text-center px-6 py-3 rounded-lg border border-gray-600 text-white hover:bg-gray-700 font-bold transition-colors"
          >
            {{ t("landing.pricing_free_cta") }}
          </router-link>
          <router-link
            v-else
            to="/stores"
            class="block w-full text-center px-6 py-3 rounded-lg border border-gray-600 text-white hover:bg-gray-700 font-bold transition-colors"
          >
            {{ t("landing.pricing_free_cta_logged_in") }}
          </router-link>
        </div>

        <!-- PRO Plan (Highlighted) -->
        <div
          class="bg-gray-800 rounded-2xl p-8 border-2 border-indigo-500 shadow-2xl relative transform md:scale-[1.02] z-10"
        >
          <div
            v-if="canUpgradeToPro"
            class="absolute top-0 right-0 -mt-4 mr-4 bg-emerald-500 text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wide"
          >
            {{
              t("landing.pricing_pro_trial_badge", {
                days: pricing.trial_days,
              })
            }}
          </div>
          <h3
            class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
          >
            {{ t("landing.pricing_pro_name") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("landing.pricing_pro_tagline") }}
          </p>
          <div class="flex items-baseline flex-wrap gap-x-1 mb-2">
            <span
              v-if="proHasMonthlyDiscount(pricing.pro)"
              class="text-xl font-medium text-gray-500 line-through mr-2"
              >{{ formatSats(pricing.pro.sats_per_month_display) }}</span
            >
            <span class="text-5xl font-extrabold text-white">{{
              formatSats(proEffectiveMonthlySats(pricing.pro))
            }}</span>
            <span class="text-indigo-300">{{
              t("landing.pricing_pro_price_period")
            }}</span>
            <span class="text-indigo-300/80 text-sm">{{
              t("landing.pricing_pro_price_note")
            }}</span>
          </div>
          <p class="text-indigo-200/90 text-sm mb-6">
            {{
              t("landing.pricing_pro_yearly_price", {
                amount: formatSats(pricing.pro.sats_per_year),
              })
            }}
          </p>
          <p class="text-gray-400 text-sm mb-6">
            {{ t("landing.pricing_pro_description") }}
          </p>
          <div
            class="rounded-xl border border-indigo-500/40 bg-indigo-950/40 p-4 mb-6"
          >
            <p
              class="text-xs font-semibold uppercase tracking-wide text-indigo-300 mb-3"
            >
              {{ t("landing.pricing_invoicing_highlight_title") }}
            </p>
            <ul class="space-y-2.5">
              <li
                v-for="key in proInvoicingFeatures"
                :key="'inv-' + key"
                class="flex items-start text-indigo-100 text-sm font-medium"
              >
                <svg
                  class="w-5 h-5 text-indigo-400 mr-3 mt-0.5 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M5 13l4 4L19 7"
                  ></path>
                </svg>
                <span>{{ t("plans.features." + key) }}</span>
              </li>
            </ul>
            <p
              v-if="pricing.company_slot_packs.length"
              class="text-indigo-300/80 text-xs mt-3"
            >
              {{
                t("landing.pricing_company_slots_note", {
                  price: formatSats(pricing.company_slot_packs[0].sats),
                })
              }}
            </p>
          </div>
          <ul class="space-y-3 mb-8">
            <li
              v-for="key in proOtherFeatures"
              :key="key"
              class="flex items-start text-white font-medium text-sm"
            >
              <svg
                class="w-5 h-5 text-indigo-400 mr-3 mt-0.5 flex-shrink-0"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                ></path>
              </svg>
              <span>{{ t("plans.features." + key) }}</span>
            </li>
          </ul>
          <button
            v-if="canUpgradeToPro"
            @click="handleUpgrade('pro')"
            :disabled="subscribing"
            class="block w-full text-center px-6 py-4 rounded-xl bg-indigo-600 text-white font-bold hover:bg-indigo-500 shadow-lg shadow-indigo-600/30 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{
              subscribing
                ? t("stores.processing")
                : t("landing.pricing_pro_cta")
            }}
          </button>
          <router-link
            v-else-if="!authStore.isAuthenticated"
            to="/register"
            class="block w-full text-center px-6 py-4 rounded-xl bg-indigo-600 text-white font-bold hover:bg-indigo-500 shadow-lg shadow-indigo-600/30 transition-all"
          >
            {{ t("landing.pricing_pro_cta_guest") }}
          </router-link>
          <router-link
            v-else
            to="/account"
            class="block w-full text-center px-6 py-4 rounded-xl border border-indigo-500/50 text-indigo-200 font-bold hover:bg-indigo-950/40 transition-all"
          >
            {{ t("landing.pricing_pro_current_plan") }}
          </router-link>
        </div>
      </div>

      <!-- Need more? Enterprise teaser -->
      <div class="mt-12 max-w-2xl mx-auto">
        <div class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700">
          <h3
            class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-lg font-bold"
          >
            {{ t("landing.pricing_need_more_headline") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("landing.pricing_need_more_text") }}
          </p>
          <ul class="space-y-2 mb-6 text-gray-300 text-sm">
            <li
              v-for="key in planFeatures.enterprise.feature_keys"
              :key="key"
              class="flex items-center gap-2"
            >
              <svg
                class="w-4 h-4 text-indigo-400 flex-shrink-0"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                ></path>
              </svg>
              <span>{{ t("plans.features." + key) }}</span>
            </li>
          </ul>
          <button
            type="button"
            class="inline-flex items-center justify-center px-6 py-3 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 font-medium text-sm transition-colors"
            :aria-expanded="showEnterpriseContact"
            aria-controls="landing-enterprise-contact"
            @click="showEnterpriseContact = true"
          >
            {{ t("landing.pricing_need_more_cta") }}
          </button>
          <div
            v-if="showEnterpriseContact"
            id="landing-enterprise-contact"
            class="mt-6 rounded-xl border border-gray-600 bg-gray-900/80 p-5 text-left"
          >
            <h4 class="text-base font-semibold text-white mb-3">
              {{ t("legal.contact.enterprise_title") }}
            </h4>
            <ContactForm
              type="enterprise"
              id-prefix="landing-enterprise"
              :default-subject="t('legal.contact.enterprise_subject')"
            />
          </div>
        </div>
      </div>

      <!-- Error message -->
      <div v-if="subscribeError" class="mt-8 max-w-md mx-auto">
        <div
          role="alert"
          class="bg-red-900/50 border border-red-500/50 rounded-lg p-4 text-center"
        >
          <p class="text-sm text-red-200">{{ subscribeError }}</p>
        </div>
      </div>

      <!-- Your payments never stop -->
      <div class="mt-16 max-w-3xl mx-auto">
        <div
          class="bg-indigo-900/20 border border-indigo-500/30 rounded-xl p-6 text-center"
        >
          <div class="flex items-center justify-center mb-3">
            <svg
              class="w-6 h-6 text-indigo-400 mr-2"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
              />
            </svg>
            <h3
              class="bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-lg font-bold"
            >
              {{ t("landing.pricing_trust_headline") }}
            </h3>
          </div>
          <p class="text-gray-300 text-sm leading-relaxed">
            {{ t("landing.pricing_trust_text") }}
          </p>
        </div>
      </div>

      <!-- FAQ -->
      <div class="mt-16 max-w-2xl mx-auto">
        <h3
          class="mb-6 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-center text-xl font-bold"
        >
          {{ t("landing.pricing_faq_title") }}
        </h3>
        <div class="space-y-4">
          <div class="bg-gray-800/50 rounded-xl p-4 border border-gray-700">
            <h4
              class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 font-semibold"
            >
              {{ t("landing.pricing_faq_custody_q") }}
            </h4>
            <p class="text-gray-400 text-sm">
              {{ t("landing.pricing_faq_custody_a") }}
            </p>
          </div>
          <div class="bg-gray-800/50 rounded-xl p-4 border border-gray-700">
            <h4
              class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 font-semibold"
            >
              {{ t("landing.pricing_faq_switch_q") }}
            </h4>
            <p class="text-gray-400 text-sm">
              {{ t("landing.pricing_faq_switch_a") }}
            </p>
          </div>
          <div class="bg-gray-800/50 rounded-xl p-4 border border-gray-700">
            <h4
              class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 font-semibold"
            >
              {{ t("landing.pricing_faq_trial_q") }}
            </h4>
            <p class="text-gray-400 text-sm">
              {{
                t("landing.pricing_faq_trial_a", {
                  days: pricing.trial_days,
                  grace_days: pricing.grace_days,
                })
              }}
            </p>
          </div>
          <div class="bg-gray-800/50 rounded-xl p-4 border border-gray-700">
            <h4
              class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 font-semibold"
            >
              {{ t("landing.pricing_faq_expire_q") }}
            </h4>
            <p class="text-gray-400 text-sm">
              {{
                t("landing.pricing_faq_expire_a", {
                  grace_days: pricing.grace_days,
                })
              }}
            </p>
          </div>
          <div class="bg-gray-800/50 rounded-xl p-4 border border-gray-700">
            <h4
              class="mb-2 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 font-semibold"
            >
              {{ t("landing.pricing_faq_shutdown_q") }}
            </h4>
            <p class="text-gray-400 text-sm">
              {{ t("landing.pricing_faq_shutdown_a") }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "../../store/auth";
import {
  usePricing,
  proEffectiveMonthlySats,
  proHasMonthlyDiscount,
} from "../../composables/usePricing";
import { usePlanFeatures } from "../../composables/usePlanFeatures";
import { useCurrentPlan } from "../../composables/useCurrentPlan";
import ContactForm from "../legal/ContactForm.vue";
import api from "../../services/api";

const { t } = useI18n();
const authStore = useAuthStore();
const { pricing, formatSats } = usePricing();
const { planFeatures, invoicingHighlightKeys } = usePlanFeatures();
const { canUpgradeToPro } = useCurrentPlan();

const subscribing = ref(false);
const subscribeError = ref("");
const showEnterpriseContact = ref(false);

const proInvoicingFeatures = computed(() =>
  planFeatures.value.pro.feature_keys.filter((key: string) =>
    invoicingHighlightKeys.value.has(key),
  ),
);

const proOtherFeatures = computed(() =>
  planFeatures.value.pro.feature_keys.filter(
    (key: string) => !invoicingHighlightKeys.value.has(key),
  ),
);

async function handleUpgrade(plan: string) {
  // Both PRO and Enterprise can use the same checkout flow now
  subscribing.value = true;
  subscribeError.value = "";

  try {
    // Call checkout endpoint with plan name
    // Backend will look up storeUuid, offeringId, and planId from config
    const response = await api.post("/subscriptions/checkout", {
      plan: plan,
    });

    if (response.data.checkoutUrl) {
      window.location.href = response.data.checkoutUrl;
    } else {
      subscribeError.value = t("landing.failed_to_create_checkout");
      subscribing.value = false;
    }
  } catch (rawError) {
    const error = asApiError(rawError);
    console.error("Failed to create checkout:", error);
    subscribeError.value =
      error.response?.data?.message || t("landing.failed_to_create_checkout");
    subscribing.value = false;
  }
}
</script>
