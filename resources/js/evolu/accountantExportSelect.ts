import type { EvoluDocumentRow } from "./documentMap";
import type { EvoluExpenseAttachmentRow } from "./expenseAttachmentCrud";
import type { EvoluExpenseRow } from "./expenseMap";

/**
 * Pure selection / sizing helpers for the "balík pre účtovníka" page
 * (local-first side). The server applies the same rules for server-mode
 * companies (AccountantExportController): issued documents = not draft and
 * numbered, received expenses = not cancelled, both by issue date.
 */

export type ExportDateRange = { from: string; to: string };

export type AccountantExportFormat = "pohoda" | "csv";

export type AccountantExportOptions = {
    formats: AccountantExportFormat[];
    includePdf: boolean;
    includeIsdoc: boolean;
    includeUbl: boolean;
    includeExpenseAttachments: boolean;
};

export function defaultAccountantExportOptions(): AccountantExportOptions {
    return {
        formats: ["pohoda", "csv"],
        includePdf: true,
        includeIsdoc: true,
        includeUbl: false,
        includeExpenseAttachments: true,
    };
}

export type AccountantExportExpensePayload = {
    internal_number: string;
    external_number: string | null;
    supplier_name: string | null;
    variable_symbol: string | null;
    constant_symbol: string | null;
    issue_date: string | null;
    delivery_date: string | null;
    due_date: string | null;
    paid_at: string | null;
    total: string;
    currency: string;
    status: string;
    note: string | null;
    attachments: { filename: string; mime: string; content_base64: string }[];
};

function inRange(date: string | null, range: ExportDateRange): boolean {
    if (!date) return false;
    const day = date.slice(0, 10);
    return day >= range.from && day <= range.to;
}

export function selectIssuedDocumentsForExport(
    rows: readonly EvoluDocumentRow[],
    companyId: string,
    range: ExportDateRange,
): EvoluDocumentRow[] {
    return rows
        .filter(
            (row) =>
                String(row.companyId) === companyId
                && row.status !== "draft"
                && Boolean(row.number)
                && inRange(row.issueDate, range),
        )
        .sort((a, b) => (a.issueDate ?? "").localeCompare(b.issueDate ?? "") || (a.number ?? "").localeCompare(b.number ?? ""));
}

export function selectExpensesForExport(
    rows: readonly EvoluExpenseRow[],
    companyId: string,
    range: ExportDateRange,
): EvoluExpenseRow[] {
    return rows
        .filter(
            (row) =>
                String(row.companyId) === companyId
                && row.status !== "cancelled"
                && inRange(row.issueDate, range),
        )
        .sort((a, b) => (a.issueDate ?? "").localeCompare(b.issueDate ?? "") || a.internalNumber.localeCompare(b.internalNumber));
}

/** Mirrors the server allowlist in EphemeralAccountantExportRequest. */
export const ACCOUNTANT_EXPORT_ATTACHMENT_MIMES = new Set([
    "application/pdf",
    "image/png",
    "image/jpeg",
    "image/webp",
    "application/xml",
    "text/xml",
]);

/** Server-side per-attachment cap on the base64 string (~512 KB decoded). */
export const ACCOUNTANT_EXPORT_MAX_ATTACHMENT_BASE64 = 700_000;
/**
 * Issued documents per ephemeral request. The limit is server render time,
 * not body size: every document in a batch is rendered to PDF (+ ISDOC /
 * UBL) inside one request, and 500 DomPDF renders would approach the 300 s
 * PHP / nginx timeouts. 50 matches the proven bulk PDF ZIP cap and keeps a
 * batch at a few seconds; the JSON snapshot body itself stays far below the
 * 20 MB request limit even at 500 documents.
 */
export const ACCOUNTANT_EXPORT_MAX_DOCUMENTS_PER_BATCH = 50;

export function attachmentDecodedBytes(base64: string): number {
    const padding = base64.endsWith("==") ? 2 : base64.endsWith("=") ? 1 : 0;
    return Math.max(0, Math.floor((base64.length * 3) / 4) - padding);
}

export function buildExpensePayload(
    expense: EvoluExpenseRow,
    attachments: readonly EvoluExpenseAttachmentRow[],
    includeAttachments: boolean,
): { payload: AccountantExportExpensePayload; skippedAttachments: number } {
    let skipped = 0;
    const payloadAttachments: AccountantExportExpensePayload["attachments"] = [];
    if (includeAttachments) {
        for (const row of attachments) {
            if (row.expenseId !== expense.id) continue;
            const mime = row.mimeType ?? "";
            const content = row.contentBase64 ?? "";
            if (
                !content
                || !ACCOUNTANT_EXPORT_ATTACHMENT_MIMES.has(mime)
                || content.length > ACCOUNTANT_EXPORT_MAX_ATTACHMENT_BASE64
            ) {
                skipped++;
                continue;
            }
            payloadAttachments.push({
                filename: row.originalFilename || `${expense.internalNumber}.bin`,
                mime,
                content_base64: content,
            });
        }
    }

    return {
        skippedAttachments: skipped,
        payload: {
            internal_number: expense.internalNumber,
            external_number: expense.externalNumber,
            supplier_name: expense.title,
            variable_symbol: expense.variableSymbol,
            constant_symbol: expense.constantSymbol,
            issue_date: expense.issueDate,
            delivery_date: expense.deliveryDate,
            due_date: expense.dueDate,
            paid_at: expense.paidAt,
            total: expense.total ?? "0",
            currency: expense.currency ?? "EUR",
            status: expense.status,
            note: expense.internalNote,
            attachments: payloadAttachments,
        },
    };
}

export function estimateAttachmentBytes(payloads: readonly AccountantExportExpensePayload[]): number {
    return payloads.reduce(
        (sum, expense) => sum + expense.attachments.reduce((inner, a) => inner + attachmentDecodedBytes(a.content_base64), 0),
        0,
    );
}

export type AccountantExportBatch = {
    /** Label / manifest range: min-max issue date of the rows in the batch. */
    range: ExportDateRange;
    documentIds: string[];
    expenseIds: string[];
    attachmentBytes: number;
};

export type AccountantExportPlan = {
    /** One entry per request; a single entry means no splitting was needed. */
    batches: AccountantExportBatch[];
    documents: number;
    expenses: number;
    attachments: number;
    attachmentBytes: number;
    skippedAttachments: number;
};

type PlannedRow = { kind: "document" | "expense"; id: string; date: string; bytes: number };

/**
 * Split the selection into cap-compliant requests. Rows are consumed in issue
 * date order and a batch closes as soon as the next row would push it over
 * the row caps (documents and expenses are capped separately) or
 * `maxAttachmentBytes`; a single expense above the byte cap still gets its
 * own batch (the server rejects it with 413 and the user sees the error).
 * Record-based batching - unlike date-range chunking - holds even when one
 * day carries more rows than the caps allow. Expenses use the server package
 * row cap, while issued documents are capped per batch by server render
 * time (see ACCOUNTANT_EXPORT_MAX_DOCUMENTS_PER_BATCH). `maxRows` /
 * `maxAttachmentBytes` mirror config/invoicing.php defaults.
 */
export function planAccountantExport(input: {
    range: ExportDateRange;
    documents: readonly EvoluDocumentRow[];
    expenses: readonly EvoluExpenseRow[];
    attachments: readonly EvoluExpenseAttachmentRow[];
    companyId: string;
    includeAttachments: boolean;
    maxRows?: number;
    maxDocumentRows?: number;
    maxAttachmentBytes?: number;
}): AccountantExportPlan {
    const maxRows = Math.max(1, input.maxRows ?? 500);
    const maxDocumentRows = Math.max(
        1,
        input.maxDocumentRows ?? Math.min(maxRows, ACCOUNTANT_EXPORT_MAX_DOCUMENTS_PER_BATCH),
    );
    const maxBytes = Math.max(1, input.maxAttachmentBytes ?? 12 * 1024 * 1024);

    const documents = selectIssuedDocumentsForExport(input.documents, input.companyId, input.range);
    const expenses = selectExpensesForExport(input.expenses, input.companyId, input.range);

    let attachmentCount = 0;
    let skipped = 0;
    const rows: PlannedRow[] = documents.map((row) => ({
        kind: "document",
        id: String(row.id),
        date: (row.issueDate ?? "").slice(0, 10),
        bytes: 0,
    }));
    for (const expense of expenses) {
        const { payload, skippedAttachments } = buildExpensePayload(expense, input.attachments, input.includeAttachments);
        attachmentCount += payload.attachments.length;
        skipped += skippedAttachments;
        rows.push({
            kind: "expense",
            id: String(expense.id),
            date: (expense.issueDate ?? "").slice(0, 10),
            bytes: estimateAttachmentBytes([payload]),
        });
    }
    rows.sort((a, b) => a.date.localeCompare(b.date));

    const batches: AccountantExportBatch[] = [];
    let current: AccountantExportBatch | null = null;
    let docCount = 0;
    let expenseCount = 0;
    for (const row of rows) {
        const overflow =
            current !== null
            && ((row.kind === "document" && docCount >= maxDocumentRows)
                || (row.kind === "expense" && expenseCount >= maxRows)
                || current.attachmentBytes + row.bytes > maxBytes);
        if (current === null || overflow) {
            current = { range: { from: row.date, to: row.date }, documentIds: [], expenseIds: [], attachmentBytes: 0 };
            batches.push(current);
            docCount = 0;
            expenseCount = 0;
        }
        if (row.kind === "document") {
            current.documentIds.push(row.id);
            docCount++;
        } else {
            current.expenseIds.push(row.id);
            expenseCount++;
        }
        current.attachmentBytes += row.bytes;
        if (row.date < current.range.from) current.range.from = row.date;
        if (row.date > current.range.to) current.range.to = row.date;
    }

    // A single request keeps the user's full period as its label.
    if (batches.length === 1) {
        batches[0].range = input.range;
    }

    return {
        batches,
        documents: documents.length,
        expenses: expenses.length,
        attachments: attachmentCount,
        attachmentBytes: rows.reduce((sum, row) => sum + row.bytes, 0),
        skippedAttachments: skipped,
    };
}
