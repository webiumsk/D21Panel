import { describe, expect, it } from 'vitest';
import { detectWalletConnectionInput } from '../utils/detectWalletConnectionInput';

describe('detectWalletConnectionInput', () => {
  it('detects NWC uris', () => {
    const input =
      'nostr+walletconnect://abc1234567890123456789012345678901234567890123456789012345678901234?relay=wss%3A%2F%2Frelay.example.com&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    const result = detectWalletConnectionInput(input);
    expect(result.kind).toBe('nwc');
    expect(result.connectionType).toBe('nwc');
  });

  it('flags cashu-wallet NWC (minibits) as incompatible', () => {
    const input =
      'nostr+walletconnect://abc?relay=wss%3A%2F%2Frelay.minibits.cash&secret=deadbeef&lud16=x%40minibits.cash';
    const result = detectWalletConnectionInput(input);
    expect(result.kind).toBe('cashu_wallet_nwc');
    expect(result.cashuLightningAddress).toBe('x@minibits.cash');
  });

  it('detects bare blink.sv address as blink, not cashu', () => {
    const result = detectWalletConnectionInput('satoshi@blink.sv');
    expect(result.kind).toBe('blink');
    expect(result.normalizedSecret).toBe('type=blink;ln-address=satoshi@blink.sv;');
  });

  it('detects bare blitzwalletapp.com address as blitz, not cashu', () => {
    const result = detectWalletConnectionInput('satoshi@blitzwalletapp.com');
    expect(result.kind).toBe('blitz');
    expect(result.connectionType).toBe('blitz');
    expect(result.storeWalletType).toBe('blitz');
    expect(result.normalizedSecret).toBe('type=blitz;ln-address=satoshi@blitzwalletapp.com;');
  });

  it('detects type=blitz; connection strings', () => {
    const result = detectWalletConnectionInput('type=blitz;ln-address=satoshi;');
    expect(result.kind).toBe('blitz');
  });

  it('detects bare flashapp.me address as flash, not cashu', () => {
    const result = detectWalletConnectionInput('satoshi@flashapp.me');
    expect(result.kind).toBe('flash');
    expect(result.normalizedSecret).toBe('type=flash;ln-address=satoshi@flashapp.me;');
  });

  it('detects type=flash; connection strings', () => {
    expect(detectWalletConnectionInput('type=flash;ln-address=satoshi;').kind).toBe('flash');
  });

  it('detects any other Lightning address as cashu', () => {
    const result = detectWalletConnectionInput('satoshi@getalby.com');
    expect(result.kind).toBe('cashu');
    expect(result.cashuLightningAddress).toBe('satoshi@getalby.com');
    expect(result.cashuMintUrl).toBeNull();
  });

  it('detects a mint url plus Lightning address pair as cashu', () => {
    const result = detectWalletConnectionInput(
      'https://mint.minibits.cash/Bitcoin\nsatoshi@getalby.com',
    );
    expect(result.kind).toBe('cashu');
    expect(result.cashuMintUrl).toBe('https://mint.minibits.cash/Bitcoin');
    expect(result.cashuLightningAddress).toBe('satoshi@getalby.com');
  });

  it('detects aqua and bull descriptors with brand', () => {
    const aqua = detectWalletConnectionInput(
      'ct(slip77(ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34),elsh(wpkh(xpub6CE9h9pKdmMzM11sbeuRA1AAnmL3k6PWNzPDNw2gAGHMthvbVChXbhAADsKanndLJ7neMMBeC3oEA4uqadycLz8xYQbCdMF2NoMVZjJU7rB/0/*)))',
    );
    expect(aqua.kind).toBe('aqua_descriptor');
    expect(aqua.brand).toBe('aqua');

    const bull = detectWalletConnectionInput(
      'ct(slip77(ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34),elwpkh(xpub6CE9h9pKdmMzM11sbeuRA1AAnmL3k6PWNzPDNw2gAGHMthvbVChXbhAADsKanndLJ7neMMBeC3oEA4uqadycLz8xYQbCdMF2NoMVZjJU7rB/0/*))',
    );
    expect(bull.kind).toBe('aqua_descriptor');
    expect(bull.brand).toBe('bull');
  });

  it('returns unknown for garbage input', () => {
    expect(detectWalletConnectionInput('hello world').kind).toBe('unknown');
    expect(detectWalletConnectionInput('').kind).toBe('unknown');
  });
});
