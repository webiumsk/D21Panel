/** EU member states where EU VAT (IČ DPH / VAT ID) typically applies. */
const EU_VAT_COUNTRY_CODES = new Set([
  'AT',
  'BE',
  'BG',
  'CY',
  'CZ',
  'DE',
  'DK',
  'EE',
  // VIES alias for Greece.
  'EL',
  'ES',
  'FI',
  'FR',
  'GR',
  'HR',
  'HU',
  'IE',
  'IT',
  'LT',
  'LU',
  'LV',
  'MT',
  'NL',
  'PL',
  'PT',
  'RO',
  'SE',
  'SI',
  'SK',
]);

export function countrySupportsVatPayer(country: string | null | undefined): boolean {
  if (!country) return false;
  return EU_VAT_COUNTRY_CODES.has(country.trim().toUpperCase());
}
