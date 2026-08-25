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

    /** C3 runbook: wait until relay sync settled (fresh browser profiles start empty). */
    async waitForSync(): Promise<boolean> {
        const { waitForInvoicingRelaySync } = await import("./relaySyncWait");
        const ok = await waitForInvoicingRelaySync(evolu, { timeoutMs: 120_000 });
        log("waitForSync", ok);
        return ok;
    },

    /** C3 runbook: plain JSON backup of the whole local database (same as the account backup card). */
    async exportBackup(): Promise<unknown> {
        const { exportInvoicingBackup } = await import("./invoicingBackup");
        const app = await evolu.appOwner;
        return exportInvoicingBackup(evolu, app.id);
    },

    /** C3 runbook recovery: return a duplicated / half-shared company to private using a pre-share backup. */
    async recoverCompanyToPrivate(companyId: string, backupEnvelope: unknown): Promise<unknown> {
        const { recoverCompanyToPrivate } = await import("./companyShareRecovery");
        const envelope = backupEnvelope as { data?: unknown };
        const result = await recoverCompanyToPrivate(evolu, companyId, (envelope.data ?? envelope) as never);
        log("recoverCompanyToPrivate", result);
        return result;
    },

    /** C3 runbook: convert a company through the real migration (same code path as the UI card). */
    async convertCompany(companyId: string, force = false): Promise<unknown> {
        const { convertCompanyToShared } = await import("./companyShareMigration");
        const result = await convertCompanyToShared(evolu, companyId, {
            force,
            onProgress: (p) => log("convert progress", p),
        });
        log("convertCompany", result);
        return result;
    },

    /** C3 runbook: the share secret of a company (own partition only) - hand it to device B. */
    async shareSecret(companyId: string): Promise<{ secret: string; ownerId: string; bridgeCompanyId: string | null; status: string; migratingDeviceId: string | null } | null> {
        const { allCompanySharesQuery } = await import("./client");
        const app = await evolu.appOwner;
        const rows = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly {
            companyId: string; ownerId: string | null; sharedOwnerId: string; secretB64: string; bridgeCompanyId: string | null; status: string; migratingDeviceId: string | null;
        }[];
        const row = rows.find((r) => r.companyId === companyId && r.ownerId === app.id && r.status !== "revoked");
        return row ? { secret: row.secretB64, ownerId: row.sharedOwnerId, bridgeCompanyId: row.bridgeCompanyId, status: row.status, migratingDeviceId: (row as { migratingDeviceId?: string | null }).migratingDeviceId ?? null } : null;
    },

    /** C3 runbook: per-partition counts of a company's documents / contacts / expenses. */
    async partitionReport(companyId: string): Promise<Record<string, { app: number; shared: number; appDeleted: number }>> {
        const { allDocumentsQuery, allContactsQuery, allExpensesQuery, allDocumentLinesQuery } = await import("./client");
        const app = await evolu.appOwner;
        const count = (rows: readonly { companyId?: unknown; ownerId?: unknown; isDeleted?: unknown }[], filter: (r: { companyId?: unknown }) => boolean) => {
            const mine = rows.filter(filter);
            return {
                app: mine.filter((r) => r.ownerId === app.id && r.isDeleted !== 1).length,
                shared: mine.filter((r) => r.ownerId !== app.id && r.isDeleted !== 1).length,
                appDeleted: mine.filter((r) => r.ownerId === app.id && r.isDeleted === 1).length,
            };
        };
        const documents = (await evolu.loadQuery(allDocumentsQuery)) as unknown as readonly { id: string; companyId?: unknown; ownerId?: unknown; isDeleted?: unknown }[];
        const docIds = new Set(documents.filter((r) => r.companyId === companyId).map((r) => r.id));
        const lines = (await evolu.loadQuery(allDocumentLinesQuery)) as unknown as readonly { documentId?: unknown; ownerId?: unknown; isDeleted?: unknown }[];
        const report = {
            document: count(documents, (r) => r.companyId === companyId),
            documentLine: count(lines as never, (r) => docIds.has(String((r as { documentId?: unknown }).documentId))),
            contact: count(await evolu.loadQuery(allContactsQuery) as never, (r) => r.companyId === companyId),
            expense: count(await evolu.loadQuery(allExpensesQuery) as never, (r) => r.companyId === companyId),
        };
        log("partitionReport", report);
        return report;
    },

    /** C3 runbook: reserve a number on the bridge company (exercises the shared sequence + membership). */
    async reserveNumberProbe(bridgeCompanyId: string, issueRequestId: string): Promise<unknown> {
        const { invoicingApi } = await import("@/services/api");
        try {
            const data = await invoicingApi.numberAllocator.reserve(bridgeCompanyId, { document_type: "invoice", issue_request_id: issueRequestId });
            log("reserveNumberProbe", data);
            return data;
        } catch (error) {
            const status = (error as { response?: { status?: number } })?.response?.status;
            log("reserveNumberProbe failed", status);
            return { error: status ?? String(error) };
        }
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
