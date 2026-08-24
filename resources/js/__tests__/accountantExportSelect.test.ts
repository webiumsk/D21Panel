import { describe, expect, it } from 'vitest';
import {
    attachmentDecodedBytes,
    buildExpensePayload,
    chunkRangeByMonth,
    planAccountantExport,
    selectExpensesForExport,
    selectIssuedDocumentsForExport,
} from '../evolu/accountantExportSelect';
import type { EvoluDocumentRow } from '../evolu/documentMap';
import type { EvoluExpenseRow } from '../evolu/expenseMap';
import type { EvoluExpenseAttachmentRow } from '../evolu/expenseAttachmentCrud';
import type { CompanyId, DocumentId, DocumentType, ExpenseAttachmentId, ExpenseId } from '../evolu/schema';

const COMPANY = 'company-1';

function doc(partial: Omit<Partial<EvoluDocumentRow>, 'id'> & { id: string }): EvoluDocumentRow {
    return {
        companyId: COMPANY as CompanyId,
        contactId: null,
        documentType: 'invoice' as DocumentType,
        status: 'issued',
        quoteStatus: null,
        title: 'Doc',
        number: '2026001',
        sourceDocumentId: null,
        issueDate: '2026-03-15',
        deliveryDate: null,
        dueDate: null,
        variableSymbol: null,
        constantSymbol: null,
        specificSymbol: null,
        currency: 'EUR',
        subtotal: null,
        taxTotal: null,
        discountPercent: null,
        total: null,
        noteAboveLines: null,
        noteFooter: null,
        internalNote: null,
        pdfLocale: null,
        pdfBankQr: null,
        pdfShowSignature: null,
        pdfShowPaymentInfo: null,
        paymentBankEnabled: null,
        paymentBtcEnabled: null,
        storeId: null,
        tagsJson: null,
        paidAt: null,
        amountPaid: null,
        emailSentAt: null,
        ...partial,
        id: partial.id as DocumentId,
    };
}

function expense(partial: Omit<Partial<EvoluExpenseRow>, 'id'> & { id: string }): EvoluExpenseRow {
    return {
        companyId: COMPANY as CompanyId,
        status: 'recorded',
        internalNumber: 'EXP1',
        externalNumber: 'IN-1',
        title: 'Supplier',
        variableSymbol: null,
        constantSymbol: null,
        specificSymbol: null,
        issueDate: '2026-03-02',
        deliveryDate: null,
        dueDate: null,
        paidAt: null,
        cancelledAt: null,
        total: '88.50',
        currency: 'EUR',
        internalNote: null,
        ...partial,
        id: partial.id as ExpenseId,
    };
}

function attachment(partial: Omit<Partial<EvoluExpenseAttachmentRow>, 'id' | 'expenseId'> & { id: string; expenseId: string }): EvoluExpenseAttachmentRow {
    return {
        originalFilename: 'faktura.pdf',
        mimeType: 'application/pdf',
        sizeBytes: null,
        contentBase64: btoa('%PDF-1.4 fake'),
        ...partial,
        id: partial.id as ExpenseAttachmentId,
        expenseId: partial.expenseId as ExpenseId,
    };
}

const MARCH = { from: '2026-03-01', to: '2026-03-31' };

describe('accountant export selection', () => {
    it('picks numbered non-draft documents of the company inside the range, sorted', () => {
        const rows = [
            doc({ id: 'b', number: '2026002', issueDate: '2026-03-20' }),
            doc({ id: 'a', number: '2026001', issueDate: '2026-03-05' }),
            doc({ id: 'draft', status: 'draft', number: null, issueDate: '2026-03-06' }),
            doc({ id: 'april', number: '2026009', issueDate: '2026-04-01' }),
            doc({ id: 'other', number: '1', companyId: 'company-2' as CompanyId }),
            doc({ id: 'cancelled', number: '2026003', status: 'cancelled', issueDate: '2026-03-25' }),
        ];

        expect(selectIssuedDocumentsForExport(rows, COMPANY, MARCH).map((r) => r.id)).toEqual(['a', 'b', 'cancelled']);
    });

    it('picks non-cancelled expenses in the range', () => {
        const rows = [
            expense({ id: 'e1', internalNumber: 'EXP2', issueDate: '2026-03-10' }),
            expense({ id: 'e2', internalNumber: 'EXP1', issueDate: '2026-03-10' }),
            expense({ id: 'e3', status: 'cancelled' }),
            expense({ id: 'e4', issueDate: '2026-02-28' }),
            expense({ id: 'e5', issueDate: null }),
        ];

        expect(selectExpensesForExport(rows, COMPANY, MARCH).map((r) => r.id)).toEqual(['e2', 'e1']);
    });

    it('maps an expense with its attachments and skips unsupported or oversized ones', () => {
        const row = expense({ id: 'e1' });
        const attachments = [
            attachment({ id: 'a1', expenseId: 'e1' }),
            attachment({ id: 'a2', expenseId: 'e1', mimeType: 'application/x-msdownload' }),
            attachment({ id: 'a3', expenseId: 'e1', contentBase64: 'x'.repeat(700_001) }),
            attachment({ id: 'a4', expenseId: 'other' }),
            attachment({ id: 'a5', expenseId: 'e1', originalFilename: null, mimeType: 'image/png', contentBase64: btoa('png') }),
        ];

        const { payload, skippedAttachments } = buildExpensePayload(row, attachments, true);

        expect(payload.internal_number).toBe('EXP1');
        expect(payload.supplier_name).toBe('Supplier');
        expect(payload.total).toBe('88.50');
        expect(payload.attachments.map((a) => a.filename)).toEqual(['faktura.pdf', 'EXP1.bin']);
        expect(skippedAttachments).toBe(2);

        expect(buildExpensePayload(row, attachments, false).payload.attachments).toEqual([]);
    });

    it('estimates decoded size from base64 length', () => {
        expect(attachmentDecodedBytes(btoa('abc'))).toBe(3);
        expect(attachmentDecodedBytes(btoa('abcd'))).toBe(4);
        expect(attachmentDecodedBytes(btoa('ab'))).toBe(2);
        expect(attachmentDecodedBytes('')).toBe(0);
    });

    it('splits a range into calendar months keeping the edges', () => {
        expect(chunkRangeByMonth({ from: '2026-01-15', to: '2026-03-10' })).toEqual([
            { from: '2026-01-15', to: '2026-01-31' },
            { from: '2026-02-01', to: '2026-02-28' },
            { from: '2026-03-01', to: '2026-03-10' },
        ]);
        expect(chunkRangeByMonth({ from: '2026-03-01', to: '2026-03-31' })).toEqual([MARCH]);
        expect(chunkRangeByMonth({ from: '2026-04-01', to: '2026-03-31' })).toEqual([]);
    });

    it('plans a single request when under the caps and per-month chunks above them', () => {
        const documents = [doc({ id: 'a', issueDate: '2026-01-10' }), doc({ id: 'b', number: '2', issueDate: '2026-02-10' })];
        const expenses = [expense({ id: 'e1', issueDate: '2026-02-01' })];
        const attachments = [attachment({ id: 'a1', expenseId: 'e1' })];
        const range = { from: '2026-01-01', to: '2026-02-28' };

        const fits = planAccountantExport({ range, documents, expenses, attachments, companyId: COMPANY, includeAttachments: true });
        expect(fits.ranges).toEqual([range]);
        expect(fits).toMatchObject({ documents: 2, expenses: 1, attachments: 1, attachmentBytes: 13, skippedAttachments: 0 });

        const chunked = planAccountantExport({
            range,
            documents,
            expenses,
            attachments,
            companyId: COMPANY,
            includeAttachments: true,
            maxRows: 1,
        });
        expect(chunked.ranges).toEqual([
            { from: '2026-01-01', to: '2026-01-31' },
            { from: '2026-02-01', to: '2026-02-28' },
        ]);

        const noAttachments = planAccountantExport({ range, documents, expenses, attachments, companyId: COMPANY, includeAttachments: false });
        expect(noAttachments.attachments).toBe(0);
        expect(noAttachments.attachmentBytes).toBe(0);
    });
});
