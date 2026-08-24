import { computed, ref, type Ref } from "vue";
import { useQuery } from "@evolu/vue";
import {
    allDocumentsQuery,
    allExpenseAttachmentsQuery,
    allExpensesQuery,
    useInvoicingEvolu,
} from "@/evolu/client";
import { toAppRows } from "@/evolu/queryLoad";
import type { EvoluDocumentRow } from "@/evolu/documentMap";
import type { EvoluExpenseRow } from "@/evolu/expenseMap";
import type { EvoluExpenseAttachmentRow } from "@/evolu/expenseAttachmentCrud";
import { isInvoicingLocalFirst } from "@/evolu/flags";
import {
    buildExpensePayload,
    defaultAccountantExportOptions,
    planAccountantExport,
    selectExpensesForExport,
    selectIssuedDocumentsForExport,
    type AccountantExportOptions,
    type AccountantExportPlan,
    type ExportDateRange,
} from "@/evolu/accountantExportSelect";
import {
    buildAccountantExportEphemeralRequest,
    downloadEphemeralAccountantExport,
} from "@/evolu/ephemeralBridge";
import { downloadCsvBlob } from "@/evolu/documentBulkLocal";
import { useLocalInvoiceDocumentSupport } from "@/composables/useLocalInvoiceDocument";
import {
    defaultIssuePeriodState,
    resolveIssuePeriodRange,
    type IssuePeriodState,
} from "@/composables/useInvoicingIssuePeriod";
import { invoicingApi } from "@/services/api";

const MIN_DATE = "0000-01-01";
const MAX_DATE = "9999-12-31";

function slug(value: string): string {
    return value
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "") || "company";
}

/**
 * "Balík pre účtovníka" page state for both invoicing modes. Local-first
 * builds the transient payload from Evolu (and splits the period by month
 * when it would exceed the server caps); server mode simply downloads the
 * GET endpoint and lets the server select the rows.
 */
export function useAccountantExport(companyId: Ref<string>, companyName: Ref<string | null | undefined>) {
    const localFirst = isInvoicingLocalFirst();
    const period = ref<IssuePeriodState>({ ...defaultIssuePeriodState(), preset: "last_month" });
    const options = ref<AccountantExportOptions>(defaultAccountantExportOptions());
    const downloading = ref(false);
    const progress = ref<{ done: number; total: number } | null>(null);
    const error = ref<string | null>(null);
    const loading = ref(localFirst);
    const loadError = ref(false);

    const range = computed<ExportDateRange>(() => {
        const resolved = resolveIssuePeriodRange(period.value);
        return { from: resolved.from ?? MIN_DATE, to: resolved.to ?? MAX_DATE };
    });

    // ---- local-first data -------------------------------------------------
    const evolu = localFirst ? useInvoicingEvolu() : null;
    const localDoc = localFirst ? useLocalInvoiceDocumentSupport() : null;
    const documentRows = localFirst ? useQuery(allDocumentsQuery) : ref<readonly unknown[]>([]);
    const expenseRows = localFirst ? useQuery(allExpensesQuery) : ref<readonly unknown[]>([]);
    const attachmentRows = localFirst ? useQuery(allExpenseAttachmentsQuery) : ref<readonly unknown[]>([]);

    async function load(): Promise<void> {
        if (!evolu) return;
        loading.value = true;
        loadError.value = false;
        try {
            await Promise.all([
                evolu.loadQuery(allDocumentsQuery),
                evolu.loadQuery(allExpensesQuery),
                evolu.loadQuery(allExpenseAttachmentsQuery),
            ]);
        } catch (e) {
            loadError.value = true;
            console.error("Failed to load accountant export data", e);
        } finally {
            loading.value = false;
        }
    }
    void load();

    const documents = computed(() => toAppRows<EvoluDocumentRow>(documentRows.value));
    const expenses = computed(() => toAppRows<EvoluExpenseRow>(expenseRows.value));
    const attachments = computed(() => toAppRows<EvoluExpenseAttachmentRow>(attachmentRows.value));

    /** Local-first only - server mode has no cheap preview (rows live on the server). */
    const plan = computed<AccountantExportPlan | null>(() => {
        if (!localFirst) return null;
        return planAccountantExport({
            range: range.value,
            documents: documents.value,
            expenses: expenses.value,
            attachments: attachments.value,
            companyId: companyId.value,
            includeAttachments: options.value.includeExpenseAttachments,
        });
    });

    const nothingToExport = computed(
        () => plan.value !== null && plan.value.documents === 0 && plan.value.expenses === 0,
    );

    const wantsAnything = computed(
        () =>
            options.value.formats.length > 0
            || options.value.includePdf
            || options.value.includeIsdoc
            || options.value.includeUbl
            || options.value.includeExpenseAttachments,
    );

    function filename(chunk: ExportDateRange): string {
        return `accountant-${slug(companyName.value ?? "company")}-${chunk.from}_${chunk.to}.zip`;
    }

    async function downloadLocalChunk(chunk: ExportDateRange, signal?: AbortSignal): Promise<void> {
        if (!localDoc) return;
        const documentIds = selectIssuedDocumentsForExport(documents.value, companyId.value, chunk).map((row) => row.id);
        const expensePayloads = selectExpensesForExport(expenses.value, companyId.value, chunk).map(
            (row) => buildExpensePayload(row, attachments.value, options.value.includeExpenseAttachments).payload,
        );
        if (documentIds.length === 0 && expensePayloads.length === 0) return;

        const request = await buildAccountantExportEphemeralRequest(localDoc, companyId.value, {
            documentIds,
            expenses: expensePayloads,
            range: chunk,
            options: options.value,
        });
        if (!request) {
            throw new Error("accountant_export_build_failed");
        }
        await downloadEphemeralAccountantExport(request.body, request.bridgeCompanyId, filename(chunk), { signal });
    }

    async function downloadServer(signal?: AbortSignal): Promise<void> {
        const blob = await invoicingApi.companies.accountantExport(
            companyId.value,
            {
                from: range.value.from,
                to: range.value.to,
                "formats[]": options.value.formats,
                include_pdf: options.value.includePdf ? 1 : 0,
                include_isdoc: options.value.includeIsdoc ? 1 : 0,
                include_ubl: options.value.includeUbl ? 1 : 0,
                include_expense_attachments: options.value.includeExpenseAttachments ? 1 : 0,
            },
            { signal },
        );
        downloadCsvBlob(blob, filename(range.value));
    }

    let abort: AbortController | null = null;

    async function download(): Promise<void> {
        if (downloading.value) return;
        error.value = null;
        downloading.value = true;
        abort = new AbortController();
        try {
            if (!localFirst) {
                progress.value = { done: 0, total: 1 };
                await downloadServer(abort.signal);
                progress.value = { done: 1, total: 1 };
                return;
            }
            const ranges = plan.value?.ranges ?? [range.value];
            progress.value = { done: 0, total: ranges.length };
            for (const chunk of ranges) {
                await downloadLocalChunk(chunk, abort.signal);
                progress.value = { done: progress.value.done + 1, total: ranges.length };
            }
        } catch (e) {
            if ((e as { name?: string })?.name === "CanceledError" || (e as { name?: string })?.name === "AbortError") {
                return;
            }
            error.value = e instanceof Error ? e.message : String(e);
        } finally {
            downloading.value = false;
            abort = null;
        }
    }

    function cancel(): void {
        abort?.abort();
    }

    return {
        localFirst,
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
        retry: load,
    };
}
