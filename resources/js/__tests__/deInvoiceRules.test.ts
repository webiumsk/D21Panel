import { describe, expect, it } from 'vitest';
import { DE_KLEINBETRAG_LIMIT_EUR, deFullInvoiceBuyerMissing } from '../evolu/deInvoiceRules';

const fullBuyer = {
  name: 'Muster GmbH',
  street: 'Hauptstraße 1',
  city: 'Berlin',
  postal_code: '10115',
};

function input(overrides: Partial<Parameters<typeof deFullInvoiceBuyerMissing>[0]> = {}) {
  return {
    documentType: 'invoice',
    jurisdiction: 'eu_de',
    currency: 'EUR',
    totalGross: 300,
    buyer: fullBuyer,
    ...overrides,
  };
}

describe('deFullInvoiceBuyerMissing (§ 33 UStDV / § 14 UStG)', () => {
  it('passes with a complete buyer above the limit', () => {
    expect(deFullInvoiceBuyerMissing(input())).toBe(false);
  });

  it('blocks above the limit without a buyer or with an incomplete address', () => {
    expect(deFullInvoiceBuyerMissing(input({ buyer: null }))).toBe(true);
    expect(deFullInvoiceBuyerMissing(input({ buyer: { ...fullBuyer, street: '' } }))).toBe(true);
    expect(deFullInvoiceBuyerMissing(input({ buyer: { ...fullBuyer, city: '  ' } }))).toBe(true);
    expect(deFullInvoiceBuyerMissing(input({ buyer: { ...fullBuyer, postal_code: null } }))).toBe(true);
    expect(deFullInvoiceBuyerMissing(input({ buyer: { name: 'Muster GmbH' } }))).toBe(true);
  });

  it('small-amount invoices (up to 250 EUR incl. VAT) pass without a buyer', () => {
    expect(deFullInvoiceBuyerMissing(input({ totalGross: DE_KLEINBETRAG_LIMIT_EUR, buyer: null }))).toBe(false);
    expect(deFullInvoiceBuyerMissing(input({ totalGross: 100, buyer: null }))).toBe(false);
    // Just above the limit blocks.
    expect(deFullInvoiceBuyerMissing(input({ totalGross: 250.01, buyer: null }))).toBe(true);
  });

  it('only applies to invoices - other document types issue freely', () => {
    for (const documentType of ['quote', 'proforma', 'credit_note', null, undefined]) {
      expect(deFullInvoiceBuyerMissing(input({ documentType, buyer: null }))).toBe(false);
    }
    expect(deFullInvoiceBuyerMissing(input({ documentType: 'invoice', buyer: null }))).toBe(true);
  });

  it('only applies to DE companies and EUR documents', () => {
    expect(deFullInvoiceBuyerMissing(input({ jurisdiction: 'eu_sk', buyer: null }))).toBe(false);
    expect(deFullInvoiceBuyerMissing(input({ jurisdiction: null, buyer: null }))).toBe(false);
    // The 250 EUR threshold is EUR-defined - other currencies are skipped.
    expect(deFullInvoiceBuyerMissing(input({ currency: 'CZK', buyer: null }))).toBe(false);
    expect(deFullInvoiceBuyerMissing(input({ currency: 'eur', buyer: null }))).toBe(true);
  });
});
