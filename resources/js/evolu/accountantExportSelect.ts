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

function pad(n: number): string {
    return String(n).padStart(2, "0");
}

/**
 * Splits an inclusive range into calendar-month chunks (first chunk starts
 * at `from`, last one ends at `to`). Used when a single request would blow
 * the server row / attachment caps.
 */
export function chunkRangeByMonth(range: ExportDateRange): ExportDateRange[] {
    if (range.from > range.to) return [];
    const chunks: ExportDateRange[] = [];
    let cursor = new Date(`${range.from}T00:00:00`);
    const end = new Date(`${range.to}T00:00:00`);
    while (cursor <= end) {
        const monthEnd = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
        const chunkEnd = monthEnd <= end ? monthEnd : end;
        chunks.push({
            from: `${cursor.getFullYear()}-${pad(cursor.getMonth() + 1)}-${pad(cursor.getDate())}`,
            to: `${chunkEnd.getFullYear()}-${pad(chunkEnd.getMonth() + 1)}-${pad(chunkEnd.getDate())}`,
        });
        cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
    }
    return chunks;
}

export type AccountantExportPlan = {
    /** One entry per request; a single entry means no chunking was needed. */
    ranges: ExportDateRange[];
    documents: number;
    expenses: number;
    attachments: number;
    attachmentBytes: number;
    skippedAttachments: number;
};

/**
 * Decide whether the period fits one request or must be split per month.
 * `maxRows` / `maxAttachmentBytes` mirror config/invoicing.php defaults.
 */
export function planAccountantExport(input: {
    range: ExportDateRange;
    documents: readonly EvoluDocumentRow[];
    expenses: readonly EvoluExpenseRow[];
    attachments: readonly EvoluExpenseAttachmentRow[];
    companyId: string;
    includeAttachments: boolean;
    maxRows?: number;
    maxAttachmentBytes?: number;
}): AccountantExportPlan {
    const maxRows = input.maxRows ?? 500;
    const maxBytes = input.maxAttachmentBytes ?? 12 * 1024 * 1024;

    const documents = selectIssuedDocumentsForExport(input.documents, input.companyId, input.range);
    const expenses = selectExpensesForExport(input.expenses, input.companyId, input.range);
    let attachmentCount = 0;
    let attachmentBytes = 0;
    let skipped = 0;
    for (const expense of expenses) {
        const { payload, skippedAttachments } = buildExpensePayload(expense, input.attachments, input.includeAttachments);
        attachmentCount += payload.attachments.length;
        attachmentBytes += estimateAttachmentBytes([payload]);
        skipped += skippedAttachments;
    }

    const fitsOneRequest =
        documents.length <= maxRows && expenses.length <= maxRows && attachmentBytes <= maxBytes;

    return {
        ranges: fitsOneRequest ? [input.range] : chunkRangeByMonth(input.range),
        documents: documents.length,
        expenses: expenses.length,
        attachments: attachmentCount,
        attachmentBytes,
        skippedAttachments: skipped,
    };
}
