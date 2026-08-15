import { maxLength, NonEmptyString } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
import { useAuthStore } from "@/store/auth";
import type { DocumentId, InvoicingLocalSchema } from "./schema";

const ActionType = maxLength(128)(NonEmptyString);

/** Internal audit row for forced relay upload - hidden from invoice history UI. */
export const RELAY_SYNC_EVENT_ACTION = "relay_sync";

export type EvoluDocumentEventRow = {
    id: string;
    documentId: string;
    action: string;
    metadataJson: string | null;
    createdAt: string;
};

export type DocumentHistoryEntry = {
    id: string;
    action: string;
    created_at: string;
    // The signed-in actor, stamped into the event metadata at log time
    // (GoBD audit attribution); older events without it show no user.
    user: { email?: string | null } | null;
    metadata: Record<string, unknown> | null;
};

/** Metadata key carrying the actor's e-mail (GoBD audit attribution). */
export const EVENT_USER_EMAIL_KEY = "user_email";

function currentUserEmail(): string | null {
    try {
        // Pinia is active whenever the app runs; the guard covers tests and
        // early module use - attribution is best-effort, never a blocker.
        return useAuthStore().user?.email ?? null;
    } catch {
        return null;
    }
}

export function logDocumentEvent(
    evolu: Evolu<InvoicingLocalSchema>,
    documentId: DocumentId,
    action: string,
    metadata?: Record<string, unknown>,
) {
    const actionParsed = ActionType.from(action);
    if (!actionParsed.ok) return actionParsed;

    // The attribution key is reserved: strip any caller-supplied value so an
    // unauthenticated (or malicious) caller can never forge the actor.
    const safeMetadata: Record<string, unknown> = { ...(metadata ?? {}) };
    delete safeMetadata[EVENT_USER_EMAIL_KEY];
    const email = currentUserEmail();
    const enriched: Record<string, unknown> = {
        ...safeMetadata,
        ...(email ? { [EVENT_USER_EMAIL_KEY]: email } : {}),
    };
    const metadataJson = Object.keys(enriched).length > 0 ? JSON.stringify(enriched) : null;

    return evolu.insert("documentEvent", {
        documentId,
        action: actionParsed.value,
        metadataJson,
    });
}

export function documentHistoryFromEvents(events: EvoluDocumentEventRow[]): DocumentHistoryEntry[] {
    return [...events]
        .filter((event) => event.action !== RELAY_SYNC_EVENT_ACTION)
        .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
        .slice(0, 50)
        .map((event) => {
            const metadata = (() => {
                if (!event.metadataJson) return null;
                try {
                    return JSON.parse(event.metadataJson) as Record<string, unknown>;
                } catch {
                    return null;
                }
            })();
            const email = metadata?.[EVENT_USER_EMAIL_KEY];

            return {
                id: event.id,
                action: event.action,
                created_at: event.createdAt,
                user: typeof email === "string" && email !== "" ? { email } : null,
                metadata,
            };
        });
}
