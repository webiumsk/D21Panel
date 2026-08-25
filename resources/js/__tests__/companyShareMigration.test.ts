import { afterEach, describe, expect, it, vi } from 'vitest';

// Query tokens: the migration only uses them as lookup keys on the fake instance.
const Q = vi.hoisted(() => {
    const token = (name: string) => ({ name });
    return {
        allCompaniesDetailQuery: token('company'),
        allContactsQuery: token('contact'),
        allNumberSeriesQuery: token('numberSeries'),
        allInvoiceTemplatesQuery: token('invoiceTemplate'),
        allCompanyWarehousesQuery: token('companyWarehouse'),
        allCompanyStockItemsQuery: token('companyStockItem'),
        allCompanyStockBalancesQuery: token('companyStockBalance'),
        allCompanyStockMovementsQuery: token('companyStockMovement'),
        allDocumentsQuery: token('document'),
        allDocumentLinesQuery: token('documentLine'),
        allDocumentEventsQuery: token('documentEvent'),
        allDocumentSnapshotsQuery: token('documentSnapshot'),
        allExpensesQuery: token('expense'),
        allExpenseAttachmentsQuery: token('expenseAttachment'),
        allRecurringProfilesQuery: token('recurringProfile'),
        allRecurringProfileLinesQuery: token('recurringProfileLine'),
        allBankImportBatchesQuery: token('bankImportBatch'),
        allBankTransactionsQuery: token('bankTransaction'),
        allBankTransactionMatchesQuery: token('bankTransactionMatch'),
        allCompanySharesQuery: token('companyShare'),
    };
});
vi.mock('@/evolu/client', () => Q);
vi.mock('@/services/evoluRelayPreference', () => ({ getResolvedEvoluRelayUrl: () => '' }));
const bridge = vi.hoisted(() => ({
    ensure: vi.fn<() => Promise<{ ok: boolean; bridgeCompanyId?: string | null }>>(async () => ({ ok: true, bridgeCompanyId: 'bridge-1' })),
}));
vi.mock('@/evolu/bridgeCompanyEnsure', () => ({ ensureBridgeCompanyIdForLocalCompany: bridge.ensure }));
vi.mock('@/evolu/relaySyncWait', () => ({ waitForInvoicingDataSettled: vi.fn(async () => undefined), waitForInvoicingRelaySync: vi.fn(async () => true) }));

import type { Evolu } from '@evolu/common/local-first';
import {
    collectCompanyRows,
    convertCompanyToShared,
    MIGRATION_ORDER,
    pendingCopies,
    purgeRevokedShareResidue,
    rowForSharedUpsert,
    verifyMigrated,
    type MigrationTable,
} from '@/evolu/companyShareMigration';
import { clearCompanyShares, companyShareInfo } from '@/evolu/companyShareRegistry';
import { clearRowOwnerIndex } from '@/evolu/ownerScope';
import type { InvoicingLocalSchema } from '@/evolu/schema';

const APP = 'app-owner';
type Row = Record<string, unknown> & { id: string; ownerId?: string | null; isDeleted?: unknown };

function emptyAll(): Record<MigrationTable, Row[]> {
    return Object.fromEntries(MIGRATION_ORDER.map((t) => [t, []])) as unknown as Record<MigrationTable, Row[]>;
}

/**
 * In-memory Evolu double: rows live in a per-table map keyed by
 * (ownerId, id); upsert writes under options.ownerId (or APP), update
 * patches the row of that partition. Enough to run the orchestrator.
 */
function fakeEvolu(seed: Record<MigrationTable, Row[]>) {
    const tables = new Map<string, Map<string, Row>>();
    const put = (table: string, row: Row) => {
        if (!tables.has(table)) tables.set(table, new Map());
        tables.get(table)!.set(`${row.ownerId}|${row.id}`, row);
    };
    for (const [table, rows] of Object.entries(seed)) for (const row of rows) put(table, { ...row });
    const shares: Row[] = [];
    let idSeq = 0;
    const evolu = {
        appOwner: Promise.resolve({ id: APP }),
        useOwner: vi.fn(() => vi.fn()),
        loadQuery: vi.fn(async (query: { name: string }) => {
            if (query.name === 'companyShare') return shares.filter((r) => r.isDeleted !== 1);
            return [...(tables.get(query.name)?.values() ?? [])];
        }),
        insert: vi.fn((table: string, props: Row, options?: { onComplete?: () => void }) => {
            const row = { ...props, id: `share-${++idSeq}`, ownerId: APP };
            if (table === 'companyShare') shares.push(row);
            else put(table, row);
            options?.onComplete?.();
            return { ok: true, value: { id: row.id } };
        }),
        upsert: vi.fn((table: string, props: Row, options?: { ownerId?: string; onComplete?: () => void }) => {
            put(table, { ...props, ownerId: options?.ownerId ?? APP });
            options?.onComplete?.();
            return { ok: true, value: { id: props.id } };
        }),
        update: vi.fn((table: string, props: Row, options?: { ownerId?: string; onComplete?: () => void }) => {
            if (table === 'companyShare') {
                const row = shares.find((r) => r.id === props.id);
                if (row) Object.assign(row, props);
            } else {
                const key = `${options?.ownerId ?? APP}|${props.id}`;
                const row = tables.get(table)?.get(key);
                if (row) Object.assign(row, props);
            }
            options?.onComplete?.();
            return { ok: true, value: { id: props.id } };
        }),
    };
    const seedShare = (share: Record<string, unknown>) => shares.push({ id: `share-${++idSeq}`, ownerId: APP, ...share } as Row);
    const seedRow = (table: string, row: Row) => put(table, row);
    return { evolu: evolu as unknown as Evolu<InvoicingLocalSchema>, tables, shares, mocks: evolu, seedShare, seedRow };
}

afterEach(() => {
    clearCompanyShares();
    clearRowOwnerIndex();
    bridge.ensure.mockClear();
});

describe('company share migration - pure helpers', () => {
    it('strips system columns for the shared upsert', () => {
        expect(rowForSharedUpsert({ id: 'a', ownerId: APP, createdAt: 'x', updatedAt: 'y', isDeleted: null, name: 'n' })).toEqual({ id: 'a', name: 'n' });
    });

    it('collects the company rows across tables incl. children and skips deleted / foreign rows', () => {
        const all = emptyAll();
        all.company = [{ id: 'c1', ownerId: APP }, { id: 'c2', ownerId: APP }];
        all.document = [
            { id: 'd1', companyId: 'c1', ownerId: APP },
            { id: 'd2', companyId: 'c2', ownerId: APP },
            { id: 'd3', companyId: 'c1', ownerId: APP, isDeleted: 1 },
        ];
        all.documentLine = [
            { id: 'l1', documentId: 'd1', ownerId: APP },
            { id: 'l2', documentId: 'd2', ownerId: APP },
            { id: 'l3', documentId: 'd3', ownerId: APP },
        ];
        all.bankTransaction = [{ id: 't1', companyId: 'c1', ownerId: APP }];
        all.bankTransactionMatch = [
            { id: 'm1', bankTransactionId: 't1', businessDocumentId: 'x', ownerId: APP },
            { id: 'm2', bankTransactionId: 'other', businessDocumentId: 'd1', ownerId: APP },
            { id: 'm3', bankTransactionId: 'other', businessDocumentId: 'd2', ownerId: APP },
        ];

        const set = collectCompanyRows(all, 'c1');
        expect(set.company.map((r) => r.id)).toEqual(['c1']);
        expect(set.document.map((r) => r.id)).toEqual(['d1']);
        expect(set.documentLine.map((r) => r.id)).toEqual(['l1']);
        expect(set.bankTransactionMatch.map((r) => r.id)).toEqual(['m1', 'm2']);
    });

    it('reports pending copies and verifies completeness per partition', () => {
        const all = emptyAll();
        all.company = [{ id: 'c1', ownerId: APP }];
        all.document = [
            { id: 'd1', companyId: 'c1', ownerId: APP },
            { id: 'd1', companyId: 'c1', ownerId: 'shared' },
            { id: 'd2', companyId: 'c1', ownerId: APP },
        ];
        const set = collectCompanyRows(all, 'c1');

        const pending = pendingCopies(set, APP, 'shared');
        expect(pending.company.map((r) => r.id)).toEqual(['c1']);
        expect(pending.document.map((r) => r.id)).toEqual(['d2']);

        const before = verifyMigrated(set, APP, 'shared');
        expect(before.ok).toBe(false);
        expect(before.missing).toEqual({ company: ['c1'], document: ['d2'] });
    });
});

describe('convertCompanyToShared', () => {
    function seededCompany() {
        const seed = emptyAll();
        seed.company = [{ id: 'c1', ownerId: APP, legalName: 'Acme' }];
        seed.contact = [{ id: 'k1', companyId: 'c1', ownerId: APP, name: 'Buyer' }];
        seed.document = [{ id: 'd1', companyId: 'c1', ownerId: APP, number: '1' }];
        seed.documentLine = [{ id: 'l1', documentId: 'd1', ownerId: APP, name: 'Line' }];
        seed.expense = [{ id: 'e1', companyId: 'c1', ownerId: APP, internalNumber: 'EXP1' }];
        return seed;
    }

    it('copies every row under the shared owner, verifies, soft-deletes originals and activates the share', async () => {
        const { evolu, tables, shares, mocks } = fakeEvolu(seededCompany());

        const result = await convertCompanyToShared(evolu, 'c1');

        expect(result).toMatchObject({ ok: true, copied: 5, softDeleted: 5, resumed: false });
        if (!result.ok) return;
        const shared = result.ownerId;

        // Share row written first (migrating), flipped to active at the end.
        expect(shares).toHaveLength(1);
        expect(shares[0]).toMatchObject({ companyId: 'c1', sharedOwnerId: shared, status: 'active', bridgeCompanyId: 'bridge-1', role: 'owner' });
        expect(companyShareInfo('c1')?.status).toBe('active');
        expect(mocks.useOwner).toHaveBeenCalledTimes(1);

        // Every row exists under the shared owner with the same id; originals are soft-deleted, not removed.
        for (const [table, id] of [['company', 'c1'], ['contact', 'k1'], ['document', 'd1'], ['documentLine', 'l1'], ['expense', 'e1']] as const) {
            expect(tables.get(table)?.get(`${shared}|${id}`)).toBeDefined();
            expect(tables.get(table)?.get(`${shared}|${id}`)?.isDeleted).toBeUndefined();
            expect(tables.get(table)?.get(`${APP}|${id}`)?.isDeleted).toBe(1);
        }

        // Copies carry no system columns and the upsert was scoped explicitly.
        const upsertCall = mocks.upsert.mock.calls.find((c) => c[0] === 'document');
        expect(upsertCall?.[1]).toEqual({ id: 'd1', companyId: 'c1', number: '1' });
        expect(upsertCall?.[2]).toMatchObject({ ownerId: shared });

        // Order: parents before children.
        const order = mocks.upsert.mock.calls.map((c) => c[0]);
        expect(order.indexOf('document')).toBeLessThan(order.indexOf('documentLine'));
        expect(order.indexOf('company')).toBe(0);
    });

    it('resumes a migrating share without creating a second owner and skips already copied rows', async () => {
        const { evolu, shares, mocks } = fakeEvolu(seededCompany());
        const first = await convertCompanyToShared(evolu, 'c1');
        expect(first.ok).toBe(true);
        // Simulate a crash before activation: flip back to migrating.
        shares[0].status = 'migrating';
        clearCompanyShares();
        mocks.upsert.mockClear();

        const second = await convertCompanyToShared(evolu, 'c1');

        expect(second).toMatchObject({ ok: true, copied: 0, resumed: true });
        if (!second.ok || !first.ok) return;
        expect(second.ownerId).toBe(first.ownerId);
        expect(shares).toHaveLength(1);
        expect(shares[0].status).toBe('active');
        expect(mocks.upsert).not.toHaveBeenCalled();
    });

    it('purges dead copies left by a revoked share and no longer blocks conversion', async () => {
        const { evolu, tables, seedShare, seedRow } = fakeEvolu(seededCompany());
        // A previous share was revoked, but a live company copy lingers under it.
        seedShare({ companyId: 'c1', sharedOwnerId: 'ghost-owner', secretB64: 's', role: 'owner', status: 'revoked' });
        seedRow('company', { id: 'c1', ownerId: 'ghost-owner', legalName: 'Acme' });
        seedRow('document', { id: 'd1', companyId: 'c1', ownerId: 'ghost-owner' });

        const purged = await purgeRevokedShareResidue(evolu, 'c1');
        expect(purged).toBe(2);
        expect(tables.get('company')?.get('ghost-owner|c1')?.isDeleted).toBe(1);
        expect(tables.get('document')?.get('ghost-owner|d1')?.isDeleted).toBe(1);

        // With the residue gone the guard does not fire; conversion proceeds.
        const result = await convertCompanyToShared(evolu, 'c1');
        expect(result.ok).toBe(true);
    });

    it('refuses to mint a second owner when an orphaned shared copy exists', async () => {
        const seed = seededCompany();
        // A prior attempt left a shared copy of the company but no share row.
        seed.company.push({ id: 'c1', ownerId: 'orphan-owner', legalName: 'Acme' });
        const { evolu, mocks } = fakeEvolu(seed);

        const result = await convertCompanyToShared(evolu, 'c1');

        expect(result).toEqual({ ok: false, error: 'orphaned_shared_copy', detail: ['orphan-owner'] });
        expect(mocks.insert).not.toHaveBeenCalled();
    });

    it('refuses an already shared company and reports a missing bridge', async () => {
        const { evolu } = fakeEvolu(seededCompany());
        expect(await convertCompanyToShared(evolu, 'c1')).toMatchObject({ ok: true });
        expect(await convertCompanyToShared(evolu, 'c1')).toEqual({ ok: false, error: 'already_shared' });
        expect(await convertCompanyToShared(evolu, 'nope')).toEqual({ ok: false, error: 'company_not_found' });

        bridge.ensure.mockResolvedValueOnce({ ok: false });
        const { evolu: fresh } = fakeEvolu(seededCompany());
        expect(await convertCompanyToShared(fresh, 'c1')).toEqual({ ok: false, error: 'bridge_unavailable' });
    });
});
