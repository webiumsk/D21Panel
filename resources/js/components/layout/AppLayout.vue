<template>
  <!-- Quasar QLayout-style: fixed viewport shell; scroll only inside routed pages (not main), so store toolbars stay visible on all breakpoints. -->
  <div
    class="flex min-h-dvh max-h-dvh flex-col overflow-hidden bg-gray-900 md:h-dvh md:max-h-dvh md:min-h-0"
  >
    <AppHeader class="shrink-0" />
    <SecurityAlertBanner />
    <main class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        <div
          class="flex min-h-0 min-w-0 flex-1 flex-col"
          :class="[
            layoutScrollMobile
              ? 'overflow-hidden max-md:overflow-y-auto max-md:overscroll-y-contain'
              : 'overflow-hidden',
            isInvoicingRoute ? 'max-md:bg-gray-100' : '',
          ]"
        >
          <slot />
        </div>
        <AppMobileBottomNav />
        <AppFooter class="hidden md:block shrink-0" />
      </div>
    </main>
    <OnboardingTour />
  </div>
</template>

<script setup lang="ts">
import AppHeader from './AppHeader.vue';
import SecurityAlertBanner from './SecurityAlertBanner.vue';
import AppFooter from './AppFooter.vue';
import AppMobileBottomNav from './AppMobileBottomNav.vue';
import OnboardingTour from '../OnboardingTour.vue';
import { useAppLayoutScroll } from '../../composables/useAppLayoutScroll';

const { layoutScrollMobile, isInvoicingRoute } = useAppLayoutScroll();
</script>
