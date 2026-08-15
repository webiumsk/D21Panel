<template>
  <div
    ref="rootRef"
    class="invoicing-company-switcher"
    :class="{ 'ml-auto': variant === 'dropdown', 'invoicing-company-switcher--inline': variant === 'inline' }"
  >
    <button
      v-if="variant === 'dropdown'"
      type="button"
      class="invoicing-company-switcher__trigger"
      :class="{ 'invoicing-company-switcher__trigger--open': open }"
      :aria-expanded="open"
      aria-haspopup="listbox"
      @click="toggleOpen"
    >
      <span class="sr-only">{{ t('invoicing.company_switcher_title') }}</span>
      <img
        v-if="currentLogoUrl"
        :src="currentLogoUrl"
        alt=""
        class="invoicing-company-switcher__logo"
      />
      <InvoicingIcons v-else name="building" class="h-4 w-4 shrink-0 text-gray-500" />
      <span class="invoicing-company-switcher__label">{{ currentLabel }}</span>
      <span
        v-if="vatLimitStatus"
        class="h-2 w-2 shrink-0 rounded-full"
        :class="vatLimitStatus.level === 'approaching' ? 'bg-amber-500' : 'bg-red-500'"
        :title="t('invoicing.vat_limit_indicator_tooltip', { percent: vatLimitStatus.percent })"
      >
        <span class="sr-only">{{
          t('invoicing.vat_limit_indicator_tooltip', { percent: vatLimitStatus.percent })
        }}</span>
      </span>
      <InvoicingIcons name="chevrons-up-down" class="h-3.5 w-3.5 shrink-0 text-gray-400" />
    </button>

    <div
      v-if="variant === 'inline' || open"
      class="invoicing-company-switcher__panel"
      :class="{ 'invoicing-company-switcher__panel--inline': variant === 'inline' }"
      role="listbox"
      :aria-label="t('invoicing.company_switcher_title')"
    >
      <div class="invoicing-company-switcher__search-wrap">
        <InvoicingIcons name="search" class="h-4 w-4 shrink-0 text-gray-400" />
        <input
          ref="searchRef"
          v-model="searchQuery"
          type="search"
          class="invoicing-company-switcher__search"
          :placeholder="t('invoicing.company_switcher_search')"
          autocomplete="off"
          @keydown.esc.prevent="close"
        />
      </div>

      <div class="invoicing-company-switcher__list">
        <p v-if="loading" class="invoicing-company-switcher__empty">
          {{ t('common.loading') }}
        </p>
        <p v-else-if="filteredCompanies.length === 0" class="invoicing-company-switcher__empty">
          {{ t('invoicing.company_switcher_none') }}
        </p>
        <button
          v-for="company in filteredCompanies"
          :key="company.id"
          type="button"
          role="option"
          class="invoicing-company-switcher__item"
          :class="{ 'invoicing-company-switcher__item--active': company.id === companyId }"
          :aria-selected="company.id === companyId"
          @click="selectCompany(company.id)"
        >
          <img
            v-if="company.logo_url"
            :src="company.logo_url"
            alt=""
            class="invoicing-company-switcher__logo"
          />
          <InvoicingIcons v-else name="building" class="h-4 w-4 shrink-0 text-gray-400" />
          <span class="min-w-0 flex-1 truncate text-left">{{ invoicingCompanyLabel(company) }}</span>
          <InvoicingIcons
            v-if="company.id === companyId"
            name="check"
            class="h-4 w-4 shrink-0 text-indigo-600"
          />
        </button>
      </div>

      <div class="invoicing-company-switcher__footer">
        <button type="button" class="invoicing-company-switcher__footer-link" @click="goToAll">
          {{ t('invoicing.company_switcher_all') }}
        </button>
        <button type="button" class="invoicing-company-switcher__footer-link" @click="goToNew">
          {{ t('invoicing.add_company') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import InvoicingIcons from './icons/InvoicingIcons.vue';
import { invoicingCompanyLabel } from '../../composables/invoicingCompanyLabel';
import { dedupeInvoicingCompanyList } from '../../composables/dedupeInvoicingCompanyList';
import { useInvoicingCompanies } from '../../composables/useInvoicingCompanies';
import { useInvoicingLayout } from '../../composables/useInvoicingLayout';
import { useVatLimitStatus } from '../../composables/useVatLimitStatus';

const props = withDefaults(
  defineProps<{
    currentLabel: string;
    variant?: 'dropdown' | 'inline';
  }>(),
  {
    variant: 'dropdown',
  },
);

const emit = defineEmits<{
  switched: [];
}>();

const { t } = useI18n();
const router = useRouter();
const { companyId, switchCompany } = useInvoicingLayout();
const { companies, loading, refresh } = useInvoicingCompanies();
const { status: vatLimitStatus } = useVatLimitStatus(companyId);

const rootRef = ref<HTMLElement | null>(null);
const searchRef = ref<HTMLInputElement | null>(null);
const open = ref(false);
const searchQuery = ref('');

const uniqueCompanies = computed(() => dedupeInvoicingCompanyList(companies.value, companyId.value));

const currentLogoUrl = computed(() => {
  const match = uniqueCompanies.value.find((company) => company.id === companyId.value);
  return match?.logo_url ?? null;
});

const filteredCompanies = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  const list = uniqueCompanies.value;
  if (!q) {
    return list;
  }
  return list.filter((company) => {
    const label = invoicingCompanyLabel(company).toLowerCase();
    const legal = company.legal_name.toLowerCase();
    return label.includes(q) || legal.includes(q);
  });
});

function toggleOpen() {
  open.value = !open.value;
}

function close() {
  open.value = false;
}

function selectCompany(id: string) {
  switchCompany(id);
  close();
  emit('switched');
}

function goToAll() {
  close();
  router.push({ name: 'invoicing' });
}

function goToNew() {
  close();
  router.push({ name: 'invoicing-company-new' });
}

function onDocumentClick(event: MouseEvent) {
  if (props.variant !== 'dropdown' || !open.value || !rootRef.value) {
    return;
  }
  if (!rootRef.value.contains(event.target as Node)) {
    close();
  }
}

watch(
  () => (props.variant === 'inline' ? true : open.value),
  async (visible) => {
    if (!visible) {
      return;
    }
    searchQuery.value = '';
    await refresh();
    if (props.variant === 'dropdown') {
      await nextTick();
      searchRef.value?.focus();
    }
  },
  { immediate: props.variant === 'inline' },
);

onMounted(() => {
  document.addEventListener('click', onDocumentClick);
  if (props.variant === 'dropdown') {
    void refresh();
  }
});

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick);
});
</script>
