import type { Evolu } from "@evolu/common/local-first";
import type { OwnerId } from "@evolu/common/local-first";
import { isRegisteredSharedOwnerId, ownerIdForCompany } from "./companyShareRegistry";
import type { InvoicingLocalSchema } from "./schema";

/**
 * Evolu keys app rows by (ownerId, id): a mutation without `{ ownerId }`
 * on a row that lives under a SharedOwner silently forks it into the
 * AppOwner partition. Instead of teaching ~150 call sites to pass the
 * option, the singleton is wrapped once (client.ts) and every mutation is
 * scoped automatically:
 *
 *   1. an explicit `options.ownerId` always wins;
 *   2. `companyShare` rows stay in the AppOwner partition (they hold the
 *      secret of the share itself);
 *   3. rows carrying `companyId` follow the company's share (registry);
 *   4. otherwise the row's own `id` (update / upsert) or its parent key
 *      (documentId, expenseId, ...) is looked up in the row -> owner index,
 *      which every query result passing through the proxy keeps fresh.
 *
 * Private companies resolve to `undefined` = today's behaviour.
 */

const MUTATION_METHODS = new Set<PropertyKey>(["insert", "update", "upsert"]);
const QUERY_METHODS = new Set<PropertyKey>(["loadQuery", "getQueryRows"]);

/** Child tables and the column pointing at the parent whose owner they inherit. */
const PARENT_KEYS: Record<string, string> = {
    documentLine: "documentId",
    documentEvent: "documentId",
    documentSnapshot: "documentId",
    expenseAttachment: "expenseId",
    recurringProfileLine: "recurringProfileId",
    bankTransactionMatch: "bankTransactionId",
};

const APP_OWNER_ONLY_TABLES = new Set<string>(["companyShare"]);

const rowOwner = new Map<string, OwnerId>();

type RowLike = { id?: unknown; ownerId?: unknown };

/**
 * Remember which owner partition a row came from. When both partitions hold
 * the same id (migration window) the shared copy wins - that is the one
 * mutations must keep targeting.
 */
export function indexRowOwners(rows: readonly unknown[]): void {
    for (const raw of rows) {
        const row = raw as RowLike;
        if (typeof row?.id !== "string" || typeof row.ownerId !== "string" || row.ownerId === "") {
            continue;
        }
        const incoming = row.ownerId as OwnerId;
        const current = rowOwner.get(row.id);
        if (current && isRegisteredSharedOwnerId(current) && !isRegisteredSharedOwnerId(incoming)) {
            continue;
        }
        rowOwner.set(row.id, incoming);
    }
}

export function knownRowOwner(id: string | null | undefined): OwnerId | undefined {
    return id ? rowOwner.get(String(id)) : undefined;
}

/** Test / teardown helper. */
export function clearRowOwnerIndex(): void {
    rowOwner.clear();
}

export function resolveMutationOwner(
    table: string,
    props: Record<string, unknown>,
    explicit: OwnerId | undefined,
): OwnerId | undefined {
    if (explicit) return explicit;
    if (APP_OWNER_ONLY_TABLES.has(table)) return undefined;

    const companyId = props.companyId;
    if (typeof companyId === "string" && companyId !== "") {
        return ownerIdForCompany(companyId);
    }

    const id = props.id;
    if (typeof id === "string") {
        const known = rowOwner.get(id);
        if (known) return isRegisteredSharedOwnerId(known) ? known : undefined;
    }

    const parentKey = PARENT_KEYS[table];
    const parentId = parentKey ? props[parentKey] : undefined;
    if (typeof parentId === "string") {
        const known = rowOwner.get(parentId);
        if (known) return isRegisteredSharedOwnerId(known) ? known : undefined;
        if (import.meta.env.DEV) {
            console.warn(`[owner-scope] ${table}.${parentKey}=${parentId} not in the row index - writing to the AppOwner partition`);
        }
    }

    return undefined;
}

export type ScopedEvolu = Evolu<InvoicingLocalSchema> & { readonly scopeOwnerId: OwnerId | undefined };

/**
 * Explicit scope for one owner - used by migration / spike code that must
 * write under a given owner regardless of the registry.
 */
export function scopedEvolu(evolu: Evolu<InvoicingLocalSchema>, ownerId: OwnerId | undefined): ScopedEvolu {
    if (!ownerId) {
        return Object.assign(Object.create(Object.getPrototypeOf(evolu) as object) as object, evolu, {
            scopeOwnerId: undefined,
        }) as ScopedEvolu;
    }

    return new Proxy(evolu as ScopedEvolu, {
        get(target, prop, receiver) {
            if (prop === "scopeOwnerId") {
                return ownerId;
            }
            const value = Reflect.get(target, prop, receiver) as unknown;
            if (MUTATION_METHODS.has(prop) && typeof value === "function") {
                const mutate = value as (...args: unknown[]) => unknown;
                return (table: unknown, props: unknown, options?: Record<string, unknown>) =>
                    mutate.call(target, table, props, { ...(options ?? {}), ownerId });
            }
            return value;
        },
    });
}

/** The singleton wrapper installed in client.ts - see the module comment. */
export function withCompanyOwnerScoping(raw: Evolu<InvoicingLocalSchema>): Evolu<InvoicingLocalSchema> {
    return new Proxy(raw, {
        get(target, prop, receiver) {
            const value = Reflect.get(target, prop, receiver) as unknown;
            if (typeof value !== "function") {
                return value;
            }

            if (MUTATION_METHODS.has(prop)) {
                const mutate = value as (...args: unknown[]) => { ok: boolean; value?: { id?: unknown } };
                return (table: string, props: Record<string, unknown>, options?: Record<string, unknown>) => {
                    const ownerId = resolveMutationOwner(table, props ?? {}, options?.ownerId as OwnerId | undefined);
                    const result = ownerId
                        ? mutate.call(target, table, props, { ...(options ?? {}), ownerId })
                        : mutate.call(target, table, props, options);
                    // Newly written rows are indexed right away so dependent
                    // child inserts (lines after a document) land in the same partition.
                    if (result?.ok && typeof result.value?.id === "string" && ownerId) {
                        rowOwner.set(result.value.id, ownerId);
                    }
                    return result;
                };
            }

            if (prop === "createQuery") {
                // Every query additionally selects the system `ownerId` column
                // so results can feed the row -> owner index. App-level row
                // types are unaffected (they are cast via toAppRows anyway).
                const create = value as (cb: (db: unknown) => unknown, options?: unknown) => unknown;
                return (cb: (db: unknown) => unknown, options?: unknown) =>
                    create.call(
                        target,
                        (db: unknown) => {
                            const built = cb(db) as { select?: (column: string) => unknown };
                            return typeof built?.select === "function" ? built.select("ownerId") : built;
                        },
                        options,
                    );
            }

            if (prop === "loadQuery") {
                const load = value as (query: unknown) => Promise<readonly unknown[]>;
                return (query: unknown) =>
                    load.call(target, query).then((rows) => {
                        indexRowOwners(rows);
                        return rows;
                    });
            }

            if (prop === "loadQueries") {
                const load = value as (queries: unknown[]) => Promise<readonly unknown[]>[];
                return (queries: unknown[]) =>
                    load.call(target, queries).map((promise) =>
                        promise.then((rows) => {
                            indexRowOwners(rows);
                            return rows;
                        }),
                    );
            }

            if (QUERY_METHODS.has(prop)) {
                const get = value as (query: unknown) => readonly unknown[];
                return (query: unknown) => {
                    const rows = get.call(target, query);
                    indexRowOwners(rows);
                    return rows;
                };
            }

            // Everything else (subscribe*, createQuery, appOwner getters,
            // useOwner, export...) is forwarded with the raw instance as `this`.
            return (value as (...args: unknown[]) => unknown).bind(target);
        },
    });
}

/** Owner id a row was written under, or undefined for AppOwner / unknown rows. */
export function rowOwnerId(row: { ownerId?: string | null } | null | undefined): OwnerId | undefined {
    const value = row?.ownerId;
    return typeof value === "string" && value !== "" ? (value as OwnerId) : undefined;
}

/** True when the row lives in a shared company's partition. */
export function isSharedRow(row: { ownerId?: string | null } | null | undefined): boolean {
    return isRegisteredSharedOwnerId(rowOwnerId(row));
}
