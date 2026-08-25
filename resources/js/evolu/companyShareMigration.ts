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
import type { CompanyId, CompanyShareId, InvoicingLocalSchema } from "./schema";
import {
    createCompanyShareSecret,
    decodeOwnerSecret,
    encodeOwnerSecret,
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
    | { ok: false; error: "company_not_found" | "bridge_unavailable" | "already_shared" | "share_row_failed" | "copy_failed" | "verify_failed"; detail?: unknown };

type Loader = Evolu<InvoicingLocalSchema>;

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

function mutateAwait(run: (onComplete: () => void) => { ok: boolean; error?: unknown }): Promise<{ ok: boolean; error?: unknown }> {
    return new Promise((resolve) => {
        const result = run(() => resolve({ ok: true }));
        if (!result.ok) resolve({ ok: false, error: result.error });
    });
}

export type ConvertOptions = {
    onProgress?: (progress: ShareMigrationProgress) => void;
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
    const companies = await evolu.loadQuery(allCompaniesDetailQuery);
    if (!companies.some((row) => row.id === companyId)) {
        return { ok: false, error: "company_not_found" };
    }

    // Existing share row (resume) - only rows in the user's own partition count.
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly {
        id: string; ownerId: string | null; companyId: string; sharedOwnerId: string; secretB64: string; status: string; bridgeCompanyId: string | null;
    }[];
    const existing = shares.find((row) => row.companyId === companyId && row.ownerId === appOwnerId && row.status !== "revoked");
    if (existing?.status === "active") {
        return { ok: false, error: "already_shared" };
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
    report({ phase: "verify", done, total });
    set = collectCompanyRows(await loadAll(evolu), companyId);
    const verification = verifyMigrated(set, appOwnerId, owner.id);
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

    await mutateAwait((onComplete) => evolu.update("companyShare", { id: shareRowId, status: "active" } as never, { onComplete }));
    setCompanyShare({ companyId, ownerId: owner.id, role: "owner", status: "active", bridgeCompanyId });
    report({ phase: "done", done, total });

    return { ok: true, ownerId: owner.id, copied: done, softDeleted, resumed: existing !== undefined };
}

/** Boot hook: finish conversions a reload interrupted. */
export async function resumePendingCompanyShareMigrations(evolu: Loader): Promise<void> {
    const appOwnerId = (await evolu.appOwner).id;
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly { ownerId: string | null; companyId: string; status: string }[];
    for (const row of shares) {
        if (row.ownerId !== appOwnerId || row.status !== "migrating") continue;
        if (companyShareInfo(row.companyId)?.status === "active") continue;
        const result = await convertCompanyToShared(evolu, row.companyId);
        if (!result.ok && import.meta.env.DEV) {
            console.warn("[company-share] resume failed", row.companyId, result.error, result.detail);
        }
    }
}
