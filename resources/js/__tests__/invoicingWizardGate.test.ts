import { describe, expect, it } from 'vitest';
import {
  shouldAutoOfferInvoicingWizard,
  type InvoicingWizardGateState,
} from '../utils/invoicingWizardGate';

/** A Pro user, companies loaded and empty, migration known, nothing competing. */
function base(overrides: Partial<InvoicingWizardGateState> = {}): InvoicingWizardGateState {
  return {
    canUse: true,
    loading: false,
    loadError: false,
    companyCount: 0,
    migrationGateReady: true,
    showServerMigration: false,
    isRelaySyncing: false,
    ...overrides,
  };
}

describe('shouldAutoOfferInvoicingWizard', () => {
  it('offers for a Pro user with no companies once everything has settled', () => {
    expect(shouldAutoOfferInvoicingWizard(base())).toBe(true);
  });

  it('waits until the migration status resolves, even with empty companies', () => {
    // The company list has resolved to empty, but the migration probe is still
    // in flight - opening now could pre-empt a server-migration prompt.
    expect(shouldAutoOfferInvoicingWizard(base({ migrationGateReady: false }))).toBe(false);
  });

  it('does not offer while the company list is still loading', () => {
    expect(shouldAutoOfferInvoicingWizard(base({ loading: true }))).toBe(false);
  });

  it('does not offer on a load error', () => {
    expect(shouldAutoOfferInvoicingWizard(base({ loadError: true }))).toBe(false);
  });

  it('does not offer when the user already has companies', () => {
    expect(shouldAutoOfferInvoicingWizard(base({ companyCount: 2 }))).toBe(false);
  });

  it('does not offer without the business-invoicing entitlement', () => {
    expect(shouldAutoOfferInvoicingWizard(base({ canUse: false }))).toBe(false);
  });

  it('yields to a server-migration prompt', () => {
    expect(shouldAutoOfferInvoicingWizard(base({ showServerMigration: true }))).toBe(false);
  });

  it('does not offer during a relay sync', () => {
    expect(shouldAutoOfferInvoicingWizard(base({ isRelaySyncing: true }))).toBe(false);
  });
});
