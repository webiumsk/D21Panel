<template>
  <div
    v-if="visible"
    class="rounded-lg border border-indigo-200 bg-indigo-50/60 px-4 py-3"
    role="status"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="text-sm text-indigo-900">
        <p class="font-medium">
          {{ t('invoicing.efaktura_readiness_title', { date: mandatoryFromDisplay }) }}
        </p>
        <p class="text-indigo-800 text-xs mt-0.5">
          {{
            featureEnabled
              ? t('invoicing.efaktura_readiness_intro')
              : t('invoicing.efaktura_readiness_teaser')
          }}
        </p>
      </div>
      <button
        type="button"
        class="text-xs font-medium rounded-md px-3 py-1.5 border border-indigo-300 text-indigo-800 hover:bg-indigo-100"
        @click="snooze"
      >
        {{ t('invoicing.efaktura_readiness_snooze') }}
      </button>
    </div>

    <ul class="mt-2 space-y-1 text-xs text-indigo-900">
      <template v-if="featureEnabled">
        <li v-for="step in setupSteps" :key="step.key" class="flex items-center gap-2">
          <span :class="stepIconClass(step.done)">{{ step.done ? '✓' : '○' }}</span>
          <RouterLink
            v-if="!step.done"
            :to="settingsTo"
            class="underline hover:text-indigo-700"
          >
            {{ t(`invoicing.efaktura_readiness_${step.key}`) }}
          </RouterLink>
          <span v-else class="text-indigo-700">{{ t(`invoicing.efaktura_readiness_${step.key}`) }}</span>
        </li>
      </template>

      <li class="flex items-center gap-2">
        <span :class="stepIconClass(contactsReady)">{{ contactsReady ? '✓' : '○' }}</span>
        <RouterLink
          v-if="!contactsReady"
          :to="contactsTo"
          class="underline hover:text-indigo-700"
        >
          {{ t('invoicing.efaktura_readiness_contacts', { count: contactsMissingIds }) }}
        </RouterLink>
        <span v-else class="text-indigo-700">{{ t('invoicing.efaktura_readiness_contacts_ok') }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  efakturaSecretIsSet,
  efakturaSettingsFromCompany,
  isCompanyEfakturaEligible,
} from '../../composables/useCompanyEfakturaSettings';
import { useEfakturaFeature } from '../../composables/useEfakturaFeature';
import { isInvoicingLocalFirst } from '../../evolu/flags';
import { invoicingApi } from '../../services/api';
import { resolvePeppolEndpoint } from '../../utils/peppolEndpoint';

/**
 * "Get ready for 2027" checklist for SK full VAT payers - shown on the
 * invoices list and company settings until every step is done. Renders in a
 * reduced "prepare your contacts" state while the module is globally off
 * (statutory deadline is public via /api/config). Snoozable for 14 days.
 */
const props = defineProps<{
  companyId: string;
  company: Record<string, unknown> | null;
}>();

const SNOOZE_DAYS = 14;

const { t, locale } = useI18n();
const { enabled: featureEnabled, mandatoryFrom, load: loadFeature } = useEfakturaFeature();
const localFirst = isInvoicingLocalFirst();

const snoozed = ref(true);
const contactsMissingIds = ref(0);
const contactsLoaded = ref(false);

// Server-mode pages without the full company record (e.g. the invoices
// list) let the card fetch it itself; local-first pages pass it in.
const fetchedCompany = ref<Record<string, unknown> | null>(null);
const effectiveCompany = computed(() => props.company ?? fetchedCompany.value);

const eligible = computed(() => isCompanyEfakturaEligible(effectiveCompany.value, true));

const settings = computed(() => efakturaSettingsFromCompany(effectiveCompany.value));
const secretSet = computed(() => efakturaSecretIsSet(effectiveCompany.value));

const setupSteps = computed(() => [
  {
    key: 'provider',
    done: settings.value.efaktura_enabled && settings.value.efaktura_sapi_base_url.trim() !== '',
  },
  {
    key: 'credentials',
    done: settings.value.efaktura_sapi_client_id.trim() !== '' && secretSet.value,
  },
  {
    key: 'tested',
    done: settings.value.efaktura_connection_tested_at !== null,
  },
  {
    key: 'auto_send',
    done: settings.value.efaktura_auto_send,
  },
]);

const contactsReady = computed(() => contactsLoaded.value && contactsMissingIds.value === 0);

// auto_send is a recommendation - a merchant who deliberately keeps manual
// sending should not be nagged forever, so it does not block completeness.
const complete = computed(() => {
  const required = setupSteps.value.filter((step) => step.key !== 'auto_send');
  return required.every((step) => step.done) && contactsReady.value;
});

const visible = computed(() => {
  if (!eligible.value || snoozed.value || !contactsLoaded.value) {
    return false;
  }
  if (featureEnabled.value) {
    return !complete.value;
  }

  // Teaser while the module is globally off: only the contacts step is
  // actionable, so stay quiet once the contacts are covered.
  return !contactsReady.value;
});

const mandatoryFromDisplay = computed(() => {
  const raw = mandatoryFrom.value;
  if (!raw) return '';
  const date = new Date(`${raw}T12:00:00`);
  return Number.isNaN(date.getTime()) ? raw : date.toLocaleDateString(locale.value);
});

const settingsTo = computed(() => ({
  name: 'invoicing-company',
  params: { companyId: props.companyId },
  query: { tab: 'efaktura' },
}));
const contactsTo = computed(() => ({
  name: 'invoicing-contacts',
  params: { companyId: props.companyId },
}));

function snoozeKey(): string {
  return `satflux.efaktura_readiness_snooze.${props.companyId}`;
}

function isSnoozed(): boolean {
  try {
    const until = Number(window.localStorage.getItem(snoozeKey()) ?? 0);
    return Number.isFinite(until) && until > Date.now();
  } catch {
    return false;
  }
}

function snooze() {
  try {
    window.localStorage.setItem(
      snoozeKey(),
      String(Date.now() + SNOOZE_DAYS * 24 * 60 * 60 * 1000),
    );
  } catch {
    // Storage unavailable - hide for this session only.
  }
  snoozed.value = true;
}

function stepIconClass(done: boolean): string {
  return done ? 'text-emerald-600 font-bold' : 'text-indigo-400';
}

type ContactLike = {
  country?: string | null;
  tax_id?: string | null;
  registration_number?: string | null;
  peppol_participant_id?: string | null;
};

function countMissingIds(contacts: ContactLike[]): number {
  return contacts.filter((contact) => {
    const country = String(contact.country ?? '').trim().toUpperCase();
    if (country !== 'SK' && country !== 'SVK') {
      return false;
    }
    return (
      resolvePeppolEndpoint({
        peppol_participant_id: contact.peppol_participant_id ?? null,
        tax_id: contact.tax_id ?? null,
        registration_number: contact.registration_number ?? null,
        country: contact.country ?? null,
      }) === null
    );
  }).length;
}

// Guards all async work: a company switch bumps the generation, so late
// responses for the previous company can never touch the current state.
let loadGeneration = 0;

async function loadContactsCoverage(): Promise<void> {
  const generation = loadGeneration;
  try {
    let missing = 0;
    if (localFirst) {
      const [{ evolu, allContactsQuery }, { evoluContactToApi }] = await Promise.all([
        import('../../evolu/client'),
        import('../../evolu/contactMap'),
      ]);
      const rows = await evolu.loadQuery(allContactsQuery);
      const contacts = rows
        .filter((row) => String(row.companyId ?? '') === props.companyId)
        .map((row) => evoluContactToApi(row as never) as ContactLike);
      missing = countMissingIds(contacts);
    } else {
      // Walk every contact page (API caps a page at 100); the page cap of
      // 50 is only a runaway backstop far above real contact counts.
      const all: ContactLike[] = [];
      let page = 1;
      let lastPage = 1;
      do {
        const { data, meta } = await invoicingApi.contacts.list<ContactLike>(props.companyId, {
          per_page: 100,
          page,
        });
        all.push(...data);
        lastPage = Number(meta.last_page ?? 1);
        page += 1;
      } while (page <= lastPage && page <= 50 && generation === loadGeneration);
      missing = countMissingIds(all);
    }
    if (generation !== loadGeneration) {
      return;
    }
    contactsMissingIds.value = missing;
    contactsLoaded.value = true;
  } catch {
    // Coverage unknown - keep the card hidden rather than guessing.
  }
}

/** (Re)initialize for the current companyId - also on prop switches. */
function initForCompany(): void {
  loadGeneration += 1;
  fetchedCompany.value = null;
  contactsLoaded.value = false;
  contactsMissingIds.value = 0;
  snoozed.value = isSnoozed();
  if (snoozed.value) {
    return;
  }
  void loadFeature();
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
}

onMounted(initForCompany);
watch(() => props.companyId, initForCompany);

// The company record can arrive after mount (async load on both pages) -
// fetch the contacts coverage once eligibility is known and not snoozed.
watch(
  [eligible, snoozed],
  ([isEligible, isSnoozedNow]) => {
    if (isEligible && !isSnoozedNow && !contactsLoaded.value) {
      void loadContactsCoverage();
    }
  },
  { immediate: true },
);
</script>
