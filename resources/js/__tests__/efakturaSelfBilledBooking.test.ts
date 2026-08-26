import { describe, expect, it, vi } from 'vitest';

vi.mock('@/evolu/client', () => ({ allContactsQuery: { name: 'contacts' }, allDocumentsQuery: { name: 'documents' } }));
vi.mock('@/evolu/contactCrud', () => ({ insertLocalContactFromForm: () => ({ ok: false }) }));
vi.mock('@/evolu/efakturaInboxImport', () => ({ markEfakturaInboxImported: async () => undefined, isSelfBilledInboxEntry: () => true }));

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
