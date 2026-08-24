import { describe, expect, it } from 'vitest';
import {
  efakturaCompanyMode,
  isCompanyEfakturaEligible,
  isCompanyEfakturaInboundEligible,
} from '../composables/useCompanyEfakturaSettings';

// Mirrors the server split (CompanyEfakturaEligibility::supportsOutbound vs
// supportsInbound): only SK full VAT payers issue e-invoices, but every SK
// company must be able to receive them.
describe('e-faktura eligibility', () => {
  const skPayer = { jurisdiction: 'eu_sk', vat_payer: true, vat_status: 'payer' };
  const skNonPayer = { jurisdiction: 'eu_sk', vat_payer: false, vat_status: 'none' };
  const skPartial = { jurisdiction: 'eu_sk', vat_payer: false, vat_status: 'partial' };
  const czPayer = { jurisdiction: 'eu_cz', vat_payer: true, vat_status: 'payer' };

  it('outbound stays limited to SK full payers', () => {
    expect(isCompanyEfakturaEligible(skPayer)).toBe(true);
    expect(isCompanyEfakturaEligible(skNonPayer)).toBe(false);
    expect(isCompanyEfakturaEligible(skPartial)).toBe(false);
    expect(isCompanyEfakturaEligible(czPayer)).toBe(false);
  });

  it('inbound covers every SK company regardless of VAT status', () => {
    expect(isCompanyEfakturaInboundEligible(skPayer)).toBe(true);
    expect(isCompanyEfakturaInboundEligible(skNonPayer)).toBe(true);
    expect(isCompanyEfakturaInboundEligible(skPartial)).toBe(true);
    expect(isCompanyEfakturaInboundEligible(czPayer)).toBe(false);
    expect(isCompanyEfakturaInboundEligible(null)).toBe(false);
  });

  it('the global flag gates both directions', () => {
    expect(isCompanyEfakturaEligible(skPayer, false)).toBe(false);
    expect(isCompanyEfakturaInboundEligible(skNonPayer, false)).toBe(false);
    expect(efakturaCompanyMode(skPayer, false)).toBeNull();
  });

  it('resolves the UI mode per company', () => {
    expect(efakturaCompanyMode(skPayer)).toBe('full');
    expect(efakturaCompanyMode(skNonPayer)).toBe('inbound_only');
    expect(efakturaCompanyMode(skPartial)).toBe('inbound_only');
    expect(efakturaCompanyMode(czPayer)).toBeNull();
  });
});
