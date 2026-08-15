import { computed } from "vue";
import { useQuery } from "@evolu/vue";
import {
    allCompaniesDetailQuery,
    allContactsQuery,
    allDocumentEventsQuery,
    allDocumentLinesQuery,
    allDocumentsQuery,
    allNumberSeriesQuery,
    useInvoicingEvolu,
} from "@/evolu/client";
import { evoluCompanyToApi, type EvoluCompanyRow } from "@/evolu/companyMap";
import { evoluContactToApi } from "@/evolu/contactMap";
import {
    approveLocalQuote,
    cancelLocalDocument,
    cancelLocalDocumentAsync,
    createLocalCreditNoteFromInvoice,
    createLocalFinalInvoiceFromProforma,
    createLocalInvoiceFromQuote,
    deleteLocalDocument,
    deleteLocalDocumentAsync,
    getLocalDocumentApi,
    issueLocalDocumentAsync,
    markLocalDocumentPaid,
    markLocalDocumentEmailSent,
    payloadFromApiDocument,
    rejectLocalQuote,
    saveLocalDocument,
    unmarkLocalDocumentPaid,
    type DocumentLinePayload,
} from "@/evolu/documentCrud";
import { previewNextLocalDocumentNumber } from "@/evolu/numberSeriesCrud";
import {
    localHighCounterForStoreBridge,
    previewNextDocumentNumberFromStore,
} from "@/evolu/numberSequenceBridge";
import type { EvoluDocumentRow } from "@/evolu/documentMap";
import type { EvoluNumberSeriesRow } from "@/evolu/numberSeriesMap";
import type { EvoluDocumentEventRow } from "@/evolu/documentEventLog";
import type { CompanyId, DocumentId } from "@/evolu/schema";
import { useStoresStore } from "@/store/stores";
import { toAppRows } from "../evolu/queryLoad";
import type { LocalDocumentDeletionPolicy } from "@/evolu/documentBulkLocal";

/** Evolu-backed document operations (requires EvoluProvider). */
export function useLocalInvoiceDocumentSupport() {
    const evolu = useInvoicingEvolu();
    const storesStore = useStoresStore();

    const companyRows = useQuery(allCompaniesDetailQuery);
    const contactRows = useQuery(allContactsQuery);
    const documentRows = useQuery(allDocumentsQuery);
    const lineRows = useQuery(allDocumentLinesQuery);
    const seriesRows = useQuery(allNumberSeriesQuery);
    const documentEventRows = useQuery(allDocumentEventsQuery) as { value: EvoluDocumentEventRow[] };

    async function refreshAll(): Promise<void> {
        await Promise.all([
            evolu.loadQuery(allCompaniesDetailQuery),
            evolu.loadQuery(allContactsQuery),
            evolu.loadQuery(allDocumentsQuery),
            evolu.loadQuery(allDocumentLinesQuery),
            evolu.loadQuery(allNumberSeriesQuery),
            evolu.loadQuery(allDocumentEventsQuery),
        ]);
    }

    function companyApi(companyId: string) {
        const row = companyRows.value.find((c) => c.id === companyId);
        if (!row) return null;
        return evoluCompanyToApi(row as EvoluCompanyRow, (storeId) => {
            const store = storesStore.stores.find((s) => s.id === storeId);
            return store
                ? { id: store.id, name: store.name, default_currency: store.default_currency }
                : undefined;
        });
    }

    function contactsForCompany(companyId: string) {
        return contactRows.value
            .filter((c) => c.companyId === companyId)
            .map((c) => evoluContactToApi(c));
    }

    function previewNumber(companyId: CompanyId, documentType: string) {
        return previewNextLocalDocumentNumber(
            evolu,
            companyId,
            documentType as Parameters<typeof previewNextLocalDocumentNumber>[2],
            toAppRows<EvoluDocumentRow>(documentRows.value),
            toAppRows<EvoluNumberSeriesRow>(seriesRows.value),
        );
    }

    function deletionPolicy(): LocalDocumentDeletionPolicy {
        return {
            jurisdictionByCompanyId: new Map(
                companyRows.value.map((row) => [String(row.id), String(row.jurisdiction ?? "")]),
            ),
        };
    }

    async function issueLocalDocumentAsyncWrapped(
        evoluInst: typeof evolu,
        documentId: DocumentId,
        company: EvoluCompanyRow,
    ) {
        return issueLocalDocumentAsync(evoluInst, documentId, company);
    }

    async function previewNumberAsync(companyId: CompanyId, documentType: string) {
        await Promise.all([
            evolu.loadQuery(allNumberSeriesQuery),
            evolu.loadQuery(allDocumentsQuery),
            evolu.loadQuery(allCompaniesDetailQuery),
        ]);
        const documents = toAppRows<EvoluDocumentRow>(documentRows.value);
        const series = toAppRows<EvoluNumberSeriesRow>(seriesRows.value);
        const companyRow = companyRows.value.find((c) => c.id === companyId) as
            | EvoluCompanyRow
            | undefined;
        const linkedStoreId = companyRow?.linkedStoreId?.trim() ?? "";
        if (linkedStoreId) {
            const localHigh = localHighCounterForStoreBridge(
                companyId,
                documentType as Parameters<typeof localHighCounterForStoreBridge>[1],
                documents,
                series,
            );
            const fromStore = await previewNextDocumentNumberFromStore(
                linkedStoreId,
                documentType,
                undefined,
                localHigh,
                series,
                companyId,
                {
                    legal_name: companyRow?.legalName ?? null,
                    registration_number: companyRow?.registrationNumber ?? null,
                },
            );
            if (fromStore) {
                return fromStore;
            }
        }
        return previewNumber(companyId, documentType);
    }

    function documentApi(documentId: DocumentId) {
        return getLocalDocumentApi(
            documentId,
            documentRows.value as Parameters<typeof getLocalDocumentApi>[1],
            lineRows.value as Parameters<typeof getLocalDocumentApi>[2],
            deletionPolicy(),
        );
    }

    async function deleteLocalDocumentAsyncWrapped(
        evoluInst: typeof evolu,
        documentId: DocumentId,
        documents: Parameters<typeof deleteLocalDocumentAsync>[2],
        allSeries: Parameters<typeof deleteLocalDocumentAsync>[3],
    ) {
        return deleteLocalDocumentAsync(
            evoluInst,
            documentId,
            documents,
            allSeries,
            deletionPolicy(),
        );
    }

    function saveOptions(
        defaultVat: number,
        lineTaxApplies: (line: DocumentLinePayload) => boolean,
        lineTaxRate: (line: DocumentLinePayload) => number,
    ) {
        return {
            defaultVat,
            lineTaxApplies,
            lineTaxRate,
            existingLines: lineRows.value as Parameters<typeof saveLocalDocument>[3]["existingLines"],
        };
    }

    const documentsForCompany = computed(() => documentRows.value);

    return {
        evolu,
        companyRows,
        contactRows,
        documentRows,
        lineRows,
        seriesRows,
        documentEventRows,
        documentsForCompany,
        refreshAll,
        companyApi,
        contactsForCompany,
        previewNumber,
        previewNumberAsync,
        documentApi,
        saveOptions,
        saveLocalDocument,
        issueLocalDocumentAsync: issueLocalDocumentAsyncWrapped,
        deleteLocalDocument,
        deleteLocalDocumentAsync: deleteLocalDocumentAsyncWrapped,
        cancelLocalDocument,
        cancelLocalDocumentAsync,
        markLocalDocumentPaid,
        unmarkLocalDocumentPaid,
        markLocalDocumentEmailSent,
        approveLocalQuote,
        rejectLocalQuote,
        createLocalInvoiceFromQuote,
        createLocalFinalInvoiceFromProforma,
        createLocalCreditNoteFromInvoice,
        payloadFromApiDocument,
    };
}
