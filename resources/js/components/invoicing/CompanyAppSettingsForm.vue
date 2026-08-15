<template>
  <section class="invoicing-card-pad">
    <CompanyAppTabsNav :company-id="companyId" active-tab="basic" />

    <form class="space-y-8" @submit.prevent="save">
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-8 xl:gap-10">
        <!-- Left column -->
        <div class="space-y-4">
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_rounding_method') }}</label>
            <select v-model="form.rounding_method" class="invoicing-sf-input">
              <option value="per_line">{{ t('invoicing.app_rounding_per_line') }}</option>
              <option value="per_document">{{ t('invoicing.app_rounding_per_document') }}</option>
              <option value="none">{{ t('invoicing.app_rounding_none') }}</option>
            </select>
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_invoice_line_label') }}</label>
            <input v-model="form.invoice_line_label" type="text" class="invoicing-sf-input" />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_default_payment_terms') }}</label>
            <input
              v-model.number="form.default_invoice_payment_terms_days"
              type="number"
              min="0"
              max="365"
              class="invoicing-sf-input"
            />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_default_delivery_method') }}</label>
            <input v-model="form.default_delivery_method" type="text" class="invoicing-sf-input" />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_default_delivery_date') }}</label>
            <select v-model="form.default_delivery_date_mode" class="invoicing-sf-input">
              <option value="empty">{{ t('invoicing.app_delivery_date_empty') }}</option>
              <option value="issue_date">{{ t('invoicing.app_delivery_date_issue') }}</option>
              <option value="due_date">{{ t('invoicing.app_delivery_date_due') }}</option>
            </select>
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_default_payment_method') }}</label>
            <input v-model="form.default_payment_method" type="text" class="invoicing-sf-input" />
          </div>
        </div>

        <!-- Middle column -->
        <div class="space-y-4">
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_home_currency') }}</label>
            <div class="invoicing-sf-input bg-gray-50 text-gray-700 flex items-center gap-2 cursor-default">
              <span aria-hidden="true">🇪🇺</span>
              <span>{{ homeCurrency }}</span>
            </div>
          </div>
          <div class="relative">
            <label class="invoicing-sf-label flex items-center gap-1">
              {{ t('invoicing.app_pdf_filename') }}
              <button
                type="button"
                class="text-gray-400 hover:text-indigo-600 text-xs"
                :aria-label="t('invoicing.app_pdf_shortcuts')"
                @click="showPdfHints = !showPdfHints"
              >
                ?
              </button>
            </label>
            <input v-model="form.pdf_filename_pattern" type="text" class="invoicing-sf-input font-mono text-sm" />
            <div
              v-if="showPdfHints"
              class="absolute z-20 left-0 right-0 mt-1 p-3 rounded-lg border border-gray-200 bg-white shadow-lg text-xs text-gray-600 space-y-1"
            >
              <p class="font-semibold text-gray-800 mb-2">{{ t('invoicing.app_pdf_shortcuts') }}</p>
              <p v-for="item in PDF_FILENAME_SHORTCUTS" :key="item.token">
                <code class="text-indigo-700">{{ item.token }}</code> - {{ t(item.key) }}
              </p>
            </div>
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_expense_attachment_name') }}</label>
            <input v-model="form.expense_attachment_name_pattern" type="text" class="invoicing-sf-input" />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_sort_lists_by') }}</label>
            <select v-model="form.sort_lists_by" class="invoicing-sf-input">
              <option value="issue_date">{{ t('invoicing.app_sort_issue_date') }}</option>
              <option value="due_date">{{ t('invoicing.app_sort_due_date') }}</option>
              <option value="number">{{ t('invoicing.app_sort_number') }}</option>
            </select>
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_number_documents_by') }}</label>
            <select v-model="form.number_documents_by" class="invoicing-sf-input">
              <option value="issue_date">{{ t('invoicing.app_number_by_issue_date') }}</option>
              <option value="calendar_year">{{ t('invoicing.app_number_by_calendar_year') }}</option>
            </select>
          </div>
        </div>

        <!-- Right column -->
        <div v-if="isUsCompany" class="md:col-span-2 rounded-lg border border-indigo-100 bg-indigo-50/40 p-4 space-y-3">
          <h3 class="text-sm font-semibold text-indigo-900">{{ t('invoicing.us_sales_tax_title') }}</h3>
          <p class="text-xs text-gray-600">{{ t('invoicing.us_sales_tax_hint') }}</p>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.us_sales_tax_provider') }}</label>
            <select v-model="form.us_sales_tax_provider" class="invoicing-sf-input">
              <option value="manual">{{ t('invoicing.us_sales_tax_manual') }}</option>
              <option value="stripe_tax">{{ t('invoicing.us_sales_tax_stripe') }}</option>
              <option value="avalara">{{ t('invoicing.us_sales_tax_avalara') }}</option>
            </select>
          </div>
          <div v-if="form.us_sales_tax_provider === 'stripe_tax'">
            <label class="invoicing-sf-label">{{ t('invoicing.stripe_tax_secret_key') }}</label>
            <input
              v-model="form.stripe_tax_secret_key"
              type="password"
              autocomplete="off"
              class="invoicing-sf-input font-mono text-xs"
              :placeholder="t('invoicing.stripe_tax_secret_placeholder')"
            />
          </div>
        </div>

        <div class="space-y-4">
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_default_constant_symbol') }}</label>
            <input v-model="form.default_constant_symbol" type="text" maxlength="10" class="invoicing-sf-input" />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.app_tax_free_minimum') }}</label>
            <input v-model="form.tax_free_minimum" type="text" inputmode="decimal" class="invoicing-sf-input" />
          </div>
          <fieldset class="space-y-2.5 pt-1">
            <legend class="sr-only">{{ t('invoicing.app_display_options') }}</legend>
            <label v-for="opt in checkboxOptions" :key="opt.key" class="flex items-start gap-2 text-sm text-gray-700">
              <input
                v-model="form[opt.key]"
                type="checkbox"
                class="mt-0.5 rounded border-gray-300 text-indigo-600"
              />
              <span>{{ t(opt.labelKey) }}</span>
            </label>
          </fieldset>
          <div v-if="form.reverse_charge">
            <label class="invoicing-sf-label">{{ t('invoicing.app_opt_reverse_charge_note') }}</label>
            <textarea
              v-model="form.reverse_charge_note"
              rows="2"
              class="invoicing-sf-input w-full"
              :placeholder="t('invoicing.app_opt_reverse_charge_note')"
            />
          </div>
          <label v-if="isDeCompany" class="flex items-start gap-2 text-sm text-gray-700">
            <input
              v-model="form.export_goods"
              type="checkbox"
              class="mt-0.5 rounded border-gray-300 text-indigo-600"
            />
            <span>{{ t('invoicing.app_opt_export_goods') }}</span>
          </label>
          <div v-if="isDeCompany">
            <label class="invoicing-sf-label">{{ t('invoicing.app_opt_export_note') }}</label>
            <textarea
              v-model="form.export_note"
              rows="2"
              class="invoicing-sf-input w-full"
              :placeholder="t('invoicing.app_opt_export_note_placeholder')"
            />
            <p class="text-xs text-gray-500 mt-1">{{ t('invoicing.app_opt_export_note_hint') }}</p>
          </div>
        </div>
      </div>

      <div class="pt-4 border-t border-gray-100 text-center">
        <button type="submit" class="invoicing-btn-primary px-10" :disabled="saving">
          {{ t('invoicing.save_my_details') }}
        </button>
        <p class="text-xs text-gray-500 mt-2">{{ t('invoicing.app_save_note') }}</p>
        <p class="text-xs text-gray-500 mt-4">
          <router-link
            to="/legal/privacy"
            class="text-indigo-600 hover:text-indigo-500 underline"
          >
            {{ t('invoicing.app_privacy_data_link') }}
          </router-link>
        </p>
        <p v-if="saveError" class="text-sm text-red-600 mt-2">{{ saveError }}</p>
      </div>
    </form>
  </section>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  PDF_FILENAME_SHORTCUTS,
  appSettingsFromCompany,
  type CompanyAppSettingsState,
} from '../../composables/useCompanyAppSettings';
import { asCompanyId } from '../../composables/useInvoicingCompany';
import { allCompaniesDetailQuery, useInvoicingEvolu } from '../../evolu/client';
import { evoluCompanyToApi, type EvoluCompanyRow } from '../../evolu/companyMap';
import { isInvoicingLocalFirst } from '../../evolu/flags';
import { updateLocalAppSettings } from '../../evolu/companySettingsCrud';
import { invoicingApi } from "../../services/api";
import { useStoresStore } from '../../store/stores';
import { useInvoicingSaveFeedback } from '../../composables/useInvoicingSaveFeedback';
import CompanyAppTabsNav from './CompanyAppTabsNav.vue';

const props = defineProps<{
  companyId: string;
  company: Record<string, any> | null;
}>();

const emit = defineEmits<{
  updated: [company: Record<string, any>];
}>();

const { t } = useI18n();
const { notifySaved } = useInvoicingSaveFeedback();
const localFirst = isInvoicingLocalFirst();
const evolu = localFirst ? useInvoicingEvolu() : null;
const storesStore = useStoresStore();

const saving = ref(false);
const saveError = ref('');
const showPdfHints = ref(false);
const form = reactive<CompanyAppSettingsState>(appSettingsFromCompany(null));

const homeCurrency = computed(() => props.company?.default_currency ?? 'EUR');
const isUsCompany = computed(() => props.company?.jurisdiction === 'us');
const isDeCompany = computed(() => props.company?.jurisdiction === 'eu_de');

const checkboxOptions: { key: keyof CompanyAppSettingsState; labelKey: string }[] = [
  { key: 'show_contextual_help', labelKey: 'invoicing.app_opt_contextual_help' },
  { key: 'embed_isdoc_in_pdf', labelKey: 'invoicing.app_opt_embed_isdoc' },
  { key: 'embed_zugferd_in_pdf', labelKey: 'invoicing.app_opt_embed_zugferd' },
  { key: 'reverse_charge', labelKey: 'invoicing.app_opt_reverse_charge' },
  { key: 'show_pay_by_square', labelKey: 'invoicing.app_opt_pay_by_square' },
  { key: 'show_invoice_by_square', labelKey: 'invoicing.app_opt_invoice_by_square' },
  { key: 'show_client_phone_on_invoices', labelKey: 'invoicing.app_opt_client_phone' },
  { key: 'show_payme_on_invoices', labelKey: 'invoicing.app_opt_payme' },
  { key: 'variable_symbol_from_proforma', labelKey: 'invoicing.app_opt_vs_from_proforma' },
  { key: 'show_prices_on_delivery_notes', labelKey: 'invoicing.app_opt_prices_delivery' },
  { key: 'show_prices_on_orders', labelKey: 'invoicing.app_opt_prices_orders' },
  { key: 'show_line_suggester', labelKey: 'invoicing.app_opt_line_suggester' },
  { key: 'show_summary_on_quotes', labelKey: 'invoicing.app_opt_summary_quotes' },
  { key: 'runs_eshop', labelKey: 'invoicing.app_opt_eshop' },
];

watch(
  () => props.company,
  (c) => {
    Object.assign(form, appSettingsFromCompany(c));
  },
  { immediate: true, deep: true }
);

function emitUpdatedFromEvoluRow() {
  if (!evolu) return;
  const row = evolu.getQueryRows(allCompaniesDetailQuery).find((c) => c.id === props.companyId);
  if (!row) return;
  emit(
    'updated',
    evoluCompanyToApi(row as EvoluCompanyRow, (storeId) => {
      const store = storesStore.stores.find((s) => s.id === storeId);
      return store
        ? { id: store.id, name: store.name, default_currency: store.default_currency }
        : undefined;
    }),
  );
}

async function save() {
  saving.value = true;
  saveError.value = '';
  try {
    const payload: Partial<CompanyAppSettingsState> = { ...form };
    if (!payload.stripe_tax_secret_key) {
      delete payload.stripe_tax_secret_key;
    }

    if (localFirst && evolu) {
      const result = updateLocalAppSettings(evolu, asCompanyId(props.companyId), payload);
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      emitUpdatedFromEvoluRow();
      notifySaved();
      return;
    }

    emit('updated', await invoicingApi.companies.updateAppSettings(props.companyId, payload));
    notifySaved();
  } catch (rawError) {
    const e = asApiError(rawError);
    saveError.value = e?.response?.data?.message ?? t('common.error_generic');
  } finally {
    saving.value = false;
  }
}
</script>
