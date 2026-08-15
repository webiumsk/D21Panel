import { onMounted, ref, type ComputedRef, type Ref } from "vue";
import { invoicingApi } from "@/services/api";
import { isInvoicingLocalFirst } from "@/evolu/flags";
import { useLocalInvoicingCompanies } from "./useInvoicingCompaniesLocal";

/** Company row shape shared by server API and Evolu local-first list. */
export interface InvoicingCompanyListItem {
    id: string;
    legal_name: string;
    trade_name: string | null;
    registration_number?: string | null;
    documents_count: number;
    logo_url?: string | null;
    has_logo?: boolean;
}

export interface UseInvoicingCompaniesResult {
    localFirst: boolean;
    companies: Ref<InvoicingCompanyListItem[]> | ComputedRef<InvoicingCompanyListItem[]>;
    loading: Ref<boolean>;
    forbidden: Ref<boolean>;
    loadError: Ref<boolean>;
    /** Local-first only: the load is blocked because another tab owns the Evolu worker. */
    multiTabBlocked: Ref<boolean>;
    refresh: () => Promise<void>;
}

function useServerInvoicingCompanies(): UseInvoicingCompaniesResult {
    const companies = ref<InvoicingCompanyListItem[]>([]);
    const loading = ref(false);
    const forbidden = ref(false);
    const loadError = ref(false);
    const multiTabBlocked = ref(false);

    async function refresh(): Promise<void> {
        loading.value = true;
        forbidden.value = false;
        try {
            companies.value = await invoicingApi.companies.list<InvoicingCompanyListItem>();
        } catch (e: unknown) {
            const status = (e as { response?: { status?: number } })?.response?.status;
            if (status === 403) {
                forbidden.value = true;
            }
        } finally {
            loading.value = false;
        }
    }

    onMounted(() => {
        void refresh();
    });

    return {
        localFirst: false,
        companies,
        loading,
        forbidden,
        loadError,
        multiTabBlocked,
        refresh,
    };
}

/**
 * Unified company list for /invoicing index.
 * Server API by default; Evolu SQLite when VITE_INVOICING_LOCAL_FIRST=true.
 */
export function useInvoicingCompanies(): UseInvoicingCompaniesResult {
    if (isInvoicingLocalFirst()) {
        return useLocalInvoicingCompanies();
    }
    return useServerInvoicingCompanies();
}
