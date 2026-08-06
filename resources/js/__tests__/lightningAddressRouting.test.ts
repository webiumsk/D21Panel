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
      brand: null,
    });
  });

  it('routes curated LUD-21 domains to lnaddress with the wallet brand', () => {
    expect(routeLightningAddress('satoshi@blitzwalletapp.com')).toEqual({
      target: 'lnaddress',
      address: 'satoshi@blitzwalletapp.com',
      connectionSecret: 'type=lnaddress;ln-address=satoshi@blitzwalletapp.com;',
      brand: 'blitz',
    });
    expect(routeLightningAddress('satoshi@flashapp.me')).toEqual({
      target: 'lnaddress',
      address: 'satoshi@flashapp.me',
      connectionSecret: 'type=lnaddress;ln-address=satoshi@flashapp.me;',
      brand: 'flash',
    });
    expect(routeLightningAddress('satoshi@coinos.io')).toEqual({
      target: 'lnaddress',
      address: 'satoshi@coinos.io',
      connectionSecret: 'type=lnaddress;ln-address=satoshi@coinos.io;',
      brand: 'coinos',
    });
  });

  it('routes unknown domains to probe (server-side LUD-21 check decides)', () => {
    const route = routeLightningAddress('satoshi@getalby.com');
    expect(route).toEqual({
      target: 'probe',
      address: 'satoshi@getalby.com',
      connectionSecret: null,
      brand: null,
    });
  });

  it('routes known Cashu wallet domains (minibits) straight to cashu', () => {
    expect(routeLightningAddress('x@minibits.cash')?.target).toBe('cashu');
  });

  it('is case-insensitive on the routed domains', () => {
    expect(routeLightningAddress('Satoshi@Blink.SV')?.target).toBe('blink');
    expect(routeLightningAddress('Satoshi@BlitzWalletApp.com')?.target).toBe('lnaddress');
    expect(routeLightningAddress('Satoshi@FlashApp.ME')?.target).toBe('lnaddress');
    expect(routeLightningAddress('Satoshi@Coinos.IO')?.target).toBe('lnaddress');
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
