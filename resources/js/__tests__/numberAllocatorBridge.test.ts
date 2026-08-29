import { afterEach, describe, expect, it, vi } from "vitest";

type ApiMocks = {
    list: ReturnType<typeof vi.fn>;
    create: ReturnType<typeof vi.fn>;
    reserve: ReturnType<typeof vi.fn>;
    confirm: ReturnType<typeof vi.fn>;
};

async function loadBridge(mocks: Partial<ApiMocks> = {}) {
    vi.resetModules();
    const api: ApiMocks = {
        list: mocks.list ?? vi.fn().mockResolvedValue([]),
        create: mocks.create ?? vi.fn(),
        reserve: mocks.reserve ?? vi.fn(),
        confirm: mocks.confirm ?? vi.fn().mockResolvedValue({}),
    };
    vi.doMock("@/services/api", () => ({
        invoicingApi: {
            companies: { list: api.list, create: api.create },
            numberAllocator: { reserve: api.reserve, confirm: api.confirm },
        },
        default: {},
    }));
    const bridge = await import("@/evolu/numberAllocatorBridge");
    return { bridge, api };
}

afterEach(() => {
    vi.doUnmock("@/services/api");
    vi.unstubAllGlobals();
});

const identity = { legal_name: "ACME s.r.o.", registration_number: "12345678" };

describe("reserveIssueNumber", () => {
    it("reserves via the identity-matched bridge company", async () => {
        const { bridge, api } = await loadBridge({
            list: vi.fn().mockResolvedValue([
                { id: "co-1", legal_name: "ACME s.r.o.", registration_number: "12345678" },
            ]),
            reserve: vi.fn().mockResolvedValue({ number: "20260071", counter: 71, status: "reserved" }),
        });

        const result = await bridge.reserveIssueNumber(identity, "invoice", "doc-request-0001", 70);

        expect(result).toEqual({
            ok: true,
            value: { number: "20260071", counter: 71, status: "reserved", bridgeCompanyId: "co-1" },
        });
        expect(api.create).not.toHaveBeenCalled();
        expect(api.reserve).toHaveBeenCalledWith("co-1", {
            document_type: "invoice",
            issue_request_id: "doc-request-0001",
            local_high_counter: 70,
        });
    });

    it("creates a minimal identity-only bridge company when none matches", async () => {
        const { bridge, api } = await loadBridge({
            list: vi.fn().mockResolvedValue([]),
            create: vi.fn().mockResolvedValue({ id: "co-new" }),
            reserve: vi.fn().mockResolvedValue({ number: "20260001", counter: 1 }),
        });

        const result = await bridge.reserveIssueNumber(
            { ...identity, jurisdiction: "eu_sk", country: "SK", default_currency: "EUR" },
            "invoice",
            "doc-request-0002",
        );

        expect(api.create).toHaveBeenCalledWith({
            legal_name: "ACME s.r.o.",
            registration_number: "12345678",
            jurisdiction: "eu_sk",
            country: "SK",
            default_currency: "EUR",
        });
        expect(result.ok).toBe(true);
        if (result.ok) expect(result.value.bridgeCompanyId).toBe("co-new");
    });

    it("converges on the canonical (oldest) bridge company after a concurrent create race", async () => {
        // First list: empty (we create). Re-list after create: a concurrent
        // client's row (older id) AND ours exist - both clients must settle
        // on the older row so all reservations share one sequence.
        const list = vi
            .fn()
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce([
                { id: "co-b-ours", legal_name: "ACME s.r.o.", registration_number: "12345678" },
                { id: "co-a-racer", legal_name: "ACME s.r.o.", registration_number: "12345678" },
            ]);
        const { bridge, api } = await loadBridge({
            list,
            create: vi.fn().mockResolvedValue({ id: "co-b-ours" }),
            reserve: vi.fn().mockResolvedValue({ number: "20260001", counter: 1 }),
        });

        const result = await bridge.reserveIssueNumber(identity, "invoice", "doc-request-0009");

        expect(result.ok).toBe(true);
        if (result.ok) expect(result.value.bridgeCompanyId).toBe("co-a-racer");
        expect(api.reserve).toHaveBeenCalledWith("co-a-racer", expect.any(Object));
    });

    it("requires being online - no server call, no local fallback number", async () => {
        const { bridge, api } = await loadBridge();
        vi.stubGlobal("navigator", { onLine: false });

        const result = await bridge.reserveIssueNumber(identity, "invoice", "doc-request-0003");

        expect(result).toEqual({ ok: false, error: "issue_requires_online" });
        expect(api.list).not.toHaveBeenCalled();
        expect(api.reserve).not.toHaveBeenCalled();
    });

    it("maps a network failure to issue_requires_online and an HTTP error to reserve_failed", async () => {
        const networkError = Object.assign(new Error("Network Error"), {});
        const { bridge: offlineBridge } = await loadBridge({
            list: vi.fn().mockRejectedValue(networkError),
        });
        expect(await offlineBridge.reserveIssueNumber(identity, "invoice", "doc-request-0004")).toEqual({
            ok: false,
            error: "issue_requires_online",
        });

        const httpError = Object.assign(new Error("422"), { response: { status: 422 } });
        const { bridge: failBridge } = await loadBridge({
            list: vi.fn().mockResolvedValue([{ id: "co-1", legal_name: "ACME s.r.o.", registration_number: "12345678" }]),
            reserve: vi.fn().mockRejectedValue(httpError),
        });
        expect(await failBridge.reserveIssueNumber(identity, "invoice", "doc-request-0005")).toEqual({
            ok: false,
            error: "reserve_failed",
        });
    });

    it("maps the server company-limit 403 to company_limit (local-first over-limit backstop)", async () => {
        const limitError = Object.assign(new Error("403"), {
            response: { status: 403, data: { code: "company_limit", max_allowed: 2 } },
        });
        const { bridge } = await loadBridge({
            list: vi.fn().mockResolvedValue([]),
            create: vi.fn().mockRejectedValue(limitError),
        });
        expect(await bridge.reserveIssueNumber(identity, "invoice", "doc-request-0007")).toEqual({
            ok: false,
            error: "company_limit",
        });
    });

    it("fails without a legal name (no bridge identity to allocate under)", async () => {
        const { bridge, api } = await loadBridge();
        const result = await bridge.reserveIssueNumber({ legal_name: null }, "invoice", "doc-request-0006");
        expect(result).toEqual({ ok: false, error: "reserve_failed" });
        expect(api.reserve).not.toHaveBeenCalled();
    });
});

describe("reserveIssueNumber create defaults", () => {
    it("defaults the required jurisdiction when the local company has none", async () => {
        const { bridge, api } = await loadBridge({
            list: vi.fn().mockResolvedValue([]),
            create: vi.fn().mockResolvedValue({ id: "co-new" }),
            reserve: vi.fn().mockResolvedValue({ number: "20260001", counter: 1 }),
        });

        await bridge.reserveIssueNumber(identity, "invoice", "doc-request-0010");

        expect(api.create).toHaveBeenCalledWith({
            legal_name: "ACME s.r.o.",
            registration_number: "12345678",
            jurisdiction: "eu_other",
        });
    });
});

describe("sha256Hex", () => {
    it("computes the standard SHA-256 hex digest", async () => {
        const { bridge } = await loadBridge();
        expect(await bridge.sha256Hex("abc")).toBe(
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
        );
    });
});

describe("confirmIssueNumber", () => {
    it("sends the snapshot hash and format version", async () => {
        const { bridge, api } = await loadBridge();

        await bridge.confirmIssueNumber("co-1", "invoice", "doc-request-0007", "abc", "1");

        expect(api.confirm).toHaveBeenCalledWith("co-1", {
            document_type: "invoice",
            issue_request_id: "doc-request-0007",
            snapshot_hash: "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
            snapshot_format_version: "1",
        });
    });

    it("swallows confirm failures (best-effort)", async () => {
        const { bridge } = await loadBridge({
            confirm: vi.fn().mockRejectedValue(new Error("boom")),
        });
        await expect(
            bridge.confirmIssueNumber("co-1", "invoice", "doc-request-0008"),
        ).resolves.toBeUndefined();
    });
});
