import type { VatPolicyCompany } from './useCompanyVatPolicy';
import { isFullVatPayer } from './useCompanyVatPolicy';
import { resolvePeppolEndpoint } from '../utils/peppolEndpoint';

export type EfakturaInboundPollStats = {
  imported: number;
  acknowledged: number;
  skipped: number;
  failed: number;
};

export type CompanyEfakturaSettingsState = {
  efaktura_enabled: boolean;
  efaktura_auto_send: boolean;
  efaktura_inbound_enabled: boolean;
  efaktura_sapi_base_url: string;
  efaktura_peppol_participant_id: string;
  efaktura_sapi_client_id: string;
  efaktura_sapi_client_secret: string;
  efaktura_connection_tested_at: string | null;
  efaktura_inbound_last_poll_at: string | null;
  efaktura_inbound_last_poll_stats: EfakturaInboundPollStats | null;
};

function inboundPollStatsFromRaw(raw: Record<string, unknown>): EfakturaInboundPollStats | null {
  const stats = raw.efaktura_inbound_last_poll_stats;
  if (!stats || typeof stats !== 'object') {
    return null;
  }

  const row = stats as Record<string, unknown>;

  return {
    imported: Number(row.imported ?? 0),
    acknowledged: Number(row.acknowledged ?? 0),
    skipped: Number(row.skipped ?? 0),
    failed: Number(row.failed ?? 0),
  };
}

export function defaultEfakturaSettings(): CompanyEfakturaSettingsState {
  return {
    efaktura_enabled: false,
    efaktura_auto_send: false,
    efaktura_inbound_enabled: false,
    efaktura_sapi_base_url: '',
    efaktura_peppol_participant_id: '',
    efaktura_sapi_client_id: '',
    efaktura_sapi_client_secret: '',
    efaktura_connection_tested_at: null,
    efaktura_inbound_last_poll_at: null,
    efaktura_inbound_last_poll_stats: null,
  };
}

export function efakturaSettingsFromCompany(company: Record<string, unknown> | null): CompanyEfakturaSettingsState {
  const raw = (company?.app_settings ?? {}) as Record<string, unknown>;
  const base = defaultEfakturaSettings();

  return {
    ...base,
    efaktura_enabled: Boolean(raw.efaktura_enabled),
    efaktura_auto_send: Boolean(raw.efaktura_auto_send),
    efaktura_inbound_enabled: Boolean(raw.efaktura_inbound_enabled),
    efaktura_sapi_base_url: String(raw.efaktura_sapi_base_url ?? ''),
    efaktura_peppol_participant_id: String(raw.efaktura_peppol_participant_id ?? ''),
    efaktura_sapi_client_id: String(raw.efaktura_sapi_client_id ?? ''),
    efaktura_sapi_client_secret: '',
    efaktura_connection_tested_at: raw.efaktura_connection_tested_at
      ? String(raw.efaktura_connection_tested_at)
      : null,
    efaktura_inbound_last_poll_at: raw.efaktura_inbound_last_poll_at
      ? String(raw.efaktura_inbound_last_poll_at)
      : null,
    efaktura_inbound_last_poll_stats: inboundPollStatsFromRaw(raw),
  };
}

export function efakturaSecretIsSet(company: Record<string, unknown> | null): boolean {
  const raw = (company?.app_settings ?? {}) as Record<string, unknown>;
  if (raw.efaktura_sapi_client_secret_set === true) {
    return true;
  }

  const plain = raw.efaktura_sapi_client_secret;
  if (typeof plain === 'string' && plain !== '') {
    return true;
  }

  const encrypted = raw.efaktura_sapi_client_secret_encrypted;
  return typeof encrypted === 'string' && encrypted !== '';
}

export function isEfakturaConfigured(company: Record<string, unknown> | null): boolean {
  const settings = efakturaSettingsFromCompany(company);

  // Mirror of the server CompanyEfakturaSettings::configured(): an explicit
  // participant ID or one derivable from the company DIČ/IČO both count.
  const participantId =
    settings.efaktura_peppol_participant_id.trim() !== ''
    || resolvePeppolEndpoint({
      tax_id: company?.tax_id as string | null,
      registration_number: company?.registration_number as string | null,
      country: company?.country as string | null,
      jurisdiction: company?.jurisdiction as string | null,
    }) !== null;

  return (
    settings.efaktura_enabled
    && settings.efaktura_sapi_base_url.trim() !== ''
    && participantId
    && settings.efaktura_sapi_client_id.trim() !== ''
    && efakturaSecretIsSet(company)
  );
}

export function isSkDomesticContact(contact: Record<string, unknown> | null | undefined): boolean {
  const country = String(contact?.country ?? '').trim().toUpperCase();

  return country === 'SK' || country === 'SVK';
}

export function isCompanyEfakturaEligible(
  company: VatPolicyCompany | Record<string, unknown> | null,
  globallyEnabled = true,
): boolean {
  if (!globallyEnabled) {
    return false;
  }

  const row = company as VatPolicyCompany;
  if (row?.jurisdiction !== 'eu_sk') {
    return false;
  }

  return isFullVatPayer(row);
}

export type EfakturaSendability =
  | 'ok'
  | 'not_configured'
  | 'no_contact'
  | 'foreign_buyer'
  | 'missing_ids';

/**
 * Whether a document for this buyer would go out as an e-faktura, and if
 * not, the first reason why - drives the invoice-form indicator and the
 * send-panel preflight. Returns null when the company itself is not an
 * e-faktura company (non-SK, non-payer, or the module is globally off) -
 * the indicator should not render at all in that case.
 */
export function efakturaSendability(
  company: VatPolicyCompany | Record<string, unknown> | null,
  contact: Record<string, unknown> | null | undefined,
  globallyEnabled = true,
): EfakturaSendability | null {
  if (!isCompanyEfakturaEligible(company, globallyEnabled)) {
    return null;
  }

  if (!isEfakturaConfigured(company as Record<string, unknown>)) {
    return 'not_configured';
  }

  if (!contact) {
    return 'no_contact';
  }

  if (!isSkDomesticContact(contact)) {
    return 'foreign_buyer';
  }

  if (
    resolvePeppolEndpoint({
      peppol_participant_id: contact.peppol_participant_id as string | null,
      tax_id: contact.tax_id as string | null,
      registration_number: contact.registration_number as string | null,
      country: contact.country as string | null,
    }) === null
  ) {
    return 'missing_ids';
  }

  return 'ok';
}
