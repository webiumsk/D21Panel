import { generateMnemonic, mnemonicToSeedSync, validateMnemonic } from "@scure/bip39";
import { wordlist } from "@scure/bip39/wordlists/english.js";
import { hkdf } from "@noble/hashes/hkdf.js";
import { sha512 } from "@noble/hashes/sha2.js";
import * as ed25519 from "@noble/ed25519";
import {
    deriveEvoluOwnerMnemonic,
    isTargetEvoluOwner,
} from "./evoluOwner";
import { withTimeout } from "@/evolu/asyncTimeout";

ed25519.hashes.sha512 = sha512;

const RECOVERY_HKDF_INFO = new TextEncoder().encode("satflux-guest-ed25519-v1");
const ACCOUNT_MNEMONIC_STORAGE_KEY = "satflux.account.mnemonic.v1";
const PERSISTENT_ACCOUNT_MNEMONIC_KEY = "satflux.account.mnemonic.persistent.v1";
const LEGACY_GUEST_MNEMONIC_STORAGE_KEY = "satflux.guest.mnemonic.v1";

function bytesToHex(u8: Uint8Array): string {
    return Array.from(u8, (b) => b.toString(16).padStart(2, "0")).join("");
}

export function normalizeAccountMnemonic(mnemonic: string): string {
    return mnemonic
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .join(" ");
}

export function generateAccountMnemonic24(): string {
    return generateMnemonic(wordlist, 256);
}

export function deriveRecoveryPublicKeyHex(mnemonic: string): string {
    const normalized = normalizeAccountMnemonic(mnemonic);
    if (!validateMnemonic(normalized, wordlist)) {
        throw new Error("invalid_mnemonic");
    }
    const seed = mnemonicToSeedSync(normalized, "");
    const sk = hkdf(sha512, seed, new Uint8Array(0), RECOVERY_HKDF_INFO, 32);
    const pk = ed25519.getPublicKey(sk);
    return bytesToHex(pk);
}

export function signRecoveryMessage(mnemonic: string, messageUtf8: string): string {
    const normalized = normalizeAccountMnemonic(mnemonic);
    if (!validateMnemonic(normalized, wordlist)) {
        throw new Error("invalid_mnemonic");
    }
    const seed = mnemonicToSeedSync(normalized, "");
    const sk = hkdf(sha512, seed, new Uint8Array(0), RECOVERY_HKDF_INFO, 32);
    const sig = ed25519.sign(new TextEncoder().encode(messageUtf8), sk);
    return bytesToHex(sig);
}

export { deriveEvoluOwnerMnemonic };

/**
 * Store the recovery phrase for the current session only (sessionStorage).
 *
 * The phrase is the Evolu owner secret that decrypts all invoicing data, so it
 * is deliberately NOT written to localStorage: a plaintext copy at rest is
 * readable by any same-origin script (XSS, hostile extension) and survives on
 * disk. It lives in sessionStorage so it survives a tab reload but is gone once
 * the browser is fully closed - the user then re-unlocks with their phrase.
 * It is never sent to the server, logs, analytics, or the URL.
 */
export function storeAccountMnemonic(mnemonic: string): void {
    const normalized = normalizeAccountMnemonic(mnemonic);
    if (!validateMnemonic(normalized, wordlist)) {
        throw new Error("invalid_mnemonic");
    }
    sessionStorage.setItem(ACCOUNT_MNEMONIC_STORAGE_KEY, normalized);
    sessionStorage.removeItem(LEGACY_GUEST_MNEMONIC_STORAGE_KEY);
    clearEvoluOwnerRestoreAttempted();
}

/**
 * One-time migration bridge for users who still have a plaintext phrase in the
 * legacy localStorage key: copy it into the session so they keep working this
 * session, WITHOUT deleting the localStorage copy yet. The localStorage copy is
 * only removed once the user has confirmed their backup
 * (finalizeSeedMigrationAfterBackupConfirmed) - deleting it before that could
 * lock out someone who relied solely on the browser copy. Data-loss-safe: the
 * phrase is a key, and the encrypted Evolu data remains recoverable with it.
 */
export function hydrateAccountMnemonicSession(): void {
    if (getStoredAccountMnemonic()) {
        return;
    }
    try {
        const persisted = localStorage.getItem(PERSISTENT_ACCOUNT_MNEMONIC_KEY);
        if (!persisted) {
            return;
        }
        const normalized = normalizeAccountMnemonic(persisted);
        if (!validateMnemonic(normalized, wordlist)) {
            localStorage.removeItem(PERSISTENT_ACCOUNT_MNEMONIC_KEY);
            return;
        }
        sessionStorage.setItem(ACCOUNT_MNEMONIC_STORAGE_KEY, normalized);
        sessionStorage.removeItem(LEGACY_GUEST_MNEMONIC_STORAGE_KEY);
    } catch {
        // Storage unavailable.
    }
}

export function getStoredAccountMnemonic(): string | null {
    const current = sessionStorage.getItem(ACCOUNT_MNEMONIC_STORAGE_KEY);
    if (current) {
        return current;
    }
    const legacy = sessionStorage.getItem(LEGACY_GUEST_MNEMONIC_STORAGE_KEY);
    if (legacy) {
        sessionStorage.setItem(ACCOUNT_MNEMONIC_STORAGE_KEY, legacy);
        sessionStorage.removeItem(LEGACY_GUEST_MNEMONIC_STORAGE_KEY);
        return legacy;
    }
    return null;
}

/** True when a plaintext phrase still sits in the legacy localStorage key. */
export function hasLegacyPersistedMnemonic(): boolean {
    try {
        const persisted = localStorage.getItem(PERSISTENT_ACCOUNT_MNEMONIC_KEY);
        return !!persisted && validateMnemonic(normalizeAccountMnemonic(persisted), wordlist);
    } catch {
        return false;
    }
}

/**
 * Remove the legacy plaintext localStorage copy. Idempotent. Call only after the
 * user has confirmed their recovery phrase is backed up - after this the phrase
 * lives session-only, so a browser restart requires re-unlocking with it.
 */
export function finalizeSeedMigrationAfterBackupConfirmed(): void {
    try {
        localStorage.removeItem(PERSISTENT_ACCOUNT_MNEMONIC_KEY);
    } catch {
        // Storage unavailable - nothing persisted to remove.
    }
}

export function clearStoredAccountMnemonic(): void {
    sessionStorage.removeItem(ACCOUNT_MNEMONIC_STORAGE_KEY);
    sessionStorage.removeItem(LEGACY_GUEST_MNEMONIC_STORAGE_KEY);
    try {
        localStorage.removeItem(PERSISTENT_ACCOUNT_MNEMONIC_KEY);
    } catch {
        // Storage unavailable.
    }
}

export function clearSessionAccountMnemonic(): void {
    sessionStorage.removeItem(ACCOUNT_MNEMONIC_STORAGE_KEY);
    sessionStorage.removeItem(LEGACY_GUEST_MNEMONIC_STORAGE_KEY);
}

export type OwnerSwitchImpact =
    | { switches: false }
    | { switches: true; companies: number; contacts: number; documents: number };

/**
 * Evolu `appOwner` resolves only once the DB worker initializes; when the
 * worker cannot come up at all (private windows without OPFS - e.g. Firefox
 * private browsing), the promise stays pending FOREVER, so every login-path
 * await on it must be bounded. Generous on purpose: on working browsers this
 * covers a cold WASM load, and timing out the data-loss preview skips the
 * owner-switch warning, so it must not fire on a merely slow device.
 */
export const EVOLU_READY_TIMEOUT_MS = 10_000;

/** Short probe once a timeout already happened (this page load or, via sessionStorage, a previous one). */
export const EVOLU_READY_RETRY_TIMEOUT_MS = 1_500;

/** Token wait after a timeout in the SAME page load - the worker is not coming up. */
export const EVOLU_READY_FAST_PROBE_TIMEOUT_MS = 250;

export const EVOLU_READY_PROBE_FAILED_KEY = "satflux.evolu.ready_probe_failed.v1";

/** True for the "worker never came up" rejection of initEvoluFromAccountSeedIfNeeded. */
export function isEvoluUnavailableError(error: unknown): boolean {
    return error instanceof Error && error.message === "evolu_unavailable";
}

let evoluReadyLatch: "unknown" | "ready" | "timed_out" = "unknown";

function readyProbeFlagged(): boolean {
    try {
        return sessionStorage.getItem(EVOLU_READY_PROBE_FAILED_KEY) === "1";
    } catch {
        return false;
    }
}

function setReadyProbeFlag(failed: boolean): void {
    try {
        if (failed) {
            sessionStorage.setItem(EVOLU_READY_PROBE_FAILED_KEY, "1");
        } else {
            sessionStorage.removeItem(EVOLU_READY_PROBE_FAILED_KEY);
        }
    } catch {
        // Storage unavailable - probes just keep their full timeout.
    }
}

async function awaitEvoluReady(evolu: { appOwner: Promise<unknown> }): Promise<void> {
    if (evoluReadyLatch === "ready") {
        return;
    }
    // Once a probe timed out, the worker almost certainly never comes up this
    // session (dead storage, e.g. Firefox private browsing without OPFS), so
    // later probes only need a token wait; the sessionStorage flag carries
    // that knowledge across the full page load into the app bundle. appOwner
    // is still raced each time, so a worker that did come up wins immediately.
    const timeoutMs =
        evoluReadyLatch === "timed_out"
            ? EVOLU_READY_FAST_PROBE_TIMEOUT_MS
            : readyProbeFlagged()
              ? EVOLU_READY_RETRY_TIMEOUT_MS
              : EVOLU_READY_TIMEOUT_MS;
    try {
        await withTimeout(evolu.appOwner, timeoutMs, "evolu_unavailable");
        evoluReadyLatch = "ready";
        setReadyProbeFlag(false);
    } catch (error) {
        evoluReadyLatch = "timed_out";
        setReadyProbeFlag(true);
        throw error;
    }
}

/**
 * Data-loss guard preview (P1): would restoring with this phrase switch the
 * local Evolu owner, and how much local data would that re-link? Read-only -
 * nothing is mutated. Invalid phrases report no switch (authentication will
 * reject them anyway).
 */
export async function previewOwnerSwitchImpact(mnemonic: string): Promise<OwnerSwitchImpact> {
    const normalized = normalizeAccountMnemonic(mnemonic);
    if (!validateMnemonic(normalized, wordlist)) {
        return { switches: false };
    }
    try {
        const { evolu } = await import("@/evolu/client");
        await awaitEvoluReady(evolu);
        const owner = await evolu.appOwner;
        if (owner.mnemonic == null || isTargetEvoluOwner(owner.mnemonic, normalized)) {
            return { switches: false };
        }
        const { countLocalInvoicingData } = await import("@/evolu/localDataPresence");
        const counts = await countLocalInvoicingData();
        if (!counts.hasData) {
            return { switches: false };
        }
        return {
            switches: true,
            companies: counts.companies,
            contacts: counts.contacts,
            documents: counts.documents,
        };
    } catch {
        // Evolu unavailable - nothing local to lose.
        return { switches: false };
    }
}

/** Store recovery phrase on this browser and bind Evolu (logged-in users, new device). */
export async function bindRecoveryPhraseOnThisDevice(
    mnemonic: string,
): Promise<EvoluAccountSeedInitResult> {
    storeAccountMnemonic(mnemonic);
    return initEvoluFromAccountSeedIfNeeded(mnemonic);
}

export type EvoluAccountSeedInitResult =
    | "restored"
    | "already_synced"
    | "migrated_legacy_owner"
    | "relay_synced"
    | "owner_restore_failed";

const PENDING_OWNER_MIGRATION_SNAPSHOT_KEY = "satflux.evolu.pending_owner_migration_snapshot.v1";
/** Set when auto restoreAppOwner completes but owner still mismatches - prevents reload loops. */
const EVOLU_OWNER_RESTORE_ATTEMPTED_KEY = "satflux.evolu.owner_restore_attempted.v1";

function clearEvoluOwnerRestoreAttempted(): void {
    sessionStorage.removeItem(EVOLU_OWNER_RESTORE_ATTEMPTED_KEY);
}

export function markEvoluOwnerRestoreAttempted(): void {
    sessionStorage.setItem(EVOLU_OWNER_RESTORE_ATTEMPTED_KEY, "1");
}

export function hasEvoluOwnerRestoreBeenAttempted(): boolean {
    return sessionStorage.getItem(EVOLU_OWNER_RESTORE_ATTEMPTED_KEY) === "1";
}

export async function initEvoluFromAccountSeedIfNeeded(
    mnemonic: string,
): Promise<EvoluAccountSeedInitResult> {
    const evoluMnemonic = deriveEvoluOwnerMnemonic(mnemonic);
    const { evolu } = await import("@/evolu/client");
    // Fail fast (instead of the 60s bootstrap cap) when the worker never
    // initializes - every operation below would hang on it.
    await awaitEvoluReady(evolu);
    const { isEvoluRelaySyncPending, markEvoluRelaySyncPending, waitForInvoicingRelaySync } =
        await import("@/evolu/relaySyncWait");
    const { ensureEvoluRelaySubscription } = await import("@/evolu/evoluRelaySubscription");
    const {
        restoreInvoicingSnapshotAsync,
        snapshotHasInvoicingData,
        snapshotInvoicingData,
    } = await import("@/evolu/invoicingSnapshot");
    type InvoicingDataSnapshot = Awaited<ReturnType<typeof snapshotInvoicingData>>;

    const pendingSnapshotRaw = sessionStorage.getItem(PENDING_OWNER_MIGRATION_SNAPSHOT_KEY);
    if (pendingSnapshotRaw) {
        sessionStorage.removeItem(PENDING_OWNER_MIGRATION_SNAPSHOT_KEY);
        try {
            const pendingSnapshot = JSON.parse(pendingSnapshotRaw) as InvoicingDataSnapshot;
            if (snapshotHasInvoicingData(pendingSnapshot)) {
                markEvoluRelaySyncPending();
                await restoreInvoicingSnapshotAsync(evolu, pendingSnapshot);
                await ensureEvoluRelaySubscription(evolu);
                await waitForInvoicingRelaySync(evolu);
                return "migrated_legacy_owner";
            }
        } catch {
            // Corrupt pending snapshot - continue with normal init.
        }
    }

    const owner = await evolu.appOwner;
    if (isTargetEvoluOwner(owner.mnemonic, mnemonic)) {
        clearEvoluOwnerRestoreAttempted();
        await ensureEvoluRelaySubscription(evolu);
        if (isEvoluRelaySyncPending()) {
            const synced = await waitForInvoicingRelaySync(evolu);
            return synced ? "relay_synced" : "already_synced";
        }
        return "already_synced";
    }

    if (hasEvoluOwnerRestoreBeenAttempted()) {
        const ownerAfterAttempt = await evolu.appOwner;
        if (isTargetEvoluOwner(ownerAfterAttempt.mnemonic, mnemonic)) {
            clearEvoluOwnerRestoreAttempted();
            await ensureEvoluRelaySubscription(evolu);
            return "already_synced";
        }
        return "owner_restore_failed";
    }

    const snapshot = await snapshotInvoicingData(evolu);
    const hasData = snapshotHasInvoicingData(snapshot);
    const wrongOwner = owner.mnemonic != null && owner.mnemonic !== evoluMnemonic;

    async function restoreInvoicingAfterOwnerReset(
        dataSnapshot: InvoicingDataSnapshot,
    ): Promise<void> {
        if (!snapshotHasInvoicingData(dataSnapshot)) {
            return;
        }
        markEvoluRelaySyncPending();
        await restoreInvoicingSnapshotAsync(evolu, dataSnapshot);
        await ensureEvoluRelaySubscription(evolu);
        await waitForInvoicingRelaySync(evolu);
    }

    async function finishAfterOwnerRestore(
        result: "restored" | "migrated_legacy_owner",
    ): Promise<EvoluAccountSeedInitResult> {
        const ownerAfterRestore = await evolu.appOwner;
        if (!isTargetEvoluOwner(ownerAfterRestore.mnemonic, mnemonic)) {
            markEvoluOwnerRestoreAttempted();
            return "owner_restore_failed";
        }
        clearEvoluOwnerRestoreAttempted();
        await ensureEvoluRelaySubscription(evolu);
        return result;
    }

    if (wrongOwner && hasData) {
        markEvoluRelaySyncPending();
        await evolu.restoreAppOwner(evoluMnemonic, { reload: false });
        await restoreInvoicingAfterOwnerReset(snapshot);
        return finishAfterOwnerRestore("migrated_legacy_owner");
    }

    markEvoluRelaySyncPending();
    await evolu.restoreAppOwner(evoluMnemonic, { reload: false });
    return finishAfterOwnerRestore("restored");
}
