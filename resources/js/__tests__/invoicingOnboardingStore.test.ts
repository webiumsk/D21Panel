import { setActivePinia, createPinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { useInvoicingOnboardingStore } from '../store/invoicingOnboarding';

const KEY = 'satflux.invoicing_onboarding.wizard_seen';

describe('invoicing onboarding store', () => {
  beforeEach(() => {
    localStorage.clear();
    setActivePinia(createPinia());
  });

  it('starts unseen and marks the wizard seen with persistence', () => {
    const store = useInvoicingOnboardingStore();
    expect(store.wizardSeen).toBe(false);

    store.markWizardSeen();
    expect(store.wizardSeen).toBe(true);
    expect(localStorage.getItem(KEY)).toBe('1');
  });

  it('hydrates the seen flag from localStorage on creation', () => {
    localStorage.setItem(KEY, '1');
    setActivePinia(createPinia());
    const store = useInvoicingOnboardingStore();
    expect(store.wizardSeen).toBe(true);
  });

  it('reset clears the flag and its persistence', () => {
    localStorage.setItem(KEY, '1');
    setActivePinia(createPinia());
    const store = useInvoicingOnboardingStore();
    expect(store.wizardSeen).toBe(true);

    store.resetWizard();
    expect(store.wizardSeen).toBe(false);
    expect(localStorage.getItem(KEY)).toBeNull();
  });
});
