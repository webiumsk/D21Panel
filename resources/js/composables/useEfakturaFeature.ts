import { computed, ref } from 'vue';
import api from '../services/api';

export type EfakturaCpdsPreset = {
  id: string;
  name: string;
  base_url: string;
};

type EfakturaConfig = {
  enabled: boolean;
  presets: EfakturaCpdsPreset[];
  mandatoryFrom: string;
};

const cachedConfig = ref<EfakturaConfig | null>(null);
let fetchPromise: Promise<boolean> | null = null;

function presetsFromRaw(raw: unknown): EfakturaCpdsPreset[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .filter((row): row is Record<string, unknown> => !!row && typeof row === 'object')
    .map((row) => ({
      id: String(row.id ?? ''),
      name: String(row.name ?? ''),
      base_url: String(row.base_url ?? ''),
    }))
    .filter((preset) => preset.name !== '' && preset.base_url !== '');
}

/**
 * Global SK e-faktura module switch from GET /api/config (EFAKTURA_ENABLED),
 * plus the admin-maintained CPDS presets and the statutory mandatory-from
 * date for the settings wizard and readiness messaging.
 */
export function useEfakturaFeature() {
  const enabled = computed(() => cachedConfig.value?.enabled === true);
  const loaded = computed(() => cachedConfig.value !== null);
  const cpdsPresets = computed(() => cachedConfig.value?.presets ?? []);
  const mandatoryFrom = computed(() => cachedConfig.value?.mandatoryFrom ?? '');

  async function load(): Promise<boolean> {
    if (cachedConfig.value !== null) {
      return cachedConfig.value.enabled;
    }

    if (fetchPromise) {
      return fetchPromise;
    }

    fetchPromise = (async () => {
      try {
        const { data } = await api.get<{
          efaktura_enabled?: boolean;
          efaktura_cpds_presets?: unknown;
          efaktura_mandatory_from?: string;
        }>('/config');
        cachedConfig.value = {
          enabled: Boolean(data.efaktura_enabled),
          presets: presetsFromRaw(data.efaktura_cpds_presets),
          mandatoryFrom: String(data.efaktura_mandatory_from ?? ''),
        };
        return cachedConfig.value.enabled;
      } catch {
        // Leave the cache unset so a later load() can retry - a transient
        // /config failure must not pin the module off for the session.
        return false;
      } finally {
        fetchPromise = null;
      }
    })();

    return fetchPromise;
  }

  return { enabled, loaded, load, cpdsPresets, mandatoryFrom };
}
