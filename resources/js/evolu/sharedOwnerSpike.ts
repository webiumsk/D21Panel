import { sqliteTrue } from "@evolu/common";
import type { OwnerId } from "@evolu/common/local-first";
import { evolu } from "./client";
import { scopedEvolu } from "./ownerScope";
import type { CompanyId } from "./schema";
import {
    createCompanyShareSecret,
    decodeOwnerSecret,
    encodeOwnerSecret,
    isSharedOwnerRegistered,
    registerSharedOwner,
    sharedOwnerFromSecret,
    unregisterSharedOwner,
} from "./sharedOwner";

/**
 * DEV-ONLY spike for Track C (docs/COMPANY_SHARING.md, "C0"). Exposed as
 * `window.__satfluxSharedOwnerSpike` in development builds so the four
 * claims behind company sharing can be verified against the real relay
 * with two browsers before any product code depends on them:
 *
 *   1. a SharedOwner registered with `useOwner` syncs through the relay,
 *   2. `upsert` with `{ ownerId }` and the SAME id writes a separate row
 *      (rows are keyed by ownerId + id),
 *   3. soft-deleting the AppOwner copy leaves exactly one visible row,
 *   4. a second browser holding only the shared secret sees the data.
 *
 * Nothing here is reachable from the UI; the module is tree-shaken out of
 * production builds (see app.ts).
 */

type OwnerRow = {
    id: string;
    legalName: string | null;
    ownerId: string | null;
    isDeleted: 0 | 1 | null;
    updatedAt: string | null;
};

const allCompaniesWithOwnerQuery = evolu.createQuery((db) =>
    db
        .selectFrom("company")
        .select(["id", "legalName", "ownerId", "isDeleted", "updatedAt"])
        .orderBy("updatedAt"),
);

async function listRows(): Promise<OwnerRow[]> {
    const rows = (await evolu.loadQuery(allCompaniesWithOwnerQuery)) as unknown as readonly OwnerRow[];
    return rows.map((row) => ({ ...row }));
}

function log(step: string, payload: unknown): void {
    console.info(`[shared-owner-spike] ${step}`, payload);
}

export const sharedOwnerSpike = {
    /** Step 1 (device A): create a share secret, register the owner, print the secret for device B. */
    async createShare(): Promise<{ secret: string; ownerId: OwnerId }> {
        const secret = createCompanyShareSecret();
        const owner = sharedOwnerFromSecret(secret);
        registerSharedOwner(evolu, owner);
        const result = { secret: encodeOwnerSecret(secret), ownerId: owner.id };
        log("createShare - hand the secret to device B", result);
        return result;
    },

    /** Step 4 (device B): join with the secret only; rows should arrive through the relay. */
    async joinShare(secret: string): Promise<OwnerId | null> {
        const decoded = decodeOwnerSecret(secret);
        if (!decoded) {
            log("joinShare - invalid secret", secret);
            return null;
        }
        const owner = sharedOwnerFromSecret(decoded);
        registerSharedOwner(evolu, owner);
        log("joinShare - owner registered, waiting for relay", { ownerId: owner.id, alreadyRegistered: isSharedOwnerRegistered(evolu, owner.id) });
        return owner.id;
    },

    /** Step 2: write a company row under the shared owner - optionally with an id copied from an AppOwner row. */
    async writeProbe(ownerId: OwnerId, legalName: string, id?: string): Promise<string | null> {
        const scoped = scopedEvolu(evolu, ownerId);
        // Minimal valid company row - the probe only needs identity + owner partition.
        const probe = { legalName, jurisdiction: "eu_sk", defaultCurrency: "EUR", vatStatus: "none" } as const;
        const result = id
            ? scoped.upsert("company", { id: id as CompanyId, ...probe } as never)
            : scoped.insert("company", probe as never);
        log("writeProbe", { ownerId, id: result.ok ? result.value.id : null, ok: result.ok, error: result.ok ? null : result.error });
        return result.ok ? String(result.value.id) : null;
    },

    /** Step 3: soft-delete the AppOwner copy of a row that was re-written under the shared owner. */
    async softDeleteAppCopy(id: string): Promise<boolean> {
        const result = evolu.update("company", { id: id as CompanyId, isDeleted: sqliteTrue });
        log("softDeleteAppCopy", { id, ok: result.ok });
        return result.ok;
    },

    /** Every company row incl. its owner partition and deletion flag - the ground truth for all four claims. */
    async rows(): Promise<OwnerRow[]> {
        const rows = await listRows();
        const appOwner = await evolu.appOwner;
        console.table(rows.map((row) => ({ ...row, partition: row.ownerId === appOwner.id ? "app" : "shared" })));
        return rows;
    },

    /** C2 diagnostics: are query results indexed by owner inside the app's own module graph? */
    async probeIndex(): Promise<{ companies: number; withOwnerId: number; indexed: number; shares: number }> {
        const { allCompaniesQuery, allCompanySharesQuery } = await import("./client");
        const { knownRowOwner } = await import("./ownerScope");
        const rows = (await evolu.loadQuery(allCompaniesQuery)) as unknown as readonly { id: string; ownerId?: string }[];
        const shares = await evolu.loadQuery(allCompanySharesQuery);
        const result = {
            companies: rows.length,
            withOwnerId: rows.filter((r) => typeof r.ownerId === "string").length,
            indexed: rows.filter((r) => knownRowOwner(r.id) === r.ownerId).length,
            shares: shares.length,
        };
        log("probeIndex", result);
        return result;
    },

    async leave(ownerId: OwnerId): Promise<void> {
        unregisterSharedOwner(evolu, ownerId);
        log("leave", ownerId);
    },
};

export function installSharedOwnerSpike(): void {
    (window as unknown as { __satfluxSharedOwnerSpike?: typeof sharedOwnerSpike }).__satfluxSharedOwnerSpike = sharedOwnerSpike;
    console.info("[shared-owner-spike] available as window.__satfluxSharedOwnerSpike (dev only)");
}
