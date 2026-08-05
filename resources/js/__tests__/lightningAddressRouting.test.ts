import { describe, expect, it } from 'vitest';
import { routeLightningAddress } from '../utils/lightningAddressRouting';

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
