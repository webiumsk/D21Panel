import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * First-run onboarding state for the Invoicing module.
 *
 * Kept deliberately separate from the PoS `onboarding` store: the two flows
 * are unrelated and generalizing one risks destabilizing the other. This
 * store only tracks whether the first-run setup wizard has been shown, so a
 * new user is guided once and never nagged on later visits. Per-company
 * "getting started" checklist dismissal lives in the checklist card itself
 * (localStorage, like the eFaktura readiness snooze).
 */
const STORAGE_KEY = 'satflux.invoicing_onboarding.wizard_seen';

function loadSeen(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        // Private mode / storage disabled - treat as not seen for this session.
        return false;
    }
}

export const useInvoicingOnboardingStore = defineStore('invoicingOnboarding', () => {
    const wizardSeen = ref<boolean>(loadSeen());

    function markWizardSeen(): void {
        wizardSeen.value = true;
        try {
            localStorage.setItem(STORAGE_KEY, '1');
        } catch {
            // Ignore write failures - the in-memory flag still suppresses
            // re-opening within this session.
        }
    }

    /** Testing / "show me again" affordance - clears the persisted flag. */
    function resetWizard(): void {
        wizardSeen.value = false;
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch {
            // Ignore write failures.
        }
    }

    return {
        wizardSeen,
        markWizardSeen,
        resetWizard,
    };
});
