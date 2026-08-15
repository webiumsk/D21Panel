import { describe, expect, it } from 'vitest';
import { hasEvoluMultiTabContention } from '../evolu/multiTab';

describe('hasEvoluMultiTabContention', () => {
    it('is true when the Evolu worker lock is held and another tab is pending', () => {
        expect(
            hasEvoluMultiTabContention({
                held: [{ name: 'evolu-sharedwebworker-satflux-invoicing' }],
                pending: [{ name: 'evolu-sharedwebworker-satflux-invoicing' }],
            }),
        ).toBe(true);
    });

    it('is false for a single tab (held, nothing pending)', () => {
        expect(
            hasEvoluMultiTabContention({
                held: [{ name: 'evolu-sharedwebworker-satflux-invoicing' }],
                pending: [],
            }),
        ).toBe(false);
    });

    it('is false when only non-Evolu locks are contended', () => {
        expect(
            hasEvoluMultiTabContention({
                held: [{ name: 'some-other-lock' }],
                pending: [{ name: 'some-other-lock' }],
            }),
        ).toBe(false);
    });

    it('is false when nothing is held even if something is pending', () => {
        expect(
            hasEvoluMultiTabContention({
                held: [],
                pending: [{ name: 'evolu-sharedwebworker-satflux-invoicing' }],
            }),
        ).toBe(false);
    });

    it('tolerates missing held/pending arrays', () => {
        expect(hasEvoluMultiTabContention({})).toBe(false);
    });
});
