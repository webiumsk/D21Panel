import { computed, ref, type Ref } from "vue";
import { useQuery } from "@evolu/vue";
import type { DocumentAdvancedFilters } from "@/composables/useInvoicingDocumentListFilters";
import type { IssuePeriodState } from "@/composables/useInvoicingIssuePeriod";
import {
    allCompaniesDetailQuery,
    allContactsQuery,
    allDocumentsQuery,
    useInvoicingEvolu,
} from "@/evolu/client";
import { evoluContactToApi } from "@/evolu/contactMap";
import { evoluDocumentToListRow, type EvoluDocumentRow } from "@/evolu/documentMap";
import { filterLocalDocumentRows } from "@/evolu/documentListFilters";
import type { CompanyId } from "@/evolu/schema";
import { toAppRows } from "../evolu/queryLoad";
import type { LocalDocumentDeletionPolicy } from "@/evolu/documentBulkLocal";

export type LocalDocumentListRow = Record<string, unknown>;

export type LocalDocumentsRefreshOptions = {
    apiType?: string | undefined;
    statusFilter?: string;
    issuePeriod?: IssuePeriodState;
    advanced?: DocumentAdvancedFilters;
    contactId?: string;
};

function mapDocumentRow(
    row: EvoluDocumentRow,
    contactName?: string | null,
    allDocuments: EvoluDocumentRow[] = [],
    policy: LocalDocumentDeletionPolicy = {},
): LocalDocumentListRow {
    return evoluDocumentToListRow(row, contactName, allDocuments, policy);
}

export function useInvoicingDocumentsLocal(companyId: Ref<string>) {
    const evolu = useInvoicingEvolu();
    const loading = ref(true);
    const filterOptions = ref<LocalDocumentsRefreshOptions>({});

    const documentsPromise = evolu.loadQuery(allDocumentsQuery);
    const contactsPromise = evolu.loadQuery(allContactsQuery);
    const companiesPromise = evolu.loadQuery(allCompaniesDetailQuery);
    const documentRows = useQuery(allDocumentsQuery, { promise: documentsPromise });
    const contactRows = useQuery(allContactsQuery, { promise: contactsPromise });
    const companyRows = useQuery(allCompaniesDetailQuery, { promise: companiesPromise });

    void Promise.all([documentsPromise, contactsPromise, companiesPromise]).finally(() => {
        loading.value = false;
    });

    const contactNameById = computed(() => {
        const map = new Map<string, string>();
        for (const row of contactRows.value) {
            if (row.companyId !== companyId.value) continue;
            map.set(row.id, evoluContactToApi(row).name);
        }
        return map;
    });

    const deletionPolicy = computed<LocalDocumentDeletionPolicy>(() => ({
        jurisdictionByCompanyId: new Map(
            companyRows.value.map((row) => [String(row.id), String(row.jurisdiction ?? "")]),
        ),
    }));

    const filteredRows = computed(() => {
        const companyRows = toAppRows<EvoluDocumentRow>(
            documentRows.value.filter(
                (row) => row.companyId === (companyId.value as CompanyId),
            ),
        );

        return filterLocalDocumentRows(companyRows, {
            apiType: filterOptions.value.apiType,
            statusFilter: filterOptions.value.statusFilter,
            issuePeriod: filterOptions.value.issuePeriod,
            advanced: filterOptions.value.advanced,
            contactNameById: contactNameById.value,
            contactId: filterOptions.value.contactId,
        });
    });

    const documents = computed(() => {
        const all = toAppRows<EvoluDocumentRow>(documentRows.value);
        return filteredRows.value.map((row) =>
            mapDocumentRow(
                row,
                row.contactId ? contactNameById.value.get(row.contactId) : null,
                all,
                deletionPolicy.value,
            ),
        );
    });

    async function refresh(options?: LocalDocumentsRefreshOptions): Promise<void> {
        filterOptions.value = options ?? {};
        loading.value = true;
        try {
            await Promise.all([
                evolu.loadQuery(allDocumentsQuery),
                evolu.loadQuery(allContactsQuery),
                evolu.loadQuery(allCompaniesDetailQuery),
            ]);
        } finally {
            loading.value = false;
        }
    }

    return {
        loading,
        documents,
        filteredRows,
        documentRows,
        contactRows,
        companyRows,
        refresh,
        evolu,
    };
}
