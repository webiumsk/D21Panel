/**
 * Curated LN address domains → wallet brand for the universal lnaddress
 * connection type (BTCPay "Satflux LN Address" plugin, LUD-21 verify).
 * Branding only - any domain whose LNURL server supports LUD-21 works; unknown
 * domains go through the server-side probe endpoint first.
 * Mirrors WalletConnectionValidator::LN_ADDRESS_WALLET_BRANDS.
 */

export type LnAddressWalletBrand = 'blitz' | 'flash' | 'coinos';

export const LN_ADDRESS_WALLET_BRANDS: Readonly<Record<string, LnAddressWalletBrand>> = {
  'blitzwalletapp.com': 'blitz',
  'flashapp.me': 'flash',
  'coinos.io': 'coinos',
};

/** Domain part of a Lightning address, lowercased; null when not an address. */
export function lnAddressDomain(address: string): string | null {
  const match = address.trim().match(/^[^@\s;=]+@([^@\s;=]+\.[^@\s;=]{2,})$/);
  return match?.[1]?.toLowerCase() ?? null;
}

/** Wallet brand for an address at a curated LUD-21 domain, else null. */
export function lnAddressBrandForAddress(address: string): LnAddressWalletBrand | null {
  const domain = lnAddressDomain(address);
  return domain ? (LN_ADDRESS_WALLET_BRANDS[domain] ?? null) : null;
}

/** Bare Lightning address at one of the curated LUD-21 wallet domains. */
export function isCuratedLnAddressBareAddress(value: string): boolean {
  return lnAddressBrandForAddress(value) !== null;
}

/** Canonical BTCPay lnaddress connection string (bare address shorthand → full form). */
export function normalizeLnAddressConnectionString(value: string): string {
  const trimmed = value.trim();
  if (!trimmed.includes(';') && !trimmed.includes('=') && lnAddressDomain(trimmed)) {
    return `type=lnaddress;ln-address=${trimmed};`;
  }
  return trimmed;
}

/**
 * lnaddress connection string - type=lnaddress;ln-address=user@domain;
 * (aliases lnaddress, username; no default domain - full address required)
 * or a bare user@domain address.
 */
export function validateLnAddressConnectionString(connectionString: string): boolean {
  const trimmed = connectionString.trim();
  if (!trimmed) return false;
  if (!trimmed.includes(';') && !trimmed.includes('=')) {
    return lnAddressDomain(trimmed) !== null;
  }
  if (!trimmed.toLowerCase().includes('type=lnaddress')) return false;
  const parts = trimmed
    .split(';')
    .map((p) => p.trim())
    .filter(Boolean);
  let typeVal = '';
  let lnAddressVal = '';
  for (const part of parts) {
    const eq = part.indexOf('=');
    if (eq === -1) continue;
    const key = part.slice(0, eq).trim().toLowerCase();
    const value = part.slice(eq + 1).trim();
    if (key === 'type') typeVal = value.toLowerCase();
    if (key === 'ln-address' || key === 'lnaddress' || key === 'username') lnAddressVal = value;
  }
  if (typeVal !== 'lnaddress' || !lnAddressVal) return false;
  return lnAddressDomain(lnAddressVal) !== null;
}

/** Lightning address carried by an lnaddress secret (bare or string form), else null. */
export function lnAddressFromConnectionSecret(secret: string): string | null {
  const trimmed = secret.trim();
  if (!trimmed.includes(';') && !trimmed.includes('=')) {
    return lnAddressDomain(trimmed) ? trimmed : null;
  }
  if (!validateLnAddressConnectionString(trimmed)) return null;
  const match = trimmed.match(/(?:ln-?address|username)=([^;=\s]+)/i);
  return match?.[1] ?? null;
}
