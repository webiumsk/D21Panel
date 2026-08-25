import { describe, expect, it, vi } from 'vitest';

vi.mock('@/services/evoluRelayPreference', () => ({
    getResolvedEvoluRelayUrl: () => 'wss://relay.test',
}));

import type { Evolu } from '@evolu/common/local-first';
import type { OwnerId } from '@evolu/common/local-first';
import { rowOwnerId, scopedEvolu } from '@/evolu/ownerScope';
import type { InvoicingLocalSchema } from '@/evolu/schema';
import {
    createCompanyShareSecret,
    decodeOwnerSecret,
    encodeOwnerSecret,
    isSharedOwnerRegistered,
    registerSharedOwner,
    sharedOwnerForRelay,
    sharedOwnerFromSecret,
    unregisterAllSharedOwners,
    unregisterSharedOwner,
} from '@/evolu/sharedOwner';

describe('company share secret and SharedOwner', () => {
    it('round-trips the secret through base64url', () => {
        const secret = createCompanyShareSecret();
        const encoded = encodeOwnerSecret(secret);

        expect(encoded).toMatch(/^[A-Za-z0-9_-]{43}$/);
        expect(decodeOwnerSecret(encoded)).toEqual(secret);
        expect(decodeOwnerSecret(` ${encoded} `)).toEqual(secret);
    });

    it('rejects malformed or wrong-length secrets', () => {
        expect(decodeOwnerSecret('')).toBeNull();
        expect(decodeOwnerSecret('not base64!!')).toBeNull();
        expect(decodeOwnerSecret('AAAA')).toBeNull();
        expect(decodeOwnerSecret(encodeOwnerSecret(createCompanyShareSecret()).slice(0, 20))).toBeNull();
    });

    it('derives the same owner from the same secret on every device and a different one otherwise', () => {
        const secret = createCompanyShareSecret();
        const a = sharedOwnerFromSecret(secret);
        const b = sharedOwnerFromSecret(decodeOwnerSecret(encodeOwnerSecret(secret))!);
        const other = sharedOwnerFromSecret(createCompanyShareSecret());

        expect(a.type).toBe('SharedOwner');
        expect(a.id).toBe(b.id);
        expect(a.encryptionKey).toEqual(b.encryptionKey);
        expect(a.writeKey).toEqual(b.writeKey);
        expect(other.id).not.toBe(a.id);
    });

    it('attaches a relay transport for the shared owner id', () => {
        const owner = sharedOwnerFromSecret(createCompanyShareSecret());
        const sync = sharedOwnerForRelay(owner);

        expect(sync.id).toBe(owner.id);
        expect(sync.transports).toHaveLength(1);
    });

    it('registers each owner once per instance and unregisters on demand', () => {
        const useOwner = vi.fn(() => vi.fn());
        const evolu = { useOwner } as unknown as Evolu<InvoicingLocalSchema>;
        const owner = sharedOwnerFromSecret(createCompanyShareSecret());

        expect(registerSharedOwner(evolu, owner)).toBe(owner.id);
        expect(registerSharedOwner(evolu, owner)).toBe(owner.id);
        expect(useOwner).toHaveBeenCalledTimes(1);
        expect(isSharedOwnerRegistered(evolu, owner.id)).toBe(true);

        const unuse = useOwner.mock.results[0].value as ReturnType<typeof vi.fn>;
        unregisterSharedOwner(evolu, owner.id);
        expect(unuse).toHaveBeenCalledTimes(1);
        expect(isSharedOwnerRegistered(evolu, owner.id)).toBe(false);

        registerSharedOwner(evolu, owner);
        unregisterAllSharedOwners(evolu);
        expect(isSharedOwnerRegistered(evolu, owner.id)).toBe(false);
    });

    it('keeps registrations of different Evolu instances apart', () => {
        const useOwnerA = vi.fn(() => vi.fn());
        const useOwnerB = vi.fn(() => vi.fn());
        const evoluA = { useOwner: useOwnerA } as unknown as Evolu<InvoicingLocalSchema>;
        const evoluB = { useOwner: useOwnerB } as unknown as Evolu<InvoicingLocalSchema>;
        const owner = sharedOwnerFromSecret(createCompanyShareSecret());

        registerSharedOwner(evoluA, owner);
        registerSharedOwner(evoluB, owner);
        expect(useOwnerA).toHaveBeenCalledTimes(1);
        expect(useOwnerB).toHaveBeenCalledTimes(1);

        unregisterSharedOwner(evoluA, owner.id);
        expect(isSharedOwnerRegistered(evoluA, owner.id)).toBe(false);
        expect(isSharedOwnerRegistered(evoluB, owner.id)).toBe(true);
        expect(useOwnerB.mock.results[0].value).not.toHaveBeenCalled();
    });
});

describe('scopedEvolu', () => {
    function fakeEvolu() {
        const mocks = {
            insert: vi.fn((..._args: unknown[]) => ({ ok: true, value: { id: 'x' } })),
            update: vi.fn((..._args: unknown[]) => ({ ok: true, value: { id: 'x' } })),
            upsert: vi.fn((..._args: unknown[]) => ({ ok: true, value: { id: 'x' } })),
            loadQuery: vi.fn(async () => []),
        };
        const evolu = { ...mocks, appOwner: Promise.resolve({ id: 'app' }) } as unknown as Evolu<InvoicingLocalSchema>;
        return { evolu, mocks };
    }

    it('merges the owner id into every mutation and leaves the rest untouched', async () => {
        const { evolu, mocks } = fakeEvolu();
        const ownerId = 'shared-owner' as OwnerId;
        const scoped = scopedEvolu(evolu, ownerId);

        scoped.insert('company', { legalName: 'A' } as never);
        scoped.update('company', { id: 'c1' } as never, { onComplete: () => undefined });
        scoped.upsert('company', { id: 'c1' } as never, { onlyValidate: true });

        expect(mocks.insert).toHaveBeenCalledWith('company', { legalName: 'A' }, { ownerId });
        expect(mocks.update.mock.calls[0][2]).toMatchObject({ ownerId });
        expect(typeof (mocks.update.mock.calls[0][2] as { onComplete?: unknown }).onComplete).toBe('function');
        expect(mocks.upsert).toHaveBeenCalledWith('company', { id: 'c1' }, { onlyValidate: true, ownerId });
        expect(scoped.scopeOwnerId).toBe(ownerId);
        await expect(scoped.loadQuery({} as never)).resolves.toEqual([]);
        await expect(scoped.appOwner).resolves.toEqual({ id: 'app' });
    });

    it('is a no-op for the AppOwner scope', () => {
        const { evolu, mocks } = fakeEvolu();
        const scoped = scopedEvolu(evolu, undefined);

        scoped.insert('company', { legalName: 'A' } as never);

        expect(mocks.insert).toHaveBeenCalledWith('company', { legalName: 'A' });
        expect(scoped.scopeOwnerId).toBeUndefined();
    });

    it('reads the owner partition off a row', () => {
        expect(rowOwnerId({ ownerId: 'o1' })).toBe('o1');
        expect(rowOwnerId({ ownerId: '' })).toBeUndefined();
        expect(rowOwnerId({ ownerId: null })).toBeUndefined();
        expect(rowOwnerId(null)).toBeUndefined();
    });
});
