import { computed, ref, watch, type Ref } from "vue";
import { ensureBridgeCompanyIdForLocalCompany } from "./bridgeCompanyEnsure";
import {
    fetchEfakturaInbox,
    reconcileEfakturaInboxWithLocalExpenses,
    type EfakturaInboxEntry,
} from "./efakturaInboxImport";
import { isInvoicingLocalFirst } from "./flags";
import { toAppRows } from "./queryLoad";

/**
 * Shared live state of the e-faktura inbox (module singleton) - the
 * local-first twin of integrationInboxLive.ts. One polling loop per SPA:
 * the Expenses page panel and any badge read `entries` from here.
 *
 * Active only for companies with `efaktura_inbound_enabled` in their
 * (Evolu) app settings and a resolvable bridge company - the inbox lives
 * on the bridge row server-side.
 */

const POLL_INTERVAL_MS = 60_000;
const REFRESH_MIN_GAP_MS = 15_000;

const entries = ref<EfakturaInboxEntry[]>([]);
const pendingCount = computed(() => entries.value.length);
const bridgeCompanyIdRef = ref<string | null>(null);

let companyId = "";
let enabled = false;
let refreshing = false;
let lastRefreshAt = 0;
let listenersInstalled = false;
/** Bumped on every company switch / settings reload; stale async results are discarded. */
let generation = 0;

type CompanyRowSlice = { id: string; appSettingsJson: string | null };

export function inboundEnabledFromSettingsJson(json: string | null): boolean {
    if (!json) return false;
    try {
        const settings = JSON.parse(json) as Record<string, unknown>;
        return Boolean(settings.efaktura_enabled) && Boolean(settings.efaktura_inbound_enabled);
    } catch {
        return false;
    }
}

async function deriveCompanyParams(nextCompanyId: string): Promise<void> {
    // Switch synchronously first so nothing from the previous company leaks
    // into the new one while the bridge lookup below is still in flight.
    const myGeneration = ++generation;
    companyId = nextCompanyId;
    enabled = false;
    bridgeCompanyIdRef.value = null;
    entries.value = [];

    const { evolu, allCompaniesQuery } = await import("./client");
    const rows = toAppRows<CompanyRowSlice>(await evolu.loadQuery(allCompaniesQuery));
    if (myGeneration !== generation) return;
    const row = rows.find((r) => String(r.id) === nextCompanyId) ?? null;
    if (row == null || !inboundEnabledFromSettingsJson(row.appSettingsJson)) {
        return;
    }

    const bridge = await ensureBridgeCompanyIdForLocalCompany(nextCompanyId);
    if (myGeneration !== generation) return;
    const bridgeId = bridge.ok ? bridge.bridgeCompanyId : null;
    bridgeCompanyIdRef.value = bridgeId;
    enabled = bridgeId !== null;
}

/**
 * Fetch + reconcile. Background callers (poll timer, focus) swallow errors;
 * an explicit user refresh passes `throwOnError` so the panel can show them.
 * Results are discarded when the company / bridge changed meanwhile.
 */
export async function refreshEfakturaInboxLive(
    force = false,
    options: { throwOnError?: boolean } = {},
): Promise<void> {
    const bridgeId = bridgeCompanyIdRef.value;
    if (!enabled || !companyId || !bridgeId || refreshing) {
        return;
    }
    if (!force && Date.now() - lastRefreshAt < REFRESH_MIN_GAP_MS) {
        return;
    }
    const myGeneration = generation;
    const myCompanyId = companyId;
    refreshing = true;
    try {
        const { evolu } = await import("./client");
        const fetched = await fetchEfakturaInbox(bridgeId);
        if (myGeneration !== generation) return;
        const remaining = await reconcileEfakturaInboxWithLocalExpenses(evolu, myCompanyId, bridgeId, fetched);
        if (myGeneration !== generation || bridgeCompanyIdRef.value !== bridgeId) return;
        entries.value = remaining;
        lastRefreshAt = Date.now();
    } catch (error) {
        if (options.throwOnError) {
            throw error;
        }
        // Background poll: errors stay silent, the panel surfaces its own.
    } finally {
        refreshing = false;
    }
}

/** Settings were just saved (inbound toggled) - re-derive and refresh. */
export async function reloadEfakturaInboxLive(): Promise<void> {
    if (!companyId) return;
    await deriveCompanyParams(companyId);
    await refreshEfakturaInboxLive(true);
}

function onWindowFocusOrVisible(): void {
    if (typeof document !== "undefined" && document.visibilityState === "hidden") {
        return;
    }
    void refreshEfakturaInboxLive();
}

function ensureLoopInstalled(): void {
    if (listenersInstalled || typeof window === "undefined") {
        return;
    }
    listenersInstalled = true;
    window.addEventListener("focus", onWindowFocusOrVisible);
    document.addEventListener("visibilitychange", onWindowFocusOrVisible);
    setInterval(() => {
        if (typeof document === "undefined" || document.visibilityState !== "hidden") {
            void refreshEfakturaInboxLive(true);
        }
    }, POLL_INTERVAL_MS);
}

export function initEfakturaInboxLive(activeCompanyId: Ref<string>): void {
    if (!isInvoicingLocalFirst()) {
        return;
    }
    ensureLoopInstalled();
    watch(
        activeCompanyId,
        async (id) => {
            const next = String(id ?? "").trim();
            if (!next || next === companyId) {
                return;
            }
            await deriveCompanyParams(next);
            void refreshEfakturaInboxLive(true);
        },
        { immediate: true },
    );
}

export function useEfakturaInboxLive() {
    return { entries, pendingCount, bridgeCompanyId: bridgeCompanyIdRef };
}
