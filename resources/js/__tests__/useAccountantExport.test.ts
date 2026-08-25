import { beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { useAccountantExport } from "../composables/useAccountantExport";
import { ACCOUNTANT_EXPORT_MAX_DOCUMENTS_PER_BATCH } from "../evolu/accountantExportSelect";

const mocks = vi.hoisted(() => ({
    queryRows: new Map<string, { value: unknown[] }>(),
    loadQuery: vi.fn(),
    buildRequest: vi.fn(),
    fetchExport: vi.fn(),
    downloadBlob: vi.fn(),
}));

vi.mock("@evolu/vue", () => ({
    useQuery: (query: string) => mocks.queryRows.get(query) ?? { value: [] },
}));

vi.mock("@/evolu/client", () => ({
    allDocumentsQuery: "documents",
    allExpensesQuery: "expenses",
    allExpenseAttachmentsQuery: "attachments",
    useInvoicingEvolu: () => ({ loadQuery: mocks.loadQuery }),
}));

vi.mock("@/evolu/flags", () => ({
    isInvoicingLocalFirst: () => true,
}));

vi.mock("@/composables/useLocalInvoiceDocument", () => ({
    useLocalInvoiceDocumentSupport: () => ({ refreshAll: vi.fn() }),
}));

vi.mock("@/evolu/ephemeralBridge", () => ({
    buildAccountantExportEphemeralRequest: mocks.buildRequest,
    fetchEphemeralAccountantExport: mocks.fetchExport,
    downloadResponseBlob: mocks.downloadBlob,
}));

const COMPANY = "company-1";

function issuedDocument(id: string) {
    return {
        id,
        companyId: COMPANY,
        status: "issued",
        number: id,
        issueDate: "2026-03-05",
    };
}

describe("useAccountantExport", () => {
    beforeEach(() => {
        mocks.queryRows.clear();
        mocks.queryRows.set("documents", {
            value: Array.from({ length: ACCOUNTANT_EXPORT_MAX_DOCUMENTS_PER_BATCH + 1 }, (_, i) =>
                issuedDocument(`2026-${String(i).padStart(3, "0")}`),
            ),
        });
        mocks.queryRows.set("expenses", { value: [] });
        mocks.queryRows.set("attachments", { value: [] });
        mocks.loadQuery.mockReset();
        mocks.loadQuery.mockResolvedValue([]);
        mocks.buildRequest.mockReset();
        mocks.buildRequest.mockImplementation(async (_localDoc, _companyId, input) => ({
            bridgeCompanyId: "bridge-company-1",
            body: {
                company: {},
                documents: input.documentIds.map((id: string) => ({ document: { number: id }, lines: [{}] })),
                expenses: input.expenses,
                options: {
                    from: input.range.from,
                    to: input.range.to,
                    formats: input.options.formats,
                    include_pdf: input.options.includePdf,
                    include_isdoc: input.options.includeIsdoc,
                    include_ubl: input.options.includeUbl,
                    include_expense_attachments: input.options.includeExpenseAttachments,
                },
            },
        }));
        mocks.fetchExport.mockReset();
        mocks.downloadBlob.mockReset();
    });

    it("does not download any local-first batch until every ZIP is prepared", async () => {
        mocks.fetchExport
            .mockResolvedValueOnce(new Blob(["part-one"]))
            .mockRejectedValueOnce(new Error("batch 2 failed"));

        const state = useAccountantExport(ref(COMPANY), ref("Acme"));
        state.period.value = {
            preset: "custom",
            customFrom: "2026-03-01",
            customTo: "2026-03-31",
        };

        await state.download();

        expect(mocks.fetchExport).toHaveBeenCalledTimes(2);
        expect(mocks.downloadBlob).not.toHaveBeenCalled();
        expect(state.error.value).toBe("batch 2 failed");
    });
});
