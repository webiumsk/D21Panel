import type { EvoluDocumentLineRow, EvoluDocumentRow } from "./documentMap";
import type { EvoluNumberSeriesRow } from "./numberSeriesMap";
import { EVENT_USER_EMAIL_KEY, type EvoluDocumentEventRow } from "./documentEventLog";
import { buildNumberGapReport, type NumberSeriesGapReport } from "./numberGapReport";
import { zipBlob } from "./zipStore";

/**
 * GoBD tax-audit data export (Datenträgerüberlassung, Z3): a structured,
 * machine-readable dump of the numbered documents, their lines and the full
 * audit event trail for a period, plus the number-gap report and a manifest
 * describing the files. RFC 4180 CSV, UTF-8 with BOM, CRLF - the same
 * conventions as the VAT report export. This is a structured data export,
 * not a certified IDEA/GDPdU container.
 */

export type GobdExportInput = {
    company: { id: string; legal_name?: string | null; registration_number?: string | null };
    documents: EvoluDocumentRow[];
    lines: EvoluDocumentLineRow[];
    events: EvoluDocumentEventRow[];
    series: EvoluNumberSeriesRow[];
    range: { from: string; to: string };
    createdAt: Date;
};

export type GobdExportFile = { name: string; content: string };

const BOM = "﻿";

function csvCell(value: string | number | null | undefined): string {
    let text = String(value ?? "");
    // Neutralize spreadsheet formula injection: a leading =, +, -, @, tab or
    // CR would execute when the CSV is opened in a spreadsheet.
    if (/^[=+\-@\t\r]/.test(text)) {
        text = `'${text}`;
    }
    return `"${text.replace(/"/g, '""')}"`;
}

function csv(rows: (string | number | null | undefined)[][]): string {
    return BOM + rows.map((row) => row.map(csvCell).join(",")).join("\r\n");
}

function inRange(issueDate: string | null, from: string, to: string): boolean {
    if (!issueDate) return false;
    const day = issueDate.slice(0, 10);
    return day >= from && day <= to;
}

/**
 * Numbered (non-draft) documents of the company within [from, to] -
 * cancelled documents stay included: they document numbering gaps.
 */
export function selectGobdDocuments(
    documents: EvoluDocumentRow[],
    companyId: string,
    range: { from: string; to: string },
): EvoluDocumentRow[] {
    return documents.filter(
        (doc) =>
            doc.companyId === companyId
            && doc.status !== "draft"
            && !!doc.number
            && inRange(doc.issueDate, range.from, range.to),
    );
}

function documentsCsv(documents: EvoluDocumentRow[]): string {
    const header = [
        "id", "document_type", "status", "number", "variable_symbol", "issue_date",
        "delivery_date", "due_date", "currency", "subtotal", "tax_total",
        "discount_percent", "total", "paid_at", "amount_paid", "contact_id",
    ];
    const rows = documents.map((doc) => [
        doc.id, doc.documentType, doc.status, doc.number, doc.variableSymbol, doc.issueDate,
        doc.deliveryDate, doc.dueDate, doc.currency, doc.subtotal, doc.taxTotal,
        doc.discountPercent, doc.total, doc.paidAt, doc.amountPaid, doc.contactId,
    ]);
    return csv([header, ...rows]);
}

function linesCsv(lines: EvoluDocumentLineRow[], documentIds: Set<string>): string {
    const header = [
        "document_id", "sort_order", "name", "description", "quantity", "unit",
        "unit_price", "line_discount_percent", "tax_rate", "line_total",
    ];
    const rows = lines
        .filter((line) => documentIds.has(line.documentId))
        .map((line) => [
            line.documentId, line.sortOrder, line.name, line.description, line.quantity,
            line.unit, line.unitPrice, line.lineDiscountPercent, line.taxRate, line.lineTotal,
        ]);
    return csv([header, ...rows]);
}

function eventsCsv(events: EvoluDocumentEventRow[], documentIds: Set<string>): string {
    const header = ["document_id", "action", "created_at", "user_email", "metadata_json"];
    const rows = events
        .filter((event) => documentIds.has(event.documentId))
        .map((event) => {
            let email = "";
            try {
                const metadata = event.metadataJson ? JSON.parse(event.metadataJson) : null;
                const value = metadata?.[EVENT_USER_EMAIL_KEY];
                if (typeof value === "string") email = value;
            } catch {
                // Malformed metadata still exports raw below.
            }
            return [event.documentId, event.action, event.createdAt, email, event.metadataJson];
        });
    return csv([header, ...rows]);
}

function gapReportCsv(reports: NumberSeriesGapReport[]): string {
    const header = [
        "document_type", "series_prefix", "min_counter", "max_counter",
        "numbered_documents", "missing_total", "missing_counters", "cancelled_numbers",
    ];
    const rows = reports.map((report) => [
        report.documentType, report.prefix, report.minCounter, report.maxCounter,
        report.numberedCount,
        report.missingTotal,
        report.missing.join(" ") + (report.missingTotal > report.missing.length ? " ..." : ""),
        report.cancelled.map((entry) => entry.number).join(" "),
    ]);
    return csv([header, ...rows]);
}

function manifest(input: GobdExportInput, documentCount: number): string {
    return [
        "GoBD data export (Datentraegerueberlassung Z3) - satflux.io",
        `company: ${input.company.legal_name ?? ""} (${input.company.registration_number ?? ""})`,
        `period: ${input.range.from} .. ${input.range.to} (issue date, inclusive)`,
        `created_at: ${input.createdAt.toISOString()}`,
        `documents: ${documentCount} (all numbered non-draft documents incl. cancelled)`,
        "",
        "files:",
        "  documents.csv  - document headers (RFC 4180, UTF-8 BOM, CRLF)",
        "  lines.csv      - document lines",
        "  events.csv     - append-only audit trail with actor attribution",
        "  number_gaps.csv - number-sequence gap report (missing + cancelled)",
        "",
        "Amounts are decimal strings in the document currency.",
        "This is a structured data export, not a certified IDEA/GDPdU container.",
    ].join("\n");
}

export function buildGobdExportFiles(input: GobdExportInput): GobdExportFile[] {
    const documents = selectGobdDocuments(input.documents, input.company.id, input.range);
    const documentIds = new Set(documents.map((doc) => doc.id));
    const gapReports = buildNumberGapReport(documents, input.series, input.company.id);

    return [
        { name: "manifest.txt", content: manifest(input, documents.length) },
        { name: "documents.csv", content: documentsCsv(documents) },
        { name: "lines.csv", content: linesCsv(input.lines, documentIds) },
        { name: "events.csv", content: eventsCsv(input.events, documentIds) },
        { name: "number_gaps.csv", content: gapReportCsv(gapReports) },
    ];
}

export function gobdExportZipBlob(input: GobdExportInput): Blob {
    return zipBlob(buildGobdExportFiles(input), input.createdAt);
}
