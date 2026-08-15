<template>
  <section class="invoicing-card-pad">
    <nav class="invoicing-tabs" role="tablist">
      <button
        type="button"
        role="tab"
        class="invoicing-tab"
        :class="{ 'invoicing-tab--active': activeTab === 'contact' }"
        @click="activeTab = 'contact'"
      >
        {{ t('invoicing.tab_contact_billing') }}
      </button>
      <button
        type="button"
        role="tab"
        class="invoicing-tab"
        :class="{ 'invoicing-tab--active': activeTab === 'bank' }"
        @click="activeTab = 'bank'"
      >
        {{ t('invoicing.tab_bank_accounts') }}
      </button>
      <button
        type="button"
        role="tab"
        class="invoicing-tab"
        :class="{ 'invoicing-tab--active': activeTab === 'branding' }"
        @click="activeTab = 'branding'"
      >
        {{ t('invoicing.tab_logo_signature') }}
      </button>
      <button
        v-if="showEfakturaTab"
        type="button"
        role="tab"
        class="invoicing-tab"
        :class="{ 'invoicing-tab--active': activeTab === 'efaktura' }"
        @click="activeTab = 'efaktura'"
      >
        {{ t('invoicing.tab_efaktura') }}
      </button>
    </nav>

    <!-- Kontaktné a fakturačné údaje -->
    <form v-show="activeTab === 'contact'" class="space-y-6" @submit.prevent="saveContact">
      <RegistryLookupField
        v-model="contactForm.legal_name"
        v-model:registry-country="registryCountry"
        :label="t('invoicing.legal_name')"
        required
        :suggestions="suggestions"
        :registry-options="registryOptions"
        :registry-loading="registryLoading"
        :registry-error="registryError"
        :show-suggestions="showSuggestions"
        @input="onLegalNameInput"
        @focus="onLegalNameFocus"
        @blur="onLegalNameBlur"
        @registry-country-change="onRegistryCountryChange"
        @pick="pickRegistrySuggestion"
      />

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
        <div class="space-y-4">
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.address') }}</label>
            <textarea v-model="contactForm.street" rows="2" class="invoicing-sf-input" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="invoicing-sf-label">{{ isUs ? t('invoicing.us_zip') : t('invoicing.postal_code') }}</label>
              <input v-model="contactForm.postal_code" class="invoicing-sf-input" />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.city') }}</label>
              <input v-model="contactForm.city" class="invoicing-sf-input" />
            </div>
          </div>
          <div v-if="isUs">
            <label class="invoicing-sf-label">{{ t('invoicing.state_region') }}</label>
            <input
              v-model="contactForm.state_region"
              class="invoicing-sf-input uppercase"
              maxlength="2"
              placeholder="WY"
            />
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.country') }}</label>
              <select v-model="contactForm.country" class="invoicing-sf-input">
                <option v-for="code in countryOptions" :key="code" :value="code">
                  {{ countryLabel(code) }}
                </option>
              </select>
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.btcpay_store') }}</label>
              <select v-model="linkedStoreId" class="invoicing-sf-input" :disabled="userStores.length === 0">
                <option value="">{{ t('invoicing.no_store') }}</option>
                <option v-for="s in userStores" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </div>
          </div>
          <p class="text-xs text-gray-500 -mt-2">{{ t('invoicing.company_store_link_hint') }}</p>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.jurisdiction') }}</label>
            <InvoicingJurisdictionSelect v-model="contactForm.jurisdiction" />
            <p
              v-if="activeJurisdictionRules.is_generic_bucket"
              class="text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 mt-2"
            >
              {{ t('invoicing.jurisdiction_generic_bucket_note') }}
            </p>
            <p v-else-if="eInvoicingInfo" class="text-xs text-gray-600 mt-2">
              {{ eInvoicingInfo }}
            </p>
          </div>
        </div>

        <div class="space-y-4">
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.company_phone') }}</label>
            <input v-model="contactForm.issuer_phone" type="tel" class="invoicing-sf-input" />
            <p class="text-xs text-gray-500 mt-1">{{ t('invoicing.company_phone_hint') }}</p>
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.website') }}</label>
            <input v-model="contactForm.website" type="url" class="invoicing-sf-input" placeholder="https://" />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.commercial_register') }}</label>
            <input v-model="contactForm.commercial_register" class="invoicing-sf-input" />
          </div>
          <!-- DE Geschaeftsbrief corporate data - mandatory on GmbH/UG
               invoice footers (Registergericht, HRB, Geschaeftsfuehrer). -->
          <template v-if="contactForm.jurisdiction === 'eu_de'">
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.register_court') }}</label>
              <input v-model="contactForm.register_court" class="invoicing-sf-input" :placeholder="t('invoicing.register_court_placeholder')" />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.register_number') }}</label>
              <input v-model="contactForm.register_number" class="invoicing-sf-input" placeholder="HRB 12345" />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.managing_directors') }}</label>
              <input v-model="contactForm.managing_directors" class="invoicing-sf-input" :placeholder="t('invoicing.managing_directors_placeholder')" />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.supervisory_board_chair') }}</label>
              <input v-model="contactForm.supervisory_board_chair" class="invoicing-sf-input" />
            </div>
          </template>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.issuer_name') }}</label>
            <input v-model="contactForm.issuer_name" class="invoicing-sf-input" />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.issuer_email') }}</label>
            <input v-model="contactForm.issuer_email" type="email" class="invoicing-sf-input" />
          </div>
          <div>
            <label class="invoicing-sf-label">{{ t('invoicing.legal_footer_note') }}</label>
            <textarea v-model="contactForm.legal_footer_note" rows="2" class="invoicing-sf-input" />
          </div>
        </div>

        <div class="space-y-4">
          <template v-if="isUs">
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.us_ein') }}</label>
              <input
                v-model="contactForm.tax_id"
                class="invoicing-sf-input font-mono"
                placeholder="12-3456789"
              />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.us_state_file_number') }}</label>
              <input
                v-model="contactForm.registration_number"
                class="invoicing-sf-input"
                :placeholder="t('invoicing.us_state_file_number_ph')"
              />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.us_default_sales_tax_rate') }}</label>
              <input
                v-model.number="contactForm.vat_rate_default"
                type="number"
                min="0"
                max="100"
                step="0.001"
                class="invoicing-sf-input w-32"
              />
              <p class="text-xs text-gray-500 mt-1">{{ t('invoicing.us_default_sales_tax_rate_hint') }}</p>
            </div>
            <p class="text-xs text-indigo-800 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2">
              {{ t('invoicing.company_settings_us_tax_hint') }}
            </p>
          </template>
          <template v-else>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.registration_number') }} *</label>
              <input v-model="contactForm.registration_number" required class="invoicing-sf-input" />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.tax_id_dic') }}</label>
              <input v-model="contactForm.tax_id" class="invoicing-sf-input" />
            </div>
            <div>
              <label class="invoicing-sf-label">{{ vatIdFieldLabel }}</label>
              <div class="flex gap-2 items-start">
                <input
                  v-model="contactForm.vat_number"
                  class="invoicing-sf-input flex-1 min-w-0"
                  :placeholder="vatIdPlaceholder"
                />
                <button
                  v-if="activeJurisdictionRules.eu_member"
                  type="button"
                  class="shrink-0 px-2 py-2 text-xs border border-gray-300 rounded-lg hover:bg-gray-50"
                  :disabled="viesLoading || !contactForm.vat_number.trim()"
                  @click="checkCompanyVies"
                >
                  {{ viesLoading ? t('invoicing.vies_checking') : t('invoicing.vies_validate') }}
                </button>
              </div>
              <p class="text-xs text-gray-500 mt-1">{{ t('invoicing.vat_number_hint') }}</p>
              <p v-if="viesFeedback" class="text-xs mt-1" :class="viesFeedbackClass">{{ viesFeedback }}</p>
            </div>
            <fieldset class="space-y-2">
              <legend class="invoicing-sf-label mb-2">{{ t('invoicing.vat_status') }}</legend>
              <label class="flex items-start gap-2 text-sm text-gray-800 cursor-pointer">
                <input v-model="vatStatus" type="radio" value="none" class="mt-0.5" />
                {{ t('invoicing.vat_status_none') }}
              </label>
              <label class="flex items-start gap-2 text-sm text-gray-800 cursor-pointer">
                <input v-model="vatStatus" type="radio" value="payer" class="mt-0.5" />
                {{ t('invoicing.vat_status_payer') }}
              </label>
              <label class="flex items-start gap-2 text-sm text-gray-800 cursor-pointer">
                <input v-model="vatStatus" type="radio" value="partial" class="mt-0.5" />
                {{ t('invoicing.vat_status_partial') }}
              </label>
            </fieldset>
            <div>
              <label class="invoicing-sf-label">{{ t('invoicing.vat_turnover_limit_label') }}</label>
              <input
                v-model.number="contactForm.vat_turnover_limit"
                type="number"
                min="0"
                step="1"
                class="invoicing-sf-input w-48"
                :placeholder="t('invoicing.vat_turnover_limit_ph')"
              />
              <p class="text-xs text-gray-500 mt-1">{{ t('invoicing.vat_turnover_limit_hint') }}</p>
            </div>
            <TaxLimitAlert
              v-if="companyId"
              :company-id="companyId"
              :limit="Number(contactForm.vat_turnover_limit) || 0"
              :currency="bankForm.default_currency || 'EUR'"
            />
          </template>
        </div>
      </div>

      <div class="pt-2 border-t border-gray-100">
        <button type="submit" class="invoicing-btn-primary" :disabled="savingContact">
          {{ t('invoicing.save_my_details') }}
        </button>
        <p class="text-xs text-gray-500 mt-2">{{ t('invoicing.profile_save_note') }}</p>
      </div>
    </form>

    <!-- Bankové účty -->
    <form v-show="activeTab === 'bank'" class="space-y-6 max-w-3xl" @submit.prevent="saveBank">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="invoicing-sf-label">{{ t('invoicing.bank_name') }}</label>
          <input v-model="bankForm.bank_name" class="invoicing-sf-input" />
        </div>
        <div>
          <label class="invoicing-sf-label">{{ t('invoicing.currency') }}</label>
          <select v-model="bankForm.default_currency" class="invoicing-sf-input">
            <option v-for="code in currencyOptions" :key="code" :value="code">{{ code }}</option>
          </select>
        </div>
        <div :class="isUs ? 'sm:col-span-2' : ''">
          <label class="invoicing-sf-label">{{ t('invoicing.bank_account') }}</label>
          <input
            v-model="bankForm.bank_account"
            class="invoicing-sf-input"
            :placeholder="isUs ? t('invoicing.us_routing_or_account') : undefined"
          />
          <p class="text-xs text-gray-500 mt-1">{{ t('invoicing.bank_account_hint') }}</p>
        </div>
        <div v-if="!isUs">
          <label class="invoicing-sf-label">{{ t('invoicing.bank_code') }}</label>
          <input v-model="bankForm.bank_code" class="invoicing-sf-input" maxlength="8" />
        </div>
        <div class="sm:col-span-2">
          <label class="invoicing-sf-label">IBAN</label>
          <input
            v-model="bankForm.iban"
            class="invoicing-sf-input font-mono text-sm"
            :placeholder="isUs ? t('invoicing.us_iban_optional') : undefined"
          />
        </div>
        <div class="sm:col-span-2">
          <label class="invoicing-sf-label">{{ t('invoicing.swift') }} / BIC</label>
          <input v-model="bankForm.bic" class="invoicing-sf-input font-mono text-sm" maxlength="11" />
        </div>
      </div>

      <div class="pt-2 border-t border-gray-100">
        <button type="submit" class="invoicing-btn-primary" :disabled="savingBank">
          {{ t('invoicing.save_my_details') }}
        </button>
        <p class="text-xs text-gray-500 mt-2">{{ t('invoicing.profile_save_note') }}</p>
      </div>
    </form>

    <CompanyEfakturaSettingsForm
      v-if="showEfakturaTab"
      v-show="activeTab === 'efaktura'"
      :company-id="companyId"
      :company="company"
      @updated="(c) => emit('updated', c)"
    />

    <!-- Logo a podpis -->
    <div v-show="activeTab === 'branding'">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
      <div>
        <label class="invoicing-sf-label">{{ t('invoicing.company_logo') }}</label>
        <p class="text-xs text-gray-500 mb-2">{{ t('invoicing.company_logo_hint') }}</p>
        <div v-if="logoPreviewUrl" class="mb-3 p-3 border border-gray-200 rounded-lg bg-gray-50 inline-block">
          <img :src="logoPreviewUrl" alt="" class="max-h-16 max-w-[200px] object-contain" />
        </div>
        <div class="flex flex-wrap gap-2">
          <label class="invoicing-btn-secondary cursor-pointer">
            {{ t('invoicing.upload_image') }}
            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="uploadLogo" />
          </label>
          <button
            v-if="logoPreviewUrl"
            type="button"
            class="invoicing-btn-secondary text-red-700"
            :disabled="uploadingLogo"
            @click="removeLogo"
          >
            {{ t('common.delete') }}
          </button>
        </div>
      </div>

      <div>
        <label class="invoicing-sf-label">{{ t('invoicing.company_signature_stamp') }}</label>
        <p class="text-xs text-gray-500 mb-2">{{ t('invoicing.company_signature_stamp_hint') }}</p>
        <div v-if="signaturePreviewUrl" class="mb-3 p-3 border border-gray-200 rounded-lg bg-gray-50 inline-block">
          <img :src="signaturePreviewUrl" alt="" class="max-h-20 max-w-[240px] object-contain" />
        </div>
        <div class="flex flex-wrap gap-2">
          <label class="invoicing-btn-secondary cursor-pointer">
            {{ t('invoicing.upload_image') }}
            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="uploadSignature" />
          </label>
          <button
            v-if="signaturePreviewUrl"
            type="button"
            class="invoicing-btn-secondary text-red-700"
            :disabled="uploadingSignature"
            @click="removeSignature"
          >
            {{ t('common.delete') }}
          </button>
        </div>
      </div>
    </div>
    </div>

    <p v-if="saveError" class="mt-4 text-sm text-red-600">{{ saveError }}</p>

    <div class="mt-8 rounded-lg border border-red-200 bg-red-50 overflow-hidden">
      <div class="px-5 py-4 border-b border-red-200 bg-red-100/60">
        <h2 class="text-base font-semibold text-red-800">{{ t('invoicing.company_reset_danger_zone') }}</h2>
      </div>
      <div class="px-5 py-5 space-y-4">
        <div>
          <h3 class="text-sm font-semibold text-red-900">{{ t('invoicing.company_reset_title') }}</h3>
          <p class="text-sm text-red-800/90 mt-1 max-w-2xl">{{ t('invoicing.company_reset_desc') }}</p>
        </div>
        <button
          type="button"
          class="invoicing-btn-secondary border-red-300 text-red-700 hover:bg-red-100"
          @click="showResetModal = true"
        >
          {{ t('invoicing.company_reset_action') }}
        </button>

        <div class="pt-4 border-t border-red-200">
          <h3 class="text-sm font-semibold text-red-900">{{ t('invoicing.company_delete_title') }}</h3>
          <p class="text-sm text-red-800/90 mt-1 max-w-2xl">{{ t('invoicing.company_delete_desc') }}</p>
          <button
            type="button"
            class="invoicing-btn-secondary border-red-400 text-red-800 hover:bg-red-100 mt-3"
            @click="showDeleteModal = true"
          >
            {{ t('invoicing.company_delete_action') }}
          </button>
        </div>
      </div>
    </div>

    <DangerConfirmModal
      :open="showDeleteModal"
      :title="t('invoicing.company_delete_confirm_title')"
      :body="t('invoicing.company_delete_confirm_desc')"
      :confirm-label="t('invoicing.company_delete_action')"
      :confirm-word="companyDisplayName"
      :busy="deleting"
      :error="deleteError || null"
      :backup-state="destructiveBackupState"
      @close="showDeleteModal = false"
      @confirm="deleteCompanyProfile"
    />

    <DangerConfirmModal
      :open="showResetModal"
      :title="t('invoicing.company_reset_confirm_title')"
      :body="t('invoicing.company_reset_confirm_desc')"
      :items="resetImpactItems"
      :confirm-label="t('invoicing.company_reset_action')"
      :confirm-word="companyDisplayName"
      :busy="resetting"
      :error="resetError || null"
      :backup-state="destructiveBackupState"
      @close="showResetModal = false"
      @confirm="resetCompanyData"
    />
  </section>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { invoicingApi } from "../../services/api";
import CompanyEfakturaSettingsForm from './CompanyEfakturaSettingsForm.vue';
import TaxLimitAlert from './TaxLimitAlert.vue';
import InvoicingJurisdictionSelect from './InvoicingJurisdictionSelect.vue';
import RegistryLookupField from './RegistryLookupField.vue';
import DangerConfirmModal from '../ui/DangerConfirmModal.vue';
import { getAnyLastExportMeta, hasLegacyPlaintextMnemonic } from '../../services/backupState';
import {
  countryAllowedForJurisdiction,
  countriesForJurisdiction,
  defaultCountryForJurisdiction,
  isUsJurisdiction,
  jurisdictionFromCountry,
} from '../../config/companyJurisdiction';
import {
  companyCurrencyOptions,
  syncCompanyDefaultCurrency,
} from '../../config/companyCurrencies';
import { defaultRegistryForJurisdiction } from '../../config/registryCountries';
import { jurisdictionRules, standardVatRate } from '../../config/jurisdictionRules';
import { localeTagFor } from '../../i18n';
import { useEfakturaFeature } from '../../composables/useEfakturaFeature';
import { isCompanyEfakturaEligible } from '../../composables/useCompanyEfakturaSettings';
import {
  useCompanyRegistryLookup,
  type CompanyRegistryFormState,
  type RegistrySummary,
} from '../../composables/useCompanyRegistryLookup';
import { useViesValidation } from '../../composables/useViesValidation';
import { useStoresStore } from '../../store/stores';
import { useFlashStore } from '../../store/flash';
import { syncLinkedStoreToServerBridge } from '../../evolu/ephemeralBridge';
import { isInvoicingLocalFirst } from '../../evolu/flags';
import { useInvoicingEvolu } from '../../evolu/client';
import { asCompanyId } from '../../composables/useInvoicingCompany';
import { evoluCompanyToApi } from '../../evolu/companyMap';
import { updateLocalCompanyBank, updateLocalCompanyContact } from '../../evolu/companyUpdate';
import {
  resizeImageFile,
  updateLocalCompanyLogo,
  updateLocalCompanySignature,
} from '../../evolu/companyBranding';

const LOGO_MAX_BYTES = 100 * 1024;
const SIGNATURE_MAX_BYTES = 100 * 1024;
import { resetLocalCompanyData } from '../../evolu/companyResetLocal';
import { deleteLocalCompany } from '../../evolu/companyDeleteLocal';
import {
  allBankImportBatchesQuery,
  allBankTransactionMatchesQuery,
  allBankTransactionsQuery,
  allCompanyStockBalancesQuery,
  allCompanyStockItemsQuery,
  allCompanyStockMovementsQuery,
  allCompanyWarehousesQuery,
  allContactsQuery,
  allDocumentEventsQuery,
  allDocumentLinesQuery,
  allDocumentsQuery,
  allExpenseAttachmentsQuery,
  allExpensesQuery,
  allNumberSeriesQuery,
  allRecurringProfileLinesQuery,
  allRecurringProfilesQuery,
} from '../../evolu/client';

import { useInvoicingSaveFeedback } from '../../composables/useInvoicingSaveFeedback';

const props = defineProps<{
  companyId: string;
  company: Record<string, any> | null;
  localFirst?: boolean;
}>();

const emit = defineEmits<{
  updated: [company: Record<string, any>];
}>();

const { t, te, locale } = useI18n();
const { notifySaved } = useInvoicingSaveFeedback();
const router = useRouter();
const route = useRoute();
const storesStore = useStoresStore();
const flashStore = useFlashStore();
const localFirst = computed(() => props.localFirst ?? isInvoicingLocalFirst());
const evolu = isInvoicingLocalFirst() ? useInvoicingEvolu() : null;

const activeTab = ref<'contact' | 'bank' | 'branding' | 'efaktura'>('contact');
const linkedStoreId = ref('');
const savedLinkedStoreId = ref('');
/** Identity used for the bridge-company match - renaming the company can break it. */
const savedBridgeIdentity = ref('');

function bridgeIdentityKey(legalName: string, registrationNumber: string | null): string {
  return `${legalName}|${registrationNumber ?? ''}`;
}
const userStores = computed(() => storesStore.stores);
const currencyOptions = computed(() => companyCurrencyOptions(bankForm.default_currency));
const savingContact = ref(false);
const savingBank = ref(false);
const uploadingLogo = ref(false);
const uploadingSignature = ref(false);
const saveError = ref('');
const showResetModal = ref(false);
const resetting = ref(false);
const resetError = ref('');
const showDeleteModal = ref(false);
const deleting = ref(false);
const deleteError = ref('');
const logoPreviewUrl = ref<string | null>(null);
const signaturePreviewUrl = ref<string | null>(null);
const vatStatus = ref<'none' | 'payer' | 'partial'>('none');
const { loading: viesLoading, message: viesMessage, lastResult: viesResult, validate: validateVies } =
  useViesValidation();
const viesFeedback = computed(() => {
  if (viesMessage.value === 'unavailable') return t('invoicing.vies_unavailable');
  if (viesResult.value?.valid) return t('invoicing.vies_valid');
  if (viesResult.value && !viesResult.value.valid) {
    return viesResult.value.error_message || t('invoicing.vies_invalid');
  }
  return '';
});
const viesFeedbackClass = computed(() =>
  viesResult.value?.valid ? 'text-emerald-700' : 'text-amber-800'
);

const contactForm = reactive({
  legal_name: '',
  street: '',
  city: '',
  postal_code: '',
  state_region: '',
  country: 'SK',
  jurisdiction: 'eu_sk',
  registration_number: '',
  tax_id: '',
  vat_number: '',
  commercial_register: '',
  register_court: '',
  register_number: '',
  managing_directors: '',
  supervisory_board_chair: '',
  issuer_name: '',
  issuer_phone: '',
  issuer_email: '',
  website: '',
  legal_footer_note: '',
  vat_rate_default: 0,
  vat_turnover_limit: 0,
});

const bankForm = reactive({
  bank_name: '',
  bank_account: '',
  bank_code: '',
  iban: '',
  bic: '',
  default_currency: 'EUR',
});

const companyDisplayName = computed(
  () => props.company?.trade_name || props.company?.legal_name || ''
);

// Backup evidence line for the destructive confirms (P1 data-loss guards).
const destructiveBackupState = computed(() => ({
  lastExportAt: getAnyLastExportMeta()?.exportedAt ?? null,
  legacyPhraseOnDevice: hasLegacyPlaintextMnemonic(),
}));

const resetImpactItems = computed(() => [
  t('invoicing.company_reset_item_documents'),
  t('invoicing.company_reset_item_contacts'),
  t('invoicing.company_reset_item_expenses'),
  t('invoicing.company_reset_item_payments'),
  t('invoicing.company_reset_item_recurring'),
  t('invoicing.company_reset_item_sequences'),
]);

const isUs = computed(() => isUsJurisdiction(contactForm.jurisdiction));

const activeJurisdictionRules = computed(() => jurisdictionRules(contactForm.jurisdiction));

const vatIdFieldLabel = computed(() =>
  activeJurisdictionRules.value.pdf_label_override
    ? activeJurisdictionRules.value.tax_id_label
    : t('invoicing.vat_number_ic_dph'),
);

const VAT_ID_PLACEHOLDERS: Record<string, string> = {
  eu_sk: 'SK2023980035',
  eu_cz: 'CZ12345678',
  eu_de: 'DE123456789',
  eu_at: 'ATU12345678',
  ch: 'CHE-123.456.789 MWST',
  uk: 'GB123456789',
};
const vatIdPlaceholder = computed(
  () => VAT_ID_PLACEHOLDERS[contactForm.jurisdiction] ?? 'SK2023980035',
);

/** Human sentence about the jurisdiction's e-invoicing mandate (empty when none). */
const eInvoicingInfo = computed(() => {
  const einv = activeJurisdictionRules.value.e_invoicing;
  if (!einv) return '';
  const network = einv.network === 'xrechnung_zugferd' ? 'XRechnung/ZUGFeRD' : 'Peppol';
  const fmt = (iso: string) => new Date(iso).toLocaleDateString(localeTagFor(locale.value));
  const parts: string[] = [];
  if (einv.b2g_only) {
    parts.push(t('invoicing.einvoicing_b2g_only'));
  }
  if (einv.receive_from) {
    parts.push(t('invoicing.einvoicing_receive_from', { date: fmt(einv.receive_from) }));
  }
  if (einv.issue_from) {
    parts.push(
      einv.issue_from_all
        ? t('invoicing.einvoicing_issue_from_phased', {
            date: fmt(einv.issue_from),
            all: fmt(einv.issue_from_all),
          })
        : t('invoicing.einvoicing_issue_from', { date: fmt(einv.issue_from) }),
    );
  }
  if (!parts.length) return '';
  return `${t('invoicing.einvoicing_intro', { network })} ${parts.join('; ')}.`;
});
const { enabled: efakturaGloballyEnabled, load: loadEfakturaFeature } = useEfakturaFeature();
const showEfakturaTab = computed(() =>
  isCompanyEfakturaEligible(props.company, efakturaGloballyEnabled.value)
);
const countryOptions = computed(() => countriesForJurisdiction(contactForm.jurisdiction));

// One watcher covers both the ?tab=efaktura deep-link (readiness checklist)
// and the visibility fallback: the query opens the tab only while it is
// actually visible, and losing visibility falls back to the contact tab.
watch(
  [showEfakturaTab, () => route.query.tab],
  ([visible, tab]) => {
    if (visible && tab === 'efaktura') {
      activeTab.value = 'efaktura';
    } else if (!visible && activeTab.value === 'efaktura') {
      activeTab.value = 'contact';
    }
  },
  { immediate: true },
);

function countryLabel(code: string): string {
  const key = `invoicing.country_${code.toLowerCase()}`;
  return te(key) ? t(key) : code;
}

const registryCountry = ref('sk');

const {
  suggestions,
  registryLoading,
  registryError,
  showSuggestions,
  registryOptions,
  scheduleSearch,
  clearSuggestions,
  selectSuggestionForCompany,
  registrySupportsAutocomplete,
} = useCompanyRegistryLookup();

function syncRegistryCountryFromForm() {
  registryCountry.value = defaultRegistryForJurisdiction(
    contactForm.jurisdiction,
    contactForm.country
  );
}

function applyRegistryCountryToForm(reg: string) {
  const iso = reg.toLowerCase() === 'uk' ? 'GB' : reg.toUpperCase();
  contactForm.country = iso.length === 2 ? iso : defaultCountryForJurisdiction(contactForm.jurisdiction);
  contactForm.jurisdiction = jurisdictionFromCountry(contactForm.country);
  if (isUsJurisdiction(contactForm.jurisdiction)) {
    if (!contactForm.state_region) contactForm.state_region = 'WY';
    vatStatus.value = 'none';
    contactForm.vat_number = '';
  }
}

function onLegalNameInput() {
  if (registrySupportsAutocomplete(registryCountry.value)) {
    scheduleSearch(contactForm.legal_name, registryCountry.value);
  } else {
    clearSuggestions();
  }
}

function onLegalNameFocus() {
  if (
    registrySupportsAutocomplete(registryCountry.value) &&
    contactForm.legal_name.trim().length >= 2
  ) {
    scheduleSearch(contactForm.legal_name, registryCountry.value);
  }
}

function onLegalNameBlur() {
  setTimeout(() => clearSuggestions(), 180);
}

function onRegistryCountryChange() {
  applyRegistryCountryToForm(registryCountry.value);
  if (
    registrySupportsAutocomplete(registryCountry.value) &&
    contactForm.legal_name.trim().length >= 2
  ) {
    scheduleSearch(contactForm.legal_name, registryCountry.value);
  } else {
    clearSuggestions();
  }
}

async function pickRegistrySuggestion(item: RegistrySummary) {
  await selectSuggestionForCompany(
    contactForm as unknown as CompanyRegistryFormState,
    item,
    registryCountry.value
  );
  if (contactForm.vat_number.trim()) {
    vatStatus.value = 'payer';
  }
  syncRegistryCountryFromForm();
}

function applyJurisdictionDefaults(jurisdiction: string, previous: string) {
  const prevCountry = contactForm.country;
  if (isUsJurisdiction(jurisdiction)) {
    contactForm.country = 'US';
    vatStatus.value = 'none';
    contactForm.vat_number = '';
    bankForm.bank_code = '';
  } else {
    if (previous === 'us' && contactForm.country === 'US') {
      contactForm.country = defaultCountryForJurisdiction(jurisdiction);
    }
    if (!countryAllowedForJurisdiction(contactForm.country, jurisdiction)) {
      contactForm.country = defaultCountryForJurisdiction(jurisdiction);
    }
  }
  bankForm.default_currency = syncCompanyDefaultCurrency(
    bankForm.default_currency,
    contactForm.country,
    jurisdiction,
    prevCountry
  );
  // Prefill the jurisdiction's standard rate for VAT payers who have none
  // set yet - never overwrite a deliberate value.
  if (vatStatus.value !== 'none' && !Number(contactForm.vat_rate_default)) {
    const standard = standardVatRate(jurisdiction);
    if (standard != null) {
      contactForm.vat_rate_default = standard;
    }
  }
  syncRegistryCountryFromForm();
}

watch(
  () => contactForm.jurisdiction,
  (jurisdiction: string, previous?: string) => {
    applyJurisdictionDefaults(jurisdiction, previous ?? jurisdiction);
    syncRegistryCountryFromForm();
    clearSuggestions();
    if (
      registrySupportsAutocomplete(registryCountry.value) &&
      contactForm.legal_name.trim().length >= 2
    ) {
      scheduleSearch(contactForm.legal_name, registryCountry.value);
    }
  }
);

watch(
  () => contactForm.country,
  (country, previous) => {
    contactForm.jurisdiction = jurisdictionFromCountry(country);
    bankForm.default_currency = syncCompanyDefaultCurrency(
      bankForm.default_currency,
      country,
      contactForm.jurisdiction,
      previous
    );
    syncRegistryCountryFromForm();
    clearSuggestions();
  }
);

function applyCompany(c: Record<string, any>) {
  contactForm.legal_name = c.legal_name ?? '';
  contactForm.street = c.street ?? '';
  linkedStoreId.value = c.stores?.[0]?.id ?? '';
  savedLinkedStoreId.value = linkedStoreId.value;
  savedBridgeIdentity.value = bridgeIdentityKey(c.legal_name ?? '', c.registration_number ?? null);
  contactForm.city = c.city ?? '';
  contactForm.postal_code = c.postal_code ?? '';
  contactForm.state_region = c.state_region ?? '';
  contactForm.jurisdiction = c.jurisdiction ?? 'eu_sk';
  if (isUsJurisdiction(contactForm.jurisdiction)) {
    contactForm.country = c.country && c.country !== 'SK' ? c.country : 'US';
  } else {
    contactForm.country = c.country ?? 'SK';
  }
  contactForm.registration_number = c.registration_number ?? '';
  contactForm.tax_id = c.tax_id ?? '';
  contactForm.vat_number = c.vat_number ?? '';
  contactForm.commercial_register = c.commercial_register ?? '';
  contactForm.register_court = c.register_court ?? '';
  contactForm.register_number = c.register_number ?? '';
  contactForm.managing_directors = c.managing_directors ?? '';
  contactForm.supervisory_board_chair = c.supervisory_board_chair ?? '';
  contactForm.issuer_name = c.issuer_name ?? '';
  contactForm.issuer_phone = c.issuer_phone ?? '';
  contactForm.issuer_email = c.issuer_email ?? '';
  contactForm.website = c.website ?? '';
  contactForm.legal_footer_note = c.legal_footer_note ?? '';

  bankForm.bank_name = c.bank_name ?? '';
  bankForm.bank_account = c.bank_account ?? '';
  bankForm.bank_code = c.bank_code ?? '';
  bankForm.iban = c.iban ?? '';
  bankForm.bic = c.bic ?? '';
  bankForm.default_currency = c.default_currency ?? 'EUR';

  contactForm.vat_rate_default = Number(c.vat_rate_default ?? 0);
  contactForm.vat_turnover_limit = Number(c.vat_turnover_limit ?? 0);

  if (isUsJurisdiction(contactForm.jurisdiction)) {
    vatStatus.value = 'none';
  } else {
    const vs = c.vat_status;
    vatStatus.value = vs === 'partial' || vs === 'payer' ? vs : c.vat_payer ? 'payer' : 'none';
  }
  logoPreviewUrl.value = c.logo_url ?? null;
  signaturePreviewUrl.value = c.signature_stamp_url ?? null;
  syncRegistryCountryFromForm();
  clearSuggestions();
}

watch(
  () => props.company,
  (c) => {
    if (c) applyCompany(c);
  },
  { immediate: true }
);

function vatPayload() {
  if (isUs.value) {
    return {
      vat_status: 'none',
      vat_payer: false,
      vat_number: null,
      vat_rate_default: Number(contactForm.vat_rate_default) || 0,
    };
  }
  return {
    vat_status: vatStatus.value,
    vat_payer: vatStatus.value === 'payer' || vatStatus.value === 'partial',
  };
}

async function checkCompanyVies() {
  const country = (contactForm.country || 'SK').slice(0, 2).toUpperCase();
  await validateVies(contactForm.vat_number, country);
}

async function saveContact() {
  savingContact.value = true;
  saveError.value = '';
  try {
    if (localFirst.value && evolu) {
      const result = updateLocalCompanyContact(evolu, asCompanyId(props.companyId), {
        ...contactForm,
        ...vatPayload(),
        linked_store_id: linkedStoreId.value || null,
      });
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      // Server-side features (number series, WooCommerce invoicing) read the
      // link from stores.company_id - mirror the local link to the bridge
      // company or they answer 422 store_not_linked on every issue. Runs on
      // every save (idempotent PATCH), not only on change: the local link may
      // already be set while the server one is missing, and re-saving is the
      // documented repair path.
      const linkChanged = linkedStoreId.value !== savedLinkedStoreId.value;
      const identityKey = bridgeIdentityKey(
        contactForm.legal_name,
        contactForm.registration_number || null,
      );
      // Renaming the company (or changing its registration number) can break the
      // identity match against the server bridge company - treat it like a link
      // change for warning purposes.
      const identityChanged = identityKey !== savedBridgeIdentity.value;
      if (linkedStoreId.value || linkChanged) {
        const syncResult = await syncLinkedStoreToServerBridge(
          {
            legal_name: contactForm.legal_name,
            registration_number: contactForm.registration_number || null,
          },
          linkedStoreId.value || null,
        );
        if (
          syncResult === 'failed'
          || (syncResult === 'no_bridge_company' && (linkChanged || identityChanged))
        ) {
          flashStore.warning(t('invoicing.store_link_server_sync_failed'));
        }
      }
      savedLinkedStoreId.value = linkedStoreId.value;
      savedBridgeIdentity.value = identityKey;
      const row = evoluCompanyToApi(
        {
          id: asCompanyId(props.companyId),
          legalName: contactForm.legal_name,
          tradeName: props.company?.trade_name ?? null,
          jurisdiction: contactForm.jurisdiction,
          defaultCurrency: bankForm.default_currency,
          registrationNumber: contactForm.registration_number || null,
          taxId: contactForm.tax_id || null,
          vatNumber: contactForm.vat_number || null,
          commercialRegister: contactForm.commercial_register || null,
          registerCourt: contactForm.register_court || null,
          registerNumber: contactForm.register_number || null,
          managingDirectors: contactForm.managing_directors || null,
          supervisoryBoardChair: contactForm.supervisory_board_chair || null,
          street: contactForm.street || null,
          city: contactForm.city || null,
          postalCode: contactForm.postal_code || null,
          country: contactForm.country || null,
          stateRegion: contactForm.state_region || null,
          iban: bankForm.iban || null,
          bic: bankForm.bic || null,
          bankName: bankForm.bank_name || null,
          bankAccount: bankForm.bank_account || null,
          bankCode: bankForm.bank_code || null,
          vatPayer: vatPayload().vat_payer ? 1 : 0,
          vatStatus: vatPayload().vat_status,
          vatRateDefault: String(contactForm.vat_rate_default ?? 0),
          vatTurnoverLimit:
            Number(contactForm.vat_turnover_limit) > 0
              ? String(contactForm.vat_turnover_limit)
              : null,
          legalFooterNote: contactForm.legal_footer_note || null,
          issuerName: contactForm.issuer_name || null,
          issuerPhone: contactForm.issuer_phone || null,
          issuerEmail: contactForm.issuer_email || null,
          website: contactForm.website || null,
          invoiceNumberPrefix: props.company?.invoice_number_prefix ?? null,
          linkedStoreId: linkedStoreId.value || null,
          appSettingsJson: props.company?.app_settings
            ? JSON.stringify(props.company.app_settings)
            : null,
          emailSettingsJson: props.company?.email_settings
            ? JSON.stringify(props.company.email_settings)
            : null,
          logoDataUrl: logoPreviewUrl.value,
          signatureDataUrl: signaturePreviewUrl.value,
        },
        (storeId) => {
          const store = storesStore.stores.find((s) => s.id === storeId);
          return store ? { id: store.id, name: store.name } : undefined;
        },
      );
      emit('updated', row);
      notifySaved();
      return;
    }

    let payload = (await invoicingApi.companies.update(props.companyId, {
      ...contactForm,
      ...vatPayload(),
    })) as Record<string, any>;
    if (linkedStoreId.value !== savedLinkedStoreId.value) {
      const storeRes = await invoicingApi.companies.updateStores(
        props.companyId,
        linkedStoreId.value ? [linkedStoreId.value] : [],
      );
      payload = {
        ...payload,
        stores: storeRes.data.data?.stores ?? storeRes.data.data,
      };
      savedLinkedStoreId.value = linkedStoreId.value;
    }
    emit('updated', payload);
    notifySaved();
  } catch (rawError) {
    const e = asApiError(rawError);
    saveError.value = extractSaveError(e);
  } finally {
    savingContact.value = false;
  }
}

onMounted(async () => {
  await loadEfakturaFeature();
  if (!storesStore.stores.length) {
    await storesStore.fetchStores();
  }
});

function extractSaveError(rawError: unknown): string {
  const e = asApiError(rawError);
  const errors = e.response?.data?.errors;
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat()[0];
    if (typeof first === 'string') {
      return first;
    }
  }
  return e.response?.data?.message || t('common.error');
}

async function saveBank() {
  savingBank.value = true;
  saveError.value = '';
  try {
    const payload = { ...bankForm };
    if (isUs.value) {
      payload.bank_code = '';
    }

    if (localFirst.value && evolu) {
      const result = updateLocalCompanyBank(evolu, asCompanyId(props.companyId), payload);
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      if (props.company) {
        emit('updated', {
          ...props.company,
          ...payload,
          default_currency: payload.default_currency,
          bank_name: payload.bank_name,
          bank_account: payload.bank_account,
          bank_code: payload.bank_code,
          iban: payload.iban,
          bic: payload.bic,
        });
      }
      notifySaved();
      return;
    }

    emit('updated', await invoicingApi.companies.update(props.companyId, payload));
    notifySaved();
  } catch (rawError) {
    const e = asApiError(rawError);
    saveError.value = extractSaveError(e);
  } finally {
    savingBank.value = false;
  }
}

async function uploadLogo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  uploadingLogo.value = true;
  saveError.value = '';
  try {
    if (localFirst.value && evolu) {
      const dataUrl = await resizeImageFile(file, 800, 400, LOGO_MAX_BYTES);
      const result = updateLocalCompanyLogo(evolu, asCompanyId(props.companyId), dataUrl);
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      logoPreviewUrl.value = dataUrl;
      if (props.company) {
        emit('updated', { ...props.company, logo_url: dataUrl });
      }
      notifySaved();
      return;
    }

    emit('updated', await invoicingApi.companies.branding.uploadLogo(props.companyId, file));
    notifySaved();
  } catch (rawError) {
    const err = asApiError(rawError);
    saveError.value = err?.message || err?.response?.data?.message || t('common.error');
  } finally {
    uploadingLogo.value = false;
    (e.target as HTMLInputElement).value = '';
  }
}

async function uploadSignature(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  uploadingSignature.value = true;
  saveError.value = '';
  try {
    if (localFirst.value && evolu) {
      const dataUrl = await resizeImageFile(file, 800, 300, SIGNATURE_MAX_BYTES);
      const result = updateLocalCompanySignature(evolu, asCompanyId(props.companyId), dataUrl);
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      signaturePreviewUrl.value = dataUrl;
      if (props.company) {
        emit('updated', { ...props.company, signature_stamp_url: dataUrl });
      }
      notifySaved();
      return;
    }

    emit('updated', await invoicingApi.companies.branding.uploadSignatureStamp(props.companyId, file));
    notifySaved();
  } catch (rawError) {
    const err = asApiError(rawError);
    saveError.value = err?.message || err?.response?.data?.message || t('common.error');
  } finally {
    uploadingSignature.value = false;
    (e.target as HTMLInputElement).value = '';
  }
}

async function removeLogo() {
  uploadingLogo.value = true;
  saveError.value = '';
  try {
    if (localFirst.value && evolu) {
      const result = updateLocalCompanyLogo(evolu, asCompanyId(props.companyId), null);
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      logoPreviewUrl.value = null;
      if (props.company) {
        emit('updated', { ...props.company, logo_url: null });
      }
      notifySaved();
      return;
    }

    emit('updated', await invoicingApi.companies.branding.deleteLogo(props.companyId));
    notifySaved();
  } catch (rawError) {
    const err = asApiError(rawError);
    saveError.value = err?.response?.data?.message || t('common.error');
  } finally {
    uploadingLogo.value = false;
  }
}

async function removeSignature() {
  uploadingSignature.value = true;
  saveError.value = '';
  try {
    if (localFirst.value && evolu) {
      const result = updateLocalCompanySignature(evolu, asCompanyId(props.companyId), null);
      if (!result.ok) {
        saveError.value = t('invoicing.company_save_validation_error');
        return;
      }
      signaturePreviewUrl.value = null;
      if (props.company) {
        emit('updated', { ...props.company, signature_stamp_url: null });
      }
      notifySaved();
      return;
    }

    emit('updated', await invoicingApi.companies.branding.deleteSignatureStamp(props.companyId));
    notifySaved();
  } catch (rawError) {
    const err = asApiError(rawError);
    saveError.value = err?.response?.data?.message || t('common.error');
  } finally {
    uploadingSignature.value = false;
  }
}

async function resetCompanyData() {
  resetting.value = true;
  resetError.value = '';
  try {
    if (localFirst.value && evolu) {
      await Promise.all([
        evolu.loadQuery(allDocumentsQuery),
        evolu.loadQuery(allContactsQuery),
        evolu.loadQuery(allNumberSeriesQuery),
        evolu.loadQuery(allDocumentEventsQuery),
      ]);
      resetLocalCompanyData(evolu, asCompanyId(props.companyId));
    } else {
      await invoicingApi.companies.resetData(props.companyId, {
        confirm_name: companyDisplayName.value.trim(),
      });
    }
    showResetModal.value = false;
    await router.push({ name: 'invoicing-invoices', params: { companyId: props.companyId } });
  } catch (rawError) {
    const e = asApiError(rawError);
    resetError.value = e?.response?.data?.message || t('errors.generic');
  } finally {
    resetting.value = false;
  }
}

async function loadLocalCompanyDeleteQueries(): Promise<void> {
  if (!evolu) return;
  await Promise.all([
    evolu.loadQuery(allDocumentsQuery),
    evolu.loadQuery(allDocumentLinesQuery),
    evolu.loadQuery(allContactsQuery),
    evolu.loadQuery(allNumberSeriesQuery),
    evolu.loadQuery(allDocumentEventsQuery),
    evolu.loadQuery(allExpensesQuery),
    evolu.loadQuery(allExpenseAttachmentsQuery),
    evolu.loadQuery(allRecurringProfilesQuery),
    evolu.loadQuery(allRecurringProfileLinesQuery),
    evolu.loadQuery(allCompanyWarehousesQuery),
    evolu.loadQuery(allCompanyStockItemsQuery),
    evolu.loadQuery(allCompanyStockBalancesQuery),
    evolu.loadQuery(allCompanyStockMovementsQuery),
    evolu.loadQuery(allBankImportBatchesQuery),
    evolu.loadQuery(allBankTransactionsQuery),
    evolu.loadQuery(allBankTransactionMatchesQuery),
  ]);
}

async function deleteCompanyProfile() {
  deleting.value = true;
  deleteError.value = '';
  try {
    if (localFirst.value && evolu) {
      await loadLocalCompanyDeleteQueries();
      deleteLocalCompany(evolu, asCompanyId(props.companyId));
    } else {
      await invoicingApi.companies.delete(props.companyId);
    }
    showDeleteModal.value = false;
    await router.push({ name: 'invoicing' });
  } catch (rawError) {
    const e = asApiError(rawError);
    deleteError.value = e?.response?.data?.message || t('errors.generic');
  } finally {
    deleting.value = false;
  }
}
</script>
