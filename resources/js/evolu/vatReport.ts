/**
 * VAT summary reporting - client-side aggregation over local-first Evolu
 * documents. All invoicing amounts are the source of truth in the browser
 * (see documentCrud.calcDocumentTotals), so a VAT report is derived here
 * without any server round-trip.
 *
 * Determinism / reconciliation:
 * - Grand totals (turnover / base / VAT) come straight from the stored
 *   scalar document fields (subtotal / taxTotal / total), which were already
 *   reconciled at save time.
 * - The per-rate breakdown is re-derived from the stored per-line gross
 *   (lineTotal) and rate (taxRate): net = gross / (1 + rate/100). Because
 *   calcDocumentTotals scales each lineTotal and the subtotal/taxTotal by the
 *   same document-discount ratio, the per-rate bases sum back exactly to the
 *   stored subtotal and the per-rate VAT to the stored taxTotal.
 * - Credit notes are stored as positive amounts with no sign; they are
 *   subtracted here (they reduce turnover and output VAT).
 * - Currencies are never summed together - the summary is grouped by the
 *   document currency (mirroring the dashboard-analytics convention).
 */
import type { EvoluDocumentLineRow, EvoluDocumentRow } from './documentMap';

/** Statuses that count as realised turnover for VAT purposes. */
export const VAT_REPORT_DEFAULT_STATUSES = ['issued', 'paid'] as const;
/** Document types that carry VAT-relevant turnover (credit notes subtract). */
export const VAT_REPORT_DEFAULT_TYPES = ['invoice', 'credit_note'] as const;

export const VAT_LIMIT_APPROACHING_PERCENT = 80;
export const VAT_LIMIT_CRITICAL_PERCENT = 95;

function toNumber(value: string | null | undefined): number {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}

/** Round to 2 decimals, half-up, guarding binary-float drift. */
function round2(value: number): number {
    return Math.round((value + Number.EPSILON) * 100) / 100;
}

function issueDateInRange(issueDate: string | null, from: string, to: string): boolean {
    if (!issueDate) return false;
    const day = issueDate.slice(0, 10);
    return day >= from && day <= to;
}

export interface VatRateBucket {
    /** VAT rate as a percentage, e.g. 23 or 0. */
    rate: number;
    /** Net taxable base at this rate. */
    base: number;
    /** VAT amount at this rate. */
    vat: number;
    /** Gross (base + vat) at this rate. */
    gross: number;
}

export interface VatSummaryByCurrency {
    currency: string;
    documentCount: number;
    /** Gross turnover (credit notes subtracted). */
    turnover: number;
    /** Net taxable base - the figure a VAT-registration limit is measured against. */
    base: number;
    /** Total output VAT. */
    vat: number;
    /** Per-rate breakdown, ascending by rate. */
    byRate: VatRateBucket[];
}

export interface VatSummary {
    from: string;
    to: string;
    byCurrency: VatSummaryByCurrency[];
}

export interface BuildVatSummaryOptions {
    statuses?: readonly string[];
    types?: readonly string[];
}

interface CurrencyAccumulator {
    documentCount: number;
    turnover: number;
    base: number;
    vat: number;
    rateBuckets: Map<number, { base: number; vat: number; gross: number }>;
}

/**
 * Aggregate a VAT summary for the given documents/lines over [from, to]
 * (inclusive, ISO YYYY-MM-DD), grouped by currency.
 */
export function buildVatSummary(
    documents: EvoluDocumentRow[],
    lines: EvoluDocumentLineRow[],
    range: { from: string; to: string },
    options: BuildVatSummaryOptions = {},
): VatSummary {
    const statuses = new Set(options.statuses ?? VAT_REPORT_DEFAULT_STATUSES);
    const types = new Set<string>(options.types ?? VAT_REPORT_DEFAULT_TYPES);
    const { from, to } = range;

    const matching = documents.filter(
        (doc) =>
            statuses.has(doc.status)
            && types.has(doc.documentType)
            && issueDateInRange(doc.issueDate, from, to),
    );

    const linesByDocument = new Map<string, EvoluDocumentLineRow[]>();
    for (const line of lines) {
        const bucket = linesByDocument.get(line.documentId);
        if (bucket) bucket.push(line);
        else linesByDocument.set(line.documentId, [line]);
    }

    const byCurrency = new Map<string, CurrencyAccumulator>();

    for (const doc of matching) {
        const currency = doc.currency ?? 'EUR';
        const sign = doc.documentType === 'credit_note' ? -1 : 1;

        let acc = byCurrency.get(currency);
        if (!acc) {
            acc = { documentCount: 0, turnover: 0, base: 0, vat: 0, rateBuckets: new Map() };
            byCurrency.set(currency, acc);
        }

        acc.documentCount += 1;
        acc.turnover += sign * toNumber(doc.total);
        acc.base += sign * toNumber(doc.subtotal);
        acc.vat += sign * toNumber(doc.taxTotal);

        for (const line of linesByDocument.get(doc.id) ?? []) {
            const gross = toNumber(line.lineTotal);
            const rate = toNumber(line.taxRate);
            const net = gross / (1 + rate / 100);
            const vat = gross - net;
            let bucket = acc.rateBuckets.get(rate);
            if (!bucket) {
                bucket = { base: 0, vat: 0, gross: 0 };
                acc.rateBuckets.set(rate, bucket);
            }
            bucket.base += sign * net;
            bucket.vat += sign * vat;
            bucket.gross += sign * gross;
        }
    }

    const result: VatSummaryByCurrency[] = [...byCurrency.entries()]
        .map(([currency, acc]) => ({
            currency,
            documentCount: acc.documentCount,
            turnover: round2(acc.turnover),
            base: round2(acc.base),
            vat: round2(acc.vat),
            byRate: [...acc.rateBuckets.entries()]
                .map(([rate, bucket]) => ({
                    rate,
                    base: round2(bucket.base),
                    vat: round2(bucket.vat),
                    gross: round2(bucket.gross),
                }))
                .sort((a, b) => a.rate - b.rate),
        }))
        .sort((a, b) => a.currency.localeCompare(b.currency));

    return { from, to, byCurrency: result };
}

export type VatLimitLevel = 'ok' | 'approaching' | 'critical' | 'exceeded';

export interface VatLimitProgress {
    /** turnover / limit as a percentage, rounded to 2 decimals. */
    percent: number;
    level: VatLimitLevel;
    turnover: number;
    limit: number;
}

/**
 * Progress of a net turnover toward a VAT-registration limit. Returns null
 * when no positive limit is configured (nothing to warn about).
 * Thresholds: >=80% approaching, >=95% critical, >=100% exceeded.
 */
export function vatLimitProgress(turnover: number, limit: number): VatLimitProgress | null {
    if (!(limit > 0)) return null;
    const percent = round2((turnover / limit) * 100);
    let level: VatLimitLevel = 'ok';
    if (percent >= 100) level = 'exceeded';
    else if (percent >= VAT_LIMIT_CRITICAL_PERCENT) level = 'critical';
    else if (percent >= VAT_LIMIT_APPROACHING_PERCENT) level = 'approaching';
    return { percent, level, turnover: round2(turnover), limit };
}

/** Net taxable turnover in a given currency (the VAT-limit basis). */
export function turnoverForCurrency(summary: VatSummary, currency: string): number {
    return summary.byCurrency.find((row) => row.currency === currency)?.base ?? 0;
}

/**
 * The VAT-limit status worth surfacing for a company: the progress of its net
 * turnover (in `currency`) toward `limit`, but only once it is actually
 * approaching (level != ok). Returns null when there is no limit, or turnover
 * is still comfortably below it - i.e. when nothing should be shown.
 */
export function activeVatLimitStatus(
    summary: VatSummary,
    limit: number,
    currency: string,
): VatLimitProgress | null {
    if (!(limit > 0)) return null;
    const progress = vatLimitProgress(turnoverForCurrency(summary, currency), limit);
    return progress && progress.level !== 'ok' ? progress : null;
}

function csvCell(value: string | number): string {
    return `"${String(value).replace(/"/g, '""')}"`;
}

/**
 * Flatten a VAT summary to RFC-4180 CSV text (UTF-8 BOM, CRLF), one row per
 * (currency, rate) plus a per-currency total row - the shape accountants
 * import. Amounts use a fixed 2-decimal representation.
 */
export function vatSummaryToCsv(summary: VatSummary): string {
    const money = (n: number): string => n.toFixed(2);
    const header = ['Currency', 'Rate (%)', 'Base', 'VAT', 'Gross'];
    const rows: string[] = [header.map(csvCell).join(',')];

    for (const currency of summary.byCurrency) {
        for (const bucket of currency.byRate) {
            rows.push(
                [
                    csvCell(currency.currency),
                    csvCell(bucket.rate),
                    csvCell(money(bucket.base)),
                    csvCell(money(bucket.vat)),
                    csvCell(money(bucket.gross)),
                ].join(','),
            );
        }
        rows.push(
            [
                csvCell(currency.currency),
                csvCell('Total'),
                csvCell(money(currency.base)),
                csvCell(money(currency.vat)),
                csvCell(money(currency.turnover)),
            ].join(','),
        );
    }

    return '﻿' + rows.join('\r\n');
}

/** VAT summary CSV as a downloadable Blob. */
export function vatSummaryCsvBlob(summary: VatSummary): Blob {
    return new Blob([vatSummaryToCsv(summary)], { type: 'text/csv;charset=utf-8' });
}
