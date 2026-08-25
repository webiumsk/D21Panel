import { invoicingApi } from "@/services/api";
import { ensureBridgeCompanyIdForLocalCompany } from "./bridgeCompanyEnsure";
import { isNetworkError } from "./numberAllocatorBridge";
import { companyShareInfo } from "./companyShareRegistry";

/**
 * Gapless numbering (P3): deleting the LAST issued invoice must free its
 * number on the server allocator, otherwise the reservation floor keeps the
 * next number one above the deleted one forever (the "next 76 after
 * deleting 75" report). Release runs BEFORE the local delete - an issued
 * invoice therefore cannot be deleted offline, mirroring the
 * issue-requires-online rule.
 */
export type ReleaseNumberResult =
    | { ok: true }
    | { ok: false; error: "delete_requires_online" | "not_last" | "release_failed" };

export async function releaseIssuedNumber(
    localCompanyId: string,
    documentType: string,
    number: string,
): Promise<ReleaseNumberResult> {
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
        return { ok: false, error: "delete_requires_online" };
    }

    try {
        // A shared company pins its bridge at conversion time: use it directly
        // so a later legal-identity change cannot release against a different
        // (identity-matched) bridge than the one the reservation lives on.
        let bridgeCompanyId = companyShareInfo(localCompanyId)?.bridgeCompanyId ?? null;
        if (!bridgeCompanyId) {
            const bridge = await ensureBridgeCompanyIdForLocalCompany(localCompanyId);
            if (!bridge.ok) {
                return { ok: false, error: "release_failed" };
            }
            if (!bridge.bridgeCompanyId) {
                // No server identity - nothing can hold a reservation floor.
                return { ok: true };
            }
            bridgeCompanyId = bridge.bridgeCompanyId;
        }

        await invoicingApi.numberAllocator.release(bridgeCompanyId, {
            document_type: documentType,
            number,
        });
        // released=true frees the number; released=false/not_found means no
        // reservation held it (pre-allocator document) - both are fine.
        return { ok: true };
    } catch (error: unknown) {
        const status = (error as { response?: { status?: number } })?.response?.status;
        if (status === 422) {
            return { ok: false, error: "not_last" };
        }
        return {
            ok: false,
            error: isNetworkError(error) ? "delete_requires_online" : "release_failed",
        };
    }
}
