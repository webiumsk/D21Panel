import type { Evolu } from "@evolu/common/local-first";
import type { DocumentAdvancedFilters } from "@/composables/useInvoicingDocumentListFilters";
import type { IssuePeriodState } from "@/composables/useInvoicingIssuePeriod";
import {
    cancelLocalDocument,
    cancelLocalDocumentAsync,
    deleteLocalDocument,
    markLocalDocumentPaid,
} from "./documentCrud";
import { syncNumberSeriesCounterFromDocuments } from "./numberSeriesCrud";
import {
    filterLocalDocumentRows,
    type LocalDocumentFilterOptions,
} from "./documentListFilters";
import type { EvoluDocumentRow } from "./documentMap";
import type { EvoluNumberSeriesRow } from "./numberSeriesMap";
import type { CompanyId, DocumentId, DocumentType, InvoicingLocalSchema } from "./schema";

export type BulkResult = {
    processed: number;
    skipped: number;
};

export type ResolveBulkTargetsOptions = LocalDocumentFilterOptions & {
    companyId: CompanyId;
    selectAll: boolean;
    selectedIds: Iterable<string>;
    allDocuments: EvoluDocumentRow[];
};

export type LocalDocumentDeletionPolicy = {
    jurisdictionByCompanyId?: ReadonlyMap<string, string | null | undefined>;
};

export function resolveBulkTargets(options: ResolveBulkTargetsOptions): EvoluDocumentRow[] {
    const { companyId, selectAll, selectedIds, allDocuments, ...filterOpts } = options;

    const companyDocs = allDocuments.filter((row) => row.companyId === companyId);

    if (selectAll) {
        return filterLocalDocumentRows(companyDocs, filterOpts);
    }

    const idSet = new Set(selectedIds);
    return companyDocs.filter((row) => idSet.has(row.id));
}

export function hasBlockingRelations(
    doc: EvoluDocumentRow,
    allDocuments: EvoluDocumentRow[],
): boolean {
    return allDocuments.some(
        (other) =>
            other.sourceDocumentId === doc.id
            && other.status !== "cancelled",
    );
}

export function isLatestForCompanyType(
    doc: EvoluDocumentRow,
    allDocuments: EvoluDocumentRow[],
): boolean {
    const sameCompanyType = allDocuments.filter(
        (row) => row.companyId === doc.companyId && row.documentType === doc.documentType,
    );
    if (sameCompanyType.length === 0) {
        return true;
    }

    const sorted = [...sameCompanyType].sort((a, b) => {
        const aDate = a.issueDate || "";
        const bDate = b.issueDate || "";
        if (aDate !== bDate) return bDate.localeCompare(aDate);
        return String(b.id).localeCompare(String(a.id));
    });

    return sorted[0]?.id === doc.id;
}

export function canDeleteLocalDocument(
    doc: EvoluDocumentRow,
    allDocuments: EvoluDocumentRow[],
    policy: LocalDocumentDeletionPolicy = {},
): boolean {
    if (hasBlockingRelations(doc, allDocuments)) {
        return false;
    }

    if (
        doc.status !== "draft"
        && policy.jurisdictionByCompanyId?.get(String(doc.companyId)) === "eu_de"
    ) {
        return false;
    }

    if (doc.status === "draft" || doc.status === "cancelled") {
        return true;
    }

    if (doc.status === "issued" || doc.status === "paid") {
        return isLatestForCompanyType(doc, allDocuments);
    }

    return false;
}

export function canCancelLocalDocument(doc: EvoluDocumentRow): boolean {
    return doc.status === "issued" || doc.status === "paid";
}

export function bulkMarkPaidLocal(
    evolu: Evolu<InvoicingLocalSchema>,
    rows: EvoluDocumentRow[],
    allDocuments: EvoluDocumentRow[],
): BulkResult {
    let processed = 0;
    let skipped = 0;

    for (const row of rows) {
        if (row.status !== "issued") {
            skipped++;
            continue;
        }
        markLocalDocumentPaid(evolu, row.id, allDocuments);
        processed++;
    }

    return { processed, skipped };
}

/**
 * Newest-first order so a selected contiguous tail of the sequence can be
 * deleted in one bulk run: after the newest falls, the next one becomes the
 * latest and passes the only-latest-deletable guard.
 */
export function sortRowsForSequentialDelete(rows: EvoluDocumentRow[]): EvoluDocumentRow[] {
    return [...rows].sort((a, b) => {
        const aDate = a.issueDate || "";
        const bDate = b.issueDate || "";
        if (aDate !== bDate) return bDate.localeCompare(aDate);
        const aNumber = String(a.number ?? "");
        const bNumber = String(b.number ?? "");
        // Numeric collation: FV-10 must sort after FV-2, not before it.
        if (aNumber !== bNumber) return bNumber.localeCompare(aNumber, undefined, { numeric: true });
        return String(b.id).localeCompare(String(a.id));
    });
}

export async function bulkDeleteLocal(
    evolu: Evolu<InvoicingLocalSchema>,
    rows: EvoluDocumentRow[],
    allDocuments: EvoluDocumentRow[],
    allSeries?: EvoluNumberSeriesRow[],
    policy: LocalDocumentDeletionPolicy = {},
): Promise<BulkResult> {
    let processed = 0;
    let skipped = 0;
    const deletedIds = new Set<DocumentId>();
    const issuedDeletes: EvoluDocumentRow[] = [];
    // The guard evaluates "is latest" against the documents that still
    // exist - shrink the working set as rows fall so a chain of the newest
    // invoices deletes in one pass (gapless numbering, P3).
    let remainingDocs = allDocuments;
    const { releaseIssuedNumber } = await import("./numberReleaseBridge");

    for (const row of sortRowsForSequentialDelete(rows)) {
        if (!canDeleteLocalDocument(row, remainingDocs, policy)) {
            skipped++;
            continue;
        }

        if (row.status !== "draft" && row.status !== "cancelled" && row.number) {
            const release = await releaseIssuedNumber(
                row.companyId,
                String(row.documentType),
                String(row.number),
            );
            if (!release.ok) {
                skipped++;
                continue;
            }
        }

        const result = deleteLocalDocument(evolu, row.id);
        if (!result.ok) {
            skipped++;
            continue;
        }
        deletedIds.add(row.id);
        remainingDocs = remainingDocs.filter((docRow) => docRow.id !== row.id);
        if (row.status !== "draft" && row.status !== "cancelled" && row.number) {
            issuedDeletes.push(row);
        }
        processed++;
    }

    if (allSeries && issuedDeletes.length > 0) {
        const remaining = allDocuments.filter((row) => !deletedIds.has(row.id));
        const synced = new Set<string>();
        for (const row of issuedDeletes) {
            const key = `${row.companyId}:${row.documentType}`;
            if (synced.has(key)) continue;
            synced.add(key);
            syncNumberSeriesCounterFromDocuments(
                evolu,
                row.companyId,
                row.documentType as DocumentType,
                remaining,
                allSeries,
            );
        }
    }

    return { processed, skipped };
}

export async function bulkCancelLocalAsync(
    evolu: Evolu<InvoicingLocalSchema>,
    rows: EvoluDocumentRow[],
    allDocuments: EvoluDocumentRow[],
): Promise<BulkResult> {
    let processed = 0;
    let skipped = 0;

    for (const row of rows) {
        if (!canCancelLocalDocument(row)) {
            skipped++;
            continue;
        }
        await cancelLocalDocumentAsync(evolu, row.id, allDocuments);
        processed++;
    }

    return { processed, skipped };
}

export function bulkCancelLocal(
    evolu: Evolu<InvoicingLocalSchema>,
    rows: EvoluDocumentRow[],
): BulkResult {
    let processed = 0;
    let skipped = 0;

    for (const row of rows) {
        if (!canCancelLocalDocument(row)) {
            skipped++;
            continue;
        }
        cancelLocalDocument(evolu, row.id);
        processed++;
    }

    return { processed, skipped };
}

export type CsvExportRow = {
    number: string;
    status: string;
    client: string;
    total: string;
    currency: string;
    issueDate: string;
    dueDate: string;
    variableSymbol: string;
};

export function buildDocumentsCsvBlob(rows: CsvExportRow[]): Blob {
    const header = [
        "Number",
        "Status",
        "Client",
        "Total",
        "Currency",
        "Issue date",
        "Due date",
        "Variable symbol",
    ];

    const escape = (value: string) => {
        const v = value.replace(/"/g, '""');
        return `"${v}"`;
    };

    const lines = [
        header.map(escape).join(","),
        ...rows.map((row) =>
            [
                row.number,
                row.status,
                row.client,
                row.total,
                row.currency,
                row.issueDate,
                row.dueDate,
                row.variableSymbol,
            ]
                .map((cell) => escape(String(cell ?? "")))
                .join(","),
        ),
    ];

    const bom = "\uFEFF";
    return new Blob([bom + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
}

export function downloadCsvBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

export function localBulkFilterOptions(
    nav: { kind: string; apiType?: string },
    statusFilter: string,
    issuePeriod: IssuePeriodState,
    advanced: DocumentAdvancedFilters,
    contactNameById?: Map<string, string>,
): LocalDocumentFilterOptions {
    return {
        apiType: nav.kind === "drafts" ? undefined : nav.apiType,
        statusFilter: nav.kind === "drafts" ? "draft" : statusFilter,
        issuePeriod,
        advanced,
        contactNameById,
    };
}
