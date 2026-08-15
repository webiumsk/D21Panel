import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed } from 'vue';

const state = vi.hoisted(() => ({
  featureEnabled: true,
  contacts: [] as Record<string, unknown>[],
  contactPages: [] as Record<string, unknown>[][],
}));

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'en' },
  }),
}));
vi.mock('../evolu/flags', () => ({ isInvoicingLocalFirst: () => false }));
vi.mock('../composables/useEfakturaFeature', () => ({
  useEfakturaFeature: () => ({
    enabled: computed(() => state.featureEnabled),
    mandatoryFrom: computed(() => '2027-01-01'),
    load: async () => state.featureEnabled,
  }),
}));
vi.mock('../services/api', () => ({
  invoicingApi: {
    contacts: {
      list: async (_companyId: string, params?: { page?: number }) => {
        const pages = state.contactPages.length ? state.contactPages : [state.contacts];
        const page = Number(params?.page ?? 1);
        return { data: pages[page - 1] ?? [], meta: { last_page: pages.length } };
      },
    },
    companies: { get: vi.fn() },
  },
}));

import EfakturaReadinessCard from '../components/invoicing/EfakturaReadinessCard.vue';

function skPayerCompany(appSettings: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    jurisdiction: 'eu_sk',
    vat_payer: true,
    vat_status: 'payer',
    tax_id: '2023980035',
    app_settings: appSettings,
  };
}

function mountCard(company: Record<string, unknown> | null) {
  return mount(EfakturaReadinessCard, {
    props: { companyId: 'company-1', company },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  });
}

describe('EfakturaReadinessCard', () => {
  beforeEach(() => {
    window.localStorage.clear();
    state.featureEnabled = true;
    state.contacts = [{ name: 'Buyer', country: 'SK', tax_id: null, registration_number: null }];
    state.contactPages = [];
  });

  it('renders nothing for companies outside the e-faktura scope', async () => {
    const wrapper = mountCard({ jurisdiction: 'eu_cz', vat_payer: true, vat_status: 'payer' });
    await flushPromises();
    expect(wrapper.text()).toBe('');
  });

  it('shows the full checklist with done and pending steps when the module is on', async () => {
    const wrapper = mountCard(
      skPayerCompany({
        efaktura_enabled: true,
        efaktura_sapi_base_url: 'https://sapi.test',
        efaktura_sapi_client_id: 'client',
        efaktura_sapi_client_secret_set: true,
      }),
    );
    await flushPromises();

    expect(wrapper.text()).toContain('efaktura_readiness_intro');
    expect(wrapper.text()).toContain('efaktura_readiness_provider');
    expect(wrapper.text()).toContain('efaktura_readiness_tested');
    // One SK contact without IDs feeds the contacts step counter.
    expect(wrapper.text()).toContain('"count":1');
  });

  it('shows only the teaser and the contacts step while the module is globally off', async () => {
    state.featureEnabled = false;
    const wrapper = mountCard(skPayerCompany());
    await flushPromises();

    expect(wrapper.text()).toContain('efaktura_readiness_teaser');
    expect(wrapper.text()).toContain('efaktura_readiness_contacts');
    expect(wrapper.text()).not.toContain('efaktura_readiness_provider');
  });

  it('hides itself entirely once everything is ready', async () => {
    state.contacts = [{ name: 'Buyer', country: 'SK', tax_id: '1234567890' }];
    const wrapper = mountCard(
      skPayerCompany({
        efaktura_enabled: true,
        efaktura_sapi_base_url: 'https://sapi.test',
        efaktura_sapi_client_id: 'client',
        efaktura_sapi_client_secret_set: true,
        efaktura_connection_tested_at: '2026-07-23T10:00:00Z',
        efaktura_auto_send: false,
      }),
    );
    await flushPromises();

    // auto_send is only a recommendation - it must not keep the card alive.
    expect(wrapper.text()).toBe('');
  });

  it('counts uncovered contacts across every API page', async () => {
    state.contactPages = [
      [{ name: 'A', country: 'SK', tax_id: null }],
      [
        { name: 'B', country: 'SK', tax_id: null },
        { name: 'C', country: 'SK', tax_id: '1234567890' },
      ],
    ];
    const wrapper = mountCard(skPayerCompany());
    await flushPromises();

    // Two uncovered SK contacts live on different pages - both must count.
    expect(wrapper.text()).toContain('"count":2');
  });

  it('snoozes via localStorage', async () => {
    const wrapper = mountCard(skPayerCompany());
    await flushPromises();
    expect(wrapper.text()).toContain('efaktura_readiness_title');

    await wrapper.find('button').trigger('click');
    expect(wrapper.text()).toBe('');
    expect(window.localStorage.getItem('satflux.efaktura_readiness_snooze.company-1')).not.toBeNull();

    const again = mountCard(skPayerCompany());
    await flushPromises();
    expect(again.text()).toBe('');
  });
});
