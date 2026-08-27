import { afterEach, describe, expect, it, vi } from 'vitest';

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
vi.mock('@/evolu/relaySyncWait', () => ({ waitForInvoicingDataSettled: vi.fn(async () => undefined), waitForInvoicingRelaySync: vi.fn(async () => true) }));

import type { Evolu } from '@evolu/common/local-first';
import { MIGRATION_ORDER, type MigrationTable } from '@/evolu/companyShareMigration';
import { rekeyCompanyShare, resumePendingCompanyShareRekeys } from '@/evolu/companyShareRekey';
import { clearCompanyShares, companyShareInfo, setCompanyShare } from '@/evolu/companyShareRegistry';
import { clearRowOwnerIndex } from '@/evolu/ownerScope';
import { waitForInvoicingDataSettled } from '@/evolu/relaySyncWait';
import { createCompanyShareSecret, encodeOwnerSecret, sharedOwnerFromSecret } from '@/evolu/sharedOwner';
import type { InvoicingLocalSchema } from '@/evolu/schema';

const APP = 'app-owner';
const waitForInvoicingDataSettledMock = vi.mocked(waitForInvoicingDataSettled);
type Row = Record<string, unknown> & { id: string; ownerId?: string | null; isDeleted?: unknown };
type FakeEvoluOptions = {
    failUpdate?: (table: string, props: Row, options?: { ownerId?: string }) => boolean;
};

function emptyAll(): Record<MigrationTable, Row[]> {
    return Object.fromEntries(MIGRATION_ORDER.map((t) => [t, []])) as unknown as Record<MigrationTable, Row[]>;
}

function fakeEvolu(seed: Record<MigrationTable, Row[]>, options: FakeEvoluOptions = {}) {
    const tables = new Map<string, Map<string, Row>>();
    const fakeOptions = options;
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
            if (fakeOptions.failUpdate?.(table, props, options)) {
                return { ok: false, error: new Error('update failed') };
            }
            if (table === 'companyShare') {
                const row = shares.find((r) => r.id === props.id);
                if (row) Object.assign(row, props);
            } else {
                const row = tables.get(table)?.get(`${options?.ownerId ?? APP}|${props.id}`);
                if (row) Object.assign(row, props);
            }
            options?.onComplete?.();
            return { ok: true, value: { id: props.id } };
        }),
    };
    const seedShare = (share: Record<string, unknown>) => { const r = { id: `share-${++idSeq}`, ownerId: APP, ...share } as Row; shares.push(r); return r; };
    const seedRow = (table: string, row: Row) => put(table, row);
    return { evolu: evolu as unknown as Evolu<InvoicingLocalSchema>, tables, shares, seedShare, seedRow };
}

afterEach(() => {
    clearCompanyShares();
    clearRowOwnerIndex();
    waitForInvoicingDataSettledMock.mockReset();
    waitForInvoicingDataSettledMock.mockResolvedValue(undefined);
});

/** Seed an active shared company (owner O1) with a company row + one contact. */
function seedSharedCompany(h: ReturnType<typeof fakeEvolu>, oldOwner: string) {
    h.seedShare({ companyId: 'c1', sharedOwnerId: oldOwner, secretB64: 'old-secret', role: 'owner', status: 'active', bridgeCompanyId: 'bridge-1', migratingDeviceId: null });
    h.seedRow('company', { id: 'c1', ownerId: oldOwner, legalName: 'Acme' });
    h.seedRow('contact', { id: 'k1', ownerId: oldOwner, companyId: 'c1', name: 'Bob' });
    setCompanyShare({ companyId: 'c1', ownerId: oldOwner as never, role: 'owner', status: 'active', bridgeCompanyId: 'bridge-1' });
}

describe('company share rekey', () => {
    it('rotates the company onto a fresh owner, copying rows and retiring the old partition', async () => {
        const h = fakeEvolu(emptyAll());
        seedSharedCompany(h, 'old-owner');

        const result = await rekeyCompanyShare(h.evolu, 'c1', { skipSyncWait: true });
        expect(result.ok).toBe(true);
        if (!result.ok) return;
        expect(result.oldOwnerId).toBe('old-owner');
        expect(result.newOwnerId).not.toBe('old-owner');
        expect(result.copied).toBe(2);

        // New partition holds live copies; old partition is soft-deleted.
        expect(h.tables.get('company')?.get(`${result.newOwnerId}|c1`)?.isDeleted).not.toBe(1);
        expect(h.tables.get('contact')?.get(`${result.newOwnerId}|k1`)).toBeTruthy();
        expect(h.tables.get('company')?.get('old-owner|c1')?.isDeleted).toBe(1);
        expect(h.tables.get('contact')?.get('old-owner|k1')?.isDeleted).toBe(1);

        // Share rows: old revoked, new active; registry points at the new owner.
        const active = h.shares.filter((r) => r.status === 'active');
        expect(active).toHaveLength(1);
        expect(active[0].sharedOwnerId).toBe(result.newOwnerId);
        expect(h.shares.find((r) => r.sharedOwnerId === 'old-owner')?.status).toBe('revoked');
        expect(companyShareInfo('c1')?.ownerId).toBe(result.newOwnerId);
        expect(companyShareInfo('c1')?.status).toBe('active');
    });

    it('leaves the old partition live when activation fails', async () => {
        const h = fakeEvolu(emptyAll(), {
            failUpdate: (table, props) => table === 'companyShare' && props.status === 'active',
        });
        seedSharedCompany(h, 'old-owner');

        const result = await rekeyCompanyShare(h.evolu, 'c1', { skipSyncWait: true });

        expect(result).toMatchObject({ ok: false, error: 'activation_failed' });
        expect(h.tables.get('company')?.get('old-owner|c1')?.isDeleted).not.toBe(1);
        expect(h.tables.get('contact')?.get('old-owner|k1')?.isDeleted).not.toBe(1);
        expect(h.shares.some((r) => r.status === 'rekeying')).toBe(true);
        expect(companyShareInfo('c1')?.ownerId).toBe('old-owner');
    });

    it('refreshes rows written during the final settle before retiring the old partition', async () => {
        const h = fakeEvolu(emptyAll());
        seedSharedCompany(h, 'old-owner');
        h.seedRow('contact', { id: 'k2', ownerId: 'old-owner', companyId: 'c1', name: 'Carol' });
        let settleCalls = 0;
        waitForInvoicingDataSettledMock.mockImplementation(async () => {
            settleCalls++;
            if (settleCalls === 2) {
                h.seedRow('contact', { id: 'k1', ownerId: 'old-owner', companyId: 'c1', name: 'Alice' });
                h.seedRow('contact', { id: 'k2', ownerId: 'old-owner', companyId: 'c1', name: 'Carol', isDeleted: 1 });
                h.seedRow('document', { id: 'd1', ownerId: 'old-owner', companyId: 'c1', documentType: 'invoice', status: 'draft', title: 'Late invoice' });
            }
        });

        const result = await rekeyCompanyShare(h.evolu, 'c1', { skipSyncWait: true });

        expect(result.ok).toBe(true);
        if (!result.ok) return;
        expect(h.tables.get('contact')?.get(`${result.newOwnerId}|k1`)?.name).toBe('Alice');
        expect(h.tables.get('contact')?.get(`${result.newOwnerId}|k2`)?.isDeleted).toBe(1);
        expect(h.tables.get('document')?.get(`${result.newOwnerId}|d1`)?.title).toBe('Late invoice');
        expect(h.tables.get('contact')?.get('old-owner|k1')?.isDeleted).toBe(1);
        expect(h.tables.get('document')?.get('old-owner|d1')?.isDeleted).toBe(1);
    });

    it('refuses a company that is not actively shared', async () => {
        const h = fakeEvolu(emptyAll());
        const result = await rekeyCompanyShare(h.evolu, 'c1', { skipSyncWait: true });
        expect(result).toEqual({ ok: false, error: 'not_shared' });
    });

    it('adopts and finishes an interrupted rekey instead of minting a second owner', async () => {
        const h = fakeEvolu(emptyAll());
        seedSharedCompany(h, 'old-owner');
        const secret = createCompanyShareSecret();
        const newOwner = sharedOwnerFromSecret(secret);
        // An in-flight rekeying row from a previous attempt (this device).
        h.seedShare({ companyId: 'c1', sharedOwnerId: newOwner.id, secretB64: encodeOwnerSecret(secret), role: 'owner', status: 'rekeying', bridgeCompanyId: 'bridge-1', migratingDeviceId: null });
        h.seedRow('company', { id: 'c1', ownerId: newOwner.id, legalName: 'Acme' }); // partial prior copy

        const result = await rekeyCompanyShare(h.evolu, 'c1', { skipSyncWait: true });
        expect(result.ok).toBe(true);
        if (!result.ok) return;
        expect(result.resumed).toBe(true);
        expect(result.newOwnerId).toBe(newOwner.id);
        // No third owner minted: exactly one rekeying row existed and it is now active.
        expect(h.shares.filter((r) => r.status === 'active' && r.sharedOwnerId === newOwner.id)).toHaveLength(1);
        expect(companyShareInfo('c1')?.ownerId).toBe(newOwner.id);
    });

    it('refuses to take over a rekey another device started', async () => {
        const h = fakeEvolu(emptyAll());
        seedSharedCompany(h, 'old-owner');
        const secret = createCompanyShareSecret();
        const newOwner = sharedOwnerFromSecret(secret);
        h.seedShare({ companyId: 'c1', sharedOwnerId: newOwner.id, secretB64: encodeOwnerSecret(secret), role: 'owner', status: 'rekeying', bridgeCompanyId: 'bridge-1', migratingDeviceId: 'some-other-device' });

        const result = await rekeyCompanyShare(h.evolu, 'c1', { skipSyncWait: true });
        expect(result).toEqual({ ok: false, error: 'rekeying_elsewhere' });
    });

    it('resume hook completes an interrupted rekey', async () => {
        const h = fakeEvolu(emptyAll());
        seedSharedCompany(h, 'old-owner');
        const secret = createCompanyShareSecret();
        const newOwner = sharedOwnerFromSecret(secret);
        h.seedShare({ companyId: 'c1', sharedOwnerId: newOwner.id, secretB64: encodeOwnerSecret(secret), role: 'owner', status: 'rekeying', bridgeCompanyId: 'bridge-1', migratingDeviceId: null });

        await resumePendingCompanyShareRekeys(h.evolu);
        expect(companyShareInfo('c1')?.ownerId).toBe(newOwner.id);
        expect(h.shares.find((r) => r.sharedOwnerId === 'old-owner')?.status).toBe('revoked');
    });

    it('resume hook finishes a dual-active cutover without minting another owner', async () => {
        const h = fakeEvolu(emptyAll());
        seedSharedCompany(h, 'old-owner');
        const secret = createCompanyShareSecret();
        const newOwner = sharedOwnerFromSecret(secret);
        h.seedShare({ companyId: 'c1', sharedOwnerId: newOwner.id, secretB64: encodeOwnerSecret(secret), role: 'owner', status: 'active', bridgeCompanyId: 'bridge-1', migratingDeviceId: null });
        h.seedRow('company', { id: 'c1', ownerId: newOwner.id, legalName: 'Acme' });
        h.seedRow('contact', { id: 'k1', ownerId: newOwner.id, companyId: 'c1', name: 'Bob' });
        h.seedRow('document', { id: 'd1', ownerId: 'old-owner', companyId: 'c1', documentType: 'invoice', status: 'draft', title: 'Old partition invoice' });

        await resumePendingCompanyShareRekeys(h.evolu);

        expect(h.shares).toHaveLength(2);
        expect(h.shares.filter((r) => r.status === 'active')).toHaveLength(1);
        expect(h.shares.find((r) => r.sharedOwnerId === newOwner.id)?.status).toBe('active');
        expect(h.shares.find((r) => r.sharedOwnerId === 'old-owner')?.status).toBe('revoked');
        expect(h.tables.get('document')?.get(`${newOwner.id}|d1`)?.title).toBe('Old partition invoice');
        expect(h.tables.get('company')?.get('old-owner|c1')?.isDeleted).toBe(1);
        expect(h.tables.get('contact')?.get('old-owner|k1')?.isDeleted).toBe(1);
        expect(h.tables.get('document')?.get('old-owner|d1')?.isDeleted).toBe(1);
        expect(companyShareInfo('c1')?.ownerId).toBe(newOwner.id);
    });
});
