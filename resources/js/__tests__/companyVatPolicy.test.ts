import { describe, expect, it } from 'vitest';
import { useCompanyVatPolicy, type VatPolicyCompany } from '../composables/useCompanyVatPolicy';

function deCompany(vatStatus: 'none' | 'payer' | 'partial'): VatPolicyCompany {
  return {
    country: 'DE',
    jurisdiction: 'eu_de',
    vat_payer: vatStatus !== 'none',
    vat_status: vatStatus,
    vat_rate_default: 19,
  };
}

const policy = useCompanyVatPolicy();

function skCompany(vatStatus: 'none' | 'payer' | 'partial'): VatPolicyCompany {
  return {
    country: 'SK',
    jurisdiction: 'eu_sk',
    vat_payer: vatStatus !== 'none',
    vat_status: vatStatus,
    vat_rate_default: 23,
  };
}

const sk = { country: 'SK' };
const cz = { country: 'CZ' };
const us = { country: 'US' };
const freeText = { country: 'Nemecká spolková republika' };

describe('supplyRegion', () => {
  it('classifies domestic, EU and non-EU counterparties', () => {
    const company = skCompany('partial');
    expect(policy.supplyRegion(company, sk)).toBe('domestic');
    expect(policy.supplyRegion(company, null)).toBe('domestic');
    expect(policy.supplyRegion(company, cz)).toBe('eu');
    expect(policy.supplyRegion(company, us)).toBe('non_eu');
  });

  it('never treats an unrecognized country as EU', () => {
    expect(policy.supplyRegion(skCompany('partial'), freeText)).toBe('non_eu');
  });

  it('canonicalizes the Greek VIES alias EL to GR (domestic supply)', () => {
    const grCompany = { ...skCompany('payer'), country: 'GR' };
    expect(policy.supplyRegion(grCompany, { country: 'EL' })).toBe('domestic');
  });

  it('is company-country relative: a CZ company treats SK as EU', () => {
    const czCompany = { ...skCompany('payer'), country: 'CZ', jurisdiction: 'eu_cz' };
    expect(policy.supplyRegion(czCompany, { country: 'CZ' })).toBe('domestic');
    expect(policy.supplyRegion(czCompany, { country: 'SK' })).toBe('eu');
    expect(policy.euB2bReverseCharge(czCompany, { country: 'SK', vat_id: 'SK2020' })).toBe(true);
  });

  it('falls back to the jurisdiction country when the company has none', () => {
    const czNoCountry = { ...skCompany('payer'), country: null, jurisdiction: 'eu_cz' };
    // CZ buyer is domestic, never EU reverse charge.
    expect(policy.supplyRegion(czNoCountry, { country: 'CZ', vat_id: 'CZ123' })).toBe('domestic');
    // Multi-country buckets have no fallback - domestic-safe.
    const euOther = { ...skCompany('payer'), country: null, jurisdiction: 'eu_other' };
    expect(policy.supplyRegion(euOther, { country: 'DE', vat_id: 'DE123' })).toBe('domestic');
  });
});

describe('§4 payer + EU B2B (counterparty with IČ DPH) reverse charges automatically', () => {
  const czB2b = { country: 'CZ', vat_id: 'CZ12345678' };

  it('charges no VAT but keeps the summary and note', () => {
    const company = skCompany('payer');
    expect(policy.euB2bReverseCharge(company, czB2b)).toBe(true);
    expect(policy.calculatesVatAmounts(company, czB2b)).toBe(false);
    expect(policy.resolveLineTaxRate(company, czB2b, 23)).toBe(0);
    expect(policy.showsVatSummary(company, czB2b)).toBe(true);
    expect(policy.reverseChargeApplies(company, czB2b)).toBe(true);
  });

  it('EU B2C (no vat_id) keeps normal VAT', () => {
    const company = skCompany('payer');
    expect(policy.euB2bReverseCharge(company, cz)).toBe(false);
    expect(policy.calculatesVatAmounts(company, cz)).toBe(true);
    expect(policy.resolveLineTaxRate(company, cz, 23)).toBe(23);
    expect(policy.reverseChargeApplies(company, cz)).toBe(false);
  });

  it('does not apply domestically or outside the EU even with a vat_id', () => {
    const company = skCompany('payer');
    expect(policy.euB2bReverseCharge(company, { country: 'SK', vat_id: 'SK123' })).toBe(false);
    expect(policy.euB2bReverseCharge(company, { country: 'US', vat_id: 'X1' })).toBe(false);
  });

  it('non-payer with an EU B2B counterparty stays without VAT and without the note', () => {
    const company = skCompany('none');
    expect(policy.euB2bReverseCharge(company, czB2b)).toBe(false);
    expect(policy.showsVatSummary(company, czB2b)).toBe(false);
    expect(policy.reverseChargeApplies(company, czB2b)).toBe(false);
  });
});

describe('showsVatSummary - the invoice VAT display matrix', () => {
  it('full payer (§4) always shows VAT', () => {
    const company = skCompany('payer');
    expect(policy.showsVatSummary(company, sk)).toBe(true);
    expect(policy.showsVatSummary(company, cz)).toBe(true);
    expect(policy.showsVatSummary(company, us)).toBe(true);
    expect(policy.showsVatSummary(company, null)).toBe(true);
  });

  it('non-payer never shows VAT', () => {
    const company = skCompany('none');
    expect(policy.showsVatSummary(company, sk)).toBe(false);
    expect(policy.showsVatSummary(company, cz)).toBe(false);
    expect(policy.showsVatSummary(company, us)).toBe(false);
  });

  it('§7a shows VAT 0 only for EU counterparties', () => {
    const company = skCompany('partial');
    expect(policy.showsVatSummary(company, sk)).toBe(false);
    expect(policy.showsVatSummary(company, us)).toBe(false);
    expect(policy.showsVatSummary(company, cz)).toBe(true);
  });
});

describe('reverseChargeApplies', () => {
  it('applies only for §7a with an EU counterparty', () => {
    expect(policy.reverseChargeApplies(skCompany('partial'), cz)).toBe(true);
    expect(policy.reverseChargeApplies(skCompany('partial'), sk)).toBe(false);
    expect(policy.reverseChargeApplies(skCompany('partial'), us)).toBe(false);
    expect(policy.reverseChargeApplies(skCompany('partial'), freeText)).toBe(false);
    expect(policy.reverseChargeApplies(skCompany('payer'), cz)).toBe(false);
    expect(policy.reverseChargeApplies(skCompany('none'), cz)).toBe(false);
  });
});

describe('DE statutory clauses (taxClauseKind)', () => {
  it('DE non-payer always carries the Kleinunternehmer clause', () => {
    const company = deCompany('none');
    expect(policy.taxClauseKind(company, null)).toBe('kleinunternehmer_de');
    expect(policy.taxClauseKind(company, { country: 'DE' })).toBe('kleinunternehmer_de');
    expect(policy.taxClauseKind(company, { country: 'US' })).toBe('kleinunternehmer_de');
  });

  it('DE payer + EU B2B is reverse charge; + non-EU is the export clause', () => {
    const company = deCompany('payer');
    expect(policy.taxClauseKind(company, { country: 'FR', vat_id: 'FR123' })).toBe('reverse_charge');
    expect(policy.taxClauseKind(company, { country: 'US' })).toBe('export_de');
    expect(policy.taxClauseKind(company, { country: 'DE' })).toBe(null);
  });

  it('DE export exemption zeroes VAT', () => {
    const company = deCompany('payer');
    expect(policy.exportExemptionApplies(company, { country: 'US' })).toBe(true);
    expect(policy.calculatesVatAmounts(company, { country: 'US' })).toBe(false);
    expect(policy.resolveLineTaxRate(company, { country: 'US' }, 19)).toBe(0);
    // Domestic and EU B2C keep normal VAT.
    expect(policy.calculatesVatAmounts(company, { country: 'DE' })).toBe(true);
    expect(policy.calculatesVatAmounts(company, { country: 'FR' })).toBe(true);
  });

  it('the manual reverse-charge toggle applies regardless of region (server parity)', () => {
    const de = deCompany('payer');
    // Non-EU counterparty with a VAT id + manual toggle: reverse charge
    // wins over the export clause, mirroring the server precedence.
    expect(policy.taxClauseKind(de, { country: 'US', vat_id: 'X1' }, { reverse_charge: true }))
      .toBe('reverse_charge');
    // Without the toggle it stays the export clause.
    expect(policy.taxClauseKind(de, { country: 'US', vat_id: 'X1' })).toBe('export_de');
    // The toggle needs a VAT-registered counterparty.
    expect(policy.taxClauseKind(de, { country: 'US' }, { reverse_charge: true })).toBe('export_de');
    // Non-DE companies: the manual toggle also produces the clause.
    expect(policy.taxClauseKind(skCompany('payer'), { country: 'US', vat_id: 'X1' }, { reverse_charge: true }))
      .toBe('reverse_charge');
  });

  it('non-DE companies get no DE clauses and keep non-EU VAT', () => {
    const sk = skCompany('payer');
    expect(policy.taxClauseKind(sk, { country: 'US' })).toBe(null);
    expect(policy.exportExemptionApplies(sk, { country: 'US' })).toBe(false);
    expect(policy.calculatesVatAmounts(sk, { country: 'US' })).toBe(true);
    expect(policy.taxClauseKind(skCompany('none'), { country: 'SK' })).toBe(null);
    expect(policy.taxClauseKind(skCompany('partial'), { country: 'CZ' })).toBe('reverse_charge');
  });
});

describe('rates stay untouched by the display mode', () => {
  it('§7a never charges VAT, even on the EU reverse-charge invoice', () => {
    const company = skCompany('partial');
    expect(policy.resolveLineTaxRate(company, cz, 23)).toBe(0);
    expect(policy.resolveLineTaxRate(company, sk, 23)).toBe(0);
  });

  it('§4 payer charges the requested or default rate', () => {
    const company = skCompany('payer');
    expect(policy.resolveLineTaxRate(company, cz, 10)).toBe(10);
    expect(policy.resolveLineTaxRate(company, cz, null)).toBe(23);
  });

  it('the VAT rate column follows the same tiering for §7a', () => {
    const company = skCompany('partial');
    expect(policy.showsVatRateColumn(company, cz)).toBe(true);
    expect(policy.showsVatRateColumn(company, sk)).toBe(false);
    expect(policy.showsVatRateColumn(company, us)).toBe(false);
  });
});
