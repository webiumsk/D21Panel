import { createOwnerWebSocketTransport, createRandomBytes } from "@evolu/common";
import {
    createOwnerSecret,
    createSharedOwner,
    OwnerSecret,
    type OwnerId,
    type SharedOwner,
} from "@evolu/common/local-first";
import type { Evolu, SyncOwner, UnuseOwner } from "@evolu/common/local-first";
import type { InvoicingLocalSchema } from "./schema";
import { getResolvedEvoluRelayUrl } from "@/services/evoluRelayPreference";

/**
 * Company sharing (Track C) rests on Evolu SharedOwners: one owner per shared
 * company, derived from a random 32-byte secret that every member holds.
 * Rows written with `{ ownerId: shared.id }` are encrypted with the shared
 * key and sync through the relay to everyone who registered the owner.
 *
 * Known limitation (Evolu 7.4.1): the relay registers the write key on
 * first use and exposes no rotation to clients, so a revoked member keeps
 * write access to the OLD owner; revocation therefore means re-keying into
 * a fresh SharedOwner (see docs/COMPANY_SHARING.md).
 */

export const COMPANY_SHARE_SECRET_BYTES = 32;

/** Fresh random secret for a new shared company. */
export function createCompanyShareSecret(): OwnerSecret {
    return createOwnerSecret({ randomBytes: createRandomBytes() });
}

/** Base64url without padding - safe in URL fragments, JSON and QR codes. */
export function encodeOwnerSecret(secret: OwnerSecret): string {
    let binary = "";
    for (const byte of secret) {
        binary += String.fromCharCode(byte);
    }
    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

export function decodeOwnerSecret(encoded: string): OwnerSecret | null {
    const normalized = encoded.trim().replace(/-/g, "+").replace(/_/g, "/");
    if (normalized === "" || !/^[A-Za-z0-9+/]+$/.test(normalized)) {
        return null;
    }
    let binary: string;
    try {
        binary = atob(normalized + "=".repeat((4 - (normalized.length % 4)) % 4));
    } catch {
        return null;
    }
    const bytes = Uint8Array.from(binary, (c) => c.charCodeAt(0));
    if (bytes.length !== COMPANY_SHARE_SECRET_BYTES) {
        return null;
    }
    const parsed = OwnerSecret.from(bytes);
    return parsed.ok ? parsed.value : null;
}

/** Deterministic: the same secret yields the same owner (id + keys) on every device. */
export function sharedOwnerFromSecret(secret: OwnerSecret): SharedOwner {
    return createSharedOwner(secret);
}

/** Owner with its own relay transport, like the AppOwner in evoluRelayOwner.ts. */
export function sharedOwnerForRelay(owner: SharedOwner): SyncOwner {
    const relayUrl = getResolvedEvoluRelayUrl();
    if (!relayUrl) {
        return owner;
    }
    return {
        ...owner,
        transports: [createOwnerWebSocketTransport({ url: relayUrl, ownerId: owner.id })],
    };
}

const activeOwners = new Map<OwnerId, UnuseOwner>();

/**
 * Register the owner for sync (idempotent per owner id). Returns the id so
 * callers can scope mutations with it.
 */
export function registerSharedOwner(evolu: Evolu<InvoicingLocalSchema>, owner: SharedOwner): OwnerId {
    if (!activeOwners.has(owner.id)) {
        activeOwners.set(owner.id, evolu.useOwner(sharedOwnerForRelay(owner)));
    }
    return owner.id;
}

export function unregisterSharedOwner(ownerId: OwnerId): void {
    activeOwners.get(ownerId)?.();
    activeOwners.delete(ownerId);
}

export function isSharedOwnerRegistered(ownerId: OwnerId): boolean {
    return activeOwners.has(ownerId);
}

/** Test / teardown helper. */
export function unregisterAllSharedOwners(): void {
    for (const unuse of activeOwners.values()) {
        unuse();
    }
    activeOwners.clear();
}
