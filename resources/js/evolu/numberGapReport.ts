import type { EvoluDocumentRow } from "./documentMap";
import type { EvoluNumberSeriesRow } from "./numberSeriesMap";
import { counterDigitsInFormat } from "./numberSeriesFormat";

/**
 * Document-number gap report (GoBD): sequential numbering may contain gaps
 * only when they are documented. Cancelled documents keep their number and
 * count as documented gaps; a counter with no document at all is an
 * undocumented gap the operator must be able to explain.
 *
 * The counter is the trailing `counterDigitsInFormat(series.format)` digits
 * of the document number - the same parsing the number allocator uses
 * (numberSeriesCrud.highestIssuedDocumentCounter). Everything before the
 * counter is the series prefix, which naturally separates yearly/monthly
 * resets (INV2026... vs INV2025...).
 */

/** All DEFAULT_SERIES_DEFS formats end in NNNN - the fallback counter width
 * for documents whose type has no configured series. */
const FALLBACK_COUNTER_DIGITS = 4;

/**
 * Cap on enumerated missing counters per series - a corrupt or wildly
 * off-range number must not freeze the audit page or balloon the CSV.
 * The full count is always reported via missingTotal.
 */
export const MISSING_LIST_LIMIT = 200;

export type CancelledNumberEntry = {
    documentId: string;
    number: string;
    counter: number;
};

export type NumberSeriesGapReport = {
    documentType: string;
    /** Series prefix, e.g. "INV2026". */
    prefix: string;
    minCounter: number;
    maxCounter: number;
    /** Documents carrying a number in this prefix (any non-draft status). */
    numberedCount: number;
    /**
     * Counters between min and max with no document at all - capped at
     * MISSING_LIST_LIMIT entries; missingTotal carries the true count.
     */
    missing: number[];
    missingTotal: number;
    /** Documented gaps - cancelled documents keep their number. */
    cancelled: CancelledNumberEntry[];
};

function counterDigitsByType(
    series: EvoluNumberSeriesRow[],
    companyId: string,
): Map<string, number> {
    const byType = new Map<string, number>();
    for (const row of series) {
        if (row.companyId !== companyId) continue;
        const digits = counterDigitsInFormat(row.format);
        if (digits <= 0) continue;
        // Prefer the explicit default series (sqlite true = 1; null is
        // NOT a default); otherwise the first seen wins.
        if (row.isDefault === 1 || !byType.has(row.documentType)) {
            byType.set(row.documentType, digits);
        }
    }
    return byType;
}

function parseCounter(number: string, digits: number): { prefix: string; counter: number } | null {
    const trimmed = number.trim();
    if (trimmed.length < digits) return null;
    const suffix = trimmed.slice(-digits);
    if (!/^\d+$/.test(suffix)) return null;
    return { prefix: trimmed.slice(0, -digits), counter: parseInt(suffix, 10) };
}

/**
 * Build the gap report for one company. Drafts have no number and are
 * ignored; issued/paid/cancelled documents participate.
 */
export function buildNumberGapReport(
    documents: EvoluDocumentRow[],
    series: EvoluNumberSeriesRow[],
    companyId: string,
): NumberSeriesGapReport[] {
    const digitsByType = counterDigitsByType(series, companyId);

    type Group = {
        documentType: string;
        prefix: string;
        byCounter: Map<number, EvoluDocumentRow>;
    };
    const groups = new Map<string, Group>();

    for (const doc of documents) {
        if (doc.companyId !== companyId) continue;
        if (doc.status === "draft" || !doc.number) continue;
        const digits = digitsByType.get(doc.documentType) ?? FALLBACK_COUNTER_DIGITS;
        const parsed = parseCounter(doc.number, digits);
        if (!parsed) continue;

        const key = `${doc.documentType} ${parsed.prefix}`;
        let group = groups.get(key);
        if (!group) {
            group = { documentType: doc.documentType, prefix: parsed.prefix, byCounter: new Map() };
            groups.set(key, group);
        }
        group.byCounter.set(parsed.counter, doc);
    }

    const reports: NumberSeriesGapReport[] = [];
    for (const group of groups.values()) {
        const counters = [...group.byCounter.keys()].sort((a, b) => a - b);
        const minCounter = counters[0];
        const maxCounter = counters[counters.length - 1];

        // Walk consecutive counters instead of the raw min..max range -
        // work scales with the document count, the gap sizes are computed
        // arithmetically and enumeration stops at the cap.
        const missing: number[] = [];
        let missingTotal = 0;
        for (let i = 1; i < counters.length; i += 1) {
            const previous = counters[i - 1];
            const gap = counters[i] - previous - 1;
            if (gap <= 0) continue;
            missingTotal += gap;
            for (let c = previous + 1; c < counters[i] && missing.length < MISSING_LIST_LIMIT; c += 1) {
                missing.push(c);
            }
        }

        const cancelled: CancelledNumberEntry[] = [];
        for (const counter of counters) {
            const doc = group.byCounter.get(counter);
            if (doc && doc.status === "cancelled") {
                cancelled.push({ documentId: doc.id, number: doc.number ?? "", counter });
            }
        }

        reports.push({
            documentType: group.documentType,
            prefix: group.prefix,
            minCounter,
            maxCounter,
            numberedCount: counters.length,
            missing,
            missingTotal,
            cancelled,
        });
    }

    return reports.sort(
        (a, b) => a.documentType.localeCompare(b.documentType) || a.prefix.localeCompare(b.prefix),
    );
}
