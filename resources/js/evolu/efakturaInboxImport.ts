import { createIdFromString } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
import { invoicingApi } from "@/services/api";
import { allExpensesQuery } from "./client";
import { insertLocalExpense, type ExpenseSavePayload } from "./expenseCrud";
import {
    insertLocalExpenseAttachmentFromBase64,
    LOCAL_EXPENSE_ATTACHMENT_MAX_BYTES,
} from "./expenseAttachmentCrud";
import type { EvoluExpenseRow } from "./expenseMap";
import { ExpenseAttachmentId, ExpenseId, type CompanyId, type InvoicingLocalSchema } from "./schema";
import { toAppRows } from "./queryLoad";

/**
 * Received e-invoices (Peppol via the CPDS) for a LOCAL-FIRST company. The
 * server parks each document on the bridge company as an inbox item
 * (EfakturaInboundInboxService); this module turns it into an Evolu expense
 * with the UBL attached and clears the server copy. Mirrors
 * integrationInboxImport.ts for WooCommerce orders.
 */

export type EfakturaInboxEntry = {
    inbox_id: string;
    evolu_expense_id: string | null;
    external_document_id: string;
    inbox_status: string;
    created_at: string | null;
    draft: Record<string, unknown> | null;
    summary: {
        supplier_name: string | null;
        external_number: string | null;
        total: string | null;
        currency: string | null;
    };
};

export type EfakturaInboxEntryDetail = EfakturaInboxEntry & { ubl: string | null };

export type EfakturaInboxImportResult =
    | { ok: true; expenseId: ExpenseId; attachmentSkipped: boolean; alreadyImported: boolean }
    | { ok: false; error: "invalid_entry" | "expense_insert_failed" };

/** Same inbox item -> same Evolu expense id on every device. */
export function stableExpenseIdFromInboxUuid(inboxEvoluUuid: string | null | undefined): ExpenseId | null {
    const normalized = String(inboxEvoluUuid ?? "").trim().toLowerCase();
    if (!normalized) {
        return null;
    }
    const parsed = ExpenseId.from(createIdFromString(`satflux.efaktura-expense.v1.${normalized}`));
    return parsed.ok ? parsed.value : null;
}

/** The UBL attachment of that expense - one row per inbox item on every device. */
export function stableAttachmentIdFromInboxUuid(inboxEvoluUuid: string | null | undefined): ExpenseAttachmentId | null {
    const normalized = String(inboxEvoluUuid ?? "").trim().toLowerCase();
    if (!normalized) {
        return null;
    }
    const parsed = ExpenseAttachmentId.from(createIdFromString(`satflux.efaktura-expense-attachment.v1.${normalized}`));
    return parsed.ok ? parsed.value : null;
}

/** Server draft (UblExpenseDraftParser output) -> local expense payload. */
export function draftToExpensePayload(draft: Record<string, unknown> | null, entry: EfakturaInboxEntry): ExpenseSavePayload | null {
    const text = (value: unknown): string | null => {
        const s = String(value ?? "").trim();
        return s === "" ? null : s;
    };
    const issueDate = text(draft?.issue_date) ?? (entry.created_at ? entry.created_at.slice(0, 10) : null);
    if (!issueDate) {
        return null;
    }
    const total = text(draft?.total) ?? entry.summary.total;
    const currency = (text(draft?.currency) ?? entry.summary.currency ?? "EUR").toUpperCase().slice(0, 3);

    return {
        title: text(draft?.title) ?? entry.summary.supplier_name ?? "Prijatá e-faktúra",
        external_number: text(draft?.external_number) ?? entry.summary.external_number,
        variable_symbol: text(draft?.variable_symbol),
        issue_date: issueDate,
        delivery_date: text(draft?.delivery_date) ?? issueDate,
        due_date: text(draft?.due_date),
        total: total ?? "0",
        currency,
        internal_note: text(draft?.internal_note) ?? "Importované z Peppol (SAPI-SK).",
    };
}

export async function fetchEfakturaInbox(bridgeCompanyId: string): Promise<EfakturaInboxEntry[]> {
    return invoicingApi.efaktura.inboxList<EfakturaInboxEntry>(bridgeCompanyId);
}

export async function fetchEfakturaInboxDetail(bridgeCompanyId: string, inboxId: string): Promise<EfakturaInboxEntryDetail> {
    return invoicingApi.efaktura.inboxDetail<EfakturaInboxEntryDetail>(bridgeCompanyId, inboxId);
}

export async function markEfakturaInboxImported(bridgeCompanyId: string, inboxId: string): Promise<void> {
    await invoicingApi.efaktura.inboxImported(bridgeCompanyId, inboxId);
}

export async function dismissEfakturaInboxItem(bridgeCompanyId: string, inboxId: string): Promise<void> {
    await invoicingApi.efaktura.inboxDismiss(bridgeCompanyId, inboxId);
}

/** Entries whose expense already exists locally (imported on another device) are cleared server-side and hidden. */
export async function reconcileEfakturaInboxWithLocalExpenses(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: string,
    bridgeCompanyId: string,
    entries: EfakturaInboxEntry[],
): Promise<EfakturaInboxEntry[]> {
    const rows = toAppRows<EvoluExpenseRow>(await evolu.loadQuery(allExpensesQuery));
    const remaining: EfakturaInboxEntry[] = [];

    for (const entry of entries) {
        if (!findLocalExpenseForInboxEntry(rows, companyId, entry)) {
            remaining.push(entry);
            continue;
        }
        try {
            await markEfakturaInboxImported(bridgeCompanyId, entry.inbox_id);
        } catch {
            // Server copy may already be cleared; still hide locally.
        }
    }

    return remaining;
}

export function findLocalExpenseForInboxEntry(
    rows: readonly EvoluExpenseRow[],
    companyId: string,
    entry: Pick<EfakturaInboxEntry, "evolu_expense_id">,
): EvoluExpenseRow | null {
    const stableId = stableExpenseIdFromInboxUuid(entry.evolu_expense_id);
    if (!stableId) {
        return null;
    }
    return rows.find((row) => row.id === stableId && String(row.companyId) === companyId) ?? null;
}

/**
 * Import one inbox item: expense under its stable id (upsert - idempotent),
 * the UBL as an XML attachment when it fits the local cap, then clear the
 * server copy. Already-imported items only get cleared.
 */
export async function importEfakturaInboxEntry(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: string,
    bridgeCompanyId: string,
    entry: EfakturaInboxEntry,
): Promise<EfakturaInboxImportResult> {
    const stableId = stableExpenseIdFromInboxUuid(entry.evolu_expense_id);
    if (!stableId) {
        return { ok: false, error: "invalid_entry" };
    }

    const rows = toAppRows<EvoluExpenseRow>(await evolu.loadQuery(allExpensesQuery));
    if (findLocalExpenseForInboxEntry(rows, companyId, entry)) {
        await markEfakturaInboxImported(bridgeCompanyId, entry.inbox_id);
        return { ok: true, expenseId: stableId, attachmentSkipped: false, alreadyImported: true };
    }

    const detail = await fetchEfakturaInboxDetail(bridgeCompanyId, entry.inbox_id);
    const payload = draftToExpensePayload(detail.draft ?? entry.draft, entry);
    if (!payload) {
        return { ok: false, error: "invalid_entry" };
    }

    const inserted = insertLocalExpense(evolu, companyId as CompanyId, payload, rows, { id: stableId });
    if (!inserted.ok) {
        return { ok: false, error: "expense_insert_failed" };
    }

    let attachmentSkipped = false;
    const ubl = detail.ubl ?? "";
    if (ubl !== "") {
        const contentBase64 = utf8ToBase64(ubl);
        const decodedBytes = new TextEncoder().encode(ubl).length;
        if (decodedBytes > LOCAL_EXPENSE_ATTACHMENT_MAX_BYTES) {
            attachmentSkipped = true;
        } else {
            const filename = `efaktura-${sanitizeFilenamePart(entry.external_document_id)}.xml`;
            const attachment = insertLocalExpenseAttachmentFromBase64(
                evolu,
                stableId,
                { filename, mimeType: "application/xml", contentBase64 },
                { id: stableAttachmentIdFromInboxUuid(entry.evolu_expense_id) ?? undefined },
            );
            if (!attachment.ok) {
                attachmentSkipped = true;
            }
        }
    }

    await markEfakturaInboxImported(bridgeCompanyId, entry.inbox_id);

    return { ok: true, expenseId: stableId, attachmentSkipped, alreadyImported: false };
}

export function utf8ToBase64(text: string): string {
    const bytes = new TextEncoder().encode(text);
    let binary = "";
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) {
        binary += String.fromCharCode(...bytes.subarray(i, i + chunk));
    }
    return btoa(binary);
}

function sanitizeFilenamePart(value: string): string {
    return value.replace(/[^A-Za-z0-9._-]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 80) || "document";
}
