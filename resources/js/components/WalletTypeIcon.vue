<template>
  <component
    :is="tag"
    v-if="iconSrc"
    class="inline-flex items-center gap-1.5"
    :class="sizeClass"
  >
    <img
      :src="iconSrc"
      :alt="altText"
      :title="altText"
      class="object-contain flex-shrink-0"
      :class="imgSizeClass"
    />
    <span v-if="showLabel" class="font-medium" :class="labelClass">
      {{ labelText }}
    </span>
  </component>
  <span
    v-else-if="type && !iconSrc"
    class="inline-flex items-center font-semibold text-inherit"
    :class="[sizeClass, type === 'cashu' ? 'text-emerald-400/95' : '']"
  >
    {{ labelText }}
  </span>
  <span v-else-if="fallbackText" class="font-medium text-gray-500" :class="sizeClass">
    {{ fallbackText }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AquaBoltzWalletBrand } from '../utils/aquaBoltzWalletBrand';
import type { LnAddressWalletBrand } from '../utils/lnAddressWalletBrands';

const props = withDefaults(
  defineProps<{
    /** store.wallet_type or connection.type */
    type: 'blink' | 'blitz' | 'flash' | 'lnaddress' | 'aqua_boltz' | 'cashu' | 'nwc' | 'aqua_descriptor' | null | undefined;
    /** aqua_boltz / aqua_descriptor: aqua|bull; lnaddress: blitz|flash|coinos */
    brand?: AquaBoltzWalletBrand | LnAddressWalletBrand | null | undefined;
    size?: 'sm' | 'md' | 'lg';
    showLabel?: boolean;
    fallbackText?: string;
    tag?: string;
  }>(),
  {
    size: 'md',
    showLabel: false,
    tag: 'span',
  }
);

const { t } = useI18n();

const resolvedBrand = computed((): AquaBoltzWalletBrand => {
  if (props.brand === 'bull' || props.brand === 'aqua') {
    return props.brand;
  }
  return 'aqua';
});

/** lnaddress connections carry the curated wallet brand (blitz/flash/coinos) or none. */
const lnAddressBrand = computed((): LnAddressWalletBrand | null => {
  if (props.brand === 'blitz' || props.brand === 'flash' || props.brand === 'coinos') {
    return props.brand;
  }
  return null;
});

const iconSrc = computed(() => {
  if (!props.type) return null;
  if (props.type === 'blink') return '/img/wallets/blink-64.webp';
  if (props.type === 'aqua_boltz' || props.type === 'aqua_descriptor') {
    return resolvedBrand.value === 'bull'
      ? '/img/wallets/bull-64.webp'
      : '/img/wallets/aqua-64.webp';
  }
  if (props.type === 'blitz') return '/img/wallets/blitz-64.webp';
  if (props.type === 'flash') return '/img/wallets/flash-64.webp';
  if (props.type === 'lnaddress') {
    if (lnAddressBrand.value === 'blitz') return '/img/wallets/blitz-64.webp';
    if (lnAddressBrand.value === 'flash') return '/img/wallets/flash-64.webp';
    if (lnAddressBrand.value === 'coinos') return '/img/wallets/coinos-64.webp';
    return null;
  }
  if (props.type === 'cashu') return null;
  if (props.type === 'nwc') return null;
  return null;
});

function walletTypeLabel(): string {
  if (!props.type) return props.fallbackText ?? '';
  if (props.type === 'nwc') return t('create_store.wallet_type_nwc');
  if (props.type === 'blink') return t('create_store.wallet_type_blink');
  if (props.type === 'blitz') return t('create_store.wallet_type_blitz');
  if (props.type === 'flash') return t('create_store.wallet_type_flash');
  if (props.type === 'lnaddress') {
    if (lnAddressBrand.value === 'blitz') return t('create_store.wallet_type_blitz');
    if (lnAddressBrand.value === 'flash') return t('create_store.wallet_type_flash');
    if (lnAddressBrand.value === 'coinos') return t('create_store.wallet_type_coinos');
    return t('create_store.wallet_type_lnaddress');
  }
  if (props.type === 'cashu') return t('create_store.wallet_type_cashu');
  if (resolvedBrand.value === 'bull') return t('create_store.wallet_type_bull');
  return t('create_store.wallet_type_aqua');
}

const altText = computed(() => walletTypeLabel());

const labelText = computed(() => walletTypeLabel());

// All wallet logos are wide wordmarks - render them at a uniform w-10 regardless of size.
const imgSizeClass = computed(() => 'w-10 h-auto');

const sizeClass = computed(() => {
  switch (props.size) {
    case 'sm': return 'text-sm';
    case 'lg': return 'text-base';
    default: return 'text-sm';
  }
});

const labelClass = computed(() => {
  return props.tag === 'span' ? 'text-inherit' : '';
});
</script>
