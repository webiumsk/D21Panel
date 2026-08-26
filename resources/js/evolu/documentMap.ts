import {
    canCancelLocalDocument,
    canDeleteLocalDocument,
    type LocalDocumentDeletionPolicy,
} from "./documentBulkLocal";
import type { DocumentType, CompanyId, DocumentId } from "./schema";

export type EvoluDocumentLineRow = {
    id: string;
    documentId: DocumentId;
    sortOrder: string | null;
    name: string;
    description: string | null;
    quantity: string | null;
    unit: string | null;
    unitPrice: string | null;
    lineDiscountPercent: string | null;
    taxRate: string | null;
    lineTotal: string | null;
    companyStockItemId: string | null;
    companyWarehouseId: string | null;
};

export type EvoluDocumentRow = {
    id: DocumentId;
    companyId: CompanyId;
    contactId: string | null;
    documentType: DocumentType;
    status: string;
    quoteStatus: string | null;
    title: string;
    number: string | null;
    sourceDocumentId: DocumentId | null;
    issueDate: string | null;
    deliveryDate: string | null;
    dueDate: string | null;
    variableSymbol: string | null;
    constantSymbol: string | null;
    specificSymbol: string | null;
    currency: string | null;
    subtotal: string | null;
    taxTotal: string | null;
    discountPercent: string | null;
    total: string | null;
    noteAboveLines: string | null;
    noteFooter: string | null;
    internalNote: string | null;
    pdfLocale: string | null;
    pdfBankQr: string | null;
    pdfShowSignature: 0 | 1 | null;
    pdfShowPaymentInfo: 0 | 1 | null;
    paymentBankEnabled: 0 | 1 | null;
    paymentBtcEnabled: 0 | 1 | null;
    storeId: string | null;
    tagsJson: string | null;
    paidAt: string | null;
    amountPaid: string | null;
    emailSentAt: string | null;
};

function sqliteBool(value: 0 | 1 | null | undefined, fallback = false): boolean {
    if (value === 1) return true;
    if (value === 0) return false;
    return fallback;
}

function parseTags(json: string | null): string[] {
    if (!json?.trim()) return [];
    try {
        const parsed = JSON.parse(json);
        return Array.isArray(parsed) ? parsed.filter((t) => typeof t === "string") : [];
    } catch {
        return [];
    }
}

function resolveQuoteStatus(doc: EvoluDocumentRow): string | null {
    if (doc.documentType !== "quote") return null;
    const base = doc.quoteStatus || "pending";
    if (base !== "pending" || !doc.dueDate) return base;
    const due = new Date(`${doc.dueDate}T23:59:59`);
    if (!Number.isNaN(due.getTime()) && due < new Date()) {
        return "expired";
    }
    return base;
}

function findSourceDocument(
    doc: EvoluDocumentRow,
    all: EvoluDocumentRow[],
): { id: string; number?: string; type?: string } | null {
    if (!doc.sourceDocumentId) return null;
    const src = all.find((d) => d.id === doc.sourceDocumentId);
    if (!src) return { id: doc.sourceDocumentId };
    return {
        id: src.id,
        number: src.number ?? undefined,
        type: src.documentType,
    };
}

function findFinalInvoice(
    doc: EvoluDocumentRow,
    all: EvoluDocumentRow[],
): { id: string; number?: string; status?: string } | null {
    const child = all.find(
        (d) =>
            d.sourceDocumentId === doc.id
            && d.documentType === "invoice"
            && d.status !== "cancelled",
    );
    if (!child) return null;
    return {
        id: child.id,
        number: child.number ?? undefined,
        status: child.status,
    };
}

export function documentPermissions(status: string) {
    return {
        can_update: status === "draft" || status === "issued",
        can_delete: status === "draft",
        can_cancel: status === "issued" || status === "paid",
        can_unmark_paid: status === "paid",
    };
}

function resolveDocumentPermissions(
    doc: EvoluDocumentRow,
    allDocuments: EvoluDocumentRow[],
    policy: LocalDocumentDeletionPolicy = {},
) {
    const perms = documentPermissions(doc.status);
    if (allDocuments.length === 0) {
        return perms;
    }
    return {
        ...perms,
        can_delete: canDeleteLocalDocument(doc, allDocuments, policy),
        can_cancel: canCancelLocalDocument(doc),
    };
}

export function evoluDocumentToApi(
    doc: EvoluDocumentRow,
    lines: EvoluDocumentLineRow[],
    allDocuments: EvoluDocumentRow[] = [],
    policy: LocalDocumentDeletionPolicy = {},
): Record<string, unknown> {
    const perms = resolveDocumentPermissions(doc, allDocuments, policy);
    const resolvedQuote = resolveQuoteStatus(doc);

    return {
        id: doc.id,
        type: doc.documentType,
        status: doc.status,
        quote_status: doc.quoteStatus,
        resolved_quote_status: resolvedQuote,
        number: doc.number,
        title: doc.title,
        company_contact_id: doc.contactId,
        store_id: doc.storeId,
        source_document_id: doc.sourceDocumentId,
        source_document: findSourceDocument(doc, allDocuments),
        final_invoice: findFinalInvoice(doc, allDocuments),
        issue_date: doc.issueDate,
        delivery_date: doc.deliveryDate,
        due_date: doc.dueDate,
        variable_symbol: doc.variableSymbol,
        constant_symbol: doc.constantSymbol,
        specific_symbol: doc.specificSymbol,
        currency: doc.currency || "EUR",
        subtotal: doc.subtotal || "0",
        tax_total: doc.taxTotal || "0",
        discount_percent: doc.discountPercent || "0",
        total: doc.total || "0",
        note_above_lines: doc.noteAboveLines,
        note_footer: doc.noteFooter,
        internal_note: doc.internalNote,
        pdf_locale: doc.pdfLocale || "sk",
        pdf_bank_qr: doc.pdfBankQr ?? null,
        pdf_show_signature: sqliteBool(doc.pdfShowSignature, true),
        pdf_show_payment_info: sqliteBool(doc.pdfShowPaymentInfo, true),
        payment_bank_enabled: sqliteBool(doc.paymentBankEnabled, true),
        payment_btc_enabled: sqliteBool(doc.paymentBtcEnabled, false),
        tags: parseTags(doc.tagsJson),
        paid_at: doc.paidAt,
        amount_paid: doc.amountPaid,
        email_sent_at: doc.emailSentAt,
        payment_token: null,
        bank_match: null,
        ...perms,
        lines: lines
            .slice()
            .sort((a, b) => Number(a.sortOrder ?? 0) - Number(b.sortOrder ?? 0))
            .map((line) => ({
                name: line.name,
                description: line.description,
                quantity: line.quantity || "0",
                unit: line.unit || "ks",
                unit_price: line.unitPrice || "0",
                line_discount_percent: line.lineDiscountPercent || "0",
                tax_rate: line.taxRate || "0",
                line_total: line.lineTotal || "0",
                company_stock_item_id: line.companyStockItemId,
                company_warehouse_id: line.companyWarehouseId,
            })),
    };
}

export function evoluDocumentToListRow(
    doc: EvoluDocumentRow,
    contactName?: string | null,
    allDocuments: EvoluDocumentRow[] = [],
    policy: LocalDocumentDeletionPolicy = {},
): Record<string, unknown> {
    const perms = resolveDocumentPermissions(doc, allDocuments, policy);
    return {
        id: doc.id,
        type: doc.documentType,
        status: doc.status,
        quote_status: doc.quoteStatus,
        resolved_quote_status: resolveQuoteStatus(doc),
        number: doc.number,
        title: doc.title,
        company_contact_id: doc.contactId,
        buyer_name: contactName ?? null,
        contact: contactName ? { name: contactName } : null,
        source_document: findSourceDocument(doc, allDocuments),
        final_invoice: findFinalInvoice(doc, allDocuments),
        issue_date: doc.issueDate,
        due_date: doc.dueDate,
        currency: doc.currency || "EUR",
        total: Number(doc.total ?? 0) || 0,
        email_sent_at: doc.emailSentAt,
        ...perms,
    };
}
