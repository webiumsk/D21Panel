/**
 * Detect wallet connection kind from pasted merchant input (single textarea UX).
 */

export type DetectedWalletKind =
  | 'blink'
  | 'blitz'
  | 'flash'
  | 'nwc'
  | 'aqua_descriptor'
  | 'cashu'
  | 'cashu_wallet_nwc'
  | 'unknown';

export type AquaBoltzBrand = 'aqua' | 'bull';

export type WalletConnectionDetection = {
  kind: DetectedWalletKind;
  connectionType: 'blink' | 'blitz' | 'flash' | 'nwc' | 'aqua_descriptor' | null;
  storeWalletType: 'blink' | 'blitz' | 'flash' | 'nwc' | 'aqua_boltz' | 'cashu' | null;
  brand: AquaBoltzBrand | null;
  normalizedSecret: string | null;
  cashuMintUrl: string | null;
  cashuLightningAddress: string | null;
  confidence: 'high' | 'low';
};

import { isValidAquaBoltzDescriptor } from './aquaBoltzDescriptor';
import { detectWalletBrandFromDescriptor } from './aquaBoltzWalletBrand';
import {
  extractNwcLud16,
  isBareBlinkLightningAddress,
  isBareBlitzLightningAddress,
  isBareFlashLightningAddress,
  isCashuWalletNwcUri,
  looksLikeNwcUri,
  normalizeBlinkConnectionString,
  normalizeBlitzConnectionString,
  normalizeFlashConnectionString,
  normalizeNwcUri,
} from './walletNwcHelpers';

function looksLikeNwc(value: string): boolean {
  return looksLikeNwcUri(value);
}

function looksLikeBlink(value: string): boolean {
  if (value.toLowerCase().includes('type=blink;')) {
    return true;
  }
  const parts = value.split(';');
  let hasType = false;
  let hasServer = false;
  let hasApiKey = false;
  let hasWalletId = false;
  for (const part of parts) {
    const [rawKey, rawVal] = part.split('=');
    const key = (rawKey ?? '').trim().toLowerCase();
    const val = (rawVal ?? '').trim();
    if (key === 'type' && val === 'blink') hasType = true;
    if (key === 'server' && val) hasServer = true;
    if ((key === 'api-key' || key === 'apikey') && val) hasApiKey = true;
    if ((key === 'wallet-id' || key === 'walletid') && val) hasWalletId = true;
  }
  return hasType && hasServer && hasApiKey && hasWalletId;
}

function normalizeNwc(value: string): string {
  return normalizeNwcUri(value);
}

function looksLikeMintUrl(value: string): boolean {
  try {
    const url = new URL(value);
    return (
      url.protocol === 'https:' &&
      !value.toLowerCase().includes('nostr+walletconnect') &&
      !value.toLowerCase().includes('type=blink')
    );
  } catch {
    return false;
  }
}

function looksLikeLightningAddress(value: string): boolean {
  return isValidCashuLightningAddress(value);
}

/** Lightning Address for Cashu: local@domain.tld with at least 2 chars after the last dot. */
export const CASHU_LIGHTNING_ADDRESS_PATTERN = /^[^@\s]+@[^@\s]*\.[^@\s]{2,}$/;

export function isValidCashuLightningAddress(value: string): boolean {
  return CASHU_LIGHTNING_ADDRESS_PATTERN.test(value.trim());
}

function unknown(): WalletConnectionDetection {
  return {
    kind: 'unknown',
    connectionType: null,
    storeWalletType: null,
    brand: null,
    normalizedSecret: null,
    cashuMintUrl: null,
    cashuLightningAddress: null,
    confidence: 'low',
  };
}

export function detectWalletConnectionInput(input: string): WalletConnectionDetection {
  const trimmed = input.trim();
  if (!trimmed) {
    return unknown();
  }

  if (looksLikeNwc(trimmed)) {
    if (isCashuWalletNwcUri(trimmed)) {
      const lud16 = extractNwcLud16(trimmed);
      return {
        kind: 'cashu_wallet_nwc',
        connectionType: null,
        storeWalletType: null,
        brand: null,
        normalizedSecret: null,
        cashuMintUrl: null,
        cashuLightningAddress: lud16,
        confidence: 'high',
      };
    }

    return {
      kind: 'nwc',
      connectionType: 'nwc',
      storeWalletType: 'nwc',
      brand: null,
      normalizedSecret: normalizeNwc(trimmed),
      cashuMintUrl: null,
      cashuLightningAddress: null,
      confidence: 'high',
    };
  }

  // Bare 'name@blink.sv' is the non-custodial Blink shorthand - must win over the
  // Cashu Lightning-address heuristic below.
  if (looksLikeBlink(trimmed) || isBareBlinkLightningAddress(trimmed)) {
    return {
      kind: 'blink',
      connectionType: 'blink',
      storeWalletType: 'blink',
      brand: null,
      normalizedSecret: normalizeBlinkConnectionString(trimmed),
      cashuMintUrl: null,
      cashuLightningAddress: null,
      confidence: 'high',
    };
  }

  // Bare 'name@blitzwalletapp.com' or type=blitz; strings - same precedence rule as Blink.
  if (trimmed.toLowerCase().includes('type=blitz;') || isBareBlitzLightningAddress(trimmed)) {
    return {
      kind: 'blitz',
      connectionType: 'blitz',
      storeWalletType: 'blitz',
      brand: null,
      normalizedSecret: normalizeBlitzConnectionString(trimmed),
      cashuMintUrl: null,
      cashuLightningAddress: null,
      confidence: 'high',
    };
  }

  // Bare 'name@flashapp.me' or type=flash; strings - same precedence rule as Blink/Blitz.
  if (trimmed.toLowerCase().includes('type=flash;') || isBareFlashLightningAddress(trimmed)) {
    return {
      kind: 'flash',
      connectionType: 'flash',
      storeWalletType: 'flash',
      brand: null,
      normalizedSecret: normalizeFlashConnectionString(trimmed),
      cashuMintUrl: null,
      cashuLightningAddress: null,
      confidence: 'high',
    };
  }

  if (isValidAquaBoltzDescriptor(trimmed)) {
    return {
      kind: 'aqua_descriptor',
      connectionType: 'aqua_descriptor',
      storeWalletType: 'aqua_boltz',
      brand: detectWalletBrandFromDescriptor(trimmed),
      normalizedSecret: trimmed,
      cashuMintUrl: null,
      cashuLightningAddress: null,
      confidence: 'high',
    };
  }

  const lines = trimmed
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  let mintUrl: string | null = null;
  let lightningAddress: string | null = null;

  for (const line of lines) {
    if (looksLikeMintUrl(line)) {
      mintUrl = line;
      continue;
    }
    if (looksLikeLightningAddress(line)) {
      lightningAddress = line;
    }
  }

  if (!mintUrl && lines.length === 1 && looksLikeMintUrl(lines[0]!)) {
    mintUrl = lines[0]!;
  }

  if (
    !lightningAddress &&
    lines.length === 1 &&
    looksLikeLightningAddress(lines[0]!)
  ) {
    lightningAddress = lines[0]!;
  }

  if (mintUrl || lightningAddress) {
    return {
      kind: 'cashu',
      connectionType: null,
      storeWalletType: 'cashu',
      brand: null,
      normalizedSecret: null,
      cashuMintUrl: mintUrl,
      cashuLightningAddress: lightningAddress,
      confidence: 'high',
    };
  }

  return unknown();
}

export function detectionLabelKey(
  kind: DetectedWalletKind,
  brand: AquaBoltzBrand | null,
  detection?: Pick<WalletConnectionDetection, 'cashuMintUrl' | 'cashuLightningAddress'>,
): string {
  if (kind === 'aqua_descriptor') {
    return brand === 'bull' ? 'stores.wallet_detect_bull' : 'stores.wallet_detect_aqua';
  }
  if (kind === 'cashu' && detection) {
    if (detection.cashuLightningAddress && !detection.cashuMintUrl) {
      return 'stores.wallet_detect_cashu_ln';
    }
    if (detection.cashuMintUrl && !detection.cashuLightningAddress) {
      return 'stores.wallet_detect_cashu_mint';
    }
  }
  const map: Record<Exclude<DetectedWalletKind, 'aqua_descriptor'>, string> = {
    nwc: 'stores.wallet_detect_nwc',
    blink: 'stores.wallet_detect_blink',
    blitz: 'stores.wallet_detect_blitz',
    flash: 'stores.wallet_detect_flash',
    cashu: 'stores.wallet_detect_cashu',
    cashu_wallet_nwc: 'stores.wallet_detect_cashu_wallet_nwc',
    unknown: 'stores.wallet_detect_unknown',
  };
  return map[kind];
}
