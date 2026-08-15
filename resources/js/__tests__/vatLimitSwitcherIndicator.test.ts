import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import type { VatLimitProgress } from '../evolu/vatReport';

const state = vi.hoisted(() => ({ status: null as VatLimitProgress | null }));

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string) => key }),
}));
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock('../composables/useInvoicingCompanies', () => ({
    useInvoicingCompanies: () => ({ companies: ref([]), loading: ref(false), refresh: vi.fn() }),
}));
vi.mock('../composables/useInvoicingLayout', () => ({
    useInvoicingLayout: () => ({ companyId: ref('company-1'), switchCompany: vi.fn() }),
}));
vi.mock('../composables/useVatLimitStatus', () => ({
    useVatLimitStatus: () => ({ status: ref(state.status) }),
}));

async function mountSwitcher() {
    const { default: Switcher } = await import('../components/invoicing/InvoicingCompanySwitcher.vue');
    return mount(Switcher, {
        props: { currentLabel: 'Webium s.r.o.', variant: 'dropdown' },
        global: { stubs: { InvoicingIcons: true } },
    });
}

function progress(level: VatLimitProgress['level'], percent: number): VatLimitProgress {
    return { level, percent, turnover: 0, limit: 50000 };
}

describe('company switcher VAT-limit indicator', () => {
    beforeEach(() => {
        state.status = null;
    });

    it('shows no dot when status is null (below threshold / no limit)', async () => {
        const wrapper = await mountSwitcher();
        expect(wrapper.find('span.rounded-full').exists()).toBe(false);
    });

    it('shows an amber dot when approaching', async () => {
        state.status = progress('approaching', 82);
        const wrapper = await mountSwitcher();
        const dot = wrapper.find('span.rounded-full');
        expect(dot.exists()).toBe(true);
        expect(dot.classes()).toContain('bg-amber-500');
    });

    it('shows a red dot when critical or exceeded', async () => {
        state.status = progress('exceeded', 104);
        const wrapper = await mountSwitcher();
        const dot = wrapper.find('span.rounded-full');
        expect(dot.exists()).toBe(true);
        expect(dot.classes()).toContain('bg-red-500');
    });
});
