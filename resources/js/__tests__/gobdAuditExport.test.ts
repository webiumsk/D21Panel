import { describe, expect, it } from "vitest";
import type { EvoluDocumentRow, EvoluDocumentLineRow } from "@/evolu/documentMap";
import type { EvoluNumberSeriesRow } from "@/evolu/numberSeriesMap";
import type { EvoluDocumentEventRow } from "@/evolu/documentEventLog";
import { buildNumberGapReport, MISSING_LIST_LIMIT } from "@/evolu/numberGapReport";
import { buildGobdExportFiles, selectGobdDocuments } from "@/evolu/gobdExport";
import { buildStoredZip, crc32 } from "@/evolu/zipStore";

const series = [
    { id: "s1", companyId: "cmp-1", documentType: "invoice", format: "INVYYYYNNNN", isDefault: 1 },
    { id: "s2", companyId: "cmp-1", documentType: "credit_note", format: "CNYYYYNNNN", isDefault: 1 },
] as unknown as EvoluNumberSeriesRow[];

function doc(overrides: Record<string, unknown>): EvoluDocumentRow {
    return {
        id: `doc-${String(overrides.number ?? Math.random())}`,
        companyId: "cmp-1",
        documentType: "invoice",
        status: "issued",
        number: null,
        issueDate: "2026-03-10",
        currency: "EUR",
        subtotal: "100",
        taxTotal: "0",
        total: "100",
        ...overrides,
    } as unknown as EvoluDocumentRow;
}

describe("buildNumberGapReport", () => {
    it("finds undocumented gaps and lists cancelled numbers as documented", () => {
        const documents = [
            doc({ id: "d1", number: "INV20260001" }),
            doc({ id: "d2", number: "INV20260002", status: "cancelled" }),
            // 0003 missing entirely - undocumented gap.
            doc({ id: "d4", number: "INV20260004", status: "paid" }),
        ];

        const reports = buildNumberGapReport(documents, series, "cmp-1");
        expect(reports).toHaveLength(1);
        expect(reports[0].prefix).toBe("INV2026");
        expect(reports[0].minCounter).toBe(1);
        expect(reports[0].maxCounter).toBe(4);
        expect(reports[0].numberedCount).toBe(3);
        expect(reports[0].missing).toEqual([3]);
        expect(reports[0].cancelled).toEqual([
            { documentId: "d2", number: "INV20260002", counter: 2 },
        ]);
    });

    it("separates series by prefix (yearly reset) and by document type", () => {
        const documents = [
            doc({ number: "INV20250009" }),
            doc({ number: "INV20260001" }),
            doc({ number: "CN20260001", documentType: "credit_note" }),
        ];
        const reports = buildNumberGapReport(documents, series, "cmp-1");
        expect(reports.map((r) => `${r.documentType} ${r.prefix}`)).toEqual([
            "credit_note CN2026",
            "invoice INV2025",
            "invoice INV2026",
        ]);
        // Single-document series have no gaps.
        expect(reports.every((r) => r.missing.length === 0)).toBe(true);
    });

    it("accepts no-prefix series where the number length equals the counter width", () => {
        const bareSeries = [
            { id: "s3", companyId: "cmp-1", documentType: "invoice", format: "NNNN", isDefault: 1 },
        ] as unknown as EvoluNumberSeriesRow[];
        const documents = [
            doc({ id: "d1", number: "0001" }),
            doc({ id: "d3", number: "0003" }),
        ];
        const reports = buildNumberGapReport(documents, bareSeries, "cmp-1");
        expect(reports).toHaveLength(1);
        expect(reports[0].prefix).toBe("");
        expect(reports[0].missing).toEqual([2]);
        expect(reports[0].missingTotal).toBe(1);
    });

    it("a null isDefault series never overrides the explicit default", () => {
        const mixedSeries = [
            { id: "sA", companyId: "cmp-1", documentType: "invoice", format: "INVYYYYNNNN", isDefault: 1 },
            { id: "sB", companyId: "cmp-1", documentType: "invoice", format: "INVYYYYNNNNNN", isDefault: null },
        ] as unknown as EvoluNumberSeriesRow[];
        // 4-digit counter parsing (from the explicit default) splits the
        // prefix as INV2026; a 6-digit parse would produce prefix "INV20".
        const reports = buildNumberGapReport([doc({ number: "INV20260001" })], mixedSeries, "cmp-1");
        expect(reports[0].prefix).toBe("INV2026");
    });

    it("caps enumerated missing counters while reporting the true total", () => {
        const documents = [
            doc({ id: "d1", number: "INV20260001" }),
            // A wildly off-range number must not freeze the report.
            doc({ id: "d2", number: "INV20269999" }),
        ];
        const reports = buildNumberGapReport(documents, series, "cmp-1");
        expect(reports[0].missingTotal).toBe(9997);
        expect(reports[0].missing).toHaveLength(MISSING_LIST_LIMIT);
        expect(reports[0].missing[0]).toBe(2);
    });

    it("ignores drafts, other companies and unparseable numbers", () => {
        const documents = [
            doc({ number: "INV20260001", status: "draft" }),
            doc({ number: "INV20260002", companyId: "cmp-2" }),
            doc({ number: "NO-DIGITS-" }),
            doc({ number: null }),
        ];
        expect(buildNumberGapReport(documents, series, "cmp-1")).toEqual([]);
    });
});

describe("GoBD export", () => {
    const documents = [
        doc({ id: "d1", number: "INV20260001", issueDate: "2026-01-05" }),
        doc({ id: "d2", number: "INV20260002", issueDate: "2026-02-01", status: "cancelled" }),
        doc({ id: "d3", number: "INV20260003", issueDate: "2027-01-01" }), // outside range
        doc({ id: "d4", number: null, status: "draft", issueDate: "2026-01-06" }),
    ];
    const lines = [
        { documentId: "d1", name: "Item A", lineTotal: "100" },
        { documentId: "d3", name: "Item B", lineTotal: "50" },
    ] as unknown as EvoluDocumentLineRow[];
    const events: EvoluDocumentEventRow[] = [
        {
            id: "e1",
            documentId: "d1",
            action: "business_document.issued",
            metadataJson: JSON.stringify({ number: "INV20260001", user_email: "a@b.sk" }),
            createdAt: "2026-01-05T10:00:00Z",
        },
        {
            id: "e2",
            documentId: "d3",
            action: "business_document.issued",
            metadataJson: null,
            createdAt: "2027-01-01T10:00:00Z",
        },
    ];
    const input = {
        company: { id: "cmp-1", legal_name: "Test GmbH", registration_number: "HRB 123" },
        documents,
        lines,
        events,
        series,
        range: { from: "2026-01-01", to: "2026-12-31" },
        createdAt: new Date("2026-07-22T12:00:00Z"),
    };

    it("selects numbered non-draft documents in range incl. cancelled", () => {
        const selected = selectGobdDocuments(documents, "cmp-1", input.range);
        expect(selected.map((d) => d.id)).toEqual(["d1", "d2"]);
    });

    it("builds the file set with filtered lines and attributed events", () => {
        const files = buildGobdExportFiles(input);
        expect(files.map((f) => f.name)).toEqual([
            "manifest.txt", "documents.csv", "lines.csv", "events.csv", "number_gaps.csv",
        ]);

        const byName = Object.fromEntries(files.map((f) => [f.name, f.content]));
        expect(byName["manifest.txt"]).toContain("Test GmbH");
        expect(byName["manifest.txt"]).toContain("documents: 2");
        expect(byName["documents.csv"]).toContain("INV20260001");
        expect(byName["documents.csv"]).toContain("INV20260002");
        expect(byName["documents.csv"]).not.toContain("INV20260003");
        // Lines of out-of-range documents are excluded.
        expect(byName["lines.csv"]).toContain("Item A");
        expect(byName["lines.csv"]).not.toContain("Item B");
        // Actor attribution lands in its own column.
        expect(byName["events.csv"]).toContain('"a@b.sk"');
        expect(byName["events.csv"]).not.toContain("2027-01-01T10:00:00Z");
        // The gap report covers the exported range only (no d3 counter).
        expect(byName["number_gaps.csv"]).toContain('"INV2026"');
    });

    it("neutralizes spreadsheet formula injection in CSV cells", () => {
        const files = buildGobdExportFiles({
            ...input,
            lines: [
                { documentId: "d1", name: "=SUM(A1:A9)", lineTotal: "1" },
            ] as unknown as EvoluDocumentLineRow[],
        });
        const linesCsv = files.find((f) => f.name === "lines.csv")?.content ?? "";
        expect(linesCsv).toContain(`"'=SUM(A1:A9)"`);
        expect(linesCsv).not.toContain('"=SUM');
    });
});

describe("buildStoredZip", () => {
    it("produces a valid STORED archive structure", () => {
        const zip = buildStoredZip(
            [
                { name: "a.txt", content: "hello" },
                { name: "dir/b.csv", content: "x,y\r\n1,2" },
            ],
            new Date("2026-07-22T12:00:00Z"),
        );

        const sig = (offset: number) =>
            zip[offset] | (zip[offset + 1] << 8) | (zip[offset + 2] << 16) | (zip[offset + 3] << 24);

        // Local header, central directory and EOCD signatures present.
        expect(sig(0) >>> 0).toBe(0x04034b50);
        const eocdOffset = zip.length - 22;
        expect(sig(eocdOffset) >>> 0).toBe(0x06054b50);
        // EOCD entry count = 2.
        expect(zip[eocdOffset + 10] | (zip[eocdOffset + 11] << 8)).toBe(2);
    });

    it("computes the standard CRC32", () => {
        // Well-known vector: "123456789" -> 0xCBF43926.
        expect(crc32(new TextEncoder().encode("123456789"))).toBe(0xcbf43926);
    });
});
