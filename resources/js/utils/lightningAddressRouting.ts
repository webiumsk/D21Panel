/**
 * Domain-based routing of a bare Lightning address to a wallet connection target.
 * The primary onboarding path: the merchant types one Lightning address and we
 * pick the backend for them - no connection strings.
 *
 * - *@blink.sv            → Blink   (type=blink;ln-address=...; receive-only)
 * - *@blitzwalletapp.com  → Blitz   (type=blitz;ln-address=...; receive-only)
 * - *@flashapp.me         → Flash   (type=flash;ln-address=...; receive-only)
 * - any other LN address  → CashuMelt (default mint + this address as payout)
 */

import { isValidCashuLightningAddress } from './detectWalletConnectionInput';
import {
  isBareBlinkLightningAddress,
  isBareBlitzLightningAddress,
  isBareFlashLightningAddress,
  normalizeBlinkConnectionString,
  normalizeBlitzConnectionString,
  normalizeFlashConnectionString,
} from './walletNwcHelpers';

export type LightningAddressTarget = 'blink' | 'blitz' | 'flash' | 'cashu';

export type LightningAddressRoute = {
  target: LightningAddressTarget;
  /** The address as typed, trimmed. */
  address: string;
  /** Connection secret for blink/blitz targets; null for cashu (uses settings API). */
  connectionSecret: string | null;
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
    };
  }

  if (isBareBlitzLightningAddress(address)) {
    return {
      target: 'blitz',
      address,
      connectionSecret: normalizeBlitzConnectionString(address),
    };
  }

  if (isBareFlashLightningAddress(address)) {
    return {
      target: 'flash',
      address,
      connectionSecret: normalizeFlashConnectionString(address),
    };
  }

  return { target: 'cashu', address, connectionSecret: null };
}
