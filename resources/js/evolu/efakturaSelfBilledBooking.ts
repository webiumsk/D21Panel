import { booleanToSqliteBoolean, createIdFromString } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
import { emptyContactForm, type ContactFormState } from "@/composables/useCompanyContact";
import { allContactsQuery, allDocumentsQuery } from "./client";
import { insertLocalContactFromForm } from "./contactCrud";
import type { EvoluContactRow } from "./contactMap";
import { markEfakturaInboxImported, type EfakturaInboxEntry, isSelfBilledInboxEntry } from "./efakturaInboxImport";
import { toAppRows } from "./queryLoad";
import { ContactId, DocumentId, DocumentLineId, type CompanyId, type InvoicingLocalSchema } from "./schema";

/**
 * Book a self-billed inbox item as an issued document (D3.3). On the SUPPLIER
 * side a received self-billed invoice/credit note is their own sale: it becomes
 * an issued `document` (self_billed = true) whose counterparty is the UBL
 * customer (the party who created it). The number comes from the received UBL
 * (self-billed documents are numbered by the issuer, so no allocator call).
 *
 * The Evolu writes mirror insertLocalExpense (upsert with a stable id derived
 * from the inbox item, so a re-import lands on the same rows on every device).
 */

type Loader = Evolu<InvoicingLocalSchema>;
type Draft = Record<string, unknown>;
type Customer = Record<string, unknown>;
type DraftLine = Record<string, unknown>;

export type SelfBilledBookingResult =
    | { ok: true; documentId: DocumentId; alreadyBooked: boolean }
    | { ok: false; error: "invalid_entry" | "not_self_billed" | "duplicate_number" | "contact_failed" | "document_failed" };

const str = (value: unknown): string | null => {
    const s = String(value ?? "").trim();
    return s === "" ? null : s;
};

/** 389 -> invoice, 261 -> credit note. */
export function bookedDocumentType(draft: Draft): "invoice" | "credit_note" {
    return str(draft.document_type_code) === "261" ? "credit_note" : "invoice";
}

/** Stable ids so a re-import upserts the same rows on every device. */
export function stableBookedDocumentId(inboxUuid: string | null | undefined): DocumentId | null {
    const normalized = String(inboxUuid ?? "").trim().toLowerCase();
    if (!normalized) return null;
    const parsed = DocumentId.from(createIdFromString(`satflux.efaktura-document.v1.${normalized}`));
    return parsed.ok ? parsed.value : null;
}

function stableBookedLineId(inboxUuid: string, index: number): DocumentLineId | null {
    const parsed = DocumentLineId.from(createIdFromString(`satflux.efaktura-document-line.v1.${inboxUuid}.${index}`));
    return parsed.ok ? parsed.value : null;
}

/** UBL customer party -> a contact form (the counterparty on the supplier side). */
export function contactFormFromCustomer(customer: Customer | null): ContactFormState {
    const form = emptyContactForm();
    form.name = str(customer?.name) ?? str(customer?.legal_name) ?? "Odberateľ";
    form.registration_number = str(customer?.registration_number) ?? "";
    form.vat_id = str(customer?.vat_number) ?? "";
    form.street = str(customer?.street) ?? "";
    form.city = str(customer?.city) ?? "";
    form.postal_code = str(customer?.postal_code) ?? "";
    form.country = str(customer?.country) ?? "";
    return form;
}

/** Reuse an existing contact matching the customer (registration -> VAT -> name). */
export function matchExistingContact(rows: EvoluContactRow[], customer: Customer | null): ContactId | null {
    const reg = str(customer?.registration_number);
    const vat = str(customer?.vat_number);
    const name = (str(customer?.name) ?? str(customer?.legal_name) ?? "").toLowerCase();
    const found = rows.find((row) => {
        if (reg && str(row.registrationNumber) === reg) return true;
        if (vat && str(row.vatId) === vat) return true;
        return name !== "" && String(row.name ?? "").trim().toLowerCase() === name;
    });
    // The row id already IS a ContactId (it came from an Evolu query); no need
    // to re-validate the brand.
    return found ? (found.id as unknown as ContactId) : null;
}

/** Line net + tax totals from the draft; total honours the UBL PayableAmount. */
export function bookingTotals(draft: Draft): { subtotal: string; taxTotal: string; total: string } {
    const lines = Array.isArray(draft.lines) ? (draft.lines as DraftLine[]) : [];
    let subtotal = 0;
    let tax = 0;
    for (const line of lines) {
        const net = Number(str(line.line_total) ?? "0") || 0;
        const rate = Number(str(line.tax_rate) ?? "0") || 0;
        subtotal += net;
        tax += (net * rate) / 100;
    }
    const ublTotal = Number(str(draft.total) ?? "0");
    const total = ublTotal > 0 ? ublTotal : subtotal + tax;
    return {
        subtotal: subtotal.toFixed(2),
        taxTotal: (total - subtotal).toFixed(2),
        total: total.toFixed(2),
    };
}

async function resolveContactId(evolu: Loader, companyId: CompanyId, customer: Customer | null): Promise<ContactId | null> {
    const rows = toAppRows<EvoluContactRow>(await evolu.loadQuery(allContactsQuery)).filter((r) => r.companyId === companyId);
    const existing = matchExistingContact(rows, customer);
    if (existing) return existing;
    const inserted = insertLocalContactFromForm(evolu, companyId, contactFormFromCustomer(customer), false);
    if (!inserted.ok) return null;
    // The insert returns a valid ContactId; no need to re-validate the brand.
    return inserted.value.id as unknown as ContactId;
}

export async function bookSelfBilledInboxEntry(
    evolu: Loader,
    companyId: string,
    bridgeCompanyId: string,
    entry: EfakturaInboxEntry,
): Promise<SelfBilledBookingResult> {
    if (!isSelfBilledInboxEntry(entry)) {
        return { ok: false, error: "not_self_billed" };
    }
    const draft = entry.draft as Draft | null;
    const documentId = stableBookedDocumentId(entry.evolu_expense_id);
    if (!draft || !documentId) {
        return { ok: false, error: "invalid_entry" };
    }

    const number = str(draft.external_number);
    const docRows = toAppRows<{ id: string; companyId: string; number: string | null; isDeleted?: unknown }>(
        await evolu.loadQuery(allDocumentsQuery),
    );
    // A document this inbox item already produced upserts idempotently; a
    // DIFFERENT document with the same number is a duplicate the supplier
    // likely created manually - never book a second copy.
    const clash = number
        ? docRows.find((r) => r.companyId === companyId && r.id !== documentId && str(r.number) === number && r.isDeleted !== 1)
        : undefined;
    if (clash) {
        return { ok: false, error: "duplicate_number" };
    }
    const alreadyBooked = docRows.some((r) => r.id === documentId && r.isDeleted !== 1);

    const contactId = await resolveContactId(evolu, companyId as CompanyId, (draft.customer as Customer) ?? null);
    if (!contactId) {
        return { ok: false, error: "contact_failed" };
    }

    const totals = bookingTotals(draft);
    const document = evolu.upsert("document", {
        id: documentId,
        companyId: companyId as CompanyId,
        contactId,
        documentType: bookedDocumentType(draft),
        status: "issued",
        title: str((draft.customer as Customer)?.name) ?? str(draft.title) ?? "Samofaktúra",
        number,
        issueDate: str(draft.issue_date),
        deliveryDate: str(draft.delivery_date),
        dueDate: str(draft.due_date),
        currency: str(draft.currency) ?? "EUR",
        subtotal: totals.subtotal,
        taxTotal: totals.taxTotal,
        total: totals.total,
        selfBilled: booleanToSqliteBoolean(true),
    } as never);
    if (!document.ok) {
        return { ok: false, error: "document_failed" };
    }

    const lines = Array.isArray(draft.lines) ? (draft.lines as DraftLine[]) : [];
    lines.forEach((line, index) => {
        const lineId = stableBookedLineId(entry.evolu_expense_id ?? "", index);
        if (!lineId) return;
        evolu.upsert("documentLine", {
            id: lineId,
            documentId,
            sortOrder: String(index),
            name: str(line.name) ?? "Položka",
            description: str(line.description),
            quantity: str(line.quantity) ?? "1",
            unit: str(line.unit) ?? "ks",
            unitPrice: str(line.unit_price) ?? "0",
            taxRate: str(line.tax_rate) ?? "0",
            lineTotal: str(line.line_total) ?? "0",
        } as never);
    });

    await markEfakturaInboxImported(bridgeCompanyId, entry.inbox_id);
    return { ok: true, documentId, alreadyBooked };
}
