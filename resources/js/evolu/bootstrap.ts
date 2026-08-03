import {
    getStoredAccountMnemonic,
    initEvoluFromAccountSeedIfNeeded,
    isEvoluUnavailableError,
} from "@/services/accountSeed";
import { requestPersistence } from "@/services/browserStorage";
import { sleep, withTimeout } from "./asyncTimeout";
import { isInvoicingLocalFirst } from "./flags";

/** Defer Evolu init so login/router navigation can settle (reduces WASM worker races). */
export const EVOLU_BOOTSTRAP_DEFER_MS = 350;

/** Upper bound for owner restore + relay wait during bootstrap. */
export const EVOLU_BOOTSTRAP_TIMEOUT_MS = 60_000;

let bootstrapPromise: Promise<void> | null = null;
let bootstrapState: "idle" | "running" | "done" | "failed" = "idle";
/** Incremented on reset so stale in-flight bootstrap chains cannot mutate state. */
let bootstrapGeneration = 0;

/** Allow a fresh bootstrap after manual recovery phrase restore on Profile. */
export function resetEvoluBootstrapForRetry(): void {
    bootstrapGeneration += 1;
    bootstrapState = "idle";
    bootstrapPromise = null;
}

/**
 * Bind Evolu AppOwner to the Satflux recovery phrase (same 24 words).
 * Runs at most once per page load; does not full-page reload on owner restore.
 */
export function ensureEvoluBoundToAccountSeed(): Promise<void> {
    if (!isInvoicingLocalFirst()) {
        return Promise.resolve();
    }
    const mnemonic = getStoredAccountMnemonic();
    if (!mnemonic) {
        return Promise.resolve();
    }
    if (bootstrapState === "done" || bootstrapState === "failed") {
        return Promise.resolve();
    }
    if (!bootstrapPromise) {
        const generation = bootstrapGeneration;
        bootstrapState = "running";
        // Durable storage for the local-first database (P1 phase 5): ask the
        // browser not to evict this origin. Best-effort, fire-and-forget.
        void requestPersistence();
        bootstrapPromise = sleep(EVOLU_BOOTSTRAP_DEFER_MS)
            .then(() => {
                if (generation !== bootstrapGeneration) {
                    return null;
                }
                return withTimeout(
                    initEvoluFromAccountSeedIfNeeded(mnemonic),
                    EVOLU_BOOTSTRAP_TIMEOUT_MS,
                    "evolu_bootstrap_timeout",
                );
            })
            .then((result) => {
                if (generation !== bootstrapGeneration) {
                    return;
                }
                bootstrapState = result === "owner_restore_failed" ? "failed" : "done";
            })
            .catch((error) => {
                if (generation !== bootstrapGeneration) {
                    return;
                }
                bootstrapState = "failed";
                if (import.meta.env.DEV) {
                    if (isEvoluUnavailableError(error)) {
                        console.info(
                            "[evolu] local-first storage unavailable (private window without OPFS?) - invoicing stays disabled this session",
                        );
                    } else {
                        console.warn("[evolu] bootstrap failed:", error);
                    }
                }
            })
            .finally(() => {
                if (generation === bootstrapGeneration) {
                    bootstrapPromise = null;
                }
            });
    }
    return bootstrapPromise;
}
