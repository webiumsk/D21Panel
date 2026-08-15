import { computed, ref, type Ref } from 'vue';
import { useQuery } from '@evolu/vue';
import {
    allDocumentEventsQuery,
    allDocumentLinesQuery,
    allDocumentsQuery,
    allNumberSeriesQuery,
    useInvoicingEvolu,
} from '@/evolu/client';
import { toAppRows } from '@/evolu/queryLoad';
import type { EvoluDocumentLineRow, EvoluDocumentRow } from '@/evolu/documentMap';
import type { EvoluDocumentEventRow } from '@/evolu/documentEventLog';
import type { EvoluNumberSeriesRow } from '@/evolu/numberSeriesMap';
import type { CompanyId } from '@/evolu/schema';
import {
    type IssuePeriodState,
    resolveIssuePeriodRange,
} from '@/composables/useInvoicingIssuePeriod';
import { buildNumberGapReport, type NumberSeriesGapReport } from '@/evolu/numberGapReport';
import { gobdExportZipBlob } from '@/evolu/gobdExport';

const MIN_DATE = '0000-01-01';
const MAX_DATE = '9999-12-31';

/**
 * GoBD audit data for one company: the all-time number-gap report and the
 * period-scoped tax-audit export (documents + lines + audit events + gap
 * report, zipped). Mirrors the useVatReport loading pattern.
 */
export function useGobdAudit(companyId: Ref<string>) {
    const evolu = useInvoicingEvolu();
    const loading = ref(true);
    const loadError = ref(false);

    const documentRows = useQuery(allDocumentsQuery, { promise: evolu.loadQuery(allDocumentsQuery) });
    const lineRows = useQuery(allDocumentLinesQuery, { promise: evolu.loadQuery(allDocumentLinesQuery) });
    const eventRows = useQuery(allDocumentEventsQuery, { promise: evolu.loadQuery(allDocumentEventsQuery) });
    const seriesRows = useQuery(allNumberSeriesQuery, { promise: evolu.loadQuery(allNumberSeriesQuery) });

    async function load(): Promise<void> {
        loading.value = true;
        loadError.value = false;
        try {
            await Promise.all([
                evolu.loadQuery(allDocumentsQuery),
                evolu.loadQuery(allDocumentLinesQuery),
                evolu.loadQuery(allDocumentEventsQuery),
                evolu.loadQuery(allNumberSeriesQuery),
            ]);
        } catch (error) {
            loadError.value = true;
            console.error('Failed to load GoBD audit data', error);
        } finally {
            loading.value = false;
        }
    }

    void load();

    // The export is filed per calendar period; the gap report is all-time.
    const period = ref<IssuePeriodState>({
        preset: 'this_year',
        customFrom: '',
        customTo: '',
    });

    const companyDocuments = computed(() =>
        toAppRows<EvoluDocumentRow>(
            documentRows.value.filter((row) => row.companyId === (companyId.value as CompanyId)),
        ),
    );

    const companySeries = computed(() =>
        toAppRows<EvoluNumberSeriesRow>(seriesRows.value),
    );

    const gapReports = computed<NumberSeriesGapReport[]>(() =>
        buildNumberGapReport(companyDocuments.value, companySeries.value, companyId.value),
    );

    function exportRange(): { from: string; to: string } {
        const range = resolveIssuePeriodRange(period.value);
        return { from: range.from ?? MIN_DATE, to: range.to ?? MAX_DATE };
    }

    function buildExportBlob(company: {
        id: string;
        legal_name?: string | null;
        registration_number?: string | null;
    }): { blob: Blob; range: { from: string; to: string } } {
        const range = exportRange();
        const blob = gobdExportZipBlob({
            company,
            documents: companyDocuments.value,
            lines: toAppRows<EvoluDocumentLineRow>(lineRows.value),
            events: toAppRows<EvoluDocumentEventRow>(eventRows.value),
            series: companySeries.value,
            range,
            createdAt: new Date(),
        });
        return { blob, range };
    }

    return { loading, loadError, period, gapReports, buildExportBlob, retry: load };
}
