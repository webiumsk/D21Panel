<template>
  <div
    v-if="visible"
    class="rounded-lg border border-emerald-200 bg-emerald-50/60 px-4 py-3"
    role="status"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="text-sm text-emerald-900">
        <p class="font-medium">{{ t('invoicingOnboarding.checklist_title') }}</p>
        <p class="text-emerald-800 text-xs mt-0.5">
          {{ t('invoicingOnboarding.checklist_progress', { done: doneCount, total: setupSteps.length }) }}
        </p>
      </div>
      <button
        type="button"
        class="text-xs font-medium rounded-md px-3 py-1.5 border border-emerald-300 text-emerald-800 hover:bg-emerald-100"
        @click="dismiss"
      >
        {{ t('invoicingOnboarding.checklist_dismiss') }}
      </button>
    </div>

    <ul class="mt-2 space-y-1 text-xs text-emerald-900">
      <li v-for="step in setupSteps" :key="step.key" class="flex items-center gap-2">
        <span :class="stepIconClass(step.done)">{{ step.done ? '✓' : '○' }}</span>
        <RouterLink
          v-if="!step.done"
          :to="step.to"
          class="underline hover:text-emerald-700"
        >
          {{ t(`invoicingOnboarding.checklist_${step.key}`) }}
        </RouterLink>
        <span v-else class="text-emerald-700">{{ t(`invoicingOnboarding.checklist_${step.key}`) }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isInvoicingLocalFirst } from '../../evolu/flags';
import { invoicingApi } from '../../services/api';

/**
 * "Getting started" checklist for a new invoicing company - guides a fresh
 * user from an empty company to the first issued document. Shown on the
 * invoices list (and company profile) until every core step is done, then it
 * auto-hides. Permanently dismissible per company. Mirrors the structure of
 * EfakturaReadinessCard (per-company localStorage, generation-guarded async
 * loads, self-fetch of the company record in server mode).
 */
const props = defineProps<{
  companyId: string;
  company: Record<string, unknown> | null;
}>();

const { t } = useI18n();
const localFirst = isInvoicingLocalFirst();

const dismissed = ref(true);
const countsLoaded = ref(false);
const hasContact = ref(false);
const hasDocument = ref(false);

// Server-mode pages without the full company record (e.g. the invoices list)
// let the card fetch it itself; local-first pages pass it in.
const fetchedCompany = ref<Record<string, unknown> | null>(null);
const effectiveCompany = computed(() => props.company ?? fetchedCompany.value);

function str(value: unknown): string {
  return String(value ?? '').trim();
}

const profileComplete = computed(() => {
  const c = effectiveCompany.value;
  if (!c) return false;
  const hasAddress = str(c.street) !== '' && str(c.city) !== '' && str(c.postal_code) !== '';
  const hasTaxId = str(c.tax_id) !== '' || str(c.registration_number) !== '';
  return str(c.legal_name) !== '' && hasAddress && hasTaxId;
});

const brandingComplete = computed(() => str(effectiveCompany.value?.logo_url) !== '');

const profileTo = computed(() => ({ name: 'invoicing-company', params: { companyId: props.companyId } }));
// The logo lives on the company profile form (CompanySettingsForm, "branding"
// tab), which is the profile route - not the app-settings route.
const brandingTo = computed(() => ({ name: 'invoicing-company', params: { companyId: props.companyId } }));
const contactTo = computed(() => ({ name: 'invoicing-contact-new', params: { companyId: props.companyId } }));
const documentTo = computed(() => ({ name: 'invoicing-invoice-new', params: { companyId: props.companyId } }));

const setupSteps = computed(() => [
  { key: 'profile', done: profileComplete.value, to: profileTo.value },
  { key: 'branding', done: brandingComplete.value, to: brandingTo.value },
  { key: 'contact', done: hasContact.value, to: contactTo.value },
  { key: 'document', done: hasDocument.value, to: documentTo.value },
]);

const doneCount = computed(() => setupSteps.value.filter((step) => step.done).length);
const complete = computed(() => setupSteps.value.every((step) => step.done));

const eligible = computed(() => effectiveCompany.value !== null);

const visible = computed(() => {
  if (dismissed.value || !eligible.value || !countsLoaded.value) return false;
  return !complete.value;
});

function dismissKey(): string {
  return `satflux.invoicing_getting_started.${props.companyId}`;
}

function isDismissed(): boolean {
  try {
    return window.localStorage.getItem(dismissKey()) === '1';
  } catch {
    return false;
  }
}

function dismiss(): void {
  try {
    window.localStorage.setItem(dismissKey(), '1');
  } catch {
    // Storage unavailable - hide for this session only.
  }
  dismissed.value = true;
}

function stepIconClass(done: boolean): string {
  return done ? 'text-emerald-600 font-bold' : 'text-emerald-400';
}

// Guards all async work: a company switch bumps the generation, so late
// responses for the previous company can never touch the current state.
let loadGeneration = 0;

async function loadCounts(): Promise<void> {
  const generation = loadGeneration;
  try {
    let contactExists = false;
    let documentExists = false;
    if (localFirst) {
      const { evolu, allContactsQuery, allDocumentsQuery } = await import('../../evolu/client');
      const [contacts, documents] = await Promise.all([
        evolu.loadQuery(allContactsQuery),
        evolu.loadQuery(allDocumentsQuery),
      ]);
      contactExists = contacts.some((row) => String(row.companyId ?? '') === props.companyId);
      documentExists = documents.some((row) => String(row.companyId ?? '') === props.companyId);
    } else {
      const [contactsRes, documents] = await Promise.all([
        invoicingApi.contacts.list(props.companyId, { per_page: 1 }),
        invoicingApi.documents.list(props.companyId, { per_page: 1 }),
      ]);
      contactExists = contactsRes.data.length > 0;
      documentExists = documents.length > 0;
    }
    if (generation !== loadGeneration) return;
    hasContact.value = contactExists;
    hasDocument.value = documentExists;
    countsLoaded.value = true;
  } catch {
    // Coverage unknown - keep the card hidden rather than guessing.
  }
}

/** (Re)initialize for the current companyId - also on prop switches. */
function initForCompany(): void {
  loadGeneration += 1;
  fetchedCompany.value = null;
  countsLoaded.value = false;
  hasContact.value = false;
  hasDocument.value = false;
  dismissed.value = isDismissed();
  if (dismissed.value) return;

  if (!localFirst && !props.company) {
    const generation = loadGeneration;
    void invoicingApi.companies
      .get<Record<string, unknown>>(props.companyId)
      .then((company) => {
        if (generation === loadGeneration) {
          fetchedCompany.value = company;
        }
      })
      .catch(() => {
        // Without the record the card simply stays hidden.
      });
  }
  void loadCounts();
}

onMounted(initForCompany);
watch(() => props.companyId, initForCompany);
</script>
