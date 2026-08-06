/** Domains/hosts that indicate NWC from a Cashu ecash wallet, not BTCPay store Lightning. */
const CASHU_WALLET_NWC_MARKERS = [
  'minibits',
  'coinos.io',
  'coinos.',
  'cashu.space',
  'mint.coinos',
] as const;

const CASHU_WALLET_LN_DOMAINS = [
  'minibits.cash',
  'coinos.io',
] as const;

export function normalizeNwcUri(value: string): string {
  let uri = value.trim();
  if (uri.toLowerCase().startsWith('type=nwc;')) {
    uri = uri.replace(/^type=nwc;key=/i, '');
  }
  return uri.replace('nostr+walletconnect://', 'nostr+walletconnect:');
}

export function looksLikeNwcUri(value: string): boolean {
  const lower = value.trim().toLowerCase();
  return (
    lower.startsWith('nostr+walletconnect:') ||
    lower.startsWith('nostr+walletconnect://') ||
    lower.startsWith('type=nwc;')
  );
}

export function extractNwcLud16(value: string): string | null {
  const uri = normalizeNwcUri(value);
  const match = uri.match(/[?&]lud16=([^&]+)/i);
  if (!match?.[1]) {
    return null;
  }
  try {
    return decodeURIComponent(match[1]).trim() || null;
  } catch {
    return match[1].trim() || null;
  }
}

export function isCashuWalletNwcUri(value: string): boolean {
  if (!looksLikeNwcUri(value)) {
    return false;
  }

  const lower = normalizeNwcUri(value).toLowerCase();

  if (CASHU_WALLET_NWC_MARKERS.some((marker) => lower.includes(marker))) {
    return true;
  }

  const lud16 = extractNwcLud16(value);
  if (lud16) {
    const domain = lud16.split('@')[1]?.toLowerCase();
    if (domain && CASHU_WALLET_LN_DOMAINS.some((d) => domain === d || domain.endsWith(`.${d}`))) {
      return true;
    }
  }

  return false;
}

/** Lightning address at the blink.sv domain - the only domain Blink connections accept. */
export function isBareBlinkLightningAddress(value: string): boolean {
  return /^[^@\s;=]+@blink\.sv$/i.test(value.trim());
}

/** Canonical BTCPay Blink connection string (bare ln-address shorthand → full form). */
export function normalizeBlinkConnectionString(value: string): string {
  const trimmed = value.trim();
  if (isBareBlinkLightningAddress(trimmed)) {
    return `type=blink;ln-address=${trimmed};`;
  }
  return trimmed;
}

/**
 * Blink connection string - either custodial (type=blink;server=...;api-key=...;wallet-id=...),
 * non-custodial (type=blink;ln-address=you@blink.sv;), or the bare blink.sv address shorthand.
 */
export function validateBlinkConnectionString(connectionString: string): boolean {
  const trimmed = connectionString.trim();
  if (!trimmed) return false;
  if (isBareBlinkLightningAddress(trimmed)) return true;
  if (!trimmed.includes(';')) return false;
  const parts = trimmed
    .split(';')
    .map((p) => p.trim())
    .filter(Boolean);
  let typeVal = '';
  let serverVal = '';
  let apiKeyVal = '';
  let walletIdVal = '';
  let lnAddressVal = '';
  for (const part of parts) {
    const eq = part.indexOf('=');
    if (eq === -1) continue;
    const key = part.slice(0, eq).trim().toLowerCase();
    const value = part.slice(eq + 1).trim();
    if (key === 'type') typeVal = value;
    if (key === 'server') serverVal = value;
    if (key === 'api-key' || key === 'apikey') apiKeyVal = value;
    if (key === 'wallet-id' || key === 'walletid') walletIdVal = value;
    if (key === 'ln-address' || key === 'lnaddress' || key === 'username') lnAddressVal = value;
  }
  if (typeVal !== 'blink') return false;
  if (lnAddressVal) {
    // Plugin defaults a bare username to the blink.sv domain; other domains are rejected
    const address = lnAddressVal.includes('@') ? lnAddressVal : `${lnAddressVal}@blink.sv`;
    return isBareBlinkLightningAddress(address);
  }
  return !!serverVal && !!apiKeyVal && !!walletIdVal;
}

/** Lightning address at the blitzwalletapp.com domain - routes bare pastes to Blitz. */
export function isBareBlitzLightningAddress(value: string): boolean {
  return /^[^@\s;=]+@blitzwalletapp\.com$/i.test(value.trim());
}

/** Canonical BTCPay Blitz connection string (bare address shorthand → full form). */
export function normalizeBlitzConnectionString(value: string): string {
  const trimmed = value.trim();
  if (isBareBlitzLightningAddress(trimmed)) {
    return `type=blitz;ln-address=${trimmed};`;
  }
  return trimmed;
}

/**
 * Blitz connection string - type=blitz;ln-address=<user[@domain]>; (aliases lnaddress,
 * username; a bare username defaults to blitzwalletapp.com) or the bare address shorthand.
 */
export function validateBlitzConnectionString(connectionString: string): boolean {
  const trimmed = connectionString.trim();
  if (!trimmed) return false;
  if (isBareBlitzLightningAddress(trimmed)) return true;
  if (!trimmed.toLowerCase().includes('type=blitz')) return false;
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
  if (typeVal !== 'blitz' || !lnAddressVal) return false;
  // Same domain gate as Blink: bare usernames default to blitzwalletapp.com,
  // other domains belong to the Cashu path.
  const address = lnAddressVal.includes('@') ? lnAddressVal : `${lnAddressVal}@blitzwalletapp.com`;
  return isBareBlitzLightningAddress(address);
}

/**
 * Whether a connection secret already carries a Lightning address usable as the
 * CashuMelt fallback (blink/blitz ln-address forms). Backend derives it server-side;
 * this only gates whether the UI must ask for a separate fallback address.
 */
export function fallbackAddressDerivableFromSecret(secret: string): boolean {
  const s = secret.trim();
  if (isBareBlinkLightningAddress(s) || isBareBlitzLightningAddress(s)) return true;
  const lower = s.toLowerCase();
  if (!lower.includes('type=blink') && !lower.includes('type=blitz')) return false;
  return /(?:ln-?address|username)=[^;=\s]+/i.test(s);
}

export function validateNwcUri(value: string): boolean {
  if (isCashuWalletNwcUri(value)) {
    return false;
  }
  const uri = normalizeNwcUri(value);
  const lower = uri.toLowerCase();
  return (
    lower.startsWith('nostr+walletconnect:') &&
    uri.length >= 80 &&
    lower.includes('relay=') &&
    lower.includes('secret=') &&
    !/\s/.test(uri)
  );
}
