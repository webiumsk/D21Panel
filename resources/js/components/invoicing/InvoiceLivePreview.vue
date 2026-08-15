<template>
  <div
    class="invoice-preview flex min-h-[720px] flex-col bg-white text-gray-900 rounded-lg shadow-lg text-sm p-8"
  >
    <div class="flex-1">
    <div class="grid grid-cols-3 gap-4 mb-3">
      <div class="text-[10px] leading-relaxed text-gray-700">
        <div
          class="text-[9px] uppercase font-bold text-gray-500 mb-1 tracking-wide"
        >
          {{ t("invoicing.preview_supplier") }}
        </div>
        <strong class="text-sm text-gray-900">{{ companyDisplayName }}</strong>
        <div v-if="company?.street">{{ company.street }}</div>
        <div v-if="company?.postal_code || company?.city">
          {{ company.postal_code }} {{ company.city
          }}<span v-if="company?.country">, {{ company.country }}</span>
        </div>
        <div v-if="company?.registration_number">
          {{ t("invoicing.registration_number") }}:
          {{ company.registration_number }}
        </div>
        <div v-if="company?.tax_id">
          {{ t("invoicing.tax_id_dic") }}: {{ company.tax_id }}
        </div>
        <div v-if="company?.vat_number">
          {{ t("invoicing.vat_number_ic_dph") }}: {{ company.vat_number }}
        </div>
        <div v-if="company?.commercial_register" class="text-gray-500">
          {{ company.commercial_register }}
        </div>
      </div>
      <div class="flex items-center justify-center">
        <img
          v-if="company?.logo_url"
          :src="company.logo_url"
          alt=""
          class="max-h-14 max-w-[160px] object-contain"
        />
      </div>
      <div class="text-right">
        <div class="text-2xl font-bold text-gray-900 leading-tight">
          {{ invoiceHeading }}
        </div>
        <div v-if="form.variable_symbol" class="text-xs text-gray-600 mt-1">
          {{ t("invoicing.variable_symbol") }}:
          <strong>{{ form.variable_symbol }}</strong>
        </div>
      </div>
    </div>

    <hr class="border-0 border-t border-dotted border-gray-300 my-3" />

    <div class="grid grid-cols-2 gap-6 text-xs mb-3">
      <div class="text-gray-700 space-y-0.5">
        <div v-if="company?.bank_name">
          <span class="text-gray-500">{{ t("invoicing.bank_name") }}:</span>
          {{ company.bank_name }}
        </div>
        <div v-if="company?.iban">
          <span class="text-gray-500">IBAN:</span>
          {{ formatIban(company.iban) }}
        </div>
        <div v-if="company?.bank_account">
          <span class="text-gray-500">{{ t("invoicing.bank_account") }}:</span>
          {{ company.bank_account
          }}<span v-if="company.bank_code"> / {{ company.bank_code }}</span>
        </div>
        <div v-if="form.variable_symbol">
          <span class="text-gray-500"
            >{{ t("invoicing.variable_symbol") }}:</span
          >
          {{ form.variable_symbol }}
        </div>
        <div v-if="form.constant_symbol">
          <span class="text-gray-500"
            >{{ t("invoicing.constant_symbol") }}:</span
          >
          {{ form.constant_symbol }}
        </div>
      </div>
      <div>
        <div
          class="text-[9px] uppercase font-bold text-gray-500 mb-1 tracking-wide"
        >
          {{ t("invoicing.preview_customer") }}
        </div>
        <template v-if="selectedContact">
          <strong class="text-sm text-gray-900">{{
            selectedContact.name
          }}</strong>
          <div v-if="selectedContact.street">{{ selectedContact.street }}</div>
          <div v-if="selectedContact.postal_code || selectedContact.city">
            {{ selectedContact.postal_code }} {{ selectedContact.city }}
          </div>
          <div v-if="selectedContact.email">{{ selectedContact.email }}</div>
        </template>
        <div v-else class="text-gray-400">-</div>
        <table class="w-full mt-3 text-xs">
          <tbody>
            <tr>
              <td class="text-right pr-2 text-gray-500">
                {{ t("invoicing.issue_date") }}:
              </td>
              <td class="text-right font-semibold">
                {{ formatDate(form.issue_date) }}
              </td>
            </tr>
            <!-- DE §14 UStG: the Leistungsdatum must be stated even when it
                 equals the issue date. -->
            <tr v-if="form.delivery_date || isDeCompany">
              <td class="text-right pr-2 text-gray-500">
                {{ t("invoicing.delivery_date") }}:
              </td>
              <td class="text-right font-semibold">
                {{ formatDate(form.delivery_date || form.issue_date) }}
              </td>
            </tr>
            <tr v-if="form.due_date">
              <td class="text-right pr-2 text-gray-500">
                {{
                  isQuote
                    ? t("invoicing.valid_until")
                    : t("invoicing.due_date")
                }}:
              </td>
              <td class="text-right font-semibold">
                {{ formatDate(form.due_date) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <hr class="border-0 border-t border-dotted border-gray-300 my-3" />

    <p v-if="reverseChargeNote" class="text-xs font-semibold text-gray-800 mb-3">
      {{ reverseChargeNote }}
    </p>

    <p
      v-if="form.note_above_lines"
      class="text-xs text-gray-600 mb-3 whitespace-pre-wrap"
    >
      {{ form.note_above_lines }}
    </p>

    <table class="w-full border-collapse text-xs mb-4">
      <thead>
        <tr
          class="border-b-2 border-gray-800 text-[9px] uppercase text-gray-600"
        >
          <th class="text-left py-2 font-bold">
            {{ t("invoicing.col_item") }}
          </th>
          <th class="text-right py-2 font-bold w-16">
            {{ t("invoicing.col_qty") }}
          </th>
          <th class="text-center py-2 font-bold w-14">
            {{ t("invoicing.col_unit") }}
          </th>
          <th class="text-right py-2 font-bold w-24">
            {{ t("invoicing.col_unit_price") }}
          </th>
          <th class="text-right py-2 font-bold w-24">
            {{ t("invoicing.col_line_total") }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(line, idx) in form.lines"
          :key="idx"
          class="border-b border-gray-200"
        >
          <td class="py-2.5 pr-2">
            <div class="font-semibold text-gray-900">
              {{ line.name || "-" }}
            </div>
            <div
              v-if="line.description"
              class="text-gray-500 text-[10px] mt-0.5"
            >
              {{ line.description }}
            </div>
          </td>
          <td class="text-right py-2.5 tabular-nums">{{ line.quantity }}</td>
          <td class="text-center py-2.5">{{ line.unit }}</td>
          <td class="text-right py-2.5 tabular-nums">
            {{ formatMoney(line.unit_price) }} {{ form.currency }}
          </td>
          <td class="text-right py-2.5 font-semibold tabular-nums">
            {{ formatMoney(lineTotal(line)) }} {{ form.currency }}
          </td>
        </tr>
      </tbody>
    </table>

    <div class="flex gap-8 mt-2">
      <div v-if="form.note_footer" class="flex-1 text-xs text-gray-600">
        <div class="text-[9px] uppercase font-bold text-gray-500 mb-1">
          {{ t("invoicing.note_short") }}
        </div>
        <div class="whitespace-pre-wrap">{{ form.note_footer }}</div>
      </div>
      <div class="w-60 shrink-0 ml-auto space-y-1 text-xs">
        <div
          v-if="form.discount_percent > 0"
          class="flex justify-between text-gray-600"
        >
          <span
            >{{ t("invoicing.discount") }} {{ form.discount_percent }}%</span
          >
        </div>
        <div v-if="showTaxSummary" class="flex justify-between text-gray-600">
          <span>{{ t("invoicing.subtotal") }}</span>
          <span>{{ formatMoney(totals.subtotal) }} {{ form.currency }}</span>
        </div>
        <template v-if="showTaxSummary && totals.tax_breakdown?.length">
          <div
            v-for="(row, idx) in totals.tax_breakdown"
            :key="idx"
            class="flex justify-between text-gray-600"
          >
            <span>{{ row.label || t("invoicing.sales_tax") }}</span>
            <span
              >{{ formatMoney(parseFloat(row.tax_amount)) }}
              {{ form.currency }}</span
            >
          </div>
        </template>
        <div
          v-else-if="showTaxSummary"
          class="flex justify-between text-gray-600"
        >
          <span>{{
            isUsCompany ? t("invoicing.sales_tax") : t("invoicing.vat")
          }}</span>
          <span>{{ formatMoney(totals.tax) }} {{ form.currency }}</span>
        </div>
        <div
          class="flex justify-between font-bold text-base border-t border-gray-300 pt-2"
        >
          <span>{{ t("invoicing.grand_total") }}</span>
          <span>{{ formatMoney(totals.total) }} {{ form.currency }}</span>
        </div>
        <template v-if="!isQuote && form.pdf_show_payment_info && isPaid">
          <div class="flex justify-between text-emerald-700">
            <span>{{ t("invoicing.paid_amount") }}</span>
            <span>{{ formatMoney(amountPaid) }} {{ form.currency }}</span>
          </div>
          <div class="flex justify-between font-semibold">
            <span>{{ t("invoicing.amount_due_sum") }}</span>
            <span>0,00 {{ form.currency }}</span>
          </div>
        </template>
        <div
          v-else-if="!isQuote && form.payment_bank_enabled"
          class="flex justify-between font-semibold"
        >
          <span>{{ t("invoicing.amount_due_sum") }}</span>
          <span>{{ formatMoney(amountDue) }} {{ form.currency }}</span>
        </div>
      </div>
    </div>

    <div v-if="form.pdf_show_signature" class="mt-6 flex justify-end">
      <img
        v-if="company?.signature_stamp_url"
        :src="company.signature_stamp_url"
        alt=""
        class="max-h-[72px] max-w-[220px] object-contain"
      />
      <template v-else>
        <div class="text-xs text-gray-400 text-left">
          <div>{{ t("invoicing.signature_stamp") }}</div>
          <div class="h-10 border-b border-gray-300 w-48 mt-1"></div>
        </div>
      </template>
    </div>

    <div
      v-if="!isQuote && form.payment_bank_enabled && company?.iban"
      class="mt-5 grid grid-cols-4 gap-2 rounded border border-sky-200 bg-sky-100 px-3 py-2.5 text-[10px] text-sky-950"
    >
      <div>
        <div class="text-[8px] uppercase font-bold text-sky-700 mb-0.5">
          IBAN
        </div>
        <div class="font-medium">{{ formatIban(company.iban) }}</div>
      </div>
      <div v-if="form.variable_symbol">
        <div class="text-[8px] uppercase font-bold text-sky-700 mb-0.5">
          {{ t("invoicing.variable_symbol") }}
        </div>
        <div class="font-medium">{{ form.variable_symbol }}</div>
      </div>
      <div v-if="form.due_date">
        <div class="text-[8px] uppercase font-bold text-sky-700 mb-0.5">
          {{ t("invoicing.due_date") }}
        </div>
        <div class="font-medium">{{ formatDate(form.due_date) }}</div>
      </div>
      <div>
        <div class="text-[8px] uppercase font-bold text-sky-700 mb-0.5">
          {{ t("invoicing.amount_due_sum") }}
        </div>
        <div class="text-sm font-bold">
          {{ formatMoney(amountDue) }} {{ form.currency }}
        </div>
      </div>
    </div>

    </div>

    <div class="mt-auto shrink-0 pt-4">
    <div class="border-t border-dotted border-gray-300 pt-3">
      <div
        v-if="corporateFooterLine"
        class="mb-1.5 text-center text-[9px] text-gray-500"
      >
        {{ corporateFooterLine }}
      </div>
      <div class="grid grid-cols-4 gap-2 text-[10px] text-gray-600">
        <div v-if="company?.issuer_name" class="text-left">
          <span class="font-semibold text-gray-800">{{ t('invoicing.issued_by') }}:</span>
          {{ company.issuer_name }}
        </div>
        <div v-if="company?.issuer_phone" class="flex items-center justify-center gap-1.5 min-w-0">
          <FooterContactIcon icon="phone" />
          <a :href="phoneHref" class="text-gray-600 no-underline truncate">{{ company.issuer_phone }}</a>
        </div>
        <div v-if="company?.website" class="flex items-center justify-center gap-1.5 min-w-0">
          <FooterContactIcon icon="web" />
          <a :href="websiteHref" class="text-gray-600 no-underline truncate" target="_blank" rel="noopener">{{ displayWebsite }}</a>
        </div>
        <div v-if="company?.issuer_email" class="flex items-center justify-end gap-1.5 min-w-0">
          <FooterContactIcon icon="email" />
          <a :href="emailHref" class="text-gray-600 no-underline truncate">{{ company.issuer_email }}</a>
        </div>
      </div>
      <div class="mt-2 grid grid-cols-4 items-center text-[9px] text-gray-400">
        <span />
        <p class="col-span-2 text-center">{{ t('invoicing.created_with_satflux') }}</p>
        <p class="text-right text-[7px] text-gray-300">{{ t('invoicing.page_number') }} 1/1</p>
      </div>
    </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import {
  DE_EXPORT_GOODS_NOTE,
  DE_EXPORT_SERVICES_NOTE,
  DE_KLEINUNTERNEHMER_NOTE,
  DE_REVERSE_CHARGE_NOTE,
  useCompanyVatPolicy,
} from "../../composables/useCompanyVatPolicy";
import { appSettingsFromCompany } from "../../composables/useCompanyAppSettings";
import FooterContactIcon from "./FooterContactIcon.vue";

export type InvoiceLineForm = {
  name: string;
  description: string;
  quantity: number;
  unit: string;
  unit_price: number;
  line_discount_percent: number;
  tax_rate: number;
  company_stock_item_id?: string | null;
  company_warehouse_id?: string | null;
  stock_quantity_hint?: number | null;
  stock_quantities_by_warehouse?: Record<string, number>;
  warehouse_deduct_on_issue?: boolean | null;
};

export type InvoicePreviewForm = {
  title: string;
  number: string;
  issue_date: string;
  delivery_date: string;
  due_date: string;
  variable_symbol: string;
  constant_symbol?: string;
  currency: string;
  discount_percent: number;
  note_above_lines: string;
  note_footer: string;
  payment_bank_enabled: boolean;
  pdf_show_signature: boolean;
  pdf_show_payment_info: boolean;
  lines: InvoiceLineForm[];
};

const props = withDefaults(
  defineProps<{
    company: Record<string, any> | null;
    selectedContact: Record<string, any> | null;
    form: InvoicePreviewForm;
    totals: {
      subtotal: number;
      tax: number;
      total: number;
      tax_breakdown?: { label?: string | null; tax_amount: string }[];
    };
    documentStatus: string;
    documentType?: "invoice" | "proforma" | "quote" | "credit_note";
    amountPaid?: number | null;
  }>(),
  { documentType: "invoice" },
);

const { t, locale } = useI18n();

const companyDisplayName = computed(
  () => props.company?.trade_name || props.company?.legal_name || "",
);

const vatPolicy = useCompanyVatPolicy();
const isUsCompany = computed(() => props.company?.jurisdiction === "us");
// §4 payer / US: always; non-payer: never; §7a: only EU counterparties
// (VAT 0 next to the reverse-charge note).
const showTaxSummary = computed(() =>
  vatPolicy.showsVatSummary(props.company, props.selectedContact),
);
const isDeCompany = computed(() => vatPolicy.isDeCompany(props.company));
// DE Geschaeftsbrief corporate footer - statutory German labels on purpose.
// DE-only: a company switched away from eu_de must not carry German
// statutory wording even while the field values are preserved. Mirrors the
// guard in pdf/partials/business-invoice-footer.blade.php.
const corporateFooterLine = computed(() => {
  if (!isDeCompany.value) return null;
  const c = props.company;
  const register = [c?.register_court, c?.register_number].filter(Boolean).join(" ");
  const parts = [
    register || null,
    c?.managing_directors ? `Geschäftsführer: ${c.managing_directors}` : null,
    c?.supervisory_board_chair ? `Vorsitzender des Aufsichtsrats: ${c.supervisory_board_chair}` : null,
  ].filter(Boolean);
  if (parts.length === 0) return null;
  const seat = c?.city ? ` · Sitz: ${c.city}` : "";
  return `${c?.legal_name ?? ""}${seat} · ${parts.join(" · ")}`;
});
// Statutory clause (mirrors the server taxClause): DE texts stay German
// regardless of the UI locale; custom company notes win over defaults.
const reverseChargeNote = computed(() => {
  const settings = appSettingsFromCompany(props.company);
  const kind = vatPolicy.taxClauseKind(props.company, props.selectedContact, settings);
  if (!kind) return null;
  if (kind === "kleinunternehmer_de") {
    return DE_KLEINUNTERNEHMER_NOTE;
  }
  if (kind === "export_de") {
    return (
      settings.export_note.trim() ||
      (settings.export_goods ? DE_EXPORT_GOODS_NOTE : DE_EXPORT_SERVICES_NOTE)
    );
  }
  const custom = settings.reverse_charge ? settings.reverse_charge_note.trim() : "";
  if (isDeCompany.value) {
    // The mandatory German wording always stays; a custom note appends.
    return custom ? `${DE_REVERSE_CHARGE_NOTE} ${custom}` : DE_REVERSE_CHARGE_NOTE;
  }
  return custom || t("invoicing.reverse_charge_note_partial");
});

const invoiceHeading = computed(() => {
  if (props.form.title) return props.form.title;
  const prefixKey =
    props.documentType === "proforma"
      ? "invoicing.proforma_title_prefix"
      : props.documentType === "quote"
        ? "invoicing.quote_title_prefix"
        : props.documentType === "credit_note"
          ? "invoicing.credit_note_title_prefix"
          : "invoicing.invoice_title_prefix";
  const newKey =
    props.documentType === "proforma"
      ? "invoicing.new_proforma"
      : props.documentType === "quote"
        ? "invoicing.new_quote"
        : props.documentType === "credit_note"
          ? "invoicing.new_credit_note"
          : "invoicing.new_invoice";
  if (props.form.number) return `${t(prefixKey)} ${props.form.number}`;
  return t(newKey);
});

const isQuote = computed(() => props.documentType === "quote");
const isPaid = computed(() => props.documentStatus === "paid");

const amountPaid = computed(() => props.amountPaid ?? props.totals.total);
const amountDue = computed(() =>
  isPaid.value ? 0 : Math.max(0, props.totals.total - (props.amountPaid ?? 0)),
);

const displayWebsite = computed(() =>
  props.company?.website?.replace(/^https?:\/\//i, "").replace(/\/$/, "") ?? "",
);

const phoneHref = computed(() => {
  const phone = props.company?.issuer_phone;
  if (!phone) return "";
  return `tel:${phone.replace(/[^\d+]/g, "")}`;
});

const emailHref = computed(() =>
  props.company?.issuer_email ? `mailto:${props.company.issuer_email}` : "",
);

const websiteHref = computed(() => {
  const website = props.company?.website;
  if (!website) return "";
  return /^https?:\/\//i.test(website) ? website : `https://${website.replace(/^\/+/, "")}`;
});

function lineTotal(line: InvoiceLineForm) {
  const net =
    (line.quantity || 0) *
    (line.unit_price || 0) *
    (1 - (line.line_discount_percent || 0) / 100);
  // The policy resolves the effective rate (0 for non-payers, §7a and EU
  // reverse charge) - showTaxSummary only controls summary rendering.
  const rate = vatPolicy.resolveLineTaxRate(
    props.company,
    props.selectedContact,
    line.tax_rate ?? null,
  );
  return net + net * (rate / 100);
}

function formatMoney(n: number) {
  return Number(n || 0).toLocaleString(locale.value, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatDate(iso: string) {
  if (!iso) return "-";
  const d = new Date(iso.includes("T") ? iso : `${iso}T12:00:00`);
  return d.toLocaleDateString(locale.value);
}

function formatIban(iban: string) {
  const clean = iban.replace(/\s+/g, "");
  return clean.match(/.{1,4}/g)?.join(" ") ?? iban;
}
</script>
