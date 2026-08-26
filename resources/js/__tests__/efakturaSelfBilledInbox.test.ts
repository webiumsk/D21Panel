import { describe, expect, it, vi } from 'vitest';

vi.mock('@/services/api', () => ({ invoicingApi: {} }));
vi.mock('@/evolu/client', () => ({ allExpensesQuery: { name: 'expenses' } }));
vi.mock('@/evolu/expenseCrud', () => ({ insertLocalExpense: () => ({ ok: false }) }));
vi.mock('@/evolu/expenseAttachmentCrud', () => ({ insertLocalExpenseAttachmentFromBase64: () => ({ ok: true }), LOCAL_EXPENSE_ATTACHMENT_MAX_BYTES: 1 }));

import { importEfakturaInboxEntry, isSelfBilledInboxEntry, type EfakturaInboxEntry } from '@/evolu/efakturaInboxImport';
import type { Evolu } from '@evolu/common/local-first';
import type { InvoicingLocalSchema } from '@/evolu/schema';

function entry(draft: Record<string, unknown> | null): EfakturaInboxEntry {
    return {
        inbox_id: 'i1', evolu_expense_id: 'u1', external_document_id: 'ext', inbox_status: 'pending',
        created_at: '2026-06-01', draft,
        summary: { supplier_name: 'Supplier', external_number: 'N1', total: '10', currency: 'EUR' },
    };
}

describe('self-billed inbox entries', () => {
    it('detects a self-billed receipt from the parser flag', () => {
        expect(isSelfBilledInboxEntry(entry({ self_billed: true }))).toBe(true);
        expect(isSelfBilledInboxEntry(entry({ self_billed: false }))).toBe(false);
        expect(isSelfBilledInboxEntry(entry(null))).toBe(false);
    });

    it('refuses to import a self-billed sale as an expense', async () => {
        const evolu = {} as unknown as Evolu<InvoicingLocalSchema>;
        const result = await importEfakturaInboxEntry(evolu, 'c1', 'bridge', entry({ self_billed: true }));
        expect(result).toEqual({ ok: false, error: 'self_billed_not_expense' });
    });
});
