import type { Evolu } from "@evolu/common/local-first";
import { sealCompanyInviteSecret, type SealedInvite } from "@/services/companyInviteSeal";
import type { InvoicingLocalSchema } from "./schema";

/**
 * Owner side of creating a company invite (docs/COMPANY_SHARING.md, "C4").
 * Reads the company's own SharedOwner secret and seals it to the recipient's
 * recovery public key. The plaintext secret never leaves the device except as
 * this ECIES blob (sealed mode) or the raw string handed back for a link.
 */

export type ShareSecretResult =
    | { ok: true; secretEncoded: string; bridgeCompanyId: string | null }
    | { ok: false; error: "not_shared" };

/** The active SharedOwner secret + bridge for a locally-shared company. */
export async function readOwnShareSecret(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: string,
): Promise<ShareSecretResult> {
    const { allCompanySharesQuery } = await import("./client");
    const appOwnerId = (await evolu.appOwner).id;
    const shares = (await evolu.loadQuery(allCompanySharesQuery)) as unknown as readonly {
        companyId: string; ownerId: string | null; secretB64: string; status: string; bridgeCompanyId: string | null;
    }[];
    const share = shares.find((s) => s.companyId === companyId && s.ownerId === appOwnerId && s.status === "active");
    if (!share) return { ok: false, error: "not_shared" };
    return { ok: true, secretEncoded: share.secretB64, bridgeCompanyId: share.bridgeCompanyId ?? null };
}

export type SealForRecipientResult =
    | { ok: true; sealed: SealedInvite; bridgeCompanyId: string | null }
    | { ok: false; error: "not_shared" | "seal_failed" };

/** Seal the company's share secret to a recipient's Ed25519 public key. */
export async function sealShareForRecipient(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: string,
    recipientPublicKeyHex: string,
): Promise<SealForRecipientResult> {
    const secret = await readOwnShareSecret(evolu, companyId);
    if (!secret.ok) return { ok: false, error: "not_shared" };
    const sealed = await sealCompanyInviteSecret(recipientPublicKeyHex, secret.secretEncoded);
    if (!sealed) return { ok: false, error: "seal_failed" };
    return { ok: true, sealed, bridgeCompanyId: secret.bridgeCompanyId };
}
