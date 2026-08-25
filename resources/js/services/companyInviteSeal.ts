import { sha256 } from "@noble/hashes/sha2.js";
import { ed25519, x25519 } from "@noble/curves/ed25519.js";
import { fromB64, subtleCrypto, toB64 } from "./passphraseCrypto";

/**
 * Sealed box for a company invite (docs/COMPANY_SHARING.md, "C4").
 *
 * A shared company's SharedOwner secret must reach the invitee without ever
 * touching the server in the clear. The invitee is an existing user whose
 * long-term public key is an Ed25519 SIGNING key (users.guest_recovery_public_key,
 * derived in services/accountSeed.ts). We convert that key to X25519, do an
 * ephemeral-static ECDH, and wrap the secret with AES-GCM - an ECIES seal,
 * equivalent in shape to libsodium's crypto_box_seal but built from the
 * primitives already vendored here (WebCrypto HKDF + AES-GCM, @noble curves).
 *
 * The server stores the opaque `SealedInvite` and never sees the plaintext.
 * Only the holder of the invitee's Ed25519 seed can open it.
 */

const HKDF_INFO = "satflux-company-invite-v1";

export type SealedInvite = {
    /** format version - bump on any wire change */
    v: 1;
    /** ephemeral X25519 public key (base64) */
    epkB64: string;
    /** AES-GCM IV (base64, 12 bytes) */
    ivB64: string;
    /** AES-GCM ciphertext incl. tag (base64) */
    ctB64: string;
};

/** 32-byte hex Ed25519 public key (the guest_recovery_public_key format). */
function edPubFromHex(hex: string): Uint8Array | null {
    const clean = hex.trim().toLowerCase();
    if (!/^[a-f0-9]{64}$/.test(clean)) return null;
    const bytes = new Uint8Array(32);
    for (let i = 0; i < 32; i++) bytes[i] = Number.parseInt(clean.slice(i * 2, i * 2 + 2), 16);
    return bytes;
}

/**
 * Bind the AES key to BOTH public keys so a captured blob cannot be replayed
 * against a different ephemeral/recipient pairing. salt = ephemeralPub||recipientXPub.
 */
async function deriveSealKey(sharedSecret: Uint8Array, ephemeralXPub: Uint8Array, recipientXPub: Uint8Array): Promise<CryptoKey> {
    const subtle = subtleCrypto();
    const ikm = await subtle.importKey("raw", sharedSecret as BufferSource, "HKDF", false, ["deriveKey"]);
    const salt = new Uint8Array(ephemeralXPub.length + recipientXPub.length);
    salt.set(ephemeralXPub, 0);
    salt.set(recipientXPub, ephemeralXPub.length);
    return subtle.deriveKey(
        { name: "HKDF", hash: "SHA-256", salt, info: new TextEncoder().encode(HKDF_INFO) },
        ikm,
        { name: "AES-GCM", length: 256 },
        false,
        ["encrypt", "decrypt"],
    );
}

/** Seal a UTF-8 secret to the invitee's Ed25519 public key (64 hex chars). */
export async function sealCompanyInviteSecret(recipientEd25519PubHex: string, secret: string): Promise<SealedInvite | null> {
    const edPub = edPubFromHex(recipientEd25519PubHex);
    if (!edPub) return null;
    let recipientXPub: Uint8Array;
    try {
        recipientXPub = ed25519.utils.toMontgomery(edPub);
    } catch {
        return null;
    }
    const ephemeralSec = x25519.utils.randomSecretKey();
    const ephemeralPub = x25519.getPublicKey(ephemeralSec);
    const shared = x25519.getSharedSecret(ephemeralSec, recipientXPub);
    const key = await deriveSealKey(shared, ephemeralPub, recipientXPub);
    const subtle = subtleCrypto();
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await subtle.encrypt(
        { name: "AES-GCM", iv, additionalData: recipientXPub as BufferSource },
        key,
        new TextEncoder().encode(secret) as BufferSource,
    );
    ephemeralSec.fill(0);
    shared.fill(0);
    return { v: 1, epkB64: toB64(ephemeralPub), ivB64: toB64(iv), ctB64: toB64(new Uint8Array(ct)) };
}

/** Open a sealed invite with the invitee's Ed25519 seed (32 bytes). null on any failure. */
export async function openCompanyInviteSecret(recipientEd25519Seed: Uint8Array, sealed: SealedInvite): Promise<string | null> {
    if (!sealed || sealed.v !== 1) return null;
    let recipientXSec: Uint8Array;
    let recipientXPub: Uint8Array;
    try {
        recipientXSec = ed25519.utils.toMontgomerySecret(recipientEd25519Seed);
        recipientXPub = x25519.getPublicKey(recipientXSec);
    } catch {
        return null;
    }
    try {
        const ephemeralPub = fromB64(sealed.epkB64);
        const shared = x25519.getSharedSecret(recipientXSec, ephemeralPub);
        const key = await deriveSealKey(shared, ephemeralPub, recipientXPub);
        const plain = await subtleCrypto().decrypt(
            { name: "AES-GCM", iv: fromB64(sealed.ivB64) as BufferSource, additionalData: recipientXPub as BufferSource },
            key,
            fromB64(sealed.ctB64) as BufferSource,
        );
        recipientXSec.fill(0);
        shared.fill(0);
        return new TextDecoder().decode(plain);
    } catch {
        recipientXSec.fill(0);
        return null;
    }
}

/**
 * Short human-verifiable fingerprint of the invitee's public key, shown to
 * BOTH parties so they can confirm out-of-band that the invite was sealed to
 * the right person (guards against a substituted/typo'd recipient key).
 * Six groups of four base32 chars over the first 15 bytes of SHA-256(pubkey).
 */
const B32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
export function inviteFingerprint(recipientEd25519PubHex: string): string | null {
    const edPub = edPubFromHex(recipientEd25519PubHex);
    if (!edPub) return null;
    const digest = sha256(edPub).slice(0, 15);
    let bits = 0;
    let value = 0;
    const chars: string[] = [];
    for (const byte of digest) {
        value = (value << 8) | byte;
        bits += 8;
        while (bits >= 5) {
            bits -= 5;
            chars.push(B32[(value >>> bits) & 31]);
        }
    }
    const groups: string[] = [];
    for (let i = 0; i < chars.length; i += 4) groups.push(chars.slice(i, i + 4).join(""));
    return groups.join("-");
}
