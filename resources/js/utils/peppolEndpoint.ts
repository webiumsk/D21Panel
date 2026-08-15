/**
 * Client-side mirror of SkUblProfile::resolveEndpoint (PHP) - the single
 * source of truth for how a Peppol participant ID derives from company or
 * contact registration data. Keep the ordering in sync with the server:
 * explicit ID > SK DIČ (0245) > IČO (0208); non-SK entities always 0208.
 */

export const PEPPOL_SCHEME_ICO = '0208';
export const PEPPOL_SCHEME_DIC = '0245';

export type PeppolEntity = {
  peppol_participant_id?: string | null;
  tax_id?: string | null;
  registration_number?: string | null;
  country?: string | null;
  /** Companies only - country fallback (eu_sk -> SK, eu_cz -> CZ). */
  jurisdiction?: string | null;
};

export type PeppolEndpoint = { scheme: string; id: string };

function digitsOnly(value: string | null | undefined): string | null {
  if (!value) return null;
  const digits = String(value).replace(/\D/g, '');
  return digits !== '' ? digits : null;
}

function parseParticipantId(value: string): PeppolEndpoint | null {
  const trimmed = value.trim();
  if (trimmed === '') return null;

  if (trimmed.includes(':')) {
    const [schemeRaw, idRaw] = trimmed.split(/:(.*)/s);
    const scheme = schemeRaw.trim();
    const id = digitsOnly(idRaw);
    if (scheme !== '' && id !== null) {
      return { scheme, id };
    }
  }

  const digits = digitsOnly(trimmed);
  return digits !== null ? { scheme: PEPPOL_SCHEME_DIC, id: digits } : null;
}

function countryCode(entity: PeppolEntity): string {
  const raw = String(entity.country ?? '').trim().toUpperCase();
  if (raw.length === 2) return raw;

  if (entity.jurisdiction !== undefined && entity.jurisdiction !== null) {
    if (entity.jurisdiction === 'eu_sk') return 'SK';
    if (entity.jurisdiction === 'eu_cz') return 'CZ';
    return 'EU';
  }

  return 'SK';
}

export function resolvePeppolEndpoint(entity: PeppolEntity | null | undefined): PeppolEndpoint | null {
  if (!entity) return null;

  if (entity.peppol_participant_id) {
    return parseParticipantId(String(entity.peppol_participant_id));
  }

  if (countryCode(entity) !== 'SK') {
    const id = digitsOnly(entity.registration_number) ?? digitsOnly(entity.tax_id);
    return id !== null ? { scheme: PEPPOL_SCHEME_ICO, id } : null;
  }

  const dic = digitsOnly(entity.tax_id);
  if (dic !== null) {
    return { scheme: PEPPOL_SCHEME_DIC, id: dic };
  }

  const ico = digitsOnly(entity.registration_number);
  return ico !== null ? { scheme: PEPPOL_SCHEME_ICO, id: ico } : null;
}

export function formatPeppolEndpoint(endpoint: PeppolEndpoint | null): string | null {
  return endpoint ? `${endpoint.scheme}:${endpoint.id}` : null;
}
