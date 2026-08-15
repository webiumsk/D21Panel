/**
 * Domain-based routing of a bare Lightning address to a wallet connection target.
 * The primary onboarding path: the merchant types one Lightning address and we
 * pick the backend for them - no connection strings.
 *
 * - *@blink.sv               → Blink     (type=blink;ln-address=...; receive-only)
 * - curated LUD-21 domains   → lnaddress (Blitz, Flash, Coinos - type=lnaddress;...)
 * - known Cashu wallet (minibits) → CashuMelt directly
 * - any other LN address     → probe (server-side LUD-21 check decides lnaddress vs Cashu)
 */

import { isValidCashuLightningAddress } from './detectWalletConnectionInput';
import {
  isBareBlinkLightningAddress,
  normalizeBlinkConnectionString,
  CASHU_WALLET_LN_DOMAINS,
} from './walletNwcHelpers';
import {
  isCuratedLnAddressBareAddress,
  lnAddressBrandForAddress,
  lnAddressDomain,
  normalizeLnAddressConnectionString,
  type LnAddressWalletBrand,
} from './lnAddressWalletBrands';

export type LightningAddressTarget = 'blink' | 'lnaddress' | 'cashu' | 'probe';

export type LightningAddressRoute = {
  target: LightningAddressTarget;
  /** The address as typed, trimmed. */
  address: string;
  /** Connection secret for blink/lnaddress targets; null for cashu/probe. */
  connectionSecret: string | null;
  /** Curated wallet brand for lnaddress targets (blitz/flash/coinos), else null. */
  brand: LnAddressWalletBrand | null;
};

/**
 * Routes a Lightning address, or null when the input is not a valid Lightning address.
 */
export function routeLightningAddress(input: string): LightningAddressRoute | null {
  const address = input.trim();
  // Connection strings (type=...;) are not bare addresses - the advanced tab handles those.
  if (address.includes(';') || address.includes('=')) {
    return null;
  }
  if (!isValidCashuLightningAddress(address)) {
    return null;
  }

  if (isBareBlinkLightningAddress(address)) {
    return {
      target: 'blink',
      address,
      connectionSecret: normalizeBlinkConnectionString(address),
      brand: null,
    };
  }

  if (isCuratedLnAddressBareAddress(address)) {
    return {
      target: 'lnaddress',
      address,
      connectionSecret: normalizeLnAddressConnectionString(address),
      brand: lnAddressBrandForAddress(address),
    };
  }

  const domain = lnAddressDomain(address);
  if (
    domain &&
    CASHU_WALLET_LN_DOMAINS.some((d) => domain === d || domain.endsWith(`.${d}`))
  ) {
    return { target: 'cashu', address, connectionSecret: null, brand: null };
  }

  // Unknown domain - the server-side LUD-21 probe decides lnaddress vs Cashu.
  return { target: 'probe', address, connectionSecret: null, brand: null };
}
