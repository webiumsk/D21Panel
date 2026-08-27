/**
 * Pure decision for whether the invoicing first-run wizard should auto-open.
 *
 * Extracted from Index.vue so the gate - especially the "wait for migration
 * status" rule - is unit-testable without mounting the whole page.
 */
export interface InvoicingWizardGateState {
    /** Business invoicing entitlement (Pro / admin / enterprise). */
    canUse: boolean;
    /** Company list still loading. */
    loading: boolean;
    /** Company list failed to load. */
    loadError: boolean;
    /** Number of companies the user already has. */
    companyCount: number;
    /**
     * True once we know whether a server-migration prompt is warranted: either
     * the migration status finished loading (local-first) or migration does not
     * apply (server mode). Until then the wizard must not pre-empt a migration
     * prompt that is about to appear.
     */
    migrationGateReady: boolean;
    /** A server-migration prompt is being offered. */
    showServerMigration: boolean;
    /** A relay sync is in progress. */
    isRelaySyncing: boolean;
}

export function shouldAutoOfferInvoicingWizard(state: InvoicingWizardGateState): boolean {
    return (
        state.canUse &&
        !state.loading &&
        !state.loadError &&
        state.companyCount === 0 &&
        state.migrationGateReady &&
        !state.showServerMigration &&
        !state.isRelaySyncing
    );
}
