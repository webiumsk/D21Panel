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
    const { evolu, allCompaniesQuery } = await import("./client");
    const rows = toAppRows<CompanyRowSlice>(await evolu.loadQuery(allCompaniesQuery));
    const row = rows.find((r) => String(r.id) === nextCompanyId) ?? null;

    companyId = nextCompanyId;
    enabled = row != null && inboundEnabledFromSettingsJson(row.appSettingsJson);
    bridgeCompanyIdRef.value = null;
    if (!enabled) {
        entries.value = [];
        return;
    }
    const bridge = await ensureBridgeCompanyIdForLocalCompany(nextCompanyId);
    bridgeCompanyIdRef.value = bridge.ok ? bridge.bridgeCompanyId : null;
    if (!bridgeCompanyIdRef.value) {
        enabled = false;
        entries.value = [];
    }
}

export async function refreshEfakturaInboxLive(force = false): Promise<void> {
    if (!enabled || !companyId || !bridgeCompanyIdRef.value || refreshing) {
        return;
    }
    if (!force && Date.now() - lastRefreshAt < REFRESH_MIN_GAP_MS) {
        return;
    }
    refreshing = true;
    try {
        const { evolu } = await import("./client");
        const fetched = await fetchEfakturaInbox(bridgeCompanyIdRef.value);
        entries.value = await reconcileEfakturaInboxWithLocalExpenses(
            evolu,
            companyId,
            bridgeCompanyIdRef.value,
            fetched,
        );
        lastRefreshAt = Date.now();
    } catch {
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
