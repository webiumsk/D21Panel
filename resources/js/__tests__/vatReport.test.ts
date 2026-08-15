import { describe, expect, it } from 'vitest';
import {
    activeVatLimitStatus,
    buildVatSummary,
    turnoverForCurrency,
    vatLimitProgress,
    vatSummaryToCsv,
} from '../evolu/vatReport';
import type { EvoluDocumentLineRow, EvoluDocumentRow } from '../evolu/documentMap';
import type { CompanyId, DocumentId, DocumentType } from '../evolu/schema';

function doc(partial: Omit<Partial<EvoluDocumentRow>, 'id'> & { id: string }): EvoluDocumentRow {
    return {
        id: partial.id as DocumentId,
        companyId: 'company-1' as CompanyId,
        contactId: null,
        documentType: (partial.documentType ?? 'invoice') as DocumentType,
        status: partial.status ?? 'issued',
        quoteStatus: null,
        title: 'Doc',
        number: partial.number ?? '2026001',
        sourceDocumentId: null,
        issueDate: partial.issueDate ?? '2026-03-15',
        deliveryDate: null,
        dueDate: null,
        variableSymbol: null,
        constantSymbol: null,
        specificSymbol: null,
        currency: partial.currency ?? 'EUR',
        subtotal: partial.subtotal ?? '0',
        taxTotal: partial.taxTotal ?? '0',
        discountPercent: partial.discountPercent ?? null,
        total: partial.total ?? '0',
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
    };
}

function line(
    documentId: string,
    lineTotal: string,
    taxRate: string,
    id = `${documentId}-${taxRate}-${lineTotal}`,
): EvoluDocumentLineRow {
    return {
        id,
        documentId: documentId as DocumentId,
        sortOrder: null,
        name: 'Item',
        description: null,
        quantity: '1',
        unit: 'ks',
        unitPrice: null,
        lineDiscountPercent: null,
        taxRate,
        lineTotal,
        companyStockItemId: null,
        companyWarehouseId: null,
    };
}

const YEAR = { from: '2026-01-01', to: '2026-12-31' };

describe('buildVatSummary', () => {
    it('sums turnover, base and VAT for a single-rate invoice', () => {
        const documents = [
            doc({ id: 'd1', subtotal: '100.00', taxTotal: '23.00', total: '123.00' }),
        ];
        const lines = [line('d1', '123.00', '23')];

        const summary = buildVatSummary(documents, lines, YEAR);

        expect(summary.byCurrency).toHaveLength(1);
        const eur = summary.byCurrency[0];
        expect(eur.currency).toBe('EUR');
        expect(eur.documentCount).toBe(1);
        expect(eur.turnover).toBe(123);
        expect(eur.base).toBe(100);
        expect(eur.vat).toBe(23);
        expect(eur.byRate).toEqual([{ rate: 23, base: 100, vat: 23, gross: 123 }]);
    });

    it('breaks VAT down per rate and reconciles to stored totals', () => {
        // two rates on one invoice: 100@23% + 200@19%
        const documents = [
            doc({ id: 'd1', subtotal: '300.00', taxTotal: '61.00', total: '361.00' }),
        ];
        const lines = [line('d1', '123.00', '23'), line('d1', '238.00', '19')];

        const eur = buildVatSummary(documents, lines, YEAR).byCurrency[0];

        expect(eur.byRate).toEqual([
            { rate: 19, base: 200, vat: 38, gross: 238 },
            { rate: 23, base: 100, vat: 23, gross: 123 },
        ]);
        // per-rate bases/vat reconcile to the grand totals
        const baseSum = eur.byRate.reduce((s, b) => s + b.base, 0);
        const vatSum = eur.byRate.reduce((s, b) => s + b.vat, 0);
        expect(baseSum).toBe(eur.base);
        expect(vatSum).toBe(eur.vat);
    });

    it('reconciles the per-rate breakdown when a document-level discount was applied', () => {
        // 10% document discount already baked into stored scalars + line totals
        const documents = [
            doc({ id: 'd1', subtotal: '135.00', taxTotal: '31.05', total: '166.05', discountPercent: '10' }),
        ];
        const lines = [line('d1', '110.70', '23'), line('d1', '55.35', '23')];

        const eur = buildVatSummary(documents, lines, YEAR).byCurrency[0];

        expect(eur.byRate).toEqual([{ rate: 23, base: 135, vat: 31.05, gross: 166.05 }]);
        expect(eur.base).toBe(135);
        expect(eur.vat).toBe(31.05);
    });

    it('subtracts credit notes from turnover, base and VAT', () => {
        const documents = [
            doc({ id: 'd1', subtotal: '1000.00', taxTotal: '230.00', total: '1230.00' }),
            doc({
                id: 'd2',
                documentType: 'credit_note',
                subtotal: '100.00',
                taxTotal: '23.00',
                total: '123.00',
            }),
        ];
        const lines = [line('d1', '1230.00', '23'), line('d2', '123.00', '23')];

        const eur = buildVatSummary(documents, lines, YEAR).byCurrency[0];

        expect(eur.documentCount).toBe(2);
        expect(eur.turnover).toBe(1107);
        expect(eur.base).toBe(900);
        expect(eur.vat).toBe(207);
        expect(eur.byRate).toEqual([{ rate: 23, base: 900, vat: 207, gross: 1107 }]);
    });

    it('groups by currency and never sums across currencies', () => {
        const documents = [
            doc({ id: 'd1', currency: 'EUR', subtotal: '100', taxTotal: '23', total: '123' }),
            doc({ id: 'd2', currency: 'CZK', subtotal: '1000', taxTotal: '210', total: '1210' }),
        ];
        const lines = [line('d1', '123', '23'), line('d2', '1210', '21')];

        const summary = buildVatSummary(documents, lines, YEAR);

        expect(summary.byCurrency.map((c) => c.currency)).toEqual(['CZK', 'EUR']);
        expect(summary.byCurrency.find((c) => c.currency === 'EUR')?.turnover).toBe(123);
        expect(summary.byCurrency.find((c) => c.currency === 'CZK')?.turnover).toBe(1210);
    });

    it('filters by issue-date range', () => {
        const documents = [
            doc({ id: 'in', issueDate: '2026-02-10', subtotal: '100', taxTotal: '0', total: '100' }),
            doc({ id: 'before', issueDate: '2025-12-31', subtotal: '999', taxTotal: '0', total: '999' }),
            doc({ id: 'after', issueDate: '2026-04-01', subtotal: '999', taxTotal: '0', total: '999' }),
        ];
        const lines = [line('in', '100', '0')];

        const q1 = buildVatSummary(documents, lines, { from: '2026-01-01', to: '2026-03-31' });

        expect(q1.byCurrency).toHaveLength(1);
        expect(q1.byCurrency[0].turnover).toBe(100);
        expect(q1.byCurrency[0].documentCount).toBe(1);
    });

    it('excludes drafts, quotes and proformas by default', () => {
        const documents = [
            doc({ id: 'issued', subtotal: '100', taxTotal: '0', total: '100' }),
            doc({ id: 'draft', status: 'draft', subtotal: '50', taxTotal: '0', total: '50' }),
            doc({ id: 'quote', documentType: 'quote', subtotal: '70', taxTotal: '0', total: '70' }),
            doc({ id: 'proforma', documentType: 'proforma', subtotal: '80', taxTotal: '0', total: '80' }),
        ];
        const lines = [line('issued', '100', '0')];

        const eur = buildVatSummary(documents, lines, YEAR).byCurrency[0];

        expect(eur.documentCount).toBe(1);
        expect(eur.turnover).toBe(100);
    });

    it('returns an empty summary when no documents fall in the period', () => {
        const documents = [doc({ id: 'd1', issueDate: '2024-06-01', total: '100' })];
        const summary = buildVatSummary(documents, [], YEAR);
        expect(summary.byCurrency).toEqual([]);
        expect(summary.from).toBe(YEAR.from);
        expect(summary.to).toBe(YEAR.to);
    });

    it('honours custom status/type options', () => {
        const documents = [
            doc({ id: 'd1', status: 'issued', total: '100', subtotal: '100', taxTotal: '0' }),
            doc({ id: 'd2', status: 'paid', total: '200', subtotal: '200', taxTotal: '0' }),
        ];
        const lines = [line('d1', '100', '0'), line('d2', '200', '0')];

        const paidOnly = buildVatSummary(documents, lines, YEAR, { statuses: ['paid'] });
        expect(paidOnly.byCurrency[0].turnover).toBe(200);
        expect(paidOnly.byCurrency[0].documentCount).toBe(1);
    });
});

describe('vatLimitProgress', () => {
    it('returns null when no positive limit is configured', () => {
        expect(vatLimitProgress(10000, 0)).toBeNull();
        expect(vatLimitProgress(10000, -5)).toBeNull();
    });

    it('reports ok below the approaching threshold', () => {
        const p = vatLimitProgress(39000, 50000);
        expect(p?.percent).toBe(78);
        expect(p?.level).toBe('ok');
    });

    it('flags approaching at 80% and critical at 95%', () => {
        expect(vatLimitProgress(40000, 50000)?.level).toBe('approaching');
        expect(vatLimitProgress(47000, 50000)?.level).toBe('approaching');
        expect(vatLimitProgress(47500, 50000)?.level).toBe('critical');
        expect(vatLimitProgress(49900, 50000)?.level).toBe('critical');
    });

    it('flags exceeded at or above 100%', () => {
        const p = vatLimitProgress(50000, 50000);
        expect(p?.level).toBe('exceeded');
        expect(p?.percent).toBe(100);
        expect(vatLimitProgress(60000, 50000)?.level).toBe('exceeded');
    });
});

describe('vatSummaryToCsv', () => {
    it('emits a BOM header, per-rate rows and a per-currency total row', () => {
        const documents = [
            doc({ id: 'd1', currency: 'EUR', subtotal: '300.00', taxTotal: '46.00', total: '346.00' }),
        ];
        const lines = [line('d1', '100.00', '0'), line('d1', '246.00', '23')];

        const csv = vatSummaryToCsv(buildVatSummary(documents, lines, YEAR));
        expect(csv.startsWith('﻿')).toBe(true);
        const rows = csv.replace('﻿', '').split('\r\n');

        expect(rows[0]).toBe('"Currency","Rate (%)","Base","VAT","Gross"');
        expect(rows).toContain('"EUR","0","100.00","0.00","100.00"');
        expect(rows).toContain('"EUR","23","200.00","46.00","246.00"');
        expect(rows).toContain('"EUR","Total","300.00","46.00","346.00"');
    });

    it('produces only a header for an empty summary', () => {
        const csv = vatSummaryToCsv(buildVatSummary([], [], YEAR));
        expect(csv).toBe('﻿"Currency","Rate (%)","Base","VAT","Gross"');
    });
});

describe('activeVatLimitStatus', () => {
    function summaryWithBase(base: number, currency = 'EUR') {
        const documents = [
            doc({ id: 'd1', currency, subtotal: String(base), taxTotal: '0', total: String(base) }),
        ];
        return buildVatSummary(documents, [line('d1', String(base), '0')], YEAR);
    }

    it('returns null when no limit is set', () => {
        expect(activeVatLimitStatus(summaryWithBase(90000), 0, 'EUR')).toBeNull();
    });

    it('returns null while comfortably below the threshold', () => {
        expect(activeVatLimitStatus(summaryWithBase(30000), 50000, 'EUR')).toBeNull();
    });

    it('surfaces the progress once approaching (>=80%)', () => {
        const status = activeVatLimitStatus(summaryWithBase(40000), 50000, 'EUR');
        expect(status?.level).toBe('approaching');
        expect(status?.percent).toBe(80);
    });

    it('surfaces exceeded', () => {
        expect(activeVatLimitStatus(summaryWithBase(60000), 50000, 'EUR')?.level).toBe('exceeded');
    });

    it('measures turnover in the given currency only', () => {
        const summary = summaryWithBase(40000, 'CZK');
        expect(activeVatLimitStatus(summary, 50000, 'EUR')).toBeNull();
        expect(activeVatLimitStatus(summary, 50000, 'CZK')?.level).toBe('approaching');
    });
});

describe('turnoverForCurrency', () => {
    it('returns the net base for the requested currency, 0 when absent', () => {
        const documents = [doc({ id: 'd1', currency: 'EUR', subtotal: '100', taxTotal: '23', total: '123' })];
        const summary = buildVatSummary(documents, [line('d1', '123', '23')], YEAR);
        expect(turnoverForCurrency(summary, 'EUR')).toBe(100);
        expect(turnoverForCurrency(summary, 'USD')).toBe(0);
    });
});
