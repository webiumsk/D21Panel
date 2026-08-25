import type { Evolu } from "@evolu/common/local-first";
import type { InvoicingLocalSchema } from "./schema";
import { allCompanySharesQuery } from "./client";
import {
    clearCompanyShares,
    listCompanyShares,
    removeCompanyShare,
    setCompanyShare,
    type CompanyShareRole,
    type CompanyShareStatus,
} from "./companyShareRegistry";
import { decodeOwnerSecret, registerSharedOwner, sharedOwnerFromSecret, unregisterSharedOwner } from "./sharedOwner";
import { toAppRows } from "./queryLoad";

/**
 * Rebuilds the SharedOwners this user holds from the `companyShare` table
 * (AppOwner partition, so it arrives on every device through the normal
 * sync) and keeps the registry + owner registrations in step with it.
 * Called once after the Evolu bootstrap; safe to call again.
 */

export type CompanyShareRow = {
    id: string;
    companyId: string;
    sharedOwnerId: string;
    secretB64: string;
    role: CompanyShareRole;
    status: CompanyShareStatus;
    bridgeCompanyId: string | null;
};

let unsubscribe: (() => void) | null = null;

export function applyCompanyShareRows(evolu: Evolu<InvoicingLocalSchema>, rows: readonly CompanyShareRow[]): void {
    const seen = new Set<string>();
    for (const row of rows) {
        if (row.status === "revoked") {
            continue;
        }
        const secret = decodeOwnerSecret(row.secretB64);
        if (!secret) {
            if (import.meta.env.DEV) {
                console.warn("[company-share] undecodable secret for company", row.companyId);
            }
            continue;
        }
        const owner = sharedOwnerFromSecret(secret);
        if (owner.id !== row.sharedOwnerId) {
            if (import.meta.env.DEV) {
                console.warn("[company-share] secret does not match the stored owner id for company", row.companyId);
            }
            continue;
        }
        registerSharedOwner(evolu, owner);
        setCompanyShare({
            companyId: row.companyId,
            ownerId: owner.id,
            role: row.role,
            status: row.status,
            bridgeCompanyId: row.bridgeCompanyId,
        });
        seen.add(row.companyId);
    }

    // Shares that disappeared (revoked / deleted) stop syncing.
    for (const info of listCompanyShares()) {
        if (!seen.has(info.companyId)) {
            unregisterSharedOwner(evolu, info.ownerId);
            removeCompanyShare(info.companyId);
        }
    }
}

export async function loadCompanyShareRegistry(evolu: Evolu<InvoicingLocalSchema>): Promise<void> {
    const rows = toAppRows<CompanyShareRow>(await evolu.loadQuery(allCompanySharesQuery));
    applyCompanyShareRows(evolu, rows);
    if (!unsubscribe) {
        unsubscribe = evolu.subscribeQuery(allCompanySharesQuery)(() => {
            applyCompanyShareRows(evolu, toAppRows<CompanyShareRow>(evolu.getQueryRows(allCompanySharesQuery)));
        });
    }
}

/** Test / logout helper. */
export function resetCompanyShareRegistry(evolu: Evolu<InvoicingLocalSchema>): void {
    unsubscribe?.();
    unsubscribe = null;
    applyCompanyShareRows(evolu, []);
    clearCompanyShares();
}
