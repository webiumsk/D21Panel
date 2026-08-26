import { describe, expect, it, vi } from 'vitest';

vi.mock('@/evolu/documentBulkLocal', () => ({
    canCancelLocalDocument: () => false,
    canDeleteLocalDocument: () => false,
}));
import { evoluDocumentToApi, type EvoluDocumentRow } from '@/evolu/documentMap';

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
