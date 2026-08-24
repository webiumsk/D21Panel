<template>
  <InvoicingPageShell
    content-class="pb-8"
    :title="t('invoicing.accountant_export_title')"
    :subtitle="t('invoicing.accountant_export_subtitle')"
  >
    <template #header>
      <InvoicingAppHeader />
    </template>

    <div v-if="loading" class="py-16 text-center invoicing-muted">
      {{ t("common.loading") }}
    </div>

    <div
      v-else-if="loadError"
      class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-8 text-center"
    >
      <p class="text-sm font-medium text-amber-950">{{ t("invoicing.local_db_load_failed_title") }}</p>
      <p class="mt-2 max-w-md mx-auto text-sm text-amber-900">{{ t("invoicing.local_db_load_failed_detail") }}</p>
      <button type="button" class="invoicing-btn-primary mt-4" @click="retry">
        {{ t("invoicing.local_db_retry") }}
      </button>
    </div>

    <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <section class="invoicing-card invoicing-card-pad space-y-6">
        <div>
          <h2 class="text-base font-semibold text-gray-900">{{ t("invoicing.accountant_export_period") }}</h2>
          <div class="mt-3 flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-xs invoicing-muted">
              <span>{{ t("invoicing.audit_export_period") }}</span>
              <select v-model="periodPreset" class="invoicing-sf-input min-w-[180px]" data-testid="accountant-period">
                <option v-for="opt in periodOptions" :key="opt.value" :value="opt.value">
                  {{ t(opt.labelKey) }}
                </option>
              </select>
            </label>
            <template v-if="period.preset === 'custom'">
              <label class="flex flex-col gap-1 text-xs invoicing-muted">
                <span>{{ t("invoicing.period_from") }}</span>
                <input v-model="period.customFrom" type="date" class="invoicing-sf-input" />
              </label>
              <label class="flex flex-col gap-1 text-xs invoicing-muted">
                <span>{{ t("invoicing.period_to") }}</span>
                <input v-model="period.customTo" type="date" class="invoicing-sf-input" />
              </label>
            </template>
            <p class="text-xs text-gray-500 self-center">{{ range.from }} - {{ range.to }}</p>
          </div>
        </div>

        <div>
          <h2 class="text-base font-semibold text-gray-900">{{ t("invoicing.accountant_export_formats") }}</h2>
          <div class="mt-3 space-y-2 text-sm text-gray-800">
            <label class="flex items-start gap-2">
              <input v-model="formatPohoda" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600" />
              <span>
                {{ t("invoicing.accountant_export_format_pohoda") }}
                <span class="block text-xs text-gray-500">{{ t("invoicing.accountant_export_format_pohoda_hint") }}</span>
              </span>
            </label>
            <label class="flex items-start gap-2">
              <input v-model="formatCsv" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600" />
              <span>
                {{ t("invoicing.accountant_export_format_csv") }}
                <span class="block text-xs text-gray-500">{{ t("invoicing.accountant_export_format_csv_hint") }}</span>
              </span>
            </label>
          </div>
        </div>

        <div>
          <h2 class="text-base font-semibold text-gray-900">{{ t("invoicing.accountant_export_contents") }}</h2>
          <div class="mt-3 space-y-2 text-sm text-gray-800">
            <label class="flex items-start gap-2">
              <input v-model="options.includeIsdoc" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600" />
              <span>
                {{ t("invoicing.accountant_export_include_isdoc") }}
                <span class="block text-xs text-gray-500">{{ t("invoicing.accountant_export_kros_note") }}</span>
              </span>
            </label>
            <label class="flex items-start gap-2">
              <input v-model="options.includePdf" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600" />
              <span>{{ t("invoicing.accountant_export_include_pdf") }}</span>
            </label>
            <label class="flex items-start gap-2">
              <input v-model="options.includeUbl" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600" />
              <span>{{ t("invoicing.accountant_export_include_ubl") }}</span>
            </label>
            <label class="flex items-start gap-2">
              <input v-model="options.includeExpenseAttachments" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600" />
              <span>{{ t("invoicing.accountant_export_include_expense_attachments") }}</span>
            </label>
          </div>
        </div>

        <div class="border-t border-gray-100 pt-4 flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="invoicing-btn-primary"
            :disabled="downloading || !wantsAnything || nothingToExport"
            data-testid="accountant-download"
            @click="download"
          >
            {{ downloading ? t("invoicing.accountant_export_downloading") : t("invoicing.accountant_export_download") }}
          </button>
          <button v-if="downloading" type="button" class="invoicing-btn-secondary" @click="cancel">
            {{ t("common.cancel") }}
          </button>
          <span v-if="downloading && progress && progress.total > 1" class="text-xs text-gray-500">
            {{ t("invoicing.accountant_export_progress", { done: progress.done, total: progress.total }) }}
          </span>
          <p v-if="!wantsAnything" class="text-xs text-amber-700">{{ t("invoicing.accountant_export_nothing_selected") }}</p>
          <p v-else-if="nothingToExport" class="text-xs text-amber-700">{{ t("invoicing.accountant_export_empty_period") }}</p>
          <p v-if="error" class="text-sm text-red-600 w-full">{{ error }}</p>
        </div>
      </section>

      <aside class="space-y-4">
        <section v-if="plan" class="invoicing-card invoicing-card-pad" data-testid="accountant-preview">
          <h2 class="text-sm font-semibold text-gray-900">{{ t("invoicing.accountant_export_preview_title") }}</h2>
          <dl class="mt-3 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-gray-500">{{ t("invoicing.accountant_export_preview_documents") }}</dt>
            <dd class="text-right tabular-nums text-gray-900">{{ plan.documents }}</dd>
            <dt class="text-gray-500">{{ t("invoicing.accountant_export_preview_expenses") }}</dt>
            <dd class="text-right tabular-nums text-gray-900">{{ plan.expenses }}</dd>
            <dt class="text-gray-500">{{ t("invoicing.accountant_export_preview_attachments") }}</dt>
            <dd class="text-right tabular-nums text-gray-900">{{ plan.attachments }} ({{ formatBytes(plan.attachmentBytes) }})</dd>
          </dl>
          <p v-if="plan.skippedAttachments > 0" class="mt-2 text-xs text-amber-700">
            {{ t("invoicing.accountant_export_preview_skipped", { count: plan.skippedAttachments }) }}
          </p>
          <p v-if="plan.ranges.length > 1" class="mt-2 text-xs text-gray-600">
            {{ t("invoicing.accountant_export_chunked", { count: plan.ranges.length }) }}
          </p>
        </section>

        <section class="invoicing-card invoicing-card-pad text-sm text-gray-700 space-y-2">
          <h2 class="text-sm font-semibold text-gray-900">{{ t("invoicing.accountant_export_howto_title") }}</h2>
          <p>{{ t("invoicing.accountant_export_howto_pohoda") }}</p>
          <p>{{ t("invoicing.accountant_export_howto_kros") }}</p>
          <p class="text-xs text-gray-500">{{ t("invoicing.accountant_export_privacy_note") }}</p>
        </section>
      </aside>
    </div>
  </InvoicingPageShell>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import { useI18n } from "vue-i18n";
import InvoicingPageShell from "../../components/invoicing/InvoicingPageShell.vue";
import InvoicingAppHeader from "../../components/invoicing/InvoicingAppHeader.vue";
import { useAccountantExport } from "../../composables/useAccountantExport";
import { useInvoicingCompany } from "../../composables/useInvoicingCompany";
import type { IssuePeriodPreset } from "../../composables/useInvoicingIssuePeriod";
import type { AccountantExportFormat } from "../../evolu/accountantExportSelect";

const { t } = useI18n();
const route = useRoute();
const companyId = computed(() => route.params.companyId as string);
const { company } = useInvoicingCompany(companyId);
const companyName = computed(() => (company.value?.trade_name || company.value?.legal_name) as string | null | undefined);

const {
  period,
  options,
  range,
  plan,
  nothingToExport,
  wantsAnything,
  loading,
  loadError,
  downloading,
  progress,
  error,
  download,
  cancel,
  retry,
} = useAccountantExport(companyId, companyName);

const periodOptions: { value: IssuePeriodPreset; labelKey: string }[] = [
  { value: "last_month", labelKey: "invoicing.period_last_month" },
  { value: "this_month", labelKey: "invoicing.period_this_month" },
  { value: "last_quarter", labelKey: "invoicing.period_last_quarter" },
  { value: "this_quarter", labelKey: "invoicing.period_this_quarter" },
  { value: "last_year", labelKey: "invoicing.period_last_year" },
  { value: "this_year", labelKey: "invoicing.period_this_year" },
  { value: "custom", labelKey: "invoicing.period_custom" },
];

const periodPreset = computed<IssuePeriodPreset>({
  get: () => period.value.preset,
  set: (value) => {
    period.value = { ...period.value, preset: value };
  },
});

function formatToggle(format: AccountantExportFormat) {
  return computed<boolean>({
    get: () => options.value.formats.includes(format),
    set: (on) => {
      const next = options.value.formats.filter((f) => f !== format);
      options.value = { ...options.value, formats: on ? [...next, format] : next };
    },
  });
}
const formatPohoda = formatToggle("pohoda");
const formatCsv = formatToggle("csv");

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>
