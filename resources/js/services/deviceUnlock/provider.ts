import {
    addPasskeyPrfSlot,
    createDeviceEnvelope,
    DeviceUnlockError,
    listPasskeyPrfSlots,
    removePasskeyPrfSlot,
    rotatePassphrase,
    touchPasskeyPrfSlot,
    unlockWithPassphrase,
    unlockWithPrfOutput,
    type DeviceEnvelope,
    type DeviceUnlockSlotType,
    type PasskeySlotMetadata,
} from "./envelope";
import {
    createPasskeyPrfCredential,
    evaluatePrf,
    evaluatePrfDiscoverable,
    evaluatePrfForSlots,
    isPasskeyPrfSupported,
} from "./passkeyPrf";
import {
    ACCOUNT_PRF_INPUT_B64,
    accountPrfInputBytes,
    decryptAccountEnvelope,
    encryptAccountEnvelope,
    fetchEnvelopeForLogin,
    listAccountEnvelopes,
    putAccountEnvelope,
    type AccountEnvelopeSummary,
} from "./accountPasskeyEnvelope";
import {
    clearDeviceEnvelope,
    hasDeviceEnvelope,
    loadDeviceEnvelope,
    saveDeviceEnvelope,
} from "./deviceEnvelopeStore";
import {
    deriveRecoveryPublicKeyHex,
    getStoredAccountMnemonic,
    normalizeAccountMnemonic,
    storeAccountMnemonic,
} from "../accountSeed";

/**
 * Device unlock abstraction. Today only the passphrase provider exists; a
 * PasskeyPrfUnlockProvider can be added as another slot type later WITHOUT a
 * new recovery seed or re-encrypting the dataset (see envelope.ts key-slot
 * design). Passkey PRF must go through runtime capability detection and the
 * standard PRF extension - never a WebAuthn signature used as an ad-hoc KDF.
 */
export interface DeviceUnlockProvider {
    readonly type: DeviceUnlockSlotType;
    isSupported(): Promise<boolean>;
}

export const passphraseUnlockProvider: DeviceUnlockProvider = {
    type: "passphrase",
    async isSupported() {
        return typeof globalThis.crypto?.subtle !== "undefined";
    },
};

/**
 * Passkey PRF provider: platform-authenticator probe only - whether the
 * authenticator actually implements PRF is knowable only at create() time
 * (addPasskeyToRememberedDevice fails typed when it does not).
 */
export const passkeyPrfUnlockProvider: DeviceUnlockProvider = {
    type: "passkey-prf",
    async isSupported() {
        return isPasskeyPrfSupported();
    },
};

export async function isDeviceRemembered(): Promise<boolean> {
    return hasDeviceEnvelope();
}

/**
 * Opt-in "remember this device": wrap the recovery phrase in a passphrase
 * envelope and persist it. Verifies by test-decrypt that the envelope restores
 * the same owner fingerprint before it is stored, then puts the phrase in the
 * session so the current tab is unlocked.
 */
export async function rememberDeviceWithPassphrase(recoveryPhrase: string, passphrase: string): Promise<void> {
    const normalized = normalizeAccountMnemonic(recoveryPhrase);
    const ownerFingerprint = deriveRecoveryPublicKeyHex(normalized);

    const envelope = await createDeviceEnvelope({ recoveryPhrase: normalized, passphrase, ownerFingerprint });

    // Test-decrypt before persisting: never store an envelope that cannot
    // reproduce the expected owner (crash/idempotency safety).
    const check = await unlockWithPassphrase(envelope, passphrase);
    if (deriveRecoveryPublicKeyHex(check.recoveryPhrase) !== ownerFingerprint) {
        throw new DeviceUnlockError();
    }
    check.dekRaw.fill(0);

    await saveDeviceEnvelope(envelope);
    storeAccountMnemonic(normalized);
}

/**
 * Unlock this device with the passphrase. On success the phrase is placed in
 * the session-only store; the caller then boots Evolu. The owner fingerprint is
 * re-verified from the decrypted phrase before it is trusted.
 */
export async function unlockDeviceWithPassphrase(passphrase: string): Promise<{ recoveryPhrase: string }> {
    const envelope = await loadDeviceEnvelope();
    if (!envelope) {
        throw new DeviceUnlockError();
    }
    const { recoveryPhrase, dekRaw } = await unlockWithPassphrase(envelope, passphrase);
    dekRaw.fill(0);

    if (deriveRecoveryPublicKeyHex(recoveryPhrase) !== envelope.ownerFingerprint) {
        throw new DeviceUnlockError();
    }
    storeAccountMnemonic(recoveryPhrase);
    return { recoveryPhrase };
}

/** Change the device passphrase; the recovery phrase and DEK are untouched. */
export async function changeDevicePassphrase(oldPassphrase: string, newPassphrase: string): Promise<void> {
    const envelope = await loadDeviceEnvelope();
    if (!envelope) {
        throw new DeviceUnlockError();
    }
    const rotated = await rotatePassphrase({ envelope, oldPassphrase, newPassphrase });
    await saveDeviceEnvelope(rotated);
}

/** "Forget this device": drop the encrypted envelope. Does not touch the session. */
export async function forgetDevice(): Promise<void> {
    await clearDeviceEnvelope();
}

/** Passkey slots on this device's envelope (empty when device not remembered). */
export async function listDevicePasskeySlots(): Promise<PasskeySlotMetadata[]> {
    const envelope = await loadDeviceEnvelope();
    return envelope ? listPasskeyPrfSlots(envelope) : [];
}

/**
 * Add a passkey unlock shortcut to an already remembered device. Requires the
 * device passphrase (proves possession + yields the DEK) - the passphrase
 * slot always stays; the passkey can only ever be an ADDITIONAL slot.
 */
export async function addPasskeyToRememberedDevice(
    passphrase: string,
    label: string,
): Promise<{ slots: PasskeySlotMetadata[]; cloudSynced: boolean }> {
    const envelope = await loadDeviceEnvelope();
    if (!envelope) {
        throw new DeviceUnlockError();
    }
    const { dekRaw } = await unlockWithPassphrase(envelope, passphrase);
    try {
        // The FIXED account input doubles as the local slot's salt so ONE
        // create() gesture serves both envelopes (HKDF infos differ, so the
        // derived keys stay domain-separated).
        const credential = await createPasskeyPrfCredential({
            label,
            userHandle: envelope.ownerFingerprint,
            userName: "satflux-device-unlock",
            prfSaltB64: ACCOUNT_PRF_INPUT_B64,
            requireResident: true,
        });

        // From here the PRF secret exists - zero it on every path.
        try {
            const updated = await addPasskeyPrfSlot({
                envelope,
                dekRaw,
                prfOutput: credential.prfOutput,
                credentialIdB64: credential.credentialIdB64,
                prfSaltB64: credential.prfSaltB64,
                label,
            });

            // Test-unlock before persisting - same crash/idempotency safety
            // as rememberDeviceWithPassphrase. The unwrapped DEK is zeroed
            // immediately; the fingerprint check only needs the phrase.
            const check = await unlockWithPrfOutput(updated, credential.credentialIdB64, credential.prfOutput);
            check.dekRaw.fill(0);
            if (deriveRecoveryPublicKeyHex(check.recoveryPhrase) !== envelope.ownerFingerprint) {
                throw new DeviceUnlockError();
            }

            await saveDeviceEnvelope(updated);

            // Cloud envelope: best-effort - the passkey already unlocks this
            // device; the caller surfaces a "works on this device only" hint
            // when the upload fails (retry via upgradeAccountPasskey).
            let cloudSynced = false;
            try {
                const payload = await encryptAccountEnvelope(check.recoveryPhrase, credential.prfOutput);
                await putAccountEnvelope({
                    credentialIdB64: credential.credentialIdB64,
                    payload,
                    label,
                });
                cloudSynced = true;
            } catch {
                cloudSynced = false;
            }

            return { slots: listPasskeyPrfSlots(updated), cloudSynced };
        } finally {
            credential.prfOutput.fill(0);
        }
    } finally {
        dekRaw.fill(0);
    }
}

/**
 * Sign-in path on ANY device: one discoverable WebAuthn gesture evaluates
 * the PRF, the server hands over this credential's ciphertext envelope and
 * the phrase decrypts locally. The caller then signs the guest-recovery
 * challenge with the phrase to obtain the session.
 */
export async function loginWithAccountPasskey(): Promise<{ recoveryPhrase: string }> {
    const { credentialIdB64, prfOutput } = await evaluatePrfDiscoverable(accountPrfInputBytes());
    try {
        const payload = await fetchEnvelopeForLogin(credentialIdB64);
        const recoveryPhrase = await decryptAccountEnvelope(payload, prfOutput);
        return { recoveryPhrase };
    } finally {
        prfOutput.fill(0);
    }
}

/**
 * Restore on a new device while already signed in (email login): same
 * decryption chain, but scoped to the account's known credentials so
 * non-resident passkeys work too.
 */
export async function restoreWithAccountPasskey(envelopes?: AccountEnvelopeSummary[]): Promise<{ recoveryPhrase: string }> {
    const list = envelopes ?? (await listAccountEnvelopes());
    if (list.length === 0) {
        throw new DeviceUnlockError();
    }

    const { credentialIdB64, prfOutput } = await evaluatePrfForSlots(
        list.map((entry) => ({
            credentialIdB64: credentialIdFromB64Url(entry.credential_id),
            prfSaltB64: ACCOUNT_PRF_INPUT_B64,
        })),
    );
    try {
        const payload = await fetchEnvelopeForLogin(credentialIdB64);
        const recoveryPhrase = await decryptAccountEnvelope(payload, prfOutput);
        return { recoveryPhrase };
    } finally {
        prfOutput.fill(0);
    }
}

/**
 * Create an account-level passkey WITHOUT a remembered device: needs only
 * the session phrase (Evolu unlocked). Cloud envelope only - a local slot
 * additionally requires the device passphrase path.
 */
/**
 * The passkey was created on the authenticator but the encrypted envelope
 * never reached the server. A retry must NOT create() again (that would mint
 * an orphan credential) - re-run the upload against this credential via
 * upgradeAccountPasskey.
 */
export class PasskeyEnvelopeUploadError extends Error {
    readonly credentialIdB64: string;

    constructor(credentialIdB64: string) {
        super("account envelope upload failed");
        this.credentialIdB64 = credentialIdB64;
    }
}

export async function addAccountPasskeyFromSession(label: string): Promise<void> {
    const phrase = getStoredAccountMnemonic();
    if (!phrase) {
        throw new DeviceUnlockError();
    }

    const credential = await createPasskeyPrfCredential({
        label,
        userHandle: deriveRecoveryPublicKeyHex(phrase),
        userName: "satflux-device-unlock",
        prfSaltB64: ACCOUNT_PRF_INPUT_B64,
        requireResident: true,
    });
    try {
        const payload = await encryptAccountEnvelope(phrase, credential.prfOutput);
        await putAccountEnvelope({
            credentialIdB64: credential.credentialIdB64,
            payload,
            label,
        });
    } catch {
        throw new PasskeyEnvelopeUploadError(credential.credentialIdB64);
    } finally {
        credential.prfOutput.fill(0);
    }
}

/**
 * Promote an existing local-only passkey slot to an account passkey: one
 * get() evaluating the FIXED input on that credential, then upload. Needs
 * the session phrase.
 */
export async function upgradeAccountPasskey(credentialIdB64: string, label: string | null): Promise<void> {
    const phrase = getStoredAccountMnemonic();
    if (!phrase) {
        throw new DeviceUnlockError();
    }

    const prfOutput = await evaluatePrf(credentialIdB64, ACCOUNT_PRF_INPUT_B64);
    try {
        const payload = await encryptAccountEnvelope(phrase, prfOutput);
        await putAccountEnvelope({ credentialIdB64, payload, label });
    } finally {
        prfOutput.fill(0);
    }
}

/** Server ids are base64url; WebAuthn helpers here use standard base64. */
function credentialIdFromB64Url(b64url: string): string {
    const b64 = b64url.replace(/-/g, "+").replace(/_/g, "/");
    return b64 + "=".repeat((4 - (b64.length % 4)) % 4);
}

/**
 * Unlock this device with a passkey: one platform prompt across all passkey
 * slots, PRF output unwraps the DEK, phrase goes to the session store and
 * the used slot gets a lastUsedAt stamp.
 */
export async function unlockDeviceWithPasskey(): Promise<{ recoveryPhrase: string }> {
    const envelope = await loadDeviceEnvelope();
    if (!envelope) {
        throw new DeviceUnlockError();
    }
    const slots = listPasskeyPrfSlots(envelope);
    if (slots.length === 0) {
        throw new DeviceUnlockError();
    }

    const { credentialIdB64, prfOutput } = await evaluatePrfForSlots(
        slots.map((slot) => ({ credentialIdB64: slot.credentialIdB64, prfSaltB64: findPrfSalt(envelope, slot.id) })),
    );

    try {
        const { recoveryPhrase, dekRaw, slotId } = await unlockWithPrfOutput(envelope, credentialIdB64, prfOutput);
        dekRaw.fill(0);

        if (deriveRecoveryPublicKeyHex(recoveryPhrase) !== envelope.ownerFingerprint) {
            throw new DeviceUnlockError();
        }

        storeAccountMnemonic(recoveryPhrase);
        await saveDeviceEnvelope(touchPasskeyPrfSlot(envelope, slotId));
        return { recoveryPhrase };
    } finally {
        prfOutput.fill(0);
    }
}

/** Remove a passkey slot; the passphrase slot(s) are untouched. */
export async function removeDevicePasskeySlot(slotId: string): Promise<PasskeySlotMetadata[]> {
    const envelope = await loadDeviceEnvelope();
    if (!envelope) {
        throw new DeviceUnlockError();
    }
    const updated = removePasskeyPrfSlot(envelope, slotId);
    await saveDeviceEnvelope(updated);
    return listPasskeyPrfSlots(updated);
}

function findPrfSalt(envelope: DeviceEnvelope, slotId: string): string {
    for (const slot of envelope.slots) {
        if (slot.id === slotId && slot.kdf.kind === "WEBAUTHN-PRF") {
            return slot.kdf.prfSaltB64;
        }
    }
    throw new DeviceUnlockError();
}

export type { DeviceEnvelope, PasskeySlotMetadata };
