<template>
  <InvoicingPageShell>
    <template #header>
      <InvoicingAppHeader
        :company-label="company?.trade_name || company?.legal_name"
        :show-filter-bar="false"
      />
    </template>
    <template #toolbar>
      <RouterLink :to="backTo" class="invoicing-back mb-0">← {{ backLabel }}</RouterLink>
    </template>

    <h1 class="invoicing-title mb-4">
      {{ isNew ? newTitle : editTitle }}
    </h1>

    <div v-if="localFirst && !isLocked" class="mb-4 flex flex-wrap items-center gap-2">
      <select
        v-if="(isNew || documentStatus === 'draft') && invoiceTemplates.length"
        class="invoicing-sf-input max-w-[240px]"
        :value="''"
        :aria-label="t('invoicing.template_load_placeholder')"
        @change="onPickTemplate"
      >
        <option value="">{{ t('invoicing.template_load_placeholder') }}</option>
        <option v-for="tpl in invoiceTemplates" :key="tpl.id" :value="tpl.id">
          {{ tpl.name }}
        </option>
      </select>
      <button type="button" class="invoicing-btn-secondary" @click="saveAsTemplate">
        {{ t('invoicing.template_save') }}
      </button>
    </div>

    <div
      v-if="isCreditNote && sourceDocument?.number"
      class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 mb-4"
    >
      {{ t('invoicing.linked_credited_invoice', { number: sourceDocument.number }) }}
    </div>

    <div v-if="isLocked" class="invoicing-alert-warn mb-4">{{ t('invoicing.readonly_locked_hint') }}</div>
    <div v-else-if="isIssued" class="invoicing-alert-info mb-4">{{ t('invoicing.issued_edit_hint') }}</div>

    <form class="invoicing-card-pad space-y-6" @submit.prevent="save(false)">
        <section>
          <h2 class="text-xs font-bold uppercase tracking-wide text-gray-600 mb-4">
            {{ t('invoicing.section_general') }}
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="space-y-3">
              <div>
                <label class="invoicing-sf-label">
                  {{ t('invoicing.invoice_number') }}
                  <span class="text-red-500">*</span>
                </label>
                <input
                  :value="displayDocumentNumber || t('invoicing.draft_label')"
                  type="text"
                  class="invoicing-sf-input bg-gray-50"
                  readonly
                />
              </div>
              <div>
                <label class="invoicing-sf-label">{{ t('invoicing.variable_symbol') }}</label>
                <input v-model="form.variable_symbol" type="text" class="invoicing-sf-input" maxlength="20" :disabled="isLocked" />
              </div>
              <div>
                <label class="invoicing-sf-label">{{ t('invoicing.invoice_title') }}</label>
                <input v-model="form.title" type="text" class="invoicing-sf-input" :disabled="isLocked" />
              </div>
            </div>

            <div class="space-y-3">
              <div>
                <label class="invoicing-sf-label">
                  {{ t('invoicing.issue_date') }}
                  <span class="text-red-500">*</span>
                </label>
                <input v-model="form.issue_date" type="date" class="invoicing-sf-input" required :disabled="isLocked" />
              </div>
              <div>
                <label class="invoicing-sf-label">{{ t('invoicing.delivery_date') }}</label>
                <input v-model="form.delivery_date" type="date" class="invoicing-sf-input" :disabled="isLocked" />
              </div>
              <div>
                <label class="invoicing-sf-label">
                  {{ isQuote ? t('invoicing.valid_until') : t('invoicing.due_date') }}
                  <span class="text-red-500">*</span>
                </label>
                <input v-model="form.due_date" type="date" class="invoicing-sf-input" required :disabled="isLocked" />
              </div>
            </div>

            <div class="space-y-2">
              <div>
                <label class="invoicing-sf-label">
                  {{ t('invoicing.customer') }}
                  <span class="text-red-500">*</span>
                </label>
                <select
                  v-model="form.company_contact_id"
                  class="invoicing-sf-input"
                  required
                  :disabled="isLocked"
                  @change="onContactChange"
                >
                  <option value="">{{ t('invoicing.select_customer') }}</option>
                  <option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
              </div>
              <p v-if="selectedContact && formatContactAddress(selectedContact)" class="text-xs text-gray-600 leading-relaxed">
                {{ formatContactAddress(selectedContact) }}
              </p>
              <p
                v-if="efakturaIndicator === 'ok'"
                class="text-xs text-emerald-700 leading-relaxed"
              >
                {{ t('invoicing.efaktura_will_send') }}
              </p>
              <p
                v-else-if="efakturaIndicator === 'missing_ids'"
                class="text-xs text-amber-600 leading-relaxed"
              >
                {{ t('invoicing.efaktura_indicator_missing_ids') }}
              </p>
              <p
                v-else-if="efakturaIndicator"
                class="text-xs text-gray-500 leading-relaxed"
              >
                {{ t(`invoicing.efaktura_indicator_${efakturaIndicator}`) }}
              </p>
              <div class="flex flex-wrap gap-3 text-sm">
                <button
                  v-if="!isLocked"
                  type="button"
                  class="text-indigo-600 hover:underline"
                  @click="showContactCreateModal = true"
                >
                  + {{ t('invoicing.create_client') }}
                </button>
                <RouterLink
                  v-if="selectedContact"
                  :to="contactShowTo(selectedContact.id)"
                  class="text-indigo-600 hover:underline"
                >
                  {{ t('invoicing.edit_client') }}
                </RouterLink>
              </div>
            </div>
          </div>

          <details class="mt-4 text-sm">
            <summary class="text-indigo-600 cursor-pointer hover:underline">{{ t('invoicing.more_options') }}</summary>
            <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-2">
              <div>
                <label class="invoicing-sf-label">{{ t('invoicing.currency') }}</label>
                <select v-model="form.currency" class="invoicing-sf-input" :disabled="isLocked">
                  <option v-for="code in documentCurrencyOptions" :key="code" :value="code">{{ code }}</option>
                </select>
              </div>
              <div>
                <label class="invoicing-sf-label">{{ t('invoicing.constant_symbol') }}</label>
                <input v-model="form.constant_symbol" type="text" class="invoicing-sf-input" :disabled="isLocked" />
              </div>
              <div>
                <label class="invoicing-sf-label">{{ t('invoicing.specific_symbol') }}</label>
                <input v-model="form.specific_symbol" type="text" class="invoicing-sf-input" :disabled="isLocked" />
              </div>
              <template v-if="!isQuote && !isCreditNote">
                <div>
                  <label class="invoicing-sf-label">{{ t('invoicing.btcpay_store') }}</label>
                  <select
                    v-model="form.store_id"
                    class="invoicing-sf-input"
                    :disabled="isLocked || linkedStores.length === 0"
                    :required="linkedStores.length > 0 && form.payment_btc_enabled"
                  >
                    <option v-if="linkedStores.length === 0" value="">{{ t('invoicing.no_store') }}</option>
                    <option v-for="s in linkedStores" :key="s.id" :value="s.id">{{ s.name }}</option>
                  </select>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700 pt-6">
                  <input v-model="form.payment_bank_enabled" type="checkbox" :disabled="isLocked" />
                  {{ t('invoicing.payment_bank') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 pt-6">
                  <input v-model="form.payment_btc_enabled" type="checkbox" :disabled="isLocked" />
                  {{ t('invoicing.payment_btc') }}
                </label>
                <p v-if="form.payment_btc_enabled" class="text-xs text-gray-500 sm:col-span-2 lg:col-span-4">
                  {{ t('invoicing.payment_btc_lazy_hint') }}
                </p>
              </template>
            </div>
          </details>

          <p class="mt-3">
            <RouterLink
              :to="{ name: 'invoicing-company', params: { companyId } }"
              class="text-sm text-indigo-600 hover:underline"
            >
              {{ t('invoicing.edit_company_on_invoice') }}
            </RouterLink>
          </p>
        </section>

        <section>
                <label class="invoicing-sf-label">{{ t('invoicing.note_above') }}</label>
          <textarea v-model="form.note_above_lines" rows="3" class="invoicing-sf-input" :disabled="isLocked" />
        </section>

        <section>
          <div class="hidden md:block overflow-x-auto rounded border border-gray-200">
            <table class="w-full text-sm">
              <thead class="bg-gray-100 text-gray-600 text-xs uppercase">
                <tr>
                  <th class="text-left px-3 py-2 min-w-[200px]">{{ t('invoicing.col_item') }}</th>
                  <th v-if="showLineSuggester" class="text-left px-2 py-2 min-w-[130px]">{{ t('invoicing.warehouse_col_name') }}</th>
                  <th class="text-center px-2 py-2 w-20">{{ t('invoicing.col_qty') }}</th>
                  <th class="text-center px-2 py-2 w-24">{{ t('invoicing.col_unit') }}</th>
                  <th class="text-right px-2 py-2 w-28">{{ t('invoicing.col_unit_price') }}</th>
                  <th v-if="showLineTaxColumn" class="text-right px-2 py-2 w-16">{{ lineTaxColumnLabel }}%</th>
                  <th class="text-right px-2 py-2 w-28">{{ t('invoicing.col_line_total_vat') }}</th>
                  <th class="w-8"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx" class="border-t border-gray-200">
                  <td class="px-3 py-2 align-top">
                    <StockLineSuggestField
                      v-if="showLineSuggester && !isLocked"
                      :company-id="companyId"
                      :name="line.name"
                      :description="line.description"
                      :stock-item-id="line.company_stock_item_id"
                      :warehouse-id="line.company_warehouse_id"
                      :quantity-on-hand="line.stock_quantity_hint"
                      :deduct-on-issue="line.warehouse_deduct_on_issue"
                      :unit="line.unit"
                      :enabled="showLineSuggester"
                      :disabled="isLocked"
                      :required="!isLocked"
                      :description-placeholder="t('invoicing.item_description')"
                      @update:name="line.name = $event"
                      @update:description="line.description = $event"
                      @pick="onLineStockPick(line, $event)"
                      @clear-stock-link="clearLineStockLink(line)"
                    />
                    <template v-else>
                      <input v-model="line.name" class="invoicing-sf-input-table" :required="!isLocked" :disabled="isLocked" />
                      <input
                        v-model="line.description"
                        class="invoicing-sf-input-table mt-1 text-gray-500"
                        :placeholder="t('invoicing.item_description')"
                        :disabled="isLocked"
                      />
                    </template>
                  </td>
                  <td v-if="showLineSuggester" class="px-2 py-2 align-top">
                    <StockWarehouseSelect
                      v-model="line.company_warehouse_id"
                      :warehouses="warehouses"
                      :disabled="isLocked"
                      @update:model-value="onLineWarehouseChange(line)"
                    />
                    <p
                      v-if="line.company_stock_item_id && line.warehouse_deduct_on_issue === false"
                      class="text-xs text-amber-600 mt-1"
                    >
                      {{ t('invoicing.warehouse_no_deduct_hint') }}
                    </p>
                  </td>
                  <td class="px-2 py-2 align-middle">
                    <input v-model.number="line.quantity" type="number" min="0.0001" step="any" class="invoicing-sf-input-table text-center" :disabled="isLocked" />
                  </td>
                  <td class="px-2 py-2 align-middle">
                    <InvoiceLineUnitSelect v-model="line.unit" :disabled="isLocked" />
                  </td>
                  <td class="px-2 py-2 align-middle">
                    <input v-model.number="line.unit_price" type="number" min="0" step="0.01" class="invoicing-sf-input-table text-right" :disabled="isLocked" />
                  </td>
                  <td v-if="showLineTaxColumn" class="px-2 py-2 align-middle">
                    <input v-model.number="line.tax_rate" type="number" min="0" max="100" class="invoicing-sf-input-table text-right" :disabled="isLocked" />
                  </td>
                  <td class="px-2 py-2 align-middle text-right font-medium text-gray-800 whitespace-nowrap">
                    {{ lineTotalDisplay(line).toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                  </td>
                  <td class="px-1 py-2 text-center">
                    <button
                      v-if="!isLocked && form.lines.length > 1"
                      type="button"
                      class="text-red-500 hover:text-red-700"
                      @click="form.lines.splice(idx, 1)"
                    >
                      ×
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="md:hidden space-y-3">
            <div
              v-for="(line, idx) in form.lines"
              :key="`mobile-line-${idx}`"
              class="invoicing-line-item-card"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-semibold text-gray-500 uppercase">{{ t('invoicing.col_item') }} {{ idx + 1 }}</span>
                <button
                  v-if="!isLocked && form.lines.length > 1"
                  type="button"
                  class="text-red-500 text-sm"
                  @click="form.lines.splice(idx, 1)"
                >
                  {{ t('invoicing.action_delete') }}
                </button>
              </div>
              <StockLineSuggestField
                v-if="showLineSuggester && !isLocked"
                :company-id="companyId"
                :name="line.name"
                :description="line.description"
                :stock-item-id="line.company_stock_item_id"
                :warehouse-id="line.company_warehouse_id"
                :quantity-on-hand="line.stock_quantity_hint"
                :deduct-on-issue="line.warehouse_deduct_on_issue"
                :unit="line.unit"
                :enabled="showLineSuggester"
                :disabled="isLocked"
                :required="!isLocked"
                :description-placeholder="t('invoicing.item_description')"
                @update:name="line.name = $event"
                @update:description="line.description = $event"
                @pick="onLineStockPick(line, $event)"
                @clear-stock-link="clearLineStockLink(line)"
              />
              <template v-else>
                <input v-model="line.name" class="invoicing-sf-input w-full" :required="!isLocked" :disabled="isLocked" />
                <input
                  v-model="line.description"
                  class="invoicing-sf-input w-full text-gray-500"
                  :placeholder="t('invoicing.item_description')"
                  :disabled="isLocked"
                />
              </template>
              <div v-if="showLineSuggester" class="space-y-1">
                <label class="text-xs text-gray-500">{{ t('invoicing.warehouse_col_name') }}</label>
                <StockWarehouseSelect
                  v-model="line.company_warehouse_id"
                  :warehouses="warehouses"
                  :disabled="isLocked"
                  @update:model-value="onLineWarehouseChange(line)"
                />
                <p
                  v-if="line.company_stock_item_id && line.warehouse_deduct_on_issue === false"
                  class="text-xs text-amber-600"
                >
                  {{ t('invoicing.warehouse_no_deduct_hint') }}
                </p>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="text-xs text-gray-500">{{ t('invoicing.col_qty') }}</label>
                  <input v-model.number="line.quantity" type="number" min="0.0001" step="any" class="invoicing-sf-input w-full" :disabled="isLocked" />
                </div>
                <div>
                  <label class="text-xs text-gray-500">{{ t('invoicing.col_unit') }}</label>
                  <InvoiceLineUnitSelect v-model="line.unit" class="w-full" :disabled="isLocked" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="text-xs text-gray-500">{{ t('invoicing.col_unit_price') }}</label>
                  <input v-model.number="line.unit_price" type="number" min="0" step="0.01" class="invoicing-sf-input w-full" :disabled="isLocked" />
                </div>
                <div v-if="showLineTaxColumn">
                  <label class="text-xs text-gray-500">{{ lineTaxColumnLabel }}%</label>
                  <input v-model.number="line.tax_rate" type="number" min="0" max="100" class="invoicing-sf-input w-full" :disabled="isLocked" />
                </div>
              </div>
              <div class="flex justify-end">
                <div class="text-right">
                  <p class="text-xs text-gray-500">{{ t('invoicing.col_line_total_vat') }}</p>
                  <p class="font-medium">{{ lineTotalDisplay(line).toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</p>
                </div>
              </div>
            </div>
          </div>
          <button v-if="!isLocked" type="button" class="mt-2 text-sm text-indigo-600 hover:underline" @click="addLine">
            {{ t('invoicing.add_line') }}
          </button>
          <p
            v-if="vatRateWarning"
            class="mt-2 text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2"
          >
            {{ vatRateWarning }}
          </p>
        </section>

        <section class="grid md:grid-cols-2 gap-6">
          <div>
                <label class="invoicing-sf-label">{{ t('invoicing.note_footer') }}</label>
            <textarea v-model="form.note_footer" rows="4" class="invoicing-sf-input" :disabled="isLocked" />
          </div>
          <div class="rounded-lg bg-sky-50 border border-sky-200 p-4 space-y-3">
            <div class="flex items-center gap-2">
              <label class="text-sm text-gray-700 shrink-0">{{ t('invoicing.discount') }}</label>
              <input
                v-model.number="form.discount_percent"
                type="number"
                min="0"
                max="100"
                class="invoicing-sf-input w-20 text-right"
                :disabled="isLocked"
              />
              <span class="text-sm text-gray-500">%</span>
              <span class="ml-auto text-sm text-gray-600">
                {{ discountAmount.toLocaleString(locale, { minimumFractionDigits: 2 }) }} {{ form.currency }}
              </span>
            </div>
            <div class="text-right border-t border-sky-200 pt-3">
              <span class="text-sm text-gray-600">{{ t('invoicing.grand_total') }}</span>
              <div class="text-2xl font-bold text-gray-900">
                {{ previewTotals.total.toLocaleString(locale, { minimumFractionDigits: 2 }) }} {{ form.currency }}
              </div>
            </div>
          </div>
        </section>

        <section class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
                <label class="invoicing-sf-label">{{ t('invoicing.issuer_name') }}</label>
            <input :value="company?.issuer_name || ''" type="text" class="invoicing-sf-input bg-gray-50" readonly />
          </div>
          <div>
                <label class="invoicing-sf-label">{{ t('invoicing.issuer_phone') }}</label>
            <input :value="company?.issuer_phone || ''" type="text" class="invoicing-sf-input bg-gray-50" readonly />
          </div>
          <div>
                <label class="invoicing-sf-label">{{ t('invoicing.issuer_web') }}</label>
            <input :value="company?.website || ''" type="text" class="invoicing-sf-input bg-gray-50" readonly />
          </div>
          <div>
                <label class="invoicing-sf-label">{{ t('invoicing.issuer_email') }}</label>
            <input :value="company?.issuer_email || ''" type="email" class="invoicing-sf-input bg-gray-50" readonly />
          </div>
        </section>

        <section class="grid sm:grid-cols-2 gap-4">
          <div>
                <label class="invoicing-sf-label">{{ t('invoicing.tags') }}</label>
            <input v-model="tagsInput" type="text" class="invoicing-sf-input" :placeholder="t('invoicing.tags_placeholder')" :disabled="isLocked" />
          </div>
          <div>
                <label class="invoicing-sf-label">{{ t('invoicing.internal_note') }}</label>
            <textarea v-model="form.internal_note" rows="2" class="invoicing-sf-input" :disabled="isLocked" />
          </div>
        </section>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="!isLocked" class="flex flex-wrap gap-3 pt-2">
        <button type="submit" class="invoicing-btn-primary px-6 py-3" :disabled="saving">
            {{ isNew || documentStatus === 'draft' ? t('invoicing.save_invoice') : t('invoicing.save_changes') }}
          </button>
        <button
          v-if="isNew || documentStatus === 'draft'"
          type="button"
          class="invoicing-btn-primary px-6 py-3"
          :disabled="saving"
          @click="save(true)"
        >
          {{ t('invoicing.save_and_pdf') }}
        </button>
          <RouterLink
            v-if="!isNew"
            :to="{ name: 'invoicing-invoice-show', params: { companyId, documentId } }"
            class="invoicing-btn-secondary"
          >
            {{ t('common.cancel') }}
          </RouterLink>
        </div>
      </form>

    <ContactCreateModal
      :open="showContactCreateModal"
      :company-id="companyId"
      @close="showContactCreateModal = false"
      @saved="onContactCreated"
    />
  </InvoicingPageShell>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { computed, onMounted, ref } from 'vue';
import ContactCreateModal from '../../components/invoicing/ContactCreateModal.vue';
import InvoicingAppHeader from '../../components/invoicing/InvoicingAppHeader.vue';
import InvoiceLineUnitSelect from '../../components/invoicing/InvoiceLineUnitSelect.vue';
import StockLineSuggestField from '../../components/invoicing/StockLineSuggestField.vue';
import StockWarehouseSelect from '../../components/invoicing/StockWarehouseSelect.vue';
import type { InvoiceLineForm } from '../../components/invoicing/InvoiceLivePreview.vue';
import InvoicingPageShell from '../../components/invoicing/InvoicingPageShell.vue';
import { useInvoicingLayout } from '../../composables/useInvoicingLayout';
import { businessDocumentPdfPath, getWebBlob, invoicingApi } from '../../services/api';
import {
  downloadEphemeralPdf,
  resolveEphemeralBridgeCompanyId,
} from '../../evolu/ephemeralBridge';
import { companyCurrencyOptions } from '../../config/companyCurrencies';
import { allowedVatRates, vatRateOutsideJurisdiction } from '../../config/jurisdictionRules';
import { appSettingsFromCompany } from '../../composables/useCompanyAppSettings';
import { efakturaSendability } from '../../composables/useCompanyEfakturaSettings';
import { useEfakturaFeature } from '../../composables/useEfakturaFeature';
import { useInvoiceDocument } from '../../composables/useInvoiceDocument';
import type { CompanyContactRow } from '../../composables/useCompanyContact';
import { useInvoicingInvoiceTemplates } from '../../composables/useInvoicingInvoiceTemplates';
import {
  buildTemplateSnapshot,
  parseTemplateSnapshot,
  serializeTemplateSnapshot,
  templateToDraftDocument,
} from '../../evolu/invoiceTemplate';
import { useFlashStore } from '../../store/flash';

const {
  t,
  locale,
  router,
  companyId,
  documentId,
  saving,
  error,
  documentStatus,
  displayDocumentNumber,
  company,
  contacts,
  linkedStores,
  tagsInput,
  isIssued,
  isLocked,
  form,
  selectedContact,
  previewTotals,
  lineTotalDisplay,
  newLine,
  payload,
  extractError,
  loadCompanyAndContacts,
  reloadDocument,
  initNewDraft,
  contactShowTo,
  formatContactAddress,
  route,
  calcTotals,
  documentType,
  isUsCompany,
  documentRoutes,
  documentKind,
  isProforma,
  isQuote,
  isCreditNote,
  sourceDocument,
  applyDocument,
  showLineTaxColumn,
  warehouses,
  defaultWarehouseIdValue,
  localFirst,
  saveLocalDocumentFlow,
  buildCurrentEphemeralSnapshot,
} = useInvoiceDocument();

const { rememberCompany } = useInvoicingLayout();
const { enabled: efakturaGloballyEnabled, load: loadEfakturaFeature } = useEfakturaFeature();

// "Invisible" e-faktura: the merchant sees right on the form whether this
// document will go out electronically, and if not, the first reason why.
// Renders only for e-faktura companies (SK full payers with the module on).
const efakturaIndicator = computed(() => {
  if (documentType.value !== 'invoice' && documentType.value !== 'credit_note') {
    return null;
  }
  const kind = efakturaSendability(company.value, selectedContact.value, efakturaGloballyEnabled.value);

  // An empty customer select explains itself - stay quiet until a pick.
  return kind === 'no_contact' ? null : kind;
});

const flashStore = useFlashStore();
const {
  templates: invoiceTemplates,
  save: saveTemplate,
  refresh: refreshTemplates,
} = useInvoicingInvoiceTemplates(companyId);

async function saveAsTemplate() {
  const name = window.prompt(t('invoicing.template_name_prompt'))?.trim();
  if (!name) return;
  const snapshot = buildTemplateSnapshot(payload() as Record<string, unknown>);
  const result = saveTemplate(name, serializeTemplateSnapshot(snapshot));
  if (result.ok) {
    await refreshTemplates();
    flashStore.success(t('invoicing.template_saved'));
  } else {
    flashStore.error(t('invoicing.template_save_failed'));
  }
}

async function onPickTemplate(event: Event) {
  const select = event.target as HTMLSelectElement;
  const id = select.value;
  select.value = '';
  if (!id) return;
  const tpl = invoiceTemplates.value.find((entry) => entry.id === id);
  if (!tpl) return;
  const snapshot = parseTemplateSnapshot(tpl.payloadJson);
  if (!snapshot) {
    flashStore.error(t('invoicing.template_apply_failed'));
    return;
  }
  await applyDocument(templateToDraftDocument(snapshot));
  flashStore.success(t('invoicing.template_applied', { name: tpl.name }));
}

const isNew = computed(() => !documentId.value);

/**
 * Soft legal check: warn when a line VAT rate is outside the company
 * jurisdiction's known set (never blocks - history and special regimes).
 */
const vatRateWarning = computed(() => {
  const jurisdiction = String(company.value?.jurisdiction ?? '');
  if (!jurisdiction || !showLineTaxColumn.value) return '';
  const offending = form.lines
    .map((line) => Number(line.tax_rate) || 0)
    .filter((rate) => vatRateOutsideJurisdiction(jurisdiction, rate));
  if (!offending.length) return '';
  const allowed = allowedVatRates(jurisdiction);
  return t('invoicing.vat_rate_outside_jurisdiction', {
    rates: [...new Set(offending)].join(', '),
    allowed: allowed.join(', '),
  });
});
const showContactCreateModal = ref(false);
const showLineSuggester = computed(() => appSettingsFromCompany(company.value).show_line_suggester);

function onLineStockPick(
  line: InvoiceLineForm,
  payload: {
    name: string;
    description: string;
    unit: string;
    unit_price: number;
    company_stock_item_id: string;
    quantity_on_hand: number | null;
    quantities_by_warehouse?: Record<string, number>;
    deduct_on_issue?: boolean | null;
  }
) {
  line.name = payload.name;
  line.description = payload.description;
  line.unit = payload.unit;
  line.unit_price = payload.unit_price;
  line.company_stock_item_id = payload.company_stock_item_id;
  line.stock_quantities_by_warehouse = payload.quantities_by_warehouse ?? {};
  if (!line.company_warehouse_id) {
    line.company_warehouse_id = defaultWarehouseIdValue.value || null;
  }
  syncLineStockHint(line);
}

function onLineWarehouseChange(line: InvoiceLineForm) {
  syncLineStockHint(line);
}

function syncLineStockHint(line: InvoiceLineForm) {
  const warehouseId = line.company_warehouse_id;
  if (!warehouseId) {
    line.stock_quantity_hint = null;
    line.warehouse_deduct_on_issue = null;
    return;
  }
  const warehouse = warehouses.value.find((w) => w.id === warehouseId);
  line.warehouse_deduct_on_issue = warehouse?.deduct_on_issue ?? null;
  if (line.company_stock_item_id && line.stock_quantities_by_warehouse) {
    const qty = line.stock_quantities_by_warehouse[warehouseId];
    line.stock_quantity_hint = qty != null ? Number(qty) : null;
  }
}

function clearLineStockLink(line: InvoiceLineForm) {
  line.company_stock_item_id = null;
  line.stock_quantity_hint = null;
  line.stock_quantities_by_warehouse = {};
}

const documentCurrencyOptions = computed(() =>
  companyCurrencyOptions(form.currency || company.value?.default_currency)
);

const lineTaxColumnLabel = computed(() =>
  isUsCompany.value ? t('invoicing.sales_tax') : t('invoicing.vat')
);

const newTitle = computed(() => {
  if (isProforma.value) return t('invoicing.new_proforma');
  if (isQuote.value) return t('invoicing.new_quote');
  if (isCreditNote.value) return t('invoicing.new_credit_note');
  return t('invoicing.new_invoice');
});
const editTitle = computed(() => {
  if (isProforma.value) return t('invoicing.edit_proforma');
  if (isQuote.value) return t('invoicing.edit_quote');
  if (isCreditNote.value) return t('invoicing.edit_credit_note');
  return t('invoicing.edit_invoice');
});

const backTo = computed(() =>
  isNew.value
    ? { name: documentRoutes.value.list, params: { companyId: companyId.value } }
    : {
        name: documentRoutes.value.show,
        params: { companyId: companyId.value, documentId: documentId.value },
      }
);

const backLabel = computed(() => {
  if (isNew.value) {
    if (isProforma.value) return t('invoicing.proformas_title');
    if (isQuote.value) return t('invoicing.quotes_title');
    if (isCreditNote.value) return t('invoicing.credit_notes_title');
    return t('invoicing.invoices_title');
  }
  if (isProforma.value) return t('invoicing.view_proforma');
  if (isQuote.value) return t('invoicing.view_quote');
  if (isCreditNote.value) return t('invoicing.view_credit_note');
  return t('invoicing.view_invoice');
});

const discountAmount = computed(() => {
  const { subtotal, tax, total } = calcTotals();
  return subtotal + tax - total;
});

function onContactChange() {
  const c = selectedContact.value;
  if (!c || form.due_date) return;
  const days = isQuote.value ? 14 : Number(c.default_payment_terms_days ?? 14);
  const base = form.issue_date ? new Date(`${form.issue_date}T12:00:00`) : new Date();
  base.setDate(base.getDate() + days);
  form.due_date = base.toISOString().slice(0, 10);
}

function onContactCreated(contact: CompanyContactRow) {
  const id = contact.id;
  if (!id) return;
  contacts.value = [...contacts.value, contact];
  form.company_contact_id = id;
  onContactChange();
}

function addLine() {
  form.lines.push(newLine());
}

async function save(downloadPdf: boolean) {
  if (isLocked.value) return;
  saving.value = true;
  error.value = '';
  try {
    if (localFirst) {
      const docId = await saveLocalDocumentFlow();
      if (!docId) {
        error.value = t('invoicing.company_save_validation_error');
        return;
      }
      if (downloadPdf) {
        const bridgeCompanyId = await resolveEphemeralBridgeCompanyId();
        await downloadEphemeralPdf(
          buildCurrentEphemeralSnapshot(),
          `invoice-${docId}.pdf`,
          bridgeCompanyId,
        );
      }
      router.push({
        name: documentRoutes.value.show,
        params: { companyId: companyId.value, documentId: docId },
      });
      return;
    }

    const wasDraft = isNew.value || documentStatus.value === 'draft';
    let docId = documentId.value;
    if (!isNew.value && docId) {
      await invoicingApi.documents.update(companyId.value, docId, payload());
    } else {
      const created = await invoicingApi.documents.create<{ id: string }>(companyId.value, payload());
      docId = created.id;
    }
    if (wasDraft && docId) {
      const issued = await invoicingApi.documents.action(companyId.value, docId, 'issue');
      await applyDocument(issued);
    }
    if (downloadPdf && docId) {
      const blob = await getWebBlob(businessDocumentPdfPath(companyId.value, docId));
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `invoice-${docId}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    }
    router.push({
      name: documentRoutes.value.show,
      params: { companyId: companyId.value, documentId: docId! },
    });
  } catch (rawError) {
    const e = asApiError(rawError);
    error.value = extractError(e);
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  rememberCompany(companyId.value);
  void loadEfakturaFeature();
  documentType.value =
    documentKind.value === 'proforma'
      ? 'proforma'
      : documentKind.value === 'quote'
        ? 'quote'
        : documentKind.value === 'credit_note'
          ? 'credit_note'
          : documentKind.value === 'delivery_note'
            ? 'delivery_note'
            : documentKind.value === 'order_received'
              ? 'order_received'
              : 'invoice';
  await loadCompanyAndContacts();
  if (documentId.value) {
    await reloadDocument();
  } else {
    await initNewDraft();
    const prefill = route.query.contact as string | undefined;
    if (prefill && contacts.value.some((c) => c.id === prefill)) {
      form.company_contact_id = prefill;
      onContactChange();
    }
  }
});
</script>
