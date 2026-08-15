export type CompanyJurisdictionValue =
  | 'eu_sk'
  | 'eu_cz'
  | 'eu_de'
  | 'eu_at'
  | 'eu_other'
  | 'ch'
  | 'us'
  | 'uk'
  | 'offshore'
  | 'asia';

export const COMPANY_JURISDICTION_VALUES: CompanyJurisdictionValue[] = [
  'eu_sk',
  'eu_cz',
  'eu_de',
  'eu_at',
  'eu_other',
  'ch',
  'uk',
  'offshore',
  'asia',
  'us',
];

const UK_COUNTRIES = new Set(['GB', 'UK']);
const ASIA_COUNTRIES = new Set(['HK', 'SG', 'AE']);
const OFFSHORE_COUNTRIES = new Set(['GI', 'KY', 'PA', 'VG', 'BM', 'LU', 'MT', 'EE', 'LV', 'LT']);

const DEFAULT_COUNTRY_BY_JURISDICTION: Record<CompanyJurisdictionValue, string> = {
  eu_sk: 'SK',
  eu_cz: 'CZ',
  eu_de: 'DE',
  eu_at: 'AT',
  eu_other: 'PL',
  ch: 'CH',
  us: 'US',
  uk: 'GB',
  offshore: 'GI',
  asia: 'HK',
};

/**
 * Jurisdictions with a complete market setup (VAT policy, clauses,
 * e-invoicing). The rest stay visible in the picker but disabled until
 * their market work lands.
 */
export const ENABLED_COMPANY_JURISDICTIONS: readonly CompanyJurisdictionValue[] = [
  'eu_sk',
  'eu_cz',
  'eu_de',
  'us',
];

export function isCompanyJurisdictionEnabled(value: string | null | undefined): boolean {
  return (ENABLED_COMPANY_JURISDICTIONS as readonly string[]).includes(value ?? '');
}

/** i18n keys under invoicing.jurisdiction_* */
export const JURISDICTION_I18N_KEYS: Record<CompanyJurisdictionValue, string> = {
  eu_sk: 'jurisdiction_sk',
  eu_cz: 'jurisdiction_cz',
  eu_de: 'jurisdiction_de',
  eu_at: 'jurisdiction_at',
  eu_other: 'jurisdiction_eu',
  ch: 'jurisdiction_ch',
  us: 'jurisdiction_us',
  uk: 'jurisdiction_uk',
  offshore: 'jurisdiction_offshore',
  asia: 'jurisdiction_asia',
};

export function isUsJurisdiction(jurisdiction: string): boolean {
  return jurisdiction === 'us';
}

export function jurisdictionFromCountry(country: string | null | undefined): CompanyJurisdictionValue {
  const c = (country || '').trim().toUpperCase();
  if (c === 'SK') return 'eu_sk';
  if (c === 'CZ') return 'eu_cz';
  if (c === 'DE') return 'eu_de';
  if (c === 'AT') return 'eu_at';
  // Liechtenstein is part of the Swiss VAT (MWST) area.
  if (c === 'CH' || c === 'LI') return 'ch';
  if (c === 'US' || c === 'USA') return 'us';
  if (UK_COUNTRIES.has(c)) return 'uk';
  if (ASIA_COUNTRIES.has(c)) return 'asia';
  if (OFFSHORE_COUNTRIES.has(c)) return 'offshore';
  return 'eu_other';
}

export function defaultCountryForJurisdiction(jurisdiction: string): string {
  return DEFAULT_COUNTRY_BY_JURISDICTION[jurisdiction as CompanyJurisdictionValue] ?? 'SK';
}

export function countriesForJurisdiction(jurisdiction: string): string[] {
  switch (jurisdiction) {
    case 'eu_sk':
      return ['SK'];
    case 'eu_cz':
      return ['CZ'];
    case 'eu_de':
      return ['DE'];
    case 'eu_at':
      return ['AT'];
    case 'ch':
      return ['CH', 'LI'];
    case 'us':
      return ['US'];
    case 'uk':
      return ['GB'];
    case 'asia':
      return ['HK', 'SG', 'AE'];
    case 'offshore':
      return ['GI', 'KY', 'PA', 'VG', 'BM', 'LU', 'MT', 'EE', 'LV', 'LT'];
    default:
      return [
        'SK',
        'CZ',
        'PL',
        'AT',
        'DE',
        'FR',
        'IT',
        'ES',
        'NL',
        'BE',
        'CH',
        'IE',
        'FI',
        'CY',
        'HU',
        'PT',
      ];
  }
}

export function countryAllowedForJurisdiction(country: string, jurisdiction: string): boolean {
  const allowed = countriesForJurisdiction(jurisdiction);
  return allowed.includes((country || '').trim().toUpperCase());
}
