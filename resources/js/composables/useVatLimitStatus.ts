import { computed, type Ref } from 'vue';
import { useInvoicingCompany } from '@/composables/useInvoicingCompany';
import { useVatReport } from '@/composables/useVatReport';
import { activeVatLimitStatus, type VatLimitProgress } from '@/evolu/vatReport';

/**
 * The VAT-registration-limit status for a company, ready to surface as an
 * always-visible indicator (e.g. on the company switcher). Resolves to the
 * limit progress only once turnover is approaching/critical/exceeded; null
 * otherwise (no limit set, or still comfortably below), so callers show
 * nothing until it matters.
 */
export function useVatLimitStatus(companyId: Ref<string>) {
    const { company } = useInvoicingCompany(companyId);
    const { summary } = useVatReport(companyId);

    const status = computed<VatLimitProgress | null>(() =>
        activeVatLimitStatus(
            summary.value,
            Number(company.value?.vat_turnover_limit ?? 0),
            company.value?.default_currency ?? 'EUR',
        ),
    );

    return { status };
}
