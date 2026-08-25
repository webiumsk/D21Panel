import { sqliteFalse, sqliteTrue } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
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
import { removeCompanyShare } from "./companyShareRegistry";
import { collectCompanyRows, MIGRATION_ORDER, type MigrationTable } from "./companyShareMigration";
import { scopedEvolu } from "./ownerScope";
import { unregisterSharedOwner } from "./sharedOwner";
import type { InvoicingLocalSchema } from "./schema";
import type { InvoicingDataSnapshot } from "./invoicingSnapshot";
import { toAppRows } from "./queryLoad";

/**
 * DEV-ONLY recovery for a company left in a duplicated / half-shared state by
 * an interrupted C3 conversion (docs/COMPANY_SHARING.md). Returns the company
 * to PRIVATE (AppOwner) using a pre-conversion backup for the id set:
 *
 *   1. revoke every companyShare row of the company (scoping reverts to app),
 *   2. soft-delete every row under those shared owners (they hold only this
 *      company's duplicate copy),
 *   3. un-delete the AppOwner rows the conversion soft-deleted (ids from the
 *      backup snapshot - the authoritative pre-share set),
 *   4. unregister the owners.
 *
 * Not wired to any UI; used from the console spike during the runbook.
 */

type Row = Record<string, unknown> & { id: string; ownerId?: string | null; isDeleted?: unknown };

const TABLE_QUERIES: Record<MigrationTable, unknown> = {
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

export type RecoveryReport = {
    sharedOwners: string[];
    sharesRevoked: number;
    sharedSoftDeleted: number;
    appUndeleted: number;
};

export async function recoverCompanyToPrivate(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: string,
    backup: InvoicingDataSnapshot,
): Promise<RecoveryReport> {
    const appOwnerId = (await evolu.appOwner).id;

    // 1. Revoke every non-revoked share row of the company (kept in the app partition).
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly {
        id: string; ownerId: string | null; companyId: string; sharedOwnerId: string; status: string;
    }[];
    const sharedOwners = new Set<string>();
    let sharesRevoked = 0;
    for (const row of shares) {
        if (row.companyId !== companyId || row.status === "revoked") continue;
        sharedOwners.add(row.sharedOwnerId);
        const result = evolu.update("companyShare", { id: row.id as never, status: "revoked" } as never);
        if (result.ok) sharesRevoked++;
    }

    // 2. Soft-delete everything under those shared owners (owner-scoped so it
    //    targets the shared partition rows, not the app copies).
    let sharedSoftDeleted = 0;
    for (const table of MIGRATION_ORDER) {
        const rows = toAppRows<Row>(await evolu.loadQuery(TABLE_QUERIES[table] as never));
        for (const row of rows) {
            if (row.ownerId && sharedOwners.has(row.ownerId) && row.isDeleted !== 1) {
                const result = scopedEvolu(evolu, row.ownerId as never).update(table, { id: row.id, isDeleted: sqliteTrue } as never);
                if (result.ok) sharedSoftDeleted++;
            }
        }
    }

    // 3. Un-delete the AppOwner rows the conversion soft-deleted, keyed by the
    //    company's pre-share ids from the backup.
    const appScoped = scopedEvolu(evolu, appOwnerId as never);
    const set = collectCompanyRows(backup as unknown as Record<MigrationTable, readonly Row[]>, companyId);
    let appUndeleted = 0;
    for (const table of MIGRATION_ORDER) {
        for (const row of set[table]) {
            const result = appScoped.update(table, { id: row.id, isDeleted: sqliteFalse } as never);
            if (result.ok) appUndeleted++;
        }
    }

    // 4. Stop syncing / scoping to the removed owners.
    for (const owner of sharedOwners) {
        unregisterSharedOwner(evolu, owner as never);
    }
    removeCompanyShare(companyId);

    return { sharedOwners: [...sharedOwners], sharesRevoked, sharedSoftDeleted, appUndeleted };
}
