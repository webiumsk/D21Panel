import { afterEach, describe, expect, it, vi } from "vitest";
import {
    EVOLU_READY_TIMEOUT_MS,
    generateAccountMnemonic24,
    initEvoluFromAccountSeedIfNeeded,
    previewOwnerSwitchImpact,
} from "@/services/accountSeed";

// Private windows without OPFS (e.g. Firefox private browsing): the Evolu DB
// worker never initializes and `evolu.appOwner` stays pending forever. The
// login path must degrade instead of hanging on it (GuestRestoreModal was
// stuck on "Loading..." indefinitely).
vi.mock("@/evolu/client", () => ({
    evolu: { appOwner: new Promise(() => {}) },
}));

describe("account seed - Evolu readiness timeout", () => {
    afterEach(() => {
        vi.useRealTimers();
        sessionStorage.clear();
    });

    it("previewOwnerSwitchImpact reports no switch when appOwner never settles", async () => {
        vi.useFakeTimers();
        const pending = previewOwnerSwitchImpact(generateAccountMnemonic24());
        await vi.advanceTimersByTimeAsync(EVOLU_READY_TIMEOUT_MS);
        await expect(pending).resolves.toEqual({ switches: false });
        expect(sessionStorage.getItem("satflux.evolu.ready_probe_failed.v1")).toBe("1");
    });

    it("later probes fail fast after the first timeout (in-page latch)", async () => {
        vi.useFakeTimers();
        const pending = previewOwnerSwitchImpact(generateAccountMnemonic24());
        await vi.advanceTimersByTimeAsync(250);
        await expect(pending).resolves.toEqual({ switches: false });
    });

    it("initEvoluFromAccountSeedIfNeeded fails fast when appOwner never settles", async () => {
        vi.useFakeTimers();
        const pending = initEvoluFromAccountSeedIfNeeded(generateAccountMnemonic24());
        // Silence the unhandled-rejection window while timers advance.
        pending.catch(() => {});
        await vi.advanceTimersByTimeAsync(EVOLU_READY_TIMEOUT_MS);
        await expect(pending).rejects.toThrow("evolu_unavailable");
    });
});
