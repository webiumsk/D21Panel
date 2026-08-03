import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// Private windows without OPFS (e.g. Firefox private browsing): the Evolu DB
// worker never initializes and `evolu.appOwner` stays pending forever. The
// login path must degrade instead of hanging on it (GuestRestoreModal was
// stuck on "Loading..." indefinitely).
vi.mock("@/evolu/client", () => ({
    evolu: { appOwner: new Promise(() => {}) },
}));

/** Fresh module per test - the readiness latch is module state. */
async function loadAccountSeed() {
    return import("@/services/accountSeed");
}

beforeEach(() => {
    vi.resetModules();
    vi.useFakeTimers();
    sessionStorage.clear();
});

afterEach(() => {
    vi.useRealTimers();
    sessionStorage.clear();
});

describe("account seed - Evolu readiness timeout", () => {
    it("previewOwnerSwitchImpact reports no switch when appOwner never settles", async () => {
        const {
            EVOLU_READY_PROBE_FAILED_KEY,
            EVOLU_READY_TIMEOUT_MS,
            generateAccountMnemonic24,
            previewOwnerSwitchImpact,
        } = await loadAccountSeed();

        const pending = previewOwnerSwitchImpact(generateAccountMnemonic24());
        await vi.advanceTimersByTimeAsync(EVOLU_READY_TIMEOUT_MS);
        await expect(pending).resolves.toEqual({ switches: false });
        expect(sessionStorage.getItem(EVOLU_READY_PROBE_FAILED_KEY)).toBe("1");
    });

    it("later probes fail fast after a timeout in the same page load", async () => {
        const {
            EVOLU_READY_FAST_PROBE_TIMEOUT_MS,
            EVOLU_READY_TIMEOUT_MS,
            generateAccountMnemonic24,
            previewOwnerSwitchImpact,
        } = await loadAccountSeed();

        // Arrange the in-page latch: one probe has already timed out.
        const first = previewOwnerSwitchImpact(generateAccountMnemonic24());
        await vi.advanceTimersByTimeAsync(EVOLU_READY_TIMEOUT_MS);
        await expect(first).resolves.toEqual({ switches: false });

        const second = previewOwnerSwitchImpact(generateAccountMnemonic24());
        await vi.advanceTimersByTimeAsync(EVOLU_READY_FAST_PROBE_TIMEOUT_MS);
        await expect(second).resolves.toEqual({ switches: false });
    });

    it("uses the shorter retry probe when a previous page load left the failure flag", async () => {
        const {
            EVOLU_READY_PROBE_FAILED_KEY,
            EVOLU_READY_RETRY_TIMEOUT_MS,
            generateAccountMnemonic24,
            previewOwnerSwitchImpact,
        } = await loadAccountSeed();

        sessionStorage.setItem(EVOLU_READY_PROBE_FAILED_KEY, "1");
        const pending = previewOwnerSwitchImpact(generateAccountMnemonic24());
        await vi.advanceTimersByTimeAsync(EVOLU_READY_RETRY_TIMEOUT_MS);
        await expect(pending).resolves.toEqual({ switches: false });
    });

    it("initEvoluFromAccountSeedIfNeeded fails fast when appOwner never settles", async () => {
        const {
            EVOLU_READY_TIMEOUT_MS,
            generateAccountMnemonic24,
            initEvoluFromAccountSeedIfNeeded,
            isEvoluUnavailableError,
        } = await loadAccountSeed();

        const pending = initEvoluFromAccountSeedIfNeeded(generateAccountMnemonic24());
        // Silence the unhandled-rejection window while timers advance.
        pending.catch(() => {});
        await vi.advanceTimersByTimeAsync(EVOLU_READY_TIMEOUT_MS);
        await expect(pending).rejects.toSatisfy(isEvoluUnavailableError);
    });
});
