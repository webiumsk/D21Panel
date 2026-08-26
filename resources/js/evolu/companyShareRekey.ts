import type { Evolu, OwnerId } from "@evolu/common/local-first";
import { allCompanySharesQuery } from "./client";
import {
    collectCompanyRows,
    loadAllMigrationRows,
    MIGRATION_ORDER,
    pendingCopies,
    purgeRevokedShareResidue,
    rowForSharedUpsert,
    verifyMigrated,
} from "./companyShareMigration";
import { companyShareInfo, setCompanyShare } from "./companyShareRegistry";
import { scopedEvolu } from "./ownerScope";
import { waitForInvoicingDataSettled, waitForInvoicingRelaySync } from "./relaySyncWait";
import type { CompanyId, CompanyShareId, InvoicingLocalSchema } from "./schema";
import {
    createCompanyShareSecret,
    decodeOwnerSecret,
    encodeOwnerSecret,
    localDeviceId,
    registerSharedOwner,
    sharedOwnerFromSecret,
    unregisterSharedOwner,
} from "./sharedOwner";

/**
 * Re-keys a shared company (docs/COMPANY_SHARING.md, "C5c"): rotate the company
 * onto a FRESH SharedOwner so a revoked member - who still holds the old secret -
 * cannot read anything written after the rotation, and loses the old copy once
 * its soft-delete reaches their device.
 *
 * It is a second migration: every live row under the OLD owner is re-written
 * under the NEW owner (same id, createdAt preserved), verified, then the OLD
 * partition is soft-deleted. Crash-safe by construction - the new owner is
 * recorded as a `companyShare` row with status "rekeying" BEFORE anything is
 * copied and flipped to "active" only after verification, so a reload resumes.
 * The bootstrap ignores "rekeying" rows for scoping, so live writes stay on the
 * old owner until cutover.
 *
 * Remaining (non-revoked) members must re-join under the new key: after a
 * re-key the owner re-invites them (their old invites carry the old secret).
 */

type Loader = Evolu<InvoicingLocalSchema>;

const SETTLE_MS = 3_000;
const VERIFY_ATTEMPTS = 5;
const VERIFY_RETRY_MS = 750;

export type RekeyProgress = { phase: "prepare" | "copy" | "verify" | "cleanup" | "done"; done: number; total: number };

export type RekeyResult =
    | { ok: true; oldOwnerId: string; newOwnerId: OwnerId; copied: number; softDeleted: number; resumed: boolean }
    | { ok: false; error: "not_shared" | "not_owner" | "rekeying_elsewhere" | "share_row_failed" | "copy_failed" | "verify_failed" | "cleanup_failed" | "activation_failed"; detail?: unknown };

type ShareRow = {
    id: string; ownerId: string | null; companyId: string; sharedOwnerId: string;
    secretB64: string; role: string; status: string; bridgeCompanyId: string | null; migratingDeviceId: string | null;
};

function mutate(run: (onComplete: () => void) => { ok: boolean; error?: unknown }): Promise<{ ok: boolean; error?: unknown }> {
    return new Promise((resolve) => {
        const result = run(() => resolve({ ok: true }));
        if (!result.ok) resolve({ ok: false, error: result.error });
    });
}

async function loadShares(evolu: Loader, appOwnerId: string, companyId: string): Promise<ShareRow[]> {
    const rows = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly ShareRow[];
    return rows.filter((r) => r.companyId === companyId && r.ownerId === appOwnerId);
}

async function finishSupersededActiveShares(
    evolu: Loader,
    companyId: string,
    activeShares: ShareRow[],
): Promise<RekeyResult | null> {
    if (activeShares.length < 2) {
        return null;
    }

    // allCompanySharesQuery is ordered by createdAt, so the newest active row
    // is the completed cutover target; older active rows are interrupted cleanup.
    const current = activeShares[activeShares.length - 1];
    if (current.role !== "owner") {
        return { ok: false, error: "not_owner" };
    }
    const secret = decodeOwnerSecret(current.secretB64);
    if (!secret) {
        return { ok: false, error: "share_row_failed", detail: "undecodable active rekey secret" };
    }
    const owner = sharedOwnerFromSecret(secret);
    if (owner.id !== current.sharedOwnerId) {
        return { ok: false, error: "share_row_failed", detail: "active rekey secret owner mismatch" };
    }

    registerSharedOwner(evolu, owner);
    setCompanyShare({
        companyId,
        ownerId: owner.id,
        role: "owner",
        status: "active",
        bridgeCompanyId: current.bridgeCompanyId ?? null,
    });

    const supersededOwners: string[] = [];
    for (const superseded of activeShares.slice(0, -1)) {
        const revoked = await mutate((onComplete) =>
            evolu.update("companyShare", { id: superseded.id as never, status: "revoked" } as never, { onComplete }),
        );
        if (!revoked.ok) {
            return { ok: false, error: "activation_failed", detail: revoked.error };
        }
        supersededOwners.push(superseded.sharedOwnerId);
    }

    const softDeleted = await purgeRevokedShareResidue(evolu, companyId).catch(() => 0);
    for (const ownerId of supersededOwners) {
        unregisterSharedOwner(evolu, ownerId as never);
    }
    return {
        ok: true,
        oldOwnerId: activeShares[0].sharedOwnerId,
        newOwnerId: owner.id,
        copied: 0,
        softDeleted,
        resumed: true,
    };
}

export type RekeyOptions = {
    onProgress?: (progress: RekeyProgress) => void;
    /** Skip pre-flight relay sync wait (tests / already-synced callers). */
    skipSyncWait?: boolean;
    /** Take over a rekey started on another device. */
    force?: boolean;
};

/**
 * Re-key (or resume re-keying) the company. Idempotent: a "rekeying" row is
 * adopted and resumed; a device that did not start it refuses unless `force`.
 */
export async function rekeyCompanyShare(evolu: Loader, companyId: string, options: RekeyOptions = {}): Promise<RekeyResult> {
    const report = (progress: RekeyProgress) => options.onProgress?.(progress);
    report({ phase: "prepare", done: 0, total: 0 });

    const appOwnerId = (await evolu.appOwner).id;

    if (!options.skipSyncWait) {
        await waitForInvoicingRelaySync(evolu, { timeoutMs: 30_000 }).catch(() => undefined);
    }

    const shares = await loadShares(evolu, appOwnerId, companyId);
    const activeShares = shares.filter((r) => r.status === "active");
    const finalized = await finishSupersededActiveShares(evolu, companyId, activeShares);
    if (finalized) {
        return finalized;
    }

    const active = activeShares[0];
    if (!active) {
        return { ok: false, error: "not_shared" };
    }
    if (active.role !== "owner") {
        return { ok: false, error: "not_owner" };
    }
    const oldOwnerId = active.sharedOwnerId;
    const bridgeCompanyId = active.bridgeCompanyId;

    // Adopt an in-flight rekey (resume) or mint a fresh owner.
    let newSecretEncoded: string;
    let newShareRowId: CompanyShareId;
    const inFlight = shares.find((r) => r.status === "rekeying");
    if (inFlight) {
        if (inFlight.migratingDeviceId && inFlight.migratingDeviceId !== localDeviceId() && !options.force) {
            return { ok: false, error: "rekeying_elsewhere" };
        }
        const decoded = decodeOwnerSecret(inFlight.secretB64);
        if (!decoded) {
            return { ok: false, error: "share_row_failed", detail: "undecodable rekeying secret" };
        }
        newSecretEncoded = inFlight.secretB64;
        newShareRowId = inFlight.id as CompanyShareId;
    } else {
        const secret = createCompanyShareSecret();
        newSecretEncoded = encodeOwnerSecret(secret);
        const owner = sharedOwnerFromSecret(secret);
        const inserted = await mutate((onComplete) =>
            evolu.insert("companyShare", {
                companyId: companyId as CompanyId,
                sharedOwnerId: owner.id,
                secretB64: newSecretEncoded,
                role: "owner",
                status: "rekeying",
                bridgeCompanyId: bridgeCompanyId ?? undefined,
                migratingDeviceId: localDeviceId(),
            } as never, { onComplete }),
        );
        if (!inserted.ok) {
            return { ok: false, error: "share_row_failed", detail: inserted.error };
        }
        const after = await loadShares(evolu, appOwnerId, companyId);
        const mine = after.find((r) => r.status === "rekeying" && r.sharedOwnerId === owner.id);
        if (!mine) {
            return { ok: false, error: "share_row_failed" };
        }
        newShareRowId = mine.id as CompanyShareId;
    }

    const newSecret = decodeOwnerSecret(newSecretEncoded)!;
    const newOwner = sharedOwnerFromSecret(newSecret);
    registerSharedOwner(evolu, newOwner);
    // Give the new share row a moment to reach the relay before the copy, so a
    // tab closed mid-way leaves a resumable rekey on the user's other devices.
    await waitForInvoicingDataSettled(evolu, { minWaitMs: SETTLE_MS, timeoutMs: SETTLE_MS + 3_000 }).catch(() => undefined);

    const target = scopedEvolu(evolu, newOwner.id);

    // --- copy: OLD owner -> NEW owner --------------------------------
    let set = collectCompanyRows(await loadAllMigrationRows(evolu), companyId);
    const pending = pendingCopies(set, oldOwnerId, newOwner.id);
    const total = MIGRATION_ORDER.reduce((n, table) => n + pending[table].length, 0);
    let done = 0;
    for (const table of MIGRATION_ORDER) {
        for (const row of pending[table]) {
            report({ phase: "copy", done, total });
            const result = await mutate((onComplete) => target.upsert(table, rowForSharedUpsert(row) as never, { onComplete }));
            if (!result.ok) {
                return { ok: false, error: "copy_failed", detail: { table, id: row.id, error: result.error } };
            }
            done++;
        }
    }

    // --- verify ------------------------------------------------------
    report({ phase: "verify", done, total });
    let verification = { ok: false, missing: {} as Record<string, string[]> };
    for (let attempt = 0; attempt < VERIFY_ATTEMPTS; attempt++) {
        if (attempt > 0) {
            await new Promise((resolve) => setTimeout(resolve, VERIFY_RETRY_MS));
        }
        set = collectCompanyRows(await loadAllMigrationRows(evolu), companyId);
        verification = verifyMigrated(set, oldOwnerId, newOwner.id);
        if (verification.ok) break;
        const retry = pendingCopies(set, oldOwnerId, newOwner.id);
        for (const table of MIGRATION_ORDER) {
            for (const row of retry[table]) {
                const result = await mutate((onComplete) => target.upsert(table, rowForSharedUpsert(row) as never, { onComplete }));
                if (!result.ok) {
                    return { ok: false, error: "copy_failed", detail: { table, id: row.id, error: result.error } };
                }
                done++;
            }
        }
    }
    if (!verification.ok) {
        return { ok: false, error: "verify_failed", detail: verification.missing };
    }

    // --- cutover -----------------------------------------------------
    // Activation is the cutover boundary. The old rows stay live until the new
    // share is active, so an activation failure cannot strand future writes in
    // a deleted old partition. Once the old share is revoked, residue cleanup is
    // best-effort and also handled by purgeRevokedShareResidue on later boots.
    report({ phase: "cleanup", done, total });
    await waitForInvoicingDataSettled(evolu, { minWaitMs: SETTLE_MS, timeoutMs: 20_000 }).catch(() => undefined);

    const activated = await mutate((onComplete) => evolu.update("companyShare", { id: newShareRowId, status: "active" } as never, { onComplete }));
    if (!activated.ok) {
        return { ok: false, error: "activation_failed", detail: activated.error };
    }
    setCompanyShare({ companyId, ownerId: newOwner.id, role: "owner", status: "active", bridgeCompanyId: bridgeCompanyId ?? null });

    // Retire the old share; unregister its owner only once it is revoked.
    const revoked = await mutate((onComplete) => evolu.update("companyShare", { id: active.id as never, status: "revoked" } as never, { onComplete }));
    if (!revoked.ok) {
        return { ok: false, error: "activation_failed", detail: revoked.error };
    }
    const softDeleted = await purgeRevokedShareResidue(evolu, companyId).catch(() => 0);
    unregisterSharedOwner(evolu, oldOwnerId as never);

    report({ phase: "done", done, total });
    return { ok: true, oldOwnerId, newOwnerId: newOwner.id, copied: done, softDeleted, resumed: inFlight !== undefined };
}

/** Boot hook: finish a rekey a reload interrupted (own device only). */
export async function resumePendingCompanyShareRekeys(evolu: Loader): Promise<void> {
    const appOwnerId = (await evolu.appOwner).id;
    const rows = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly ShareRow[];
    const device = localDeviceId();
    const activeByCompany = new Map<string, ShareRow[]>();
    for (const row of rows) {
        if (row.ownerId !== appOwnerId || row.status !== "active") continue;
        activeByCompany.set(row.companyId, [...(activeByCompany.get(row.companyId) ?? []), row]);
    }
    for (const [companyId, activeShares] of activeByCompany) {
        if (activeShares.length < 2) continue;
        const result = await finishSupersededActiveShares(evolu, companyId, activeShares);
        if (result && !result.ok && import.meta.env.DEV) {
            console.warn("[company-share] finish interrupted rekey failed", companyId, result.error, result.detail);
        }
    }
    const companies = new Set(
        rows
            .filter((r) => r.ownerId === appOwnerId && r.status === "rekeying" && (!r.migratingDeviceId || r.migratingDeviceId === device))
            .map((r) => r.companyId),
    );
    for (const companyId of companies) {
        // Only resume if the company still has an active share to rotate from
        // and is not already fully rotated.
        if (companyShareInfo(companyId)?.status === "active" && rows.some((r) => r.companyId === companyId && r.ownerId === appOwnerId && r.status === "active")) {
            const result = await rekeyCompanyShare(evolu, companyId, { skipSyncWait: true });
            if (!result.ok && import.meta.env.DEV) {
                console.warn("[company-share] resume rekey failed", companyId, result.error, result.detail);
            }
        }
    }
}
