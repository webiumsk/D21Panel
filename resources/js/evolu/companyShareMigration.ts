import { sqliteTrue } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
import type { OwnerId } from "@evolu/common/local-first";
import { ensureBridgeCompanyIdForLocalCompany } from "./bridgeCompanyEnsure";
import {
    allBankImportBatchesQuery,
    allBankTransactionMatchesQuery,
    allBankTransactionsQuery,
    allCompaniesDetailQuery,
    allCompanySharesQuery,
    allCompanyStockBalancesQuery,
    allCompanyStockItemsQuery,
    allCompanyStockMovementsQuery,
    allCompanyWarehousesQuery,
    allContactsQuery,
    allDocumentEventsQuery,
    allDocumentLinesQuery,
    allDocumentSnapshotsQuery,
    allDocumentsQuery,
    allExpenseAttachmentsQuery,
    allExpensesQuery,
    allInvoiceTemplatesQuery,
    allNumberSeriesQuery,
    allRecurringProfileLinesQuery,
    allRecurringProfilesQuery,
} from "./client";
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
} from "./sharedOwner";

/**
 * Converts a private company into a shared one (docs/COMPANY_SHARING.md, C3):
 * every row of the company is re-written under a fresh SharedOwner with the
 * SAME id, then the AppOwner originals are soft-deleted. Verified claims from
 * the C0 spike: rows are keyed by (ownerId, id), so this is a copy - not a
 * move - and the soft-deleted original disappears from every list query.
 *
 * Crash-safe by construction: the `companyShare` row is written with status
 * "migrating" BEFORE anything is copied and flipped to "active" only after
 * verification, so a reload resumes (rows already in the shared partition are
 * skipped, upsert is idempotent, soft-delete is idempotent). Originals are
 * never hard-deleted.
 */

export type MigrationTable =
    | "company"
    | "contact"
    | "numberSeries"
    | "document"
    | "documentLine"
    | "documentEvent"
    | "documentSnapshot"
    | "invoiceTemplate"
    | "expense"
    | "expenseAttachment"
    | "recurringProfile"
    | "recurringProfileLine"
    | "companyWarehouse"
    | "companyStockItem"
    | "companyStockBalance"
    | "companyStockMovement"
    | "bankImportBatch"
    | "bankTransaction"
    | "bankTransactionMatch";

/** Parents before children - the scoping index and relay readers rely on it. */
export const MIGRATION_ORDER: MigrationTable[] = [
    "company",
    "contact",
    "numberSeries",
    "invoiceTemplate",
    "companyWarehouse",
    "companyStockItem",
    "companyStockBalance",
    "companyStockMovement",
    "document",
    "documentLine",
    "documentEvent",
    "documentSnapshot",
    "expense",
    "expenseAttachment",
    "recurringProfile",
    "recurringProfileLine",
    "bankImportBatch",
    "bankTransaction",
    "bankTransactionMatch",
];

type Row = Record<string, unknown> & { id: string; ownerId?: string | null; isDeleted?: unknown };

export type CompanyRowSet = Record<MigrationTable, Row[]>;

/** Everything the migration must not copy: Evolu system columns. */
const STRIP_KEYS = new Set(["createdAt", "updatedAt", "isDeleted", "ownerId"]);

export function rowForSharedUpsert(row: Row): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(row)) {
        if (!STRIP_KEYS.has(key)) {
            out[key] = value;
        }
    }
    return out;
}

/**
 * Selects every live row that belongs to the company, table by table, from
 * the full query results (pure - the orchestrator loads the queries).
 */
export function collectCompanyRows(all: Record<MigrationTable, readonly Row[]>, companyId: string): CompanyRowSet {
    const own = <T extends Row>(rows: readonly T[]) => rows.filter((r) => r.companyId === companyId && r.isDeleted !== 1);
    const company = all.company.filter((r) => r.id === companyId && r.isDeleted !== 1);
    const documents = own(all.document);
    const documentIds = new Set(documents.map((r) => r.id));
    const expenses = own(all.expense);
    const expenseIds = new Set(expenses.map((r) => r.id));
    const profiles = own(all.recurringProfile);
    const profileIds = new Set(profiles.map((r) => r.id));
    const transactions = own(all.bankTransaction);
    const transactionIds = new Set(transactions.map((r) => r.id));
    const byParent = <T extends Row>(rows: readonly T[], key: string, ids: Set<string>) =>
        rows.filter((r) => ids.has(String(r[key])) && r.isDeleted !== 1);

    return {
        company,
        contact: own(all.contact),
        numberSeries: own(all.numberSeries),
        invoiceTemplate: own(all.invoiceTemplate),
        companyWarehouse: own(all.companyWarehouse),
        companyStockItem: own(all.companyStockItem),
        companyStockBalance: own(all.companyStockBalance),
        companyStockMovement: own(all.companyStockMovement),
        document: documents,
        documentLine: byParent(all.documentLine, "documentId", documentIds),
        documentEvent: byParent(all.documentEvent, "documentId", documentIds),
        documentSnapshot: byParent(all.documentSnapshot, "documentId", documentIds),
        expense: expenses,
        expenseAttachment: byParent(all.expenseAttachment, "expenseId", expenseIds),
        recurringProfile: profiles,
        recurringProfileLine: byParent(all.recurringProfileLine, "recurringProfileId", profileIds),
        bankImportBatch: own(all.bankImportBatch),
        bankTransaction: transactions,
        bankTransactionMatch: all.bankTransactionMatch.filter(
            (r) =>
                r.isDeleted !== 1
                && (transactionIds.has(String(r.bankTransactionId)) || documentIds.has(String(r.businessDocumentId))),
        ),
    };
}

/** Ids per table that still lack a copy in the shared partition. */
export function pendingCopies(set: CompanyRowSet, appOwnerId: string, sharedOwnerId: string): Record<MigrationTable, Row[]> {
    const out = {} as Record<MigrationTable, Row[]>;
    for (const table of MIGRATION_ORDER) {
        const rows = set[table];
        const sharedIds = new Set(rows.filter((r) => r.ownerId === sharedOwnerId).map((r) => r.id));
        out[table] = rows.filter((r) => r.ownerId === appOwnerId && !sharedIds.has(r.id));
    }
    return out;
}

/** Every source id must have a shared copy; reports what is missing. */
export function verifyMigrated(set: CompanyRowSet, appOwnerId: string, sharedOwnerId: string): { ok: boolean; missing: Record<string, string[]> } {
    const missing: Record<string, string[]> = {};
    for (const table of MIGRATION_ORDER) {
        const sharedIds = new Set(set[table].filter((r) => r.ownerId === sharedOwnerId).map((r) => r.id));
        const gaps = set[table].filter((r) => r.ownerId === appOwnerId && !sharedIds.has(r.id)).map((r) => r.id);
        if (gaps.length) missing[table] = gaps;
    }
    return { ok: Object.keys(missing).length === 0, missing };
}

export type ShareMigrationProgress = { phase: "prepare" | "copy" | "verify" | "cleanup" | "done"; table?: MigrationTable; done: number; total: number };

export type ShareMigrationResult =
    | { ok: true; ownerId: OwnerId; copied: number; softDeleted: number; resumed: boolean }
    | { ok: false; error: "company_not_found" | "bridge_unavailable" | "already_shared" | "orphaned_shared_copy" | "migrating_elsewhere" | "share_row_failed" | "copy_failed" | "verify_failed"; detail?: unknown };

const SHARE_ROW_SETTLE_MS = 3_000;
const SYNC_WAIT_MS = 30_000;
const VERIFY_ATTEMPTS = 5;
const VERIFY_RETRY_MS = 750;

type Loader = Evolu<InvoicingLocalSchema>;

/** Live-row query per table (owner-agnostic - the proxy adds ownerId to results). */
export const MIGRATION_TABLE_QUERIES: Record<MigrationTable, unknown> = {
    company: allCompaniesDetailQuery,
    contact: allContactsQuery,
    numberSeries: allNumberSeriesQuery,
    invoiceTemplate: allInvoiceTemplatesQuery,
    companyWarehouse: allCompanyWarehousesQuery,
    companyStockItem: allCompanyStockItemsQuery,
    companyStockBalance: allCompanyStockBalancesQuery,
    companyStockMovement: allCompanyStockMovementsQuery,
    document: allDocumentsQuery,
    documentLine: allDocumentLinesQuery,
    documentEvent: allDocumentEventsQuery,
    documentSnapshot: allDocumentSnapshotsQuery,
    expense: allExpensesQuery,
    expenseAttachment: allExpenseAttachmentsQuery,
    recurringProfile: allRecurringProfilesQuery,
    recurringProfileLine: allRecurringProfileLinesQuery,
    bankImportBatch: allBankImportBatchesQuery,
    bankTransaction: allBankTransactionsQuery,
    bankTransactionMatch: allBankTransactionMatchesQuery,
};

async function loadAll(evolu: Loader): Promise<Record<MigrationTable, readonly Row[]>> {
    const [
        company, contact, numberSeries, invoiceTemplate, companyWarehouse, companyStockItem, companyStockBalance,
        companyStockMovement, document, documentLine, documentEvent, documentSnapshot, expense, expenseAttachment,
        recurringProfile, recurringProfileLine, bankImportBatch, bankTransaction, bankTransactionMatch,
    ] = await Promise.all([
        evolu.loadQuery(allCompaniesDetailQuery),
        evolu.loadQuery(allContactsQuery),
        evolu.loadQuery(allNumberSeriesQuery),
        evolu.loadQuery(allInvoiceTemplatesQuery),
        evolu.loadQuery(allCompanyWarehousesQuery),
        evolu.loadQuery(allCompanyStockItemsQuery),
        evolu.loadQuery(allCompanyStockBalancesQuery),
        evolu.loadQuery(allCompanyStockMovementsQuery),
        evolu.loadQuery(allDocumentsQuery),
        evolu.loadQuery(allDocumentLinesQuery),
        evolu.loadQuery(allDocumentEventsQuery),
        evolu.loadQuery(allDocumentSnapshotsQuery),
        evolu.loadQuery(allExpensesQuery),
        evolu.loadQuery(allExpenseAttachmentsQuery),
        evolu.loadQuery(allRecurringProfilesQuery),
        evolu.loadQuery(allRecurringProfileLinesQuery),
        evolu.loadQuery(allBankImportBatchesQuery),
        evolu.loadQuery(allBankTransactionsQuery),
        evolu.loadQuery(allBankTransactionMatchesQuery),
    ]);
    return {
        company, contact, numberSeries, invoiceTemplate, companyWarehouse, companyStockItem, companyStockBalance,
        companyStockMovement, document, documentLine, documentEvent, documentSnapshot, expense, expenseAttachment,
        recurringProfile, recurringProfileLine, bankImportBatch, bankTransaction, bankTransactionMatch,
    } as unknown as Record<MigrationTable, readonly Row[]>;
}

/**
 * Soft-deletes leftover copies of a company under owners whose share was
 * REVOKED. A device that processed the revoke stops subscribing to that
 * owner and never receives the cleanup soft-deletes, so the dead copy lingers
 * as a duplicate; this reconciles it locally (and re-broadcasts the deletes).
 * Cheap no-op when there is nothing to purge.
 */
export async function purgeRevokedShareResidue(evolu: Loader, companyId?: string): Promise<number> {
    const appOwnerId = (await evolu.appOwner).id;
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly {
        ownerId: string | null; companyId: string; sharedOwnerId: string; status: string;
    }[];
    const revokedOwners = new Set(
        shares
            .filter((row) => row.ownerId === appOwnerId && row.status === "revoked" && (!companyId || row.companyId === companyId))
            .map((row) => row.sharedOwnerId),
    );
    if (revokedOwners.size === 0) {
        return 0;
    }

    const companies = (await evolu.loadQuery(allCompaniesDetailQuery)) as unknown as readonly Row[];
    const hasLiveResidue = companies.some((row) => row.ownerId != null && revokedOwners.has(row.ownerId) && row.isDeleted !== 1);
    if (!hasLiveResidue) {
        return 0;
    }

    let purged = 0;
    for (const table of MIGRATION_ORDER) {
        const rows = (await evolu.loadQuery(MIGRATION_TABLE_QUERIES[table] as never)) as unknown as readonly Row[];
        for (const row of rows) {
            if (row.ownerId != null && revokedOwners.has(row.ownerId) && row.isDeleted !== 1) {
                const result = scopedEvolu(evolu, row.ownerId as never).update(table, { id: row.id, isDeleted: sqliteTrue } as never);
                if (result.ok) purged++;
            }
        }
    }
    return purged;
}

function mutateAwait(run: (onComplete: () => void) => { ok: boolean; error?: unknown }): Promise<{ ok: boolean; error?: unknown }> {
    return new Promise((resolve) => {
        const result = run(() => resolve({ ok: true }));
        if (!result.ok) resolve({ ok: false, error: result.error });
    });
}

export type ConvertOptions = {
    onProgress?: (progress: ShareMigrationProgress) => void;
    /** Take over a conversion started on another device (only after that device is known to be gone). */
    force?: boolean;
    /** Skip the pre-flight relay sync wait (tests / callers that already synced). */
    skipSyncWait?: boolean;
};

/**
 * Convert (or resume converting) the company. Idempotent: a company that is
 * already active returns `already_shared`; a `migrating` row resumes.
 */
export async function convertCompanyToShared(
    evolu: Loader,
    companyId: string,
    options: ConvertOptions = {},
): Promise<ShareMigrationResult> {
    const report = (progress: ShareMigrationProgress) => options.onProgress?.(progress);
    report({ phase: "prepare", done: 0, total: 0 });

    const appOwnerId = (await evolu.appOwner).id;

    // Adopting an existing share (from a prior attempt or another device)
    // instead of minting a second SharedOwner requires that the relay has
    // delivered its companyShare row and company copy first. Best-effort and
    // bounded; skipped in tests.
    if (!options.skipSyncWait) {
        await waitForInvoicingRelaySync(evolu, { timeoutMs: SYNC_WAIT_MS }).catch(() => undefined);
    }

    // Clear dead copies from previously revoked shares before deciding, so
    // they neither block the conversion nor survive as duplicates.
    await purgeRevokedShareResidue(evolu, companyId);

    const companies = (await evolu.loadQuery(allCompaniesDetailQuery)) as unknown as readonly Row[];
    const companyRows = companies.filter((row) => row.id === companyId);
    if (companyRows.length === 0) {
        return { ok: false, error: "company_not_found" };
    }

    // Existing share row - only rows in the user's own partition count.
    // Canonical (lowest sharedOwnerId) so a broken double-share converges.
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly {
        id: string; ownerId: string | null; companyId: string; sharedOwnerId: string; secretB64: string; status: string; bridgeCompanyId: string | null; migratingDeviceId: string | null;
    }[];
    const existing = shares
        .filter((row) => row.companyId === companyId && row.ownerId === appOwnerId && row.status !== "revoked")
        .sort((a, b) => (a.sharedOwnerId < b.sharedOwnerId ? -1 : 1))[0];

    if (existing?.status === "active") {
        return { ok: false, error: "already_shared" };
    }

    // A shared copy of this company already exists but no adoptable share row
    // does (a previous conversion left an orphaned partition, or a member's
    // copy is present): minting a fresh owner would DUPLICATE the company, so
    // refuse and let the cleanup tooling reconcile it.
    if (!existing && companyRows.some((row) => row.ownerId !== appOwnerId && row.isDeleted !== 1)) {
        return {
            ok: false,
            error: "orphaned_shared_copy",
            detail: companyRows.filter((row) => row.ownerId !== appOwnerId).map((row) => row.ownerId),
        };
    }

    // Another device started this conversion: it owns the resume. This device
    // may still be catching up through the relay and must not verify or
    // soft-delete against a partial copy.
    if (existing && existing.migratingDeviceId && existing.migratingDeviceId !== localDeviceId() && !options.force) {
        return { ok: false, error: "migrating_elsewhere" };
    }

    const bridge = await ensureBridgeCompanyIdForLocalCompany(companyId);
    const bridgeCompanyId = bridge.ok ? bridge.bridgeCompanyId : null;
    if (!bridgeCompanyId) {
        return { ok: false, error: "bridge_unavailable" };
    }

    let shareRowId: CompanyShareId;
    let ownerSecret = existing ? decodeOwnerSecret(existing.secretB64) : null;
    if (existing && ownerSecret) {
        shareRowId = existing.id as CompanyShareId;
    } else {
        ownerSecret = createCompanyShareSecret();
        const owner = sharedOwnerFromSecret(ownerSecret);
        const inserted = await mutateAwait((onComplete) =>
            evolu.insert(
                "companyShare",
                {
                    companyId: companyId as CompanyId,
                    sharedOwnerId: owner.id,
                    secretB64: encodeOwnerSecret(ownerSecret!),
                    role: "owner",
                    status: "migrating",
                    bridgeCompanyId,
                    migratingDeviceId: localDeviceId(),
                } as never,
                { onComplete },
            ),
        );
        if (!inserted.ok) {
            return { ok: false, error: "share_row_failed", detail: inserted.error };
        }
        const after = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly { id: string; companyId: string; sharedOwnerId: string }[];
        const mine = after.find((row) => row.companyId === companyId && row.sharedOwnerId === owner.id);
        if (!mine) {
            return { ok: false, error: "share_row_failed" };
        }
        shareRowId = mine.id as CompanyShareId;
    }

    const owner = sharedOwnerFromSecret(ownerSecret);
    registerSharedOwner(evolu, owner);
    setCompanyShare({ companyId, ownerId: owner.id, role: "owner", status: "migrating", bridgeCompanyId });

    // The share row carries the only copy of the secret: give the relay a
    // moment to take it before the long copy phase, so a tab closed mid-way
    // still leaves the (resumable) share on the user's other devices. Evolu
    // exposes no relay ack, so this is a settle wait, not a guarantee.
    await waitForInvoicingDataSettled(evolu, { minWaitMs: SHARE_ROW_SETTLE_MS, timeoutMs: SHARE_ROW_SETTLE_MS + 3_000 }).catch(() => undefined);
    const shared = scopedEvolu(evolu, owner.id);
    const appScoped = scopedEvolu(evolu, appOwnerId);

    // --- copy ---------------------------------------------------------
    let set = collectCompanyRows(await loadAll(evolu), companyId);
    const pending = pendingCopies(set, appOwnerId, owner.id);
    const total = MIGRATION_ORDER.reduce((n, table) => n + pending[table].length, 0);
    let done = 0;
    for (const table of MIGRATION_ORDER) {
        for (const row of pending[table]) {
            report({ phase: "copy", table, done, total });
            const result = await mutateAwait((onComplete) => shared.upsert(table, rowForSharedUpsert(row) as never, { onComplete }));
            if (!result.ok) {
                return { ok: false, error: "copy_failed", detail: { table, id: row.id, error: result.error } };
            }
            done++;
        }
    }

    // --- verify -------------------------------------------------------
    // The last onComplete callbacks can fire a beat before the query layer
    // sees the rows, so verification re-reads (and re-copies any real gaps)
    // a few times before giving up.
    report({ phase: "verify", done, total });
    let verification = { ok: false, missing: {} as Record<string, string[]> };
    for (let attempt = 0; attempt < VERIFY_ATTEMPTS; attempt++) {
        if (attempt > 0) {
            await new Promise((resolve) => setTimeout(resolve, VERIFY_RETRY_MS));
        }
        set = collectCompanyRows(await loadAll(evolu), companyId);
        verification = verifyMigrated(set, appOwnerId, owner.id);
        if (verification.ok) break;
        const retry = pendingCopies(set, appOwnerId, owner.id);
        for (const table of MIGRATION_ORDER) {
            for (const row of retry[table]) {
                const result = await mutateAwait((onComplete) => shared.upsert(table, rowForSharedUpsert(row) as never, { onComplete }));
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

    // --- cleanup: soft-delete the AppOwner originals ------------------
    report({ phase: "cleanup", done, total });
    let softDeleted = 0;
    for (const table of MIGRATION_ORDER) {
        for (const row of set[table]) {
            if (row.ownerId !== appOwnerId) continue;
            const result = await mutateAwait((onComplete) =>
                appScoped.update(table, { id: row.id, isDeleted: sqliteTrue } as never, { onComplete }),
            );
            if (result.ok) softDeleted++;
        }
    }

    // Let the copied rows drain towards the relay before the share is
    // announced as active (members joining meanwhile would see a partial set).
    await waitForInvoicingDataSettled(evolu, { minWaitMs: SHARE_ROW_SETTLE_MS, timeoutMs: 20_000 }).catch(() => undefined);
    await mutateAwait((onComplete) => evolu.update("companyShare", { id: shareRowId, status: "active" } as never, { onComplete }));
    setCompanyShare({ companyId, ownerId: owner.id, role: "owner", status: "active", bridgeCompanyId });
    report({ phase: "done", done, total });

    return { ok: true, ownerId: owner.id, copied: done, softDeleted, resumed: existing !== undefined };
}

/** Boot hook: finish conversions a reload interrupted. */
export async function resumePendingCompanyShareMigrations(evolu: Loader): Promise<void> {
    const appOwnerId = (await evolu.appOwner).id;
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly { ownerId: string | null; companyId: string; status: string; migratingDeviceId: string | null }[];
    const device = localDeviceId();
    for (const row of shares) {
        if (row.ownerId !== appOwnerId || row.status !== "migrating") continue;
        if (row.migratingDeviceId && row.migratingDeviceId !== device) continue;
        if (companyShareInfo(row.companyId)?.status === "active") continue;
        const result = await convertCompanyToShared(evolu, row.companyId);
        if (!result.ok && import.meta.env.DEV) {
            console.warn("[company-share] resume failed", row.companyId, result.error, result.detail);
        }
    }
}
