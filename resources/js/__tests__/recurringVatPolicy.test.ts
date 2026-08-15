import { describe, expect, it } from 'vitest';
import { taxOptionsForProfile } from '../evolu/recurringVatPolicy';
import type { EvoluCompanyRow } from '../evolu/companyMap';
import type { EvoluContactRow } from '../evolu/contactMap';
import type { DocumentLinePayload } from '../evolu/documentCrud';

function company(overrides: Partial<EvoluCompanyRow> = {}): EvoluCompanyRow {
  return {
    id: 'company-1',
    legalName: 'Test s.r.o.',
    jurisdiction: 'eu_sk',
    country: 'SK',
    vatPayer: 1,
    vatStatus: 'payer',
    vatRateDefault: '23',
    ...overrides,
  } as unknown as EvoluCompanyRow;
}

function contact(overrides: Partial<EvoluContactRow> = {}): EvoluContactRow {
  return {
    id: 'contact-1',
    name: 'Klient',
    country: 'SK',
    vatId: null,
    ...overrides,
  } as unknown as EvoluContactRow;
}

function line(taxRate: string | null): DocumentLinePayload {
  return { name: 'Item', quantity: '1', unit_price: '100', tax_rate: taxRate } as unknown as DocumentLinePayload;
}

describe('taxOptionsForProfile - recurring invoices follow the shared VAT policy', () => {
  it('§4 payer charges VAT domestically and without a contact', () => {
    for (const c of [contact(), null]) {
      const options = taxOptionsForProfile(company(), c);
      expect(options.lineTaxApplies()).toBe(true);
      expect(options.lineTaxRate(line('23'))).toBe(23);
      // Non-numeric line rate falls back to the company default.
      expect(options.lineTaxRate(line(null))).toBe(23);
    }
  });

  it('§4 payer + EU B2B counterparty with IČ DPH reverse charges (no VAT)', () => {
    const options = taxOptionsForProfile(
      company(),
      contact({ country: 'CZ', vatId: 'CZ12345678' }),
    );
    expect(options.lineTaxApplies()).toBe(false);
    expect(options.lineTaxRate(line('23'))).toBe(0);
  });

  it('§4 payer + EU B2C counterparty keeps normal VAT', () => {
    const options = taxOptionsForProfile(company(), contact({ country: 'CZ' }));
    expect(options.lineTaxApplies()).toBe(true);
    expect(options.lineTaxRate(line('23'))).toBe(23);
  });

  it('§7a company never charges VAT on recurring documents', () => {
    const partial = company({ vatStatus: 'partial' });
    for (const c of [contact(), contact({ country: 'CZ', vatId: 'CZ1' }), null]) {
      const options = taxOptionsForProfile(partial, c);
      expect(options.lineTaxApplies()).toBe(false);
      expect(options.lineTaxRate(line('23'))).toBe(0);
    }
  });

  it('non-payer never charges VAT', () => {
    const options = taxOptionsForProfile(company({ vatPayer: 0, vatStatus: 'none' }), contact());
    expect(options.lineTaxApplies()).toBe(false);
    expect(options.lineTaxRate(line('23'))).toBe(0);
  });

  it('US companies keep sales tax behavior', () => {
    const options = taxOptionsForProfile(
      company({ jurisdiction: 'us', country: 'US', vatRateDefault: '8.5' }),
      null,
    );
    expect(options.lineTaxApplies()).toBe(true);
    expect(options.lineTaxRate(line('8.5'))).toBe(8.5);
  });
});
