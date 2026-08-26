import { describe, expect, it, vi } from 'vitest';

vi.mock('@/evolu/documentBulkLocal', () => ({
    canCancelLocalDocument: () => false,
    canDeleteLocalDocument: () => false,
}));
import { evoluDocumentToApi, type EvoluDocumentRow } from '@/evolu/documentMap';
import { buildIssuedSnapshotContentV1 } from '@/evolu/documentSnapshotCrud';

function row(overrides: Partial<EvoluDocumentRow>): EvoluDocumentRow {
    return { id: 'd1', companyId: 'c1', documentType: 'invoice', status: 'issued', ...overrides } as unknown as EvoluDocumentRow;
}

describe('self_billed mapping', () => {
    it('maps the selfBilled sqlite flag to the API self_billed boolean', () => {
        expect(evoluDocumentToApi(row({ selfBilled: 1 }), []).self_billed).toBe(true);
        expect(evoluDocumentToApi(row({ selfBilled: 0 }), []).self_billed).toBe(false);
    });

    it('defaults self_billed to false when the flag is null or absent', () => {
        expect(evoluDocumentToApi(row({ selfBilled: null }), []).self_billed).toBe(false);
        expect(evoluDocumentToApi(row({}), []).self_billed).toBe(false);
    });
});

describe('self_billed in the issued snapshot', () => {
    const base = {
        company: { legal_name: 'Webium s.r.o.' },
        contact: { name: 'Dodávateľ s.r.o.' },
        lines: [{ name: 'Služba', quantity: '1', unit: 'ks', unit_price: '100', tax_rate: '23', line_total: '123' }],
    };
    function snap(doc: Record<string, unknown>) {
        const r = buildIssuedSnapshotContentV1({
            company: base.company,
            contact: base.contact,
            document: { type: 'invoice', title: 'F', number: '20260001', currency: 'EUR', subtotal: '100.00', tax_total: '23.00', discount_percent: '0', total: '123.00', ...doc },
            lines: base.lines,
        });
        if (!r.ok) throw new Error(r.error);
        return r.value.document;
    }

    it('preserves a self-billed flag into the frozen snapshot (issue / edit / re-freeze)', () => {
        expect(snap({ self_billed: true }).self_billed).toBe(true);
    });

    it('defaults self_billed to false when the source document omits it', () => {
        expect(snap({}).self_billed).toBe(false);
        expect(snap({ self_billed: false }).self_billed).toBe(false);
    });
});
