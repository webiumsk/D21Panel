import { describe, expect, it, vi } from 'vitest';

vi.mock('@/services/api', () => ({
    invoicingApi: {
        efaktura: {
            inboxList: vi.fn(),
            inboxDetail: vi.fn(),
            inboxImported: vi.fn(),
            inboxDismiss: vi.fn(),
        },
    },
}));
vi.mock('@/evolu/client', () => ({ allExpensesQuery: {} }));

import {
    draftToExpensePayload,
    findLocalExpenseForInboxEntry,
    stableAttachmentIdFromInboxUuid,
    stableExpenseIdFromInboxUuid,
    utf8ToBase64,
    type EfakturaInboxEntry,
} from '@/evolu/efakturaInboxImport';
import { inboundEnabledFromSettingsJson } from '@/evolu/efakturaInboxLive';
import { base64DecodedLength } from '@/evolu/expenseAttachmentCrud';
import type { EvoluExpenseRow } from '@/evolu/expenseMap';
import type { CompanyId, ExpenseId } from '@/evolu/schema';

function entry(partial: Partial<EfakturaInboxEntry> = {}): EfakturaInboxEntry {
    return {
        inbox_id: 'receipt-1',
        evolu_expense_id: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b',
        external_document_id: 'inbound-42',
        inbox_status: 'pending',
        created_at: '2026-06-02T10:00:00Z',
        draft: {
            external_number: 'IN-7788',
            title: 'Supplier s.r.o.',
            variable_symbol: '7788',
            issue_date: '2026-06-02',
            delivery_date: '2026-06-02',
            due_date: '2026-06-16',
            total: '88.50',
            currency: 'EUR',
            internal_note: 'Importované z Peppol (SAPI-SK).',
        },
        summary: { supplier_name: 'Supplier s.r.o.', external_number: 'IN-7788', total: '88.50', currency: 'EUR' },
        ...partial,
    };
}

describe('efaktura inbox import helpers', () => {
    it('derives the same stable expense id for the same inbox uuid on every device', () => {
        const a = stableExpenseIdFromInboxUuid('0192A3B4-C5D6-7E8F-9A0B-1C2D3E4F5A6B');
        const b = stableExpenseIdFromInboxUuid(' 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b ');
        expect(a).not.toBeNull();
        expect(a).toBe(b);
        expect(stableExpenseIdFromInboxUuid('other')).not.toBe(a);
        expect(stableExpenseIdFromInboxUuid(null)).toBeNull();
        expect(stableExpenseIdFromInboxUuid('')).toBeNull();

        // The attachment id is deterministic too, and distinct from the expense id.
        const att = stableAttachmentIdFromInboxUuid('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b');
        expect(att).not.toBeNull();
        expect(att).toBe(stableAttachmentIdFromInboxUuid('0192A3B4-C5D6-7E8F-9A0B-1C2D3E4F5A6B'));
        expect(String(att)).not.toBe(String(a));
        expect(stableAttachmentIdFromInboxUuid(null)).toBeNull();
    });

    it('maps the server draft to an expense payload', () => {
        const payload = draftToExpensePayload(entry().draft, entry());
        expect(payload).toEqual({
            title: 'Supplier s.r.o.',
            external_number: 'IN-7788',
            variable_symbol: '7788',
            issue_date: '2026-06-02',
            delivery_date: '2026-06-02',
            due_date: '2026-06-16',
            total: '88.50',
            currency: 'EUR',
            internal_note: 'Importované z Peppol (SAPI-SK).',
        });
    });

    it('falls back to the summary and created_at when the draft is thin', () => {
        const thin = entry({ draft: null, created_at: '2026-07-01T08:00:00Z' });
        const payload = draftToExpensePayload(null, thin);
        expect(payload).toMatchObject({
            title: 'Supplier s.r.o.',
            external_number: 'IN-7788',
            issue_date: '2026-07-01',
            delivery_date: '2026-07-01',
            total: '88.50',
            currency: 'EUR',
        });
        expect(draftToExpensePayload(null, entry({ draft: null, created_at: null }))).toBeNull();
    });

    it('finds the local expense by stable id scoped to the company', () => {
        const stable = stableExpenseIdFromInboxUuid(entry().evolu_expense_id) as ExpenseId;
        const rows = [
            { id: stable, companyId: 'company-1' as CompanyId } as EvoluExpenseRow,
        ];
        expect(findLocalExpenseForInboxEntry(rows, 'company-1', entry())).toBe(rows[0]);
        expect(findLocalExpenseForInboxEntry(rows, 'company-2', entry())).toBeNull();
        expect(findLocalExpenseForInboxEntry(rows, 'company-1', entry({ evolu_expense_id: null }))).toBeNull();
    });

    it('encodes UTF-8 XML as base64 and sizes it', () => {
        const xml = '<Invoice><cbc:Name>Dodávateľ ľščťž</cbc:Name></Invoice>';
        const b64 = utf8ToBase64(xml);
        expect(base64DecodedLength(b64)).toBe(new TextEncoder().encode(xml).length);
        expect(new TextDecoder().decode(Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)))).toBe(xml);
    });

    it('reads the inbound switch from the company settings json', () => {
        expect(inboundEnabledFromSettingsJson(JSON.stringify({ efaktura_enabled: true, efaktura_inbound_enabled: true }))).toBe(true);
        expect(inboundEnabledFromSettingsJson(JSON.stringify({ efaktura_enabled: false, efaktura_inbound_enabled: true }))).toBe(false);
        expect(inboundEnabledFromSettingsJson(JSON.stringify({ efaktura_enabled: true }))).toBe(false);
        expect(inboundEnabledFromSettingsJson('{broken')).toBe(false);
        expect(inboundEnabledFromSettingsJson(null)).toBe(false);
    });
});
