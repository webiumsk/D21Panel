import { describe, expect, it } from 'vitest';
import { routeLightningAddress } from '../utils/lightningAddressRouting';
import { validateBlitzConnectionString } from '../utils/walletNwcHelpers';

describe('validateBlitzConnectionString', () => {
  it('accepts bare addresses, usernames and case-insensitive type values', () => {
    expect(validateBlitzConnectionString('satoshi@blitzwalletapp.com')).toBe(true);
    expect(validateBlitzConnectionString('type=blitz;ln-address=satoshi;')).toBe(true);
    expect(validateBlitzConnectionString('type=BLITZ;ln-address=satoshi;')).toBe(true);
  });

  it('rejects non-blitz domains and missing addresses', () => {
    expect(validateBlitzConnectionString('type=blitz;ln-address=user@otherwallet.example;')).toBe(false);
    expect(validateBlitzConnectionString('type=blitz;server=https://x;')).toBe(false);
    expect(validateBlitzConnectionString('satoshi@getalby.com')).toBe(false);
  });
});

describe('routeLightningAddress', () => {
  it('routes blink.sv addresses to blink with a canonical secret', () => {
    const route = routeLightningAddress('satoshi@blink.sv');
    expect(route).toEqual({
      target: 'blink',
      address: 'satoshi@blink.sv',
      connectionSecret: 'type=blink;ln-address=satoshi@blink.sv;',
    });
  });

  it('routes blitzwalletapp.com addresses to blitz with a canonical secret', () => {
    const route = routeLightningAddress('satoshi@blitzwalletapp.com');
    expect(route).toEqual({
      target: 'blitz',
      address: 'satoshi@blitzwalletapp.com',
      connectionSecret: 'type=blitz;ln-address=satoshi@blitzwalletapp.com;',
    });
  });

  it('routes flashapp.me addresses to flash with a canonical secret', () => {
    const route = routeLightningAddress('satoshi@flashapp.me');
    expect(route).toEqual({
      target: 'flash',
      address: 'satoshi@flashapp.me',
      connectionSecret: 'type=flash;ln-address=satoshi@flashapp.me;',
    });
  });

  it('routes any other Lightning address to cashu without a connection secret', () => {
    const route = routeLightningAddress('satoshi@getalby.com');
    expect(route).toEqual({
      target: 'cashu',
      address: 'satoshi@getalby.com',
      connectionSecret: null,
    });
  });

  it('routes cashu-wallet LN domains (coinos, minibits) to cashu', () => {
    expect(routeLightningAddress('x@coinos.io')?.target).toBe('cashu');
    expect(routeLightningAddress('x@minibits.cash')?.target).toBe('cashu');
  });

  it('is case-insensitive on the routed domains', () => {
    expect(routeLightningAddress('Satoshi@Blink.SV')?.target).toBe('blink');
    expect(routeLightningAddress('Satoshi@BlitzWalletApp.com')?.target).toBe('blitz');
    expect(routeLightningAddress('Satoshi@FlashApp.ME')?.target).toBe('flash');
  });

  it('rejects invalid Lightning addresses', () => {
    expect(routeLightningAddress('')).toBeNull();
    expect(routeLightningAddress('not-an-address')).toBeNull();
    expect(routeLightningAddress('satoshi@')).toBeNull();
    expect(routeLightningAddress('satoshi@nodot')).toBeNull();
    expect(routeLightningAddress('type=blink;ln-address=satoshi@blink.sv;')).toBeNull();
  });

  it('trims surrounding whitespace', () => {
    expect(routeLightningAddress('  satoshi@blink.sv  ')?.target).toBe('blink');
  });
});
