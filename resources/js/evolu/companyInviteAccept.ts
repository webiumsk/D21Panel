import type { Evolu } from "@evolu/common/local-first";
import { deriveRecoveryPrivateKey, getStoredAccountMnemonic } from "@/services/accountSeed";
import { openCompanyInviteSecret, type SealedInvite } from "@/services/companyInviteSeal";
import { setCompanyShare } from "./companyShareRegistry";
import type { InvoicingLocalSchema } from "./schema";
import { decodeOwnerSecret, registerSharedOwner, sharedOwnerFromSecret } from "./sharedOwner";

/**
 * Client side of accepting a company invite (docs/COMPANY_SHARING.md, "C4").
 *
 * The server only records membership; the SharedOwner secret is delivered
 * out-of-band (sealed to the invitee's recovery key, or in a link fragment).
 * Here we register the owner, wait for the shared company to sync in, and
 * persist a local `companyShare` row so owner-scoping survives a reload.
 */

export type ShareRole = "accountant" | "member" | "owner";

/** Decrypt a sealed invite blob with THIS device's recovery key. null if no phrase / wrong account. */
export async function decryptSealedInvite(sealed: SealedInvite): Promise<string | null> {
    const mnemonic = getStoredAccountMnemonic();
    if (!mnemonic) return null;
    let seed: Uint8Array | null = null;
    try {
        seed = deriveRecoveryPrivateKey(mnemonic);
        return await openCompanyInviteSecret(seed, sealed);
    } catch {
        return null;
    } finally {
        seed?.fill(0);
    }
}

function mutate(run: (onComplete: () => void) => { ok: boolean; error?: unknown }): Promise<{ ok: boolean; error?: unknown }> {
    return new Promise((resolve) => {
        const res = run(() => resolve({ ok: true }));
        if (!res.ok) resolve({ ok: false, error: res.error });
    });
}

export type MaterializeResult =
    | { ok: true; companyId: string }
    | { ok: false; error: "invalid_secret" | "share_not_synced" | "share_row_failed" };

/**
 * Register the shared owner from the (already decrypted) secret, wait for its
 * company row to arrive, and write the local companyShare row. Idempotent: a
 * second device / retry that already has the row just refreshes the registry.
 */
export async function materializeAcceptedShare(
    evolu: Evolu<InvoicingLocalSchema>,
    input: { secretEncoded: string; role: ShareRole; bridgeCompanyId: string | null },
): Promise<MaterializeResult> {
    const decoded = decodeOwnerSecret(input.secretEncoded);
    if (!decoded) return { ok: false, error: "invalid_secret" };
    const owner = sharedOwnerFromSecret(decoded);
    registerSharedOwner(evolu, owner);

    const { allCompaniesDetailQuery, allCompanySharesQuery } = await import("./client");
    const { waitForInvoicingRelaySync } = await import("./relaySyncWait");

    // Wait for the shared company row to materialize. The relay-sync wait plus
    // a bounded re-query loop covers a cold join where the row lands late.
    let companyId: string | undefined;
    for (let attempt = 0; attempt < 8 && !companyId; attempt++) {
        await waitForInvoicingRelaySync(evolu, { timeoutMs: 30_000 }).catch(() => undefined);
        const rows = (await evolu.loadQuery(allCompaniesDetailQuery)) as unknown as readonly {
            id: string; ownerId?: string | null; isDeleted?: unknown;
        }[];
        const row = rows.find((r) => r.ownerId === owner.id && r.isDeleted !== 1);
        if (row) companyId = row.id;
        else await new Promise((r) => setTimeout(r, 1_500));
    }
    if (!companyId) return { ok: false, error: "share_not_synced" };

    const appOwnerId = (await evolu.appOwner).id;
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly {
        id: string; companyId: string; ownerId: string | null; sharedOwnerId: string; status: string;
    }[];
    const existing = shares.find((s) => s.sharedOwnerId === owner.id && s.ownerId === appOwnerId && s.status !== "revoked");

    if (!existing) {
        const res = await mutate((onComplete) =>
            evolu.insert(
                "companyShare",
                {
                    companyId,
                    sharedOwnerId: owner.id,
                    secretB64: input.secretEncoded,
                    role: input.role,
                    status: "active",
                    bridgeCompanyId: input.bridgeCompanyId ?? undefined,
                } as never,
                { onComplete },
            ),
        );
        if (!res.ok) return { ok: false, error: "share_row_failed" };
    }

    setCompanyShare({ companyId, ownerId: owner.id, role: input.role, status: "active", bridgeCompanyId: input.bridgeCompanyId ?? null });
    return { ok: true, companyId };
}
