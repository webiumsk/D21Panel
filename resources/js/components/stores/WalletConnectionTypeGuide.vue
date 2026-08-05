<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import WalletTypeIcon from '../WalletTypeIcon.vue';
import type { DetectedWalletKind } from '../../utils/detectWalletConnectionInput';
import type { AquaBoltzWalletBrand } from '../../utils/aquaBoltzWalletBrand';

const props = withDefaults(
  defineProps<{
    highlightKind?: DetectedWalletKind | null;
    highlightBrand?: AquaBoltzWalletBrand | null;
  }>(),
  {
    highlightKind: null,
    highlightBrand: null,
  },
);

const { t } = useI18n();

type GuideId = 'nwc' | 'blink' | 'blitz' | 'aqua' | 'bull' | 'cashu';

const openIds = ref<GuideId[]>([]);

// Order mirrors how we prioritize backends: Blink and Blitz first (LN address
// quick connect), then Aqua/Bull (Boltz), Cashu as the universal fallback, and
// NWC deliberately last - it needs a self-hosted Alby Hub, so almost nobody uses it.
const items: Array<{
  id: GuideId;
  titleKey: string;
  subtitleKey: string;
  iconType: 'nwc' | 'blink' | 'blitz' | 'aqua_boltz' | 'cashu';
  brand?: AquaBoltzWalletBrand;
}> = [
  {
    id: 'blink',
    titleKey: 'stores.wallet_detect_blink',
    subtitleKey: 'stores.wallet_guide_sub_blink',
    iconType: 'blink',
  },
  {
    id: 'blitz',
    titleKey: 'stores.wallet_detect_blitz',
    subtitleKey: 'stores.wallet_guide_sub_blitz',
    iconType: 'blitz',
  },
  {
    id: 'aqua',
    titleKey: 'stores.wallet_detect_aqua',
    subtitleKey: 'stores.wallet_guide_sub_aqua',
    iconType: 'aqua_boltz',
    brand: 'aqua',
  },
  {
    id: 'bull',
    titleKey: 'stores.wallet_detect_bull',
    subtitleKey: 'stores.wallet_guide_sub_bull',
    iconType: 'aqua_boltz',
    brand: 'bull',
  },
  {
    id: 'cashu',
    titleKey: 'stores.wallet_detect_cashu_ln',
    subtitleKey: 'stores.wallet_guide_sub_cashu',
    iconType: 'cashu',
  },
  {
    id: 'nwc',
    titleKey: 'stores.wallet_detect_nwc',
    subtitleKey: 'stores.wallet_guide_sub_nwc',
    iconType: 'nwc',
  },
];

function isOpen(id: GuideId): boolean {
  return openIds.value.includes(id);
}

function toggle(id: GuideId): void {
  if (isOpen(id)) {
    openIds.value = openIds.value.filter((item) => item !== id);
    return;
  }
  openIds.value = [...openIds.value, id];
}

function guideIdFromDetection(
  kind: DetectedWalletKind | null | undefined,
  brand: AquaBoltzWalletBrand | null | undefined,
): GuideId | null {
  if (!kind || kind === 'unknown') return null;
  if (kind === 'nwc') return 'nwc';
  if (kind === 'blink') return 'blink';
  if (kind === 'blitz') return 'blitz';
  if (kind === 'cashu') return 'cashu';
  if (kind === 'aqua_descriptor') {
    return brand === 'bull' ? 'bull' : 'aqua';
  }
  return null;
}

watch(
  () => [props.highlightKind, props.highlightBrand] as const,
  ([kind, brand]) => {
    const id = guideIdFromDetection(kind, brand);
    if (id && !isOpen(id)) {
      openIds.value = [...openIds.value, id];
    }
  },
);
</script>

<template>
  <div class="border-t border-gray-700/60 pt-6">
    <div class="mb-2">
      <h3 class="text-sm font-medium text-gray-400">
        {{ t('stores.wallet_guide_title') }}
        <span class="text-gray-600">- {{ t('stores.wallet_guide_intro') }}</span>
      </h3>
    </div>

    <div class="divide-y divide-gray-800">
      <div v-for="item in items" :key="item.id">
        <button
          type="button"
          class="w-full py-3 text-left flex items-center justify-between gap-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded-md group"
          :aria-expanded="isOpen(item.id)"
          @click="toggle(item.id)"
        >
          <span class="flex items-center gap-3 min-w-0">
            <span class="w-10 shrink-0 flex justify-center">
              <WalletTypeIcon
                v-if="item.iconType === 'aqua_boltz' || item.iconType === 'blink' || item.iconType === 'blitz'"
                :type="item.iconType"
                :brand="item.brand"
                size="sm"
              />
              <span
                v-else
                class="text-[10px] font-bold uppercase tracking-wide text-gray-500"
              >{{ item.id === 'nwc' ? 'NWC' : 'LN' }}</span>
            </span>
            <span class="min-w-0">
              <span
                class="block text-sm truncate transition-colors"
                :class="isOpen(item.id) ? 'text-white font-medium' : 'text-gray-300 group-hover:text-white'"
              >
                {{ t(item.titleKey) }}
              </span>
              <span class="block text-xs text-gray-500 truncate">
                {{ t(item.subtitleKey) }}
              </span>
            </span>
          </span>
          <svg
            class="w-4 h-4 text-gray-600 shrink-0 transition-transform"
            :class="isOpen(item.id) ? 'rotate-180 text-gray-400' : ''"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M19 9l-7 7-7-7"
            />
          </svg>
        </button>

        <div
          v-show="isOpen(item.id)"
          class="pb-4 pl-1 text-sm text-gray-300 space-y-3 leading-relaxed"
        >
          <!-- NWC -->
          <template v-if="item.id === 'nwc'">
            <p class="font-medium text-amber-200/95">{{ t('stores.wallet_guide_nwc_alby_requirement') }}</p>
            <p>{{ t('stores.nwc_connection_hint') }}</p>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_where_label') }}
              </p>
              <p>{{ t('stores.wallet_guide_nwc_alby') }}</p>
              <p class="mt-2">
                <a
                  href="https://guides.getalby.com/user-guide/alby-hub/app-connections"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-indigo-400 hover:text-indigo-300 underline font-medium"
                >
                  {{ t('stores.wallet_guide_nwc_alby_link') }}
                </a>
              </p>
              <p class="mt-2 text-gray-400">{{ t('stores.wallet_guide_nwc_phoenix_note') }}</p>
              <p class="mt-2">{{ t('stores.wallet_guide_nwc_other') }}</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_paste_label') }}
              </p>
              <p class="font-mono text-xs text-indigo-200/90 break-all">
                nostr+walletconnect://…?relay=…&amp;secret=…
              </p>
            </div>
          </template>

          <!-- Blink -->
          <template v-else-if="item.id === 'blink'">
            <p>{{ t('stores.blink_connection_description') }}</p>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_where_label') }}
              </p>
              <p>{{ t('stores.wallet_guide_blink_where') }}</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_paste_label') }}
              </p>
              <p>{{ t('create_store.connection_string_format') }}</p>
              <p class="mt-1">{{ t('create_store.connection_string_help') }}</p>
            </div>
            <p class="text-amber-300/95">{{ t('stores.blink_keys_warning') }}</p>
            <p class="text-amber-300/90">{{ t('stores.blink_eu_legacy_notice') }}</p>
            <p>
              <a
                href="https://dashboard.blink.sv"
                target="_blank"
                rel="noopener noreferrer"
                class="text-indigo-400 hover:text-indigo-300 underline font-medium"
              >
                {{ t('stores.blink_dashboard_link') }}
              </a>
            </p>
          </template>

          <!-- Blitz -->
          <template v-else-if="item.id === 'blitz'">
            <p>{{ t('stores.blitz_connection_description') }}</p>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_paste_label') }}
              </p>
              <p>{{ t('stores.wallet_guide_blitz_paste') }}</p>
            </div>
            <p class="text-amber-300/90">{{ t('stores.blitz_receive_only_note') }}</p>
          </template>

          <!-- Aqua -->
          <template v-else-if="item.id === 'aqua'">
            <p>{{ t('stores.aqua_connection_description') }}</p>
            <p>{{ t('stores.wallet_guide_aqua_samrock') }}</p>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_where_label') }}
              </p>
              <p>{{ t('stores.wallet_guide_aqua_where') }}</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_paste_label') }}
              </p>
              <p>{{ t('create_store.descriptor_help') }}</p>
              <p class="mt-1 font-mono text-xs text-gray-400 break-all">
                {{ t('create_store.descriptor_example') }}
              </p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_notes_label') }}
              </p>
              <p class="text-amber-300/95">{{ t('stores.aqua_warning_btcpay') }}</p>
              <p class="mt-1 text-amber-300/90">
                {{ t('stores.aqua_limits_warning') }}
              </p>
            </div>
          </template>

          <!-- Bull -->
          <template v-else-if="item.id === 'bull'">
            <p>{{ t('stores.wallet_guide_bull_intro') }}</p>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_where_label') }}
              </p>
              <p>{{ t('stores.wallet_guide_bull_where') }}</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_paste_label') }}
              </p>
              <p>{{ t('create_store.descriptor_help') }}</p>
              <p class="mt-1 font-mono text-xs text-gray-400 break-all">
                ct(slip77(...),elwpkh(...))
              </p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_notes_label') }}
              </p>
              <p class="text-amber-300/95">{{ t('stores.aqua_warning_btcpay') }}</p>
              <p class="mt-1 text-amber-300/90">
                {{ t('stores.aqua_limits_warning') }}
              </p>
            </div>
          </template>

          <!-- Cashu / LN Address -->
          <template v-else-if="item.id === 'cashu'">
            <p>{{ t('stores.cashu_description') }}</p>
            <p class="text-red-200/95">{{ t('stores.wallet_guide_minibits_cashu') }}</p>
            <p>{{ t('stores.wallet_guide_cashu_paste') }}</p>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_where_label') }}
              </p>
              <p>{{ t('stores.wallet_guide_cashu_where') }}</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                {{ t('stores.wallet_guide_paste_label') }}
              </p>
              <p>{{ t('stores.cashu_lightning_address_hint') }}</p>
              <p class="mt-1 whitespace-pre-line text-gray-400">
                {{ t('stores.cashu_mint_url_hint') }}
              </p>
            </div>
            <p class="text-amber-200/95">{{ t('stores.cashu_beta_notice_short') }}</p>
            <p>
              <span>{{ t('stores.cashu_lightning_address_coinos_prefix') }}</span>
              <a
                href="https://coinos.io"
                target="_blank"
                rel="noopener noreferrer"
                class="text-indigo-400 hover:text-indigo-300 underline font-medium"
              >coinos.io</a>
            </p>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
