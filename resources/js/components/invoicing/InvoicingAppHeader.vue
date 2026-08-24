<template>
  <header class="invoicing-app-header">
    <!-- Desktop main nav -->
    <div :class="INVOICING_CONTAINER_CLASS" class="hidden md:block">
      <nav class="invoicing-main-nav" aria-label="Invoicing main">
        <button
          v-for="item in visibleMainNav"
          :key="item.id"
          type="button"
          class="invoicing-main-nav__item"
          :class="{ 'invoicing-main-nav__item--active': activeMainSection === item.id }"
          @click="navigateMain(item.id)"
        >
          {{ t(item.labelKey) }}
        </button>
        <IntegrationInboxBadge :company-id="companyId" />
        <InvoicingCompanySwitcher
          v-if="companyId && displayCompanyLabel"
          :current-label="displayCompanyLabel"
        />
      </nav>
    </div>

    <!-- Mobile compact header -->
    <div class="invoicing-mobile-header md:hidden">
      <div :class="INVOICING_CONTAINER_CLASS" class="flex items-center gap-2 min-h-[48px] py-1">
        <button
          type="button"
          class="invoicing-mobile-icon-btn shrink-0"
          :title="t('invoicing.mobile_nav_open')"
          @click="navDrawerOpen = true"
        >
          <InvoicingIcons name="menu" />
        </button>

        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-gray-900 truncate leading-tight">
            {{ mobileTitle }}
          </p>
          <p v-if="mobileSubtitle" class="text-xs text-gray-500 truncate leading-tight">
            {{ mobileSubtitle }}
          </p>
        </div>

        <IntegrationInboxBadge :company-id="companyId" />
        <button
          v-if="isMobileFilterAvailable"
          type="button"
          class="invoicing-mobile-icon-btn shrink-0 relative"
          :title="t('invoicing.mobile_filters')"
          @click="filterDrawerOpen = true"
        >
          <InvoicingIcons name="filter" />
          <span
            v-if="mobileFilterActiveCount > 0"
            class="absolute -top-0.5 -right-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-indigo-600 px-1 text-[10px] font-bold text-white"
          >
            {{ mobileFilterActiveCount > 9 ? '9+' : mobileFilterActiveCount }}
          </span>
        </button>

        <div v-if="$slots['mobile-primary-action']" class="shrink-0">
          <slot name="mobile-primary-action" />
        </div>
      </div>
    </div>

    <!-- Desktop filter bar -->
    <div v-if="showFilterBar" class="invoicing-filter-bar hidden md:block">
      <div :class="[INVOICING_CONTAINER_CLASS, 'flex flex-wrap items-center gap-3 py-3']">
        <div class="flex flex-wrap items-center gap-2 flex-1 min-w-0">
          <slot name="filters" />
        </div>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
          <slot name="actions" />
        </div>
      </div>
    </div>

    <InvoicingMobileNavDrawer
      :open="navDrawerOpen"
      :company-label="displayCompanyLabel"
      @close="navDrawerOpen = false"
    />

    <InvoicingMobileFilterDrawer
      v-if="isMobileFilterAvailable"
      :open="filterDrawerOpen"
      :active-count="mobileFilterActiveCount"
      @close="filterDrawerOpen = false"
      @apply="onFilterApply"
      @clear="emit('mobile-filter-clear')"
    >
      <slot name="mobile-filters" />
      <template v-if="$slots['mobile-actions']" #actions>
        <slot name="mobile-actions" />
      </template>
    </InvoicingMobileFilterDrawer>
  </header>
</template>

<script setup lang="ts">
import { computed, ref, useSlots } from 'vue';
import { useI18n } from 'vue-i18n';
import '../../styles/invoicing-theme.css';
import { useInvoicingCompanySummary } from '../../composables/useInvoicingCompanySummary';
import {
  INVOICING_CONTAINER_CLASS,
  useInvoicingLayout,
  type InvoicingMainSection,
} from '../../composables/useInvoicingLayout';
import { initEfakturaInboxLive } from '../../evolu/efakturaInboxLive';
import { initIntegrationInboxLive } from '../../evolu/integrationInboxLive';
import IntegrationInboxBadge from './IntegrationInboxBadge.vue';
import InvoicingIcons from './icons/InvoicingIcons.vue';
import InvoicingCompanySwitcher from './InvoicingCompanySwitcher.vue';
import InvoicingMobileNavDrawer from './InvoicingMobileNavDrawer.vue';
import InvoicingMobileFilterDrawer from './InvoicingMobileFilterDrawer.vue';

const props = withDefaults(
  defineProps<{
    companyLabel?: string;
    showFilterBar?: boolean;
    showMobileFilters?: boolean;
    mobileFilterActiveCount?: number;
  }>(),
  {
    showFilterBar: true,
    showMobileFilters: false,
    mobileFilterActiveCount: 0,
  }
);

const emit = defineEmits<{
  'mobile-filter-apply': [];
  'mobile-filter-clear': [];
}>();

const { t } = useI18n();
const {
  companyId,
  toolsNavItems,
  activeMainSection,
  activeToolsSection,
  activeDocumentKind,
  documentNavItems,
  navigateMain,
} = useInvoicingLayout();
const { hasBankAccount, companyName: summaryCompanyName } = useInvoicingCompanySummary();

// One shared polling loop feeds the badge (and the inbox panel) - the
// header mounts on every invoicing page, so this binds the active company.
initIntegrationInboxLive(companyId);
// Same idea for received e-invoices parked on the bridge company (local-first).
initEfakturaInboxLive(companyId);

const navDrawerOpen = ref(false);
const filterDrawerOpen = ref(false);
const slots = useSlots();

const isMobileFilterAvailable = computed(
  () => props.showMobileFilters && Boolean(slots['mobile-filters'])
);

const displayCompanyLabel = computed(() => props.companyLabel || summaryCompanyName.value);

const visibleMainNav = computed(() => {
  const items: { id: InvoicingMainSection; labelKey: string }[] = [
    { id: 'documents', labelKey: 'invoicing.main_nav_documents' },
    { id: 'expenses', labelKey: 'invoicing.main_nav_expenses' },
  ];

  if (hasBankAccount.value) {
    items.push({ id: 'payments', labelKey: 'invoicing.main_nav_payments' });
  }

  items.push(
    { id: 'contacts', labelKey: 'invoicing.main_nav_contacts' },
    { id: 'stock', labelKey: 'invoicing.main_nav_stock' },
    { id: 'tools', labelKey: 'invoicing.main_nav_tools' },
  );

  return items;
});

const mainSectionLabelKey = computed(() => {
  const item = visibleMainNav.value.find((i) => i.id === activeMainSection.value);
  return item?.labelKey ?? 'invoicing.main_nav_documents';
});

const mobileTitle = computed(() => t(mainSectionLabelKey.value));

const mobileSubtitle = computed(() => {
  if (activeMainSection.value === 'documents') {
    const doc = documentNavItems.value.find((d) => d.kind === activeDocumentKind.value);
    return doc ? t(doc.labelKey) : '';
  }
  if (activeMainSection.value === 'tools') {
    const tool = toolsNavItems.value.find((tItem) => tItem.section === activeToolsSection.value);
    return tool ? t(tool.labelKey) : '';
  }
  if (displayCompanyLabel.value) {
    return displayCompanyLabel.value;
  }
  return '';
});

function onFilterApply() {
  filterDrawerOpen.value = false;
  emit('mobile-filter-apply');
}

defineExpose({
  openFilterDrawer: () => {
    if (isMobileFilterAvailable.value) {
      filterDrawerOpen.value = true;
    }
  },
});
</script>
