import { describe, expect, it, vi } from 'vitest';

const contact = vi.hoisted(() => ({ insert: vi.fn(() => ({ ok: true, value: { id: 'new-contact' } })) }));
const inbox = vi.hoisted(() => ({ markImported: vi.fn(async () => undefined) }));

vi.mock('@/evolu/client', () => ({ allContactsQuery: { name: 'contacts' }, allDocumentsQuery: { name: 'documents' } }));
vi.mock('@/evolu/contactCrud', () => ({ insertLocalContactFromForm: contact.insert }));
vi.mock('@/evolu/efakturaInboxImport', () => ({
    markEfakturaInboxImported: inbox.markImported,
    isSelfBilledInboxEntry: (e: { draft?: { self_billed?: unknown } | null }) => e?.draft?.self_billed === true,
}));

import {
    bookedDocumentType,
    bookingTotals,
    contactFormFromCustomer,
    matchExistingContact,
    stableBookedDocumentId,
} from '@/evolu/efakturaSelfBilledBooking';
import type { EvoluContactRow } from '@/evolu/contactMap';

describe('self-billed booking - pure mapping', () => {
    it('maps the type code to invoice / credit_note', () => {
        expect(bookedDocumentType({ document_type_code: '389' })).toBe('invoice');
        expect(bookedDocumentType({ document_type_code: '261' })).toBe('credit_note');
        expect(bookedDocumentType({})).toBe('invoice');
    });

    it('builds a contact form from the UBL customer, falling back to legal name', () => {
        const form = contactFormFromCustomer({ name: 'A a.s.', registration_number: '123', vat_number: 'SK1', city: 'BA', country: 'SK' });
        expect(form.name).toBe('A a.s.');
        expect(form.registration_number).toBe('123');
        expect(form.vat_id).toBe('SK1');
        expect(form.city).toBe('BA');
        expect(contactFormFromCustomer({ legal_name: 'Legal s.r.o.' }).name).toBe('Legal s.r.o.');
        expect(contactFormFromCustomer(null).name).toBe('Odberateľ');
    });

    it('matches an existing contact by registration, then VAT, then name', () => {
        const rows = [
            { id: 'c-reg', name: 'X', registrationNumber: '111', vatId: null },
            { id: 'c-vat', name: 'Y', registrationNumber: null, vatId: 'SK999' },
            { id: 'c-name', name: 'Zed s.r.o.', registrationNumber: null, vatId: null },
        ] as unknown as EvoluContactRow[];
        expect(matchExistingContact(rows, { registration_number: '111' })).toBe('c-reg');
        expect(matchExistingContact(rows, { vat_number: 'SK999' })).toBe('c-vat');
        expect(matchExistingContact(rows, { name: 'zed s.r.o.' })).toBe('c-name');
        expect(matchExistingContact(rows, { registration_number: '000' })).toBeNull();
    });

    it('computes totals with the tax reconciled to the UBL payable amount', () => {
        const totals = bookingTotals({ total: '123.00', lines: [{ line_total: '100.00', tax_rate: '23' }] });
        expect(totals.subtotal).toBe('100.00');
        expect(totals.taxTotal).toBe('23.00');
        expect(totals.total).toBe('123.00');
    });

    it('derives a stable document id from the inbox uuid (idempotent), null when missing', () => {
        const a = stableBookedDocumentId('abc');
        expect(a).toBe(stableBookedDocumentId('abc'));
        expect(a).not.toBe(stableBookedDocumentId('def'));
        expect(stableBookedDocumentId('')).toBeNull();
    });
});

import { afterEach } from 'vitest';
import { bookSelfBilledInboxEntry } from '@/evolu/efakturaSelfBilledBooking';
import type { Evolu } from '@evolu/common/local-first';
import type { InvoicingLocalSchema } from '@/evolu/schema';

type Row = Record<string, unknown> & { id: string };
function fakeEvolu(seed: { contacts?: Row[]; documents?: Row[] }) {
    // Keyed by the query names the client mock exposes ('contacts'/'documents'),
    // while upserts record the real table names ('document'/'documentLine').
    const tables: Record<string, Row[]> = { contacts: seed.contacts ?? [], documents: seed.documents ?? [] };
    const upserts: Record<string, Row[]> = { document: [], documentLine: [] };
    const evolu = {
        loadQuery: vi.fn(async (q: { name: string }) => tables[q.name] ?? []),
        upsert: vi.fn((table: string, row: Row) => {
            (upserts[table] ??= []).push(row);
            return { ok: true, value: { id: row.id } };
        }),
    };
    return { evolu: evolu as unknown as Evolu<InvoicingLocalSchema>, upserts };
}

function selfBilledEntry(draft: Record<string, unknown>) {
    return {
        inbox_id: 'ibx', evolu_expense_id: 'uuid-1', external_document_id: 'ext', inbox_status: 'pending',
        created_at: '2026-06-01', draft: { self_billed: true, ...draft },
        summary: { supplier_name: null, external_number: null, total: null, currency: null },
    };
}

const sampleDraft = {
    document_type_code: '389', external_number: '20260909', issue_date: '2026-06-01', currency: 'EUR', total: '307.50',
    customer: { name: 'Odberateľ a.s.', registration_number: '36012345', vat_number: 'SK202', city: 'BA', country: 'SK' },
    lines: [{ name: 'Widget', quantity: '2.00', unit: 'ks', unit_price: '100.00', tax_rate: '23.00', line_total: '246.00' }],
};

afterEach(() => { inbox.markImported.mockClear(); contact.insert.mockClear(); });

describe('self-billed booking - orchestration', () => {
    it('books a self-billed entry into an issued document with lines and a new contact', async () => {
        const { evolu, upserts } = fakeEvolu({});
        const result = await bookSelfBilledInboxEntry(evolu, 'company-b', 'bridge', selfBilledEntry(sampleDraft));

        expect(result.ok).toBe(true);
        expect(contact.insert).toHaveBeenCalledTimes(1);
        expect(upserts.document).toHaveLength(1);
        expect(upserts.document[0]).toMatchObject({ documentType: 'invoice', status: 'issued', number: '20260909', selfBilled: 1, contactId: 'new-contact' });
        expect(upserts.documentLine).toHaveLength(1);
        expect(upserts.documentLine[0]).toMatchObject({ name: 'Widget', unitPrice: '100.00' });
        expect(inbox.markImported).toHaveBeenCalledWith('bridge', 'ibx');
    });

    it('reuses an existing contact matched by registration number', async () => {
        const { evolu, upserts } = fakeEvolu({ contacts: [{ id: 'c-existing', companyId: 'company-b', name: 'X', registrationNumber: '36012345', vatId: null }] });
        const result = await bookSelfBilledInboxEntry(evolu, 'company-b', 'bridge', selfBilledEntry(sampleDraft));
        expect(result.ok).toBe(true);
        expect(contact.insert).not.toHaveBeenCalled();
        expect(upserts.document[0]).toMatchObject({ contactId: 'c-existing' });
    });

    it('refuses a duplicate number the supplier already has', async () => {
        const { evolu, upserts } = fakeEvolu({ documents: [{ id: 'other-doc', companyId: 'company-b', number: '20260909', documentType: 'invoice' }] });
        const result = await bookSelfBilledInboxEntry(evolu, 'company-b', 'bridge', selfBilledEntry(sampleDraft));
        expect(result).toEqual({ ok: false, error: 'duplicate_number' });
        expect(upserts.document).toHaveLength(0);
        expect(inbox.markImported).not.toHaveBeenCalled();
    });

    it('refuses a non-self-billed entry', async () => {
        const { evolu } = fakeEvolu({});
        const entry = { ...selfBilledEntry(sampleDraft), draft: { self_billed: false } };
        const result = await bookSelfBilledInboxEntry(evolu, 'company-b', 'bridge', entry);
        expect(result).toEqual({ ok: false, error: 'not_self_billed' });
    });

    it('maps a 261 type code to a credit note', async () => {
        const { evolu, upserts } = fakeEvolu({});
        await bookSelfBilledInboxEntry(evolu, 'company-b', 'bridge', selfBilledEntry({ ...sampleDraft, document_type_code: '261' }));
        expect(upserts.document[0]).toMatchObject({ documentType: 'credit_note' });
    });
});
