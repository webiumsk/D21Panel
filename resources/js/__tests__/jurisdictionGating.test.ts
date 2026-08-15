import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import {
  COMPANY_JURISDICTION_VALUES,
  ENABLED_COMPANY_JURISDICTIONS,
  isCompanyJurisdictionEnabled,
} from '../config/companyJurisdiction';
import InvoicingJurisdictionSelect from '../components/invoicing/InvoicingJurisdictionSelect.vue';

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}));

describe('jurisdiction gating', () => {
  it('only SK, CZ, DE and US are enabled', () => {
    expect([...ENABLED_COMPANY_JURISDICTIONS]).toEqual(['eu_sk', 'eu_cz', 'eu_de', 'us']);
    for (const value of COMPANY_JURISDICTION_VALUES) {
      expect(isCompanyJurisdictionEnabled(value)).toBe(
        (ENABLED_COMPANY_JURISDICTIONS as readonly string[]).includes(value),
      );
    }
  });

  it('unknown or empty values are not enabled', () => {
    expect(isCompanyJurisdictionEnabled('')).toBe(false);
    expect(isCompanyJurisdictionEnabled(null)).toBe(false);
    expect(isCompanyJurisdictionEnabled(undefined)).toBe(false);
    expect(isCompanyJurisdictionEnabled('mars')).toBe(false);
  });

  it('select disables unavailable jurisdictions and suffixes their label', () => {
    const wrapper = mount(InvoicingJurisdictionSelect, {
      props: { modelValue: 'eu_sk' },
    });

    for (const option of wrapper.findAll('option')) {
      const value = option.attributes('value') ?? '';
      const enabled = isCompanyJurisdictionEnabled(value);
      expect(option.attributes('disabled')).toBe(enabled ? undefined : '');
      expect(option.text().includes('invoicing.jurisdiction_unavailable')).toBe(!enabled);
    }
  });

  it('select keeps the current unavailable jurisdiction selectable', () => {
    const wrapper = mount(InvoicingJurisdictionSelect, {
      props: { modelValue: 'ch' },
    });

    const current = wrapper.findAll('option').find((o) => o.attributes('value') === 'ch');
    expect(current).toBeDefined();
    // An existing company in a gated jurisdiction must not lose its value.
    expect(current?.attributes('disabled')).toBeUndefined();
    // The suffix still marks it as unavailable for new selections.
    expect(current?.text()).toContain('invoicing.jurisdiction_unavailable');

    // Other unavailable jurisdictions stay disabled.
    const other = wrapper.findAll('option').find((o) => o.attributes('value') === 'uk');
    expect(other?.attributes('disabled')).toBe('');
  });
});
