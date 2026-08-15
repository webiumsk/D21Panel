<template>
  <InvoicingPageShell
    content-class="pb-8"
    :title="t(isDeCompany ? 'invoicing.audit_title' : 'invoicing.audit_title_generic')"
    :subtitle="t('invoicing.audit_subtitle')"
  >
    <template #header>
      <InvoicingAppHeader />
    </template>

    <template #actions>
      <label class="flex flex-col gap-1 text-xs invoicing-muted">
        <span>{{ t("invoicing.audit_export_period") }}</span>
        <select v-model="periodPreset" class="invoicing-sf-input min-w-[180px]">
          <option v-for="opt in periodOptions" :key="opt.value" :value="opt.value">
            {{ t(opt.labelKey) }}
          </option>
        </select>
      </label>
      <button
        type="button"
        class="invoicing-btn-secondary self-end"
        :disabled="loading || loadError"
        @click="exportZip"
      >
        {{ t("invoicing.audit_export_button") }}
      </button>
    </template>

    <div v-if="loading" class="py-16 text-center invoicing-muted">
      {{ t("common.loading") }}
    </div>

    <div
      v-else-if="loadError"
      class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-8 text-center"
    >
      <p class="text-sm font-medium text-amber-950">
        {{ t("invoicing.local_db_load_failed_title") }}
      </p>
      <p class="mt-2 max-w-md mx-auto text-sm text-amber-900">
        {{ t("invoicing.local_db_load_failed_detail") }}
      </p>
      <button type="button" class="invoicing-btn-primary mt-4" @click="retry">
        {{ t("invoicing.local_db_retry") }}
      </button>
    </div>

    <div v-else class="space-y-6">
      <section class="invoicing-card overflow-hidden">
        <div class="border-b border-gray-200 bg-gray-50 px-5 py-4">
          <h2 class="text-lg font-semibold text-gray-900">{{ t("invoicing.audit_gap_title") }}</h2>
          <p class="text-sm text-gray-500 mt-1">{{ t("invoicing.audit_gap_detail") }}</p>
        </div>

        <div v-if="gapReports.length === 0" class="invoicing-card-pad text-center text-gray-600">
          {{ t("invoicing.audit_gap_empty") }}
        </div>

        <div v-else class="overflow-x-auto">
          <table class="invoice-table w-full text-sm text-left">
            <thead
              class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200"
            >
              <tr>
                <th class="px-5 py-3">{{ t("invoicing.audit_gap_col_series") }}</th>
                <th class="px-5 py-3 text-right">{{ t("invoicing.audit_gap_col_range") }}</th>
                <th class="px-5 py-3 text-right">{{ t("invoicing.audit_gap_col_count") }}</th>
                <th class="px-5 py-3">{{ t("invoicing.audit_gap_col_missing") }}</th>
                <th class="px-5 py-3">{{ t("invoicing.audit_gap_col_cancelled") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="report in gapReports"
                :key="`${report.documentType}-${report.prefix}`"
                class="border-b border-gray-100 text-gray-700"
              >
                <td class="px-5 py-2 font-medium text-gray-900">
                  {{ report.prefix }}
                  <span class="ml-2 text-xs text-gray-500">{{ report.documentType }}</span>
                </td>
                <td class="px-5 py-2 text-right tabular-nums">
                  {{ report.minCounter }}-{{ report.maxCounter }}
                </td>
                <td class="px-5 py-2 text-right tabular-nums">{{ report.numberedCount }}</td>
                <td class="px-5 py-2">
                  <span v-if="report.missingTotal === 0" class="text-xs text-green-700">
                    {{ t("invoicing.audit_gap_ok") }}
                  </span>
                  <!-- Capped preview - the full list lives in the ZIP export
                       report; missingTotal always shows the true count. -->
                  <span
                    v-for="counter in report.missing.slice(0, MISSING_BADGE_LIMIT)"
                    :key="counter"
                    class="mr-1 inline-block rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-800"
                  >
                    {{ counter }}
                  </span>
                  <span
                    v-if="report.missingTotal > MISSING_BADGE_LIMIT"
                    class="text-xs font-medium text-red-700"
                  >
                    +{{ report.missingTotal - MISSING_BADGE_LIMIT }}
                  </span>
                </td>
                <td class="px-5 py-2">
                  <span v-if="report.cancelled.length === 0" class="text-xs text-gray-400">-</span>
                  <span
                    v-for="entry in report.cancelled"
                    :key="entry.documentId"
                    class="mr-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800"
                  >
                    {{ entry.number }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="invoicing-card invoicing-card-pad">
        <h2 class="text-lg font-semibold text-gray-900">{{ t("invoicing.audit_export_title") }}</h2>
        <p class="text-sm text-gray-600 mt-2 max-w-2xl">
          {{ t(isDeCompany ? "invoicing.audit_export_detail" : "invoicing.audit_export_detail_generic") }}
        </p>
      </section>
    </div>
  </InvoicingPageShell>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import { useI18n } from "vue-i18n";
import InvoicingPageShell from "../../components/invoicing/InvoicingPageShell.vue";
import InvoicingAppHeader from "../../components/invoicing/InvoicingAppHeader.vue";
import { useGobdAudit } from "../../composables/useGobdAudit";
import { useInvoicingCompany } from "../../composables/useInvoicingCompany";
import { downloadCsvBlob } from "../../evolu/documentBulkLocal";
import type { IssuePeriodPreset } from "../../composables/useInvoicingIssuePeriod";

const { t } = useI18n();
const route = useRoute();
const companyId = computed(() => route.params.companyId as string);

const { loading, loadError, period, gapReports, buildExportBlob, retry } = useGobdAudit(companyId);

const MISSING_BADGE_LIMIT = 20;
const { company } = useInvoicingCompany(companyId);

// The number-gap check and the export are useful in every jurisdiction -
// only the GoBD/Datenträgerüberlassung framing is German. Non-DE companies
// see the neutral "Audit" wording.
const isDeCompany = computed(() => String(company.value?.jurisdiction ?? '') === 'eu_de');

const periodOptions: { value: IssuePeriodPreset; labelKey: string }[] = [
  { value: "this_month", labelKey: "invoicing.period_this_month" },
  { value: "last_month", labelKey: "invoicing.period_last_month" },
  { value: "this_quarter", labelKey: "invoicing.period_this_quarter" },
  { value: "last_quarter", labelKey: "invoicing.period_last_quarter" },
  { value: "this_year", labelKey: "invoicing.period_this_year" },
  { value: "last_year", labelKey: "invoicing.period_last_year" },
  { value: "all", labelKey: "invoicing.period_all" },
];

const periodPreset = computed<IssuePeriodPreset>({
  get: () => period.value.preset,
  set: (value) => {
    period.value = { ...period.value, preset: value };
  },
});

function exportZip(): void {
  const { blob, range } = buildExportBlob({
    id: companyId.value,
    legal_name: company.value?.legal_name ?? null,
    registration_number: company.value?.registration_number ?? null,
  });
  downloadCsvBlob(blob, `gobd-export-${range.from}_${range.to}.zip`);
}
</script>
