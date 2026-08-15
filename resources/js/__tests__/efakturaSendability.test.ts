import { describe, expect, it } from 'vitest';
import { efakturaSendability, isEfakturaConfigured } from '../composables/useCompanyEfakturaSettings';

function skPayerCompany(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    jurisdiction: 'eu_sk',
    vat_payer: true,
    vat_status: 'payer',
    tax_id: '2023980035',
    registration_number: '47615681',
    country: 'SK',
    app_settings: {
      efaktura_enabled: true,
      efaktura_sapi_base_url: 'https://sapi.test',
      efaktura_sapi_client_id: 'client',
      efaktura_sapi_client_secret_set: true,
    },
    ...overrides,
  };
}

const skContact = { name: 'Buyer', country: 'SK', tax_id: '1234567890' };

describe('efakturaSendability', () => {
  it('returns null for companies outside the e-faktura scope', () => {
    expect(efakturaSendability(skPayerCompany({ jurisdiction: 'eu_cz' }), skContact)).toBeNull();
    expect(efakturaSendability(skPayerCompany({ vat_status: 'none', vat_payer: false }), skContact)).toBeNull();
    expect(efakturaSendability(skPayerCompany(), skContact, false)).toBeNull();
    expect(efakturaSendability(null, skContact)).toBeNull();
  });

  it('reports the setup gap before anything buyer-related', () => {
    const company = skPayerCompany({ app_settings: { efaktura_enabled: false } });
    expect(efakturaSendability(company, skContact)).toBe('not_configured');
  });

  it('walks the buyer reasons in order', () => {
    const company = skPayerCompany();
    expect(efakturaSendability(company, null)).toBe('no_contact');
    expect(efakturaSendability(company, { name: 'Buyer', country: 'DE' })).toBe('foreign_buyer');
    expect(efakturaSendability(company, { name: 'Buyer', country: 'SK' })).toBe('missing_ids');
    expect(efakturaSendability(company, skContact)).toBe('ok');
  });

  it('an explicit buyer Peppol ID also counts', () => {
    expect(
      efakturaSendability(skPayerCompany(), {
        name: 'Buyer',
        country: 'SK',
        peppol_participant_id: '0245:1234567890',
      }),
    ).toBe('ok');
  });
});

describe('isEfakturaConfigured with the derived participant ID', () => {
  it('accepts a company without the explicit ID when the DIC derives one', () => {
    // Mirror of the server: CompanyEfakturaSettings::configured() passes
    // with the derived ID since the phase-1 change.
    expect(isEfakturaConfigured(skPayerCompany())).toBe(true);
  });

  it('still fails without any registration data or explicit ID', () => {
    expect(
      isEfakturaConfigured(skPayerCompany({ tax_id: null, registration_number: null })),
    ).toBe(false);
  });
});
