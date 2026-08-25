import type { Evolu } from "@evolu/common/local-first";
import type { OwnerId } from "@evolu/common/local-first";
import type { InvoicingLocalSchema } from "./schema";

/**
 * Evolu keys app rows by (ownerId, id): a mutation without `{ ownerId }`
 * on a row that lives under a SharedOwner silently forks it into the
 * AppOwner partition. Every CRUD module therefore has to route mutations
 * through a scoped client instead of remembering to pass the option.
 *
 * `scopedEvolu` is a thin proxy: insert / update / upsert merge the owner
 * id into the mutation options, everything else (queries, appOwner,
 * subscriptions) passes through untouched. `undefined` scope = AppOwner,
 * i.e. today's behaviour - so unshared companies keep working unchanged.
 */

const MUTATION_METHODS = new Set<PropertyKey>(["insert", "update", "upsert"]);

export type ScopedEvolu = Evolu<InvoicingLocalSchema> & { readonly scopeOwnerId: OwnerId | undefined };

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

/** Owner id a row was written under, or undefined for AppOwner / unknown rows. */
export function rowOwnerId(row: { ownerId?: string | null } | null | undefined): OwnerId | undefined {
    const value = row?.ownerId;
    return typeof value === "string" && value !== "" ? (value as OwnerId) : undefined;
}
