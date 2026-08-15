import { computed, ref } from "vue";
import { useQuery } from "@evolu/vue";
import {
    allCompaniesQuery,
    allDocumentsQuery,
    useInvoicingEvolu,
} from "@/evolu/client";
import { loadEvoluQueryWithTimeout } from "@/evolu/queryLoad";
import { sleep } from "@/evolu/asyncTimeout";
import { detectEvoluMultiTabContention } from "@/evolu/multiTab";
import {
    isEvoluRelaySyncPending,
    pullInvoicingFromRelay,
    waitForInvoicingRelaySync,
} from "@/evolu/relaySyncWait";
import type { CompanyId } from "@/evolu/schema";
import type { InvoicingCompanyListItem, UseInvoicingCompaniesResult } from "./useInvoicingCompanies";
import { countCompanyInvoicesForList } from "@/evolu/companyInvoiceCount";
import { dedupeRowsById } from "@/evolu/duplicateCompanies";

function mapEvoluCompanies(
    rows: ReadonlyArray<{
        id: CompanyId;
        legalName: string;
        tradeName: string | null;
        registrationNumber?: string | null;
        logoDataUrl?: string | null;
    }>,
    documentRows: ReadonlyArray<{ companyId: CompanyId; documentType: string; status: string }>,
): InvoicingCompanyListItem[] {
    // The reactive query layer can repeat the same physical row (phantom
    // duplicates observed with the relay sync owner subscribed) - the list,
    // the duplicate detection and the merge must all see each id once.
    return dedupeRowsById([...rows]).map((row) => ({
        id: row.id,
        legal_name: row.legalName,
        trade_name: row.tradeName,
        registration_number: row.registrationNumber ?? null,
        documents_count: countCompanyInvoicesForList(documentRows, row.id),
        logo_url: row.logoDataUrl ?? null,
        has_logo: Boolean(row.logoDataUrl),
    }));
}

async function loadCompanyQueries(evolu: ReturnType<typeof useInvoicingEvolu>): Promise<void> {
    await Promise.all([
        loadEvoluQueryWithTimeout(evolu, allCompaniesQuery),
        loadEvoluQueryWithTimeout(evolu, allDocumentsQuery),
    ]);
}

/**
 * Grace period before we check whether a still-pending load is caused by
 * another open tab. Evolu shares one worker across tabs; a proxy tab whose
 * handshake was missed never resolves a query, so without this the list would
 * spin indefinitely (see resources/js/evolu/multiTab.ts).
 */
const MULTI_TAB_WATCHDOG_MS = 6_000;

/** Local-first company list (requires EvoluProvider). */
export function useLocalInvoicingCompanies(): UseInvoicingCompaniesResult {
    const evolu = useInvoicingEvolu();
    const loading = ref(true);
    const forbidden = ref(false);
    const loadError = ref(false);
    const multiTabBlocked = ref(false);

    const companiesPromise = loadEvoluQueryWithTimeout(evolu, allCompaniesQuery);
    const documentsPromise = loadEvoluQueryWithTimeout(evolu, allDocumentsQuery);

    const companyRows = useQuery(allCompaniesQuery, { promise: companiesPromise });
    const documentRows = useQuery(allDocumentsQuery, { promise: documentsPromise });

    void (async () => {
        let done = false;

        // Watchdog: if the local queries are still pending after a short grace
        // period and another tab holds the Evolu worker lock, surface an
        // actionable message instead of an indefinite spinner.
        void (async () => {
            await sleep(MULTI_TAB_WATCHDOG_MS);
            if (done || !loading.value) return;
            if (await detectEvoluMultiTabContention()) {
                multiTabBlocked.value = true;
                loadError.value = true;
                loading.value = false;
            }
        })();

        try {
            await Promise.all([companiesPromise, documentsPromise]);
            if (isEvoluRelaySyncPending()) {
                await waitForInvoicingRelaySync(evolu);
            } else {
                await pullInvoicingFromRelay(evolu, { timeoutMs: 20_000 });
            }
            await loadCompanyQueries(evolu);
            // Recovered (e.g. the other tab closed) - clear any watchdog state.
            multiTabBlocked.value = false;
            loadError.value = false;
        } catch {
            loadError.value = true;
        } finally {
            done = true;
            loading.value = false;
        }
    })();

    const companies = computed(() =>
        mapEvoluCompanies(companyRows.value, documentRows.value),
    );

    async function refresh(): Promise<void> {
        loading.value = true;
        loadError.value = false;
        multiTabBlocked.value = false;
        try {
            if (isEvoluRelaySyncPending()) {
                await waitForInvoicingRelaySync(evolu);
            } else {
                await pullInvoicingFromRelay(evolu, { timeoutMs: 15_000 });
            }
            await loadCompanyQueries(evolu);
        } catch {
            if (await detectEvoluMultiTabContention()) {
                multiTabBlocked.value = true;
            }
            loadError.value = true;
        } finally {
            loading.value = false;
        }
    }

    return {
        localFirst: true,
        companies,
        loading,
        forbidden,
        loadError,
        multiTabBlocked,
        refresh,
    };
}
