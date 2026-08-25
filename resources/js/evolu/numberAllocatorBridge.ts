import { invoicingApi } from "@/services/api";
import { matchCompanyByIdentity } from "./duplicateCompanies";
import { sha256Hex } from "@/utils/sha256";

/**
 * Client side of the server number allocator (audit F3).
 *
 * Issuing a document reserves its number atomically on the server, scoped to
 * the bridge company (matched by legal identity, created on demand) - NOT to
 * a BTCPay store link. The server sees only counters, an idempotency key and
 * an opaque snapshot hash; invoice content never leaves the client.
 *
 * Definitive issue requires being online: there is no local fallback number.
 */

export type AllocatorIdentity = {
    legal_name?: string | null;
    registration_number?: string | null;
    /**
     * Creation hints for a missing bridge company row - the server REQUIRES
     * jurisdiction on create; country and currency improve fidelity. Not
     * part of the identity match itself.
     */
    jurisdiction?: string | null;
    country?: string | null;
    default_currency?: string | null;
    /**
     * Shared company (docs/COMPANY_SHARING.md): every member must hit the
     * SAME bridge row so the counter is one sequence - the id recorded at
     * conversion time short-circuits the identity match.
     */
    bridge_company_id?: string | null;
};

export type AllocatorReservation = {
    number: string;
    counter: number;
    status: string;
    bridgeCompanyId: string;
};

export type AllocatorReserveResult =
    | { ok: true; value: AllocatorReservation }
    | { ok: false; error: "issue_requires_online" | "reserve_failed" };

type BridgeCompanyRow = {
    id: string;
    legal_name: string;
    registration_number?: string | null;
};

export function isNetworkError(error: unknown): boolean {
    return typeof error === "object" && error !== null && !("response" in error && (error as { response?: unknown }).response);
}

/**
 * Deterministic identity match: rows are sorted by id (UUIDv7, so oldest
 * first) before matching, giving every client the SAME canonical row even
 * when a concurrent first issue produced duplicate bridge companies.
 */
function canonicalIdentityMatch(
    rows: BridgeCompanyRow[],
    identity: AllocatorIdentity,
): BridgeCompanyRow | null {
    const sorted = [...rows].sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
    return matchCompanyByIdentity(sorted, {
        legal_name: identity.legal_name ?? "",
        registration_number: identity.registration_number ?? null,
    });
}

/**
 * Resolves the server bridge company for this identity, creating a minimal
 * identity-only row when none exists yet. The bridge company carries no
 * document content - it anchors ownership, plan gating and number series.
 *
 * The server has no atomic resolve-or-create, so two concurrent first
 * issues can both create a row; the post-create re-list converges both
 * clients on the canonical (oldest) match, keeping all reservations in one
 * sequence. A losing duplicate stays behind empty and harmless.
 */
export async function resolveOrCreateBridgeCompanyId(identity: AllocatorIdentity): Promise<string | null> {
    if (identity.bridge_company_id) {
        return identity.bridge_company_id;
    }
    if (!identity.legal_name) {
        return null;
    }
    const rows = await invoicingApi.companies.list<BridgeCompanyRow>();
    const found = canonicalIdentityMatch(rows, identity);
    if (found) {
        return found.id;
    }
    const created = await invoicingApi.companies.create<BridgeCompanyRow>({
        legal_name: identity.legal_name,
        registration_number: identity.registration_number ?? null,
        // Server-side validation requires a jurisdiction on create.
        jurisdiction: identity.jurisdiction || "eu_other",
        ...(identity.country ? { country: identity.country } : {}),
        ...(identity.default_currency ? { default_currency: identity.default_currency } : {}),
    });
    const converged = canonicalIdentityMatch(
        await invoicingApi.companies.list<BridgeCompanyRow>(),
        identity,
    );
    return converged?.id ?? created?.id ?? null;
}

/**
 * Reserves the next document number for an issue attempt. Idempotent per
 * issueRequestId (the Evolu document id): a retried call - e.g. after an
 * interrupted issue - returns the SAME number instead of burning a new one.
 */
export async function reserveIssueNumber(
    identity: AllocatorIdentity,
    documentType: string,
    issueRequestId: string,
    localHighCounter?: number,
): Promise<AllocatorReserveResult> {
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
        return { ok: false, error: "issue_requires_online" };
    }

    try {
        const bridgeCompanyId = await resolveOrCreateBridgeCompanyId(identity);
        if (!bridgeCompanyId) {
            return { ok: false, error: "reserve_failed" };
        }

        const body: Record<string, string | number> = {
            document_type: documentType,
            issue_request_id: issueRequestId,
        };
        if (localHighCounter != null && localHighCounter > 0) {
            body.local_high_counter = localHighCounter;
        }
        const data = await invoicingApi.numberAllocator.reserve<{
            number?: string;
            counter?: number;
            status?: string;
        }>(bridgeCompanyId, body);

        if (!data?.number || data.counter == null) {
            return { ok: false, error: "reserve_failed" };
        }
        return {
            ok: true,
            value: {
                number: data.number,
                counter: data.counter,
                status: data.status ?? "reserved",
                bridgeCompanyId,
            },
        };
    } catch (error: unknown) {
        return { ok: false, error: isNetworkError(error) ? "issue_requires_online" : "reserve_failed" };
    }
}

// Re-exported for existing importers; implementation moved to the shared util.
export { sha256Hex } from "@/utils/sha256";

/**
 * Confirms a reservation after the issued snapshot is persisted locally.
 * Best-effort: the document is already issued; an unconfirmed reservation
 * only stays "reserved" on the server and can be confirmed on a later retry.
 */
export async function confirmIssueNumber(
    bridgeCompanyId: string,
    documentType: string,
    issueRequestId: string,
    snapshotPayloadJson?: string | null,
    snapshotFormatVersion?: string | null,
): Promise<void> {
    try {
        const hash = snapshotPayloadJson ? await sha256Hex(snapshotPayloadJson) : null;
        await invoicingApi.numberAllocator.confirm(bridgeCompanyId, {
            document_type: documentType,
            issue_request_id: issueRequestId,
            ...(hash ? { snapshot_hash: hash } : {}),
            ...(snapshotFormatVersion ? { snapshot_format_version: snapshotFormatVersion } : {}),
        });
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn(
                "[invoicing] number reservation confirm failed:",
                error instanceof Error ? error.message : error,
            );
        }
    }
}
