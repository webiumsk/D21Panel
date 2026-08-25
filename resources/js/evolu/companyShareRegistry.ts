import type { OwnerId } from "@evolu/common/local-first";

/**
 * In-memory view of which local company is shared under which SharedOwner.
 * Fed from the `companyShare` Evolu table (see companyShareBootstrap.ts) and
 * consulted synchronously by the mutation scoping proxy (ownerScope.ts) -
 * mutations cannot await, so this must be a plain map.
 */

export type CompanyShareRole = "owner" | "accountant" | "member";
export type CompanyShareStatus = "migrating" | "active" | "revoked";

export type CompanyShareInfo = {
    companyId: string;
    ownerId: OwnerId;
    role: CompanyShareRole;
    status: CompanyShareStatus;
    bridgeCompanyId: string | null;
};

const byCompany = new Map<string, CompanyShareInfo>();
const sharedOwnerIds = new Set<OwnerId>();
/** False until the first load from the `companyShare` table finished (see ownerScope.ts). */
let ready = false;

export function setCompanyShare(info: CompanyShareInfo): void {
    const previous = byCompany.get(info.companyId);
    if (previous && previous.ownerId !== info.ownerId) {
        sharedOwnerIds.delete(previous.ownerId);
    }
    byCompany.set(info.companyId, info);
    sharedOwnerIds.add(info.ownerId);
}

export function removeCompanyShare(companyId: string): void {
    const previous = byCompany.get(companyId);
    if (previous) {
        sharedOwnerIds.delete(previous.ownerId);
    }
    byCompany.delete(companyId);
}

export function clearCompanyShares(): void {
    byCompany.clear();
    sharedOwnerIds.clear();
    ready = false;
}

export function markCompanyShareRegistryReady(): void {
    ready = true;
}

export function isCompanyShareRegistryReady(): boolean {
    return ready;
}

/** SharedOwner of a company, or undefined when the company is private (AppOwner). */
export function ownerIdForCompany(companyId: string | null | undefined): OwnerId | undefined {
    if (!companyId) return undefined;
    const info = byCompany.get(String(companyId));
    return info && info.status !== "revoked" ? info.ownerId : undefined;
}

export function companyShareInfo(companyId: string | null | undefined): CompanyShareInfo | undefined {
    return companyId ? byCompany.get(String(companyId)) : undefined;
}

export function isRegisteredSharedOwnerId(ownerId: string | null | undefined): boolean {
    return typeof ownerId === "string" && sharedOwnerIds.has(ownerId as OwnerId);
}

export function listCompanyShares(): CompanyShareInfo[] {
    return [...byCompany.values()];
}
