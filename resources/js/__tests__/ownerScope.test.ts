import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Evolu } from '@evolu/common/local-first';
import type { OwnerId } from '@evolu/common/local-first';
import {
    clearRowOwnerIndex,
    indexRowOwners,
    isSharedRow,
    knownRowOwner,
    resolveMutationOwner,
    withCompanyOwnerScoping,
} from '@/evolu/ownerScope';
import {
    clearCompanyShares,
    companyShareInfo,
    isRegisteredSharedOwnerId,
    ownerIdForCompany,
    removeCompanyShare,
    setCompanyShare,
} from '@/evolu/companyShareRegistry';
import type { InvoicingLocalSchema } from '@/evolu/schema';

const SHARED = 'shared-owner-1' as OwnerId;
const APP = 'app-owner' as OwnerId;

function share(companyId = 'company-shared') {
    setCompanyShare({ companyId, ownerId: SHARED, role: 'accountant', status: 'active', bridgeCompanyId: null });
}

function fakeRaw() {
    const calls: { method: string; args: unknown[] }[] = [];
    const record = (method: string) =>
        vi.fn((...args: unknown[]) => {
            calls.push({ method, args });
            const props = args[1] as { id?: string };
            return { ok: true, value: { id: props?.id ?? `new-${method}` } };
        });
    const raw = {
        insert: record('insert'),
        update: record('update'),
        upsert: record('upsert'),
        loadQuery: vi.fn(async () => [{ id: 'doc-1', ownerId: SHARED }, { id: 'doc-2', ownerId: APP }]),
        loadQueries: vi.fn(() => [Promise.resolve([{ id: 'doc-3', ownerId: SHARED }])]),
        getQueryRows: vi.fn(() => [{ id: 'doc-4', ownerId: SHARED }]),
        subscribeQuery: vi.fn(() => () => () => undefined),
        appOwner: Promise.resolve({ id: APP }),
    };
    return { raw: raw as unknown as Evolu<InvoicingLocalSchema>, calls, mocks: raw };
}

afterEach(() => {
    clearCompanyShares();
    clearRowOwnerIndex();
});

describe('company share registry', () => {
    it('maps companies to their shared owner and forgets revoked ones', () => {
        share();
        expect(ownerIdForCompany('company-shared')).toBe(SHARED);
        expect(ownerIdForCompany('company-private')).toBeUndefined();
        expect(isRegisteredSharedOwnerId(SHARED)).toBe(true);
        expect(companyShareInfo('company-shared')?.role).toBe('accountant');

        setCompanyShare({ companyId: 'company-shared', ownerId: SHARED, role: 'accountant', status: 'revoked', bridgeCompanyId: null });
        expect(ownerIdForCompany('company-shared')).toBeUndefined();

        removeCompanyShare('company-shared');
        expect(isRegisteredSharedOwnerId(SHARED)).toBe(false);
    });
});

describe('resolveMutationOwner', () => {
    it('prefers the explicit option, pins companyShare to the AppOwner, follows companyId', () => {
        share();
        expect(resolveMutationOwner('document', { companyId: 'company-shared' }, 'x' as OwnerId)).toBe('x');
        expect(resolveMutationOwner('companyShare', { companyId: 'company-shared' }, undefined)).toBeUndefined();
        expect(resolveMutationOwner('document', { companyId: 'company-shared' }, undefined)).toBe(SHARED);
        expect(resolveMutationOwner('document', { companyId: 'company-private' }, undefined)).toBeUndefined();
    });

    it('resolves updates by row id and child inserts by parent id from the index', () => {
        share();
        indexRowOwners([{ id: 'doc-1', ownerId: SHARED }, { id: 'doc-2', ownerId: APP }]);

        expect(resolveMutationOwner('document', { id: 'doc-1', status: 'paid' }, undefined)).toBe(SHARED);
        expect(resolveMutationOwner('document', { id: 'doc-2', status: 'paid' }, undefined)).toBeUndefined();
        expect(resolveMutationOwner('documentLine', { documentId: 'doc-1', name: 'x' }, undefined)).toBe(SHARED);
        expect(resolveMutationOwner('documentLine', { documentId: 'doc-2', name: 'x' }, undefined)).toBeUndefined();
        expect(resolveMutationOwner('documentLine', { documentId: 'unknown', name: 'x' }, undefined)).toBeUndefined();
    });

    it('keeps the shared copy when both partitions carry the same id', () => {
        share();
        indexRowOwners([{ id: 'dup', ownerId: SHARED }]);
        indexRowOwners([{ id: 'dup', ownerId: APP }]);
        expect(knownRowOwner('dup')).toBe(SHARED);

        clearRowOwnerIndex();
        indexRowOwners([{ id: 'dup', ownerId: APP }]);
        indexRowOwners([{ id: 'dup', ownerId: SHARED }]);
        expect(knownRowOwner('dup')).toBe(SHARED);
    });
});

describe('withCompanyOwnerScoping', () => {
    it('scopes mutations of a shared company and leaves private ones untouched', () => {
        share();
        const { raw, mocks } = fakeRaw();
        const evolu = withCompanyOwnerScoping(raw);

        evolu.insert('document', { companyId: 'company-shared', title: 'A' } as never);
        evolu.insert('document', { companyId: 'company-private', title: 'B' } as never);

        expect(mocks.insert).toHaveBeenNthCalledWith(1, 'document', { companyId: 'company-shared', title: 'A' }, { ownerId: SHARED });
        expect(mocks.insert).toHaveBeenNthCalledWith(2, 'document', { companyId: 'company-private', title: 'B' }, undefined);
    });

    it('indexes newly written rows so child inserts follow the parent partition', () => {
        share();
        const { raw, mocks } = fakeRaw();
        const evolu = withCompanyOwnerScoping(raw);

        const doc = evolu.insert('document', { companyId: 'company-shared', title: 'A' } as never);
        expect(doc.ok).toBe(true);
        evolu.insert('documentLine', { documentId: 'new-insert', name: 'Line' } as never);

        expect(mocks.insert.mock.calls[1][2]).toEqual({ ownerId: SHARED });
    });

    it('learns owners from query results and merges options', async () => {
        share();
        const { raw, mocks } = fakeRaw();
        const evolu = withCompanyOwnerScoping(raw);

        await evolu.loadQuery({} as never);
        await Promise.all(evolu.loadQueries([{}] as never));
        evolu.getQueryRows({} as never);

        expect(knownRowOwner('doc-1')).toBe(SHARED);
        expect(knownRowOwner('doc-2')).toBe(APP);
        expect(knownRowOwner('doc-3')).toBe(SHARED);
        expect(knownRowOwner('doc-4')).toBe(SHARED);

        evolu.update('document', { id: 'doc-1', status: 'paid' } as never, { onComplete: () => undefined });
        expect(mocks.update.mock.calls[0][2]).toMatchObject({ ownerId: SHARED });
        expect(typeof (mocks.update.mock.calls[0][2] as { onComplete?: unknown }).onComplete).toBe('function');

        evolu.update('document', { id: 'doc-2', status: 'paid' } as never);
        expect(mocks.update.mock.calls[1][2]).toBeUndefined();

        await expect(evolu.appOwner).resolves.toEqual({ id: APP });
        expect(isSharedRow({ ownerId: SHARED })).toBe(true);
        expect(isSharedRow({ ownerId: APP })).toBe(false);
    });

    it('never scopes the companyShare table even for a shared company', () => {
        share();
        const { raw, mocks } = fakeRaw();
        const evolu = withCompanyOwnerScoping(raw);

        evolu.insert('companyShare', { companyId: 'company-shared', secretB64: 's' } as never);

        expect(mocks.insert.mock.calls[0][2]).toBeUndefined();
    });
});
