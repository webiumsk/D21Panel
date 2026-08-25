import { afterEach, describe, expect, it, vi } from 'vitest';

const api = vi.hoisted(() => ({ release: vi.fn(async () => ({})) }));
vi.mock('@/services/api', () => ({ invoicingApi: { numberAllocator: { release: api.release } } }));
const bridge = vi.hoisted(() => ({ ensure: vi.fn(async () => ({ ok: true, bridgeCompanyId: 'identity-bridge' })) }));
vi.mock('@/evolu/bridgeCompanyEnsure', () => ({ ensureBridgeCompanyIdForLocalCompany: bridge.ensure }));

import { releaseIssuedNumber } from '@/evolu/numberReleaseBridge';
import { clearCompanyShares, setCompanyShare } from '@/evolu/companyShareRegistry';
import type { OwnerId } from '@evolu/common/local-first';

afterEach(() => {
    clearCompanyShares();
    api.release.mockClear();
    bridge.ensure.mockClear();
});

describe('releaseIssuedNumber', () => {
    it('releases against the recorded bridge of a shared company, not the identity match', async () => {
        setCompanyShare({ companyId: 'company-1', ownerId: 'o1' as OwnerId, role: 'owner', status: 'active', bridgeCompanyId: 'recorded-bridge' });

        const result = await releaseIssuedNumber('company-1', 'invoice', '20260001');

        expect(result).toEqual({ ok: true });
        expect(api.release).toHaveBeenCalledWith('recorded-bridge', { document_type: 'invoice', number: '20260001' });
        // The identity resolver is bypassed for shared companies.
        expect(bridge.ensure).not.toHaveBeenCalled();
    });

    it('falls back to identity resolution for a private company', async () => {
        const result = await releaseIssuedNumber('company-2', 'invoice', '20260002');

        expect(result).toEqual({ ok: true });
        expect(bridge.ensure).toHaveBeenCalledWith('company-2');
        expect(api.release).toHaveBeenCalledWith('identity-bridge', { document_type: 'invoice', number: '20260002' });
    });
});
