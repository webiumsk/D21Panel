import { describe, expect, it, vi } from "vitest";
import type { Evolu } from "@evolu/common/local-first";
import type { EvoluDocumentRow } from "@/evolu/documentMap";
import type { CompanyId, DocumentId, InvoicingLocalSchema } from "@/evolu/schema";

// documentCrud transitively imports the real Evolu client (WASM sqlite) via
// ./client - replace it with inert query tokens before importing the module.
vi.mock("@/evolu/client", () => ({
    allCompaniesDetailQuery: "allCompaniesDetailQuery",
    allContactsQuery: "allContactsQuery",
    allDocumentLinesQuery: "allDocumentLinesQuery",
    allDocumentsQuery: "allDocumentsQuery",
    allNumberSeriesQuery: "allNumberSeriesQuery",
    allCompanyStockBalancesQuery: "allCompanyStockBalancesQuery",
    allCompanyStockItemsQuery: "allCompanyStockItemsQuery",
    allCompanyStockMovementsQuery: "allCompanyStockMovementsQuery",
    allCompanyWarehousesQuery: "allCompanyWarehousesQuery",
}));

import {
    deleteLocalDocumentAsync,
    saveLocalDocument,
    type DocumentSavePayload,
} from "@/evolu/documentCrud";
import { canDeleteLocalDocument } from "@/evolu/documentBulkLocal";
import { documentHistoryFromEvents, EVENT_USER_EMAIL_KEY } from "@/evolu/documentEventLog";

const companyId = "cmp-1" as CompanyId;

const payload = {
    type: "invoice",
    title: "Invoice",
    issue_date: "2026-07-22",
    delivery_date: null,
    due_date: null,
    currency: "EUR",
    discount_percent: 0,
    lines: [
        {
            name: "Service",
            description: "",
            quantity: "1",
            unit: "ks",
            unit_price: "100",
            line_discount_percent: "0",
            tax_rate: "19",
        },
    ],
} as unknown as DocumentSavePayload;

function issuedDocument(): EvoluDocumentRow {
    return {
        id: "doc-1" as DocumentId,
        companyId,
        documentType: "invoice",
        status: "issued",
        number: "INV20260001",
    } as unknown as EvoluDocumentRow;
}

function evoluWith(rows: EvoluDocumentRow[]): Evolu<InvoicingLocalSchema> {
    return { getQueryRows: () => rows } as unknown as Evolu<InvoicingLocalSchema>;
}

const baseOptions = {
    defaultVat: 19,
    lineTaxApplies: () => true,
    lineTaxRate: () => 19,
    existingLines: [],
};

function expectIssuedLocked(result: ReturnType<typeof saveLocalDocument>) {
    expect(result.ok).toBe(false);
    if (!result.ok) {
        expect(result.error).toBe("issued_locked");
    }
}

describe("GoBD immutability - saveLocalDocument lockIssuedContent", () => {
    it("rejects any save against an issued document when locked", () => {
        expectIssuedLocked(saveLocalDocument(evoluWith([issuedDocument()]), companyId, payload, {
            ...baseOptions,
            documentId: "doc-1" as DocumentId,
            existingDocument: issuedDocument(),
            lockIssuedContent: true,
        }));
    });

    it("verifies against PERSISTED state - a stale draft copy cannot downgrade an issued document", () => {
        const staleDraftCopy = { ...issuedDocument(), status: "draft", number: null } as EvoluDocumentRow;
        expectIssuedLocked(saveLocalDocument(evoluWith([issuedDocument()]), companyId, payload, {
            ...baseOptions,
            documentId: "doc-1" as DocumentId,
            existingDocument: staleDraftCopy,
            lockIssuedContent: true,
        }));
    });

    it("a missing existingDocument cannot bypass the lock", () => {
        expectIssuedLocked(saveLocalDocument(evoluWith([issuedDocument()]), companyId, payload, {
            ...baseOptions,
            documentId: "doc-1" as DocumentId,
            lockIssuedContent: true,
        }));
    });

    it("an unverifiable update (no persisted row, no caller copy) is rejected", () => {
        expectIssuedLocked(saveLocalDocument(evoluWith([]), companyId, payload, {
            ...baseOptions,
            documentId: "doc-1" as DocumentId,
            lockIssuedContent: true,
        }));
    });

    it("the lock beats line validation - an empty-lines edit of an issued document reports issued_locked", () => {
        const emptyLines = { ...payload, lines: [] } as unknown as typeof payload;
        expectIssuedLocked(saveLocalDocument(evoluWith([issuedDocument()]), companyId, emptyLines, {
            ...baseOptions,
            documentId: "doc-1" as DocumentId,
            existingDocument: issuedDocument(),
            lockIssuedContent: true,
        }));
    });

    it("locked drafts still save (only issued content is immutable)", () => {
        const draft = { ...issuedDocument(), status: "draft", number: null } as EvoluDocumentRow;
        // Reaching past the guard proves drafts are not blocked - the stub
        // evolu then fails, which is fine for this unit test's purpose.
        expect(() =>
            saveLocalDocument(evoluWith([draft]), companyId, payload, {
                ...baseOptions,
                documentId: "doc-1" as DocumentId,
                existingDocument: draft,
                lockIssuedContent: true,
            }),
        ).toThrow();
    });

    it("without the lock the issued save path stays reachable (edit + re-freeze)", () => {
        expect(() =>
            saveLocalDocument(evoluWith([issuedDocument()]), companyId, payload, {
                ...baseOptions,
                documentId: "doc-1" as DocumentId,
                existingDocument: issuedDocument(),
            }),
        ).toThrow();
    });
});

describe("GoBD immutability - local document deletion", () => {
    const dePolicy = {
        jurisdictionByCompanyId: new Map<string, string>([[String(companyId), "eu_de"]]),
    };

    it("does not allow issued or cancelled DE documents to disappear from the audit trail", () => {
        const issued = issuedDocument();
        const cancelled = { ...issuedDocument(), id: "doc-2" as DocumentId, status: "cancelled" };

        expect(canDeleteLocalDocument(issued, [issued], dePolicy)).toBe(false);
        expect(canDeleteLocalDocument(cancelled, [cancelled], dePolicy)).toBe(false);
    });

    it("keeps DE drafts deletable", () => {
        const draft = { ...issuedDocument(), status: "draft", number: null } as EvoluDocumentRow;

        expect(canDeleteLocalDocument(draft, [draft], dePolicy)).toBe(true);
    });

    it("blocks the direct async delete helper for non-draft DE documents", async () => {
        const update = vi.fn();
        const cancelled = { ...issuedDocument(), status: "cancelled" } as EvoluDocumentRow;
        const result = await deleteLocalDocumentAsync(
            { update } as unknown as Evolu<InvoicingLocalSchema>,
            cancelled.id,
            [cancelled],
            [],
            dePolicy,
        );

        expect(result.ok).toBe(false);
        if (!result.ok) {
            expect(result.error).toBe("issued_locked");
        }
        expect(update).not.toHaveBeenCalled();
    });
});

describe("GoBD audit attribution - documentHistoryFromEvents", () => {
    it("extracts the actor e-mail from event metadata", () => {
        const entries = documentHistoryFromEvents([
            {
                id: "e1",
                documentId: "doc-1",
                action: "issued",
                metadataJson: JSON.stringify({ number: "INV1", [EVENT_USER_EMAIL_KEY]: "user@firma.sk" }),
                createdAt: "2026-07-22T10:00:00Z",
            },
            {
                id: "e2",
                documentId: "doc-1",
                action: "created",
                metadataJson: null,
                createdAt: "2026-07-22T09:00:00Z",
            },
        ]);

        expect(entries[0].user).toEqual({ email: "user@firma.sk" });
        expect(entries[0].metadata?.number).toBe("INV1");
        // Older events without attribution show no user.
        expect(entries[1].user).toBeNull();
    });
});
