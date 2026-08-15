import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, nextTick } from 'vue';

const state = vi.hoisted(() => ({
  presets: [] as { id: string; name: string; base_url: string }[],
  testResult: { ok: true, tested_at: '2026-07-23T10:00:00Z' } as Record<string, unknown>,
  testCalls: [] as Record<string, unknown>[],
}));

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'en' },
  }),
}));
vi.mock('../composables/useInvoicingSaveFeedback', () => ({
  useInvoicingSaveFeedback: () => ({ notifySaved: vi.fn() }),
}));
vi.mock('../evolu/flags', () => ({ isInvoicingLocalFirst: () => false }));
vi.mock('../evolu/client', () => ({
  allCompaniesDetailQuery: {},
  useInvoicingEvolu: () => null,
}));
vi.mock('../evolu/companyMap', () => ({ evoluCompanyToApi: (row: unknown) => row }));
vi.mock('../evolu/companySettingsCrud', () => ({ updateLocalEfakturaSettings: vi.fn() }));
vi.mock('../composables/useInvoicingCompany', () => ({ asCompanyId: (id: string) => id }));
vi.mock('../store/stores', () => ({ useStoresStore: () => ({ stores: [] }) }));
vi.mock('../composables/useEfakturaFeature', () => ({
  useEfakturaFeature: () => ({
    enabled: computed(() => true),
    loaded: computed(() => true),
    cpdsPresets: computed(() => state.presets),
    mandatoryFrom: computed(() => '2027-01-01'),
    load: async () => true,
  }),
}));
vi.mock('../services/api', () => ({
  invoicingApi: {
    efaktura: {
      testConnection: async (_companyId: string, payload: Record<string, unknown>) => {
        state.testCalls.push(payload);
        return state.testResult;
      },
      testConnectionEphemeral: vi.fn(),
      pollInbound: vi.fn(),
    },
    companies: { updateAppSettings: vi.fn() },
  },
}));

import EfakturaForm from '../components/invoicing/CompanyEfakturaSettingsForm.vue';

function skCompany(appSettings: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 'company-1',
    jurisdiction: 'eu_sk',
    tax_id: '2023980035',
    registration_number: '47615681',
    country: 'SK',
    app_settings: { efaktura_enabled: true, ...appSettings },
  };
}

function mountForm(company: Record<string, unknown>) {
  return mount(EfakturaForm, {
    props: { companyId: 'company-1', company },
    global: { stubs: { 'i18n-t': true } },
  });
}

describe('eFaktura settings wizard', () => {
  beforeEach(() => {
    state.presets = [];
    state.testResult = { ok: true, tested_at: '2026-07-23T10:00:00Z' };
    state.testCalls = [];
  });

  it('selecting a CPDS preset fills the base URL and hides the manual input', async () => {
    state.presets = [
      { id: 'p1', name: 'Postman One', base_url: 'https://one.test' },
      { id: 'p2', name: 'Postman Two', base_url: 'https://two.test' },
    ];
    const wrapper = mountForm(skCompany());
    await nextTick();

    const select = wrapper.find('#efaktura-cpds-preset');
    expect(select.exists()).toBe(true);
    await select.setValue('p2');

    expect(wrapper.find('#efaktura-base-url').exists()).toBe(false);
    expect(wrapper.text()).toContain('https://two.test');
  });

  it('the "other" option reveals the manual URL input', async () => {
    state.presets = [{ id: 'p1', name: 'Postman One', base_url: 'https://one.test' }];
    const wrapper = mountForm(skCompany());
    await nextTick();

    await wrapper.find('#efaktura-cpds-preset').setValue('custom');
    expect(wrapper.find('#efaktura-base-url').exists()).toBe(true);
  });

  it('a stored base URL matching a preset preselects it', async () => {
    state.presets = [{ id: 'p1', name: 'Postman One', base_url: 'https://one.test' }];
    const wrapper = mountForm(skCompany({ efaktura_sapi_base_url: 'https://one.test' }));
    await nextTick();
    await nextTick();

    expect((wrapper.find('#efaktura-cpds-preset').element as HTMLSelectElement).value).toBe('p1');
  });

  it('the connection test reports success and failure codes', async () => {
    const wrapper = mountForm(skCompany({ efaktura_sapi_base_url: 'https://one.test' }));
    await nextTick();

    const testButton = wrapper
      .findAll('button')
      .find((b) => b.text().includes('efaktura_test_connection'));
    expect(testButton).toBeDefined();

    await testButton!.trigger('click');
    await new Promise((resolve) => setTimeout(resolve));
    expect(wrapper.text()).toContain('invoicing.efaktura_test_ok');
    expect(state.testCalls).toHaveLength(1);

    state.testResult = { ok: false, code: 'invalid_credentials' };
    await testButton!.trigger('click');
    await new Promise((resolve) => setTimeout(resolve));
    expect(wrapper.text()).toContain('invoicing.efaktura_test_error_invalid_credentials');
  });

  it('shows the derived Peppol ID hint when no explicit override is set', async () => {
    const wrapper = mountForm(skCompany());
    await nextTick();

    // The derived hint carries the DIC-based ID computed client-side.
    expect(wrapper.text()).toContain('efaktura_peppol_derived_hint');
    expect(wrapper.text()).toContain('0245:2023980035');
  });

  it('a preset stored with a trailing slash still matches the saved URL', async () => {
    state.presets = [{ id: 'p1', name: 'Postman One', base_url: 'https://one.test/' }];
    const wrapper = mountForm(skCompany({ efaktura_sapi_base_url: 'https://one.test' }));
    await nextTick();
    await nextTick();

    expect((wrapper.find('#efaktura-cpds-preset').element as HTMLSelectElement).value).toBe('p1');
  });

  it('editing credentials invalidates the stored connection test', async () => {
    const wrapper = mountForm(
      skCompany({
        efaktura_sapi_base_url: 'https://one.test',
        efaktura_sapi_client_id: 'client-1',
        efaktura_connection_tested_at: '2026-07-20T10:00:00Z',
      }),
    );
    await nextTick();

    expect(wrapper.text()).toContain('efaktura_connection_tested_at');

    await wrapper.find('#efaktura-client-id').trigger('input');
    await nextTick();

    expect(wrapper.text()).not.toContain('efaktura_connection_tested_at');
  });

  it('turning the module on for the first time preselects auto-send', async () => {
    const wrapper = mountForm({
      ...skCompany(),
      app_settings: { efaktura_enabled: false },
    });
    await nextTick();

    const enable = wrapper.find('input[type="checkbox"]');
    await enable.setValue(true);
    await nextTick();

    const autoSend = wrapper
      .findAll('label')
      .find((l) => l.text().includes('efaktura_auto_send'))
      ?.find('input');
    expect((autoSend?.element as HTMLInputElement).checked).toBe(true);
  });
});
