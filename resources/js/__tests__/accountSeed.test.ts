import { afterEach, beforeEach, describe, expect, it } from "vitest";
import {
    clearStoredAccountMnemonic,
    finalizeSeedMigrationAfterBackupConfirmed,
    getStoredAccountMnemonic,
    hasLegacyPersistedMnemonic,
    hydrateAccountMnemonicSession,
    storeAccountMnemonic,
} from "../services/accountSeed";

// BIP39 test vector - clearly fake, never a real recovery phrase.
const TEST_MNEMONIC =
    "abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon art";

const SESSION_KEY = "satflux.account.mnemonic.v1";
const LEGACY_SESSION_KEY = "satflux.guest.mnemonic.v1";
const PERSISTENT_KEY = "satflux.account.mnemonic.persistent.v1";

describe("accountSeed persistence", () => {
    beforeEach(() => {
        sessionStorage.clear();
        localStorage.clear();
    });

    afterEach(() => {
        sessionStorage.clear();
        localStorage.clear();
    });

    it("stores the phrase session-only and never writes localStorage", () => {
        storeAccountMnemonic(TEST_MNEMONIC);
        expect(sessionStorage.getItem(SESSION_KEY)).toBeTruthy();
        expect(localStorage.getItem(PERSISTENT_KEY)).toBeNull();
    });

    it("does not survive a full browser restart (session cleared, no localStorage)", () => {
        storeAccountMnemonic(TEST_MNEMONIC);
        sessionStorage.clear();

        hydrateAccountMnemonicSession();

        expect(getStoredAccountMnemonic()).toBeNull();
    });

    it("migration bridge: hydrates session from a legacy localStorage phrase without deleting it", () => {
        // Simulate a user who signed in before the session-only change.
        localStorage.setItem(PERSISTENT_KEY, TEST_MNEMONIC);
        expect(hasLegacyPersistedMnemonic()).toBe(true);

        hydrateAccountMnemonicSession();

        expect(getStoredAccountMnemonic()).toBe(TEST_MNEMONIC);
        // Not deleted until backup is confirmed - avoids locking out a user who
        // relied solely on the browser copy.
        expect(localStorage.getItem(PERSISTENT_KEY)).toBe(TEST_MNEMONIC);
    });

    it("finalize deletes the legacy localStorage copy after backup confirmation", () => {
        localStorage.setItem(PERSISTENT_KEY, TEST_MNEMONIC);

        finalizeSeedMigrationAfterBackupConfirmed();

        expect(localStorage.getItem(PERSISTENT_KEY)).toBeNull();
        expect(hasLegacyPersistedMnemonic()).toBe(false);
        // Idempotent.
        expect(() => finalizeSeedMigrationAfterBackupConfirmed()).not.toThrow();
    });

    it("ignores an invalid legacy localStorage value", () => {
        localStorage.setItem(PERSISTENT_KEY, "not a valid mnemonic");
        expect(hasLegacyPersistedMnemonic()).toBe(false);

        hydrateAccountMnemonicSession();

        expect(getStoredAccountMnemonic()).toBeNull();
    });

    it("returns null and removes the key when the stored session value is corrupted", () => {
        sessionStorage.setItem(SESSION_KEY, "not a valid mnemonic");

        expect(getStoredAccountMnemonic()).toBeNull();
        expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
    });

    it("drops a corrupted legacy session value instead of adopting it", () => {
        sessionStorage.setItem(LEGACY_SESSION_KEY, "not a valid mnemonic");

        expect(getStoredAccountMnemonic()).toBeNull();
        expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
        expect(sessionStorage.getItem(LEGACY_SESSION_KEY)).toBeNull();
    });

    it("clears both session and legacy localStorage on lock/logout", () => {
        storeAccountMnemonic(TEST_MNEMONIC);
        localStorage.setItem(PERSISTENT_KEY, TEST_MNEMONIC);

        clearStoredAccountMnemonic();

        expect(getStoredAccountMnemonic()).toBeNull();
        expect(localStorage.getItem(PERSISTENT_KEY)).toBeNull();
    });
});
