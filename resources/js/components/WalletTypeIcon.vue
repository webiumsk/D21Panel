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

const props = withDefaults(
  defineProps<{
    /** store.wallet_type or connection.type */
    type: 'blink' | 'blitz' | 'aqua_boltz' | 'cashu' | 'nwc' | 'aqua_descriptor' | null | undefined;
    /** When type is aqua_boltz / aqua_descriptor: which wallet logo to show */
    brand?: AquaBoltzWalletBrand | null | undefined;
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

const iconSrc = computed(() => {
  if (!props.type) return null;
  if (props.type === 'blink') return '/img/wallets/blink-64.webp';
  if (props.type === 'aqua_boltz' || props.type === 'aqua_descriptor') {
    return resolvedBrand.value === 'bull'
      ? '/img/wallets/bull-64.webp'
      : '/img/wallets/aqua-64.webp';
  }
  if (props.type === 'blitz') return null;
  if (props.type === 'cashu') return null;
  if (props.type === 'nwc') return null;
  return null;
});

function walletTypeLabel(): string {
  if (!props.type) return props.fallbackText ?? '';
  if (props.type === 'nwc') return t('create_store.wallet_type_nwc');
  if (props.type === 'blink') return t('create_store.wallet_type_blink');
  if (props.type === 'blitz') return t('create_store.wallet_type_blitz');
  if (props.type === 'cashu') return t('create_store.wallet_type_cashu');
  if (resolvedBrand.value === 'bull') return t('create_store.wallet_type_bull');
  return t('create_store.wallet_type_aqua');
}

const altText = computed(() => walletTypeLabel());

const labelText = computed(() => walletTypeLabel());

const imgSizeClass = computed(() => {
  const isBull =
    (props.type === 'aqua_boltz' || props.type === 'aqua_descriptor') &&
    resolvedBrand.value === 'bull';
  if (isBull) {
    switch (props.size) {
      case 'sm': return 'h-4 w-auto max-w-[4.5rem]';
      case 'lg': return 'h-6 w-auto max-w-[5.5rem]';
      default: return 'h-5 w-auto max-w-[5rem]';
    }
  }
  switch (props.size) {
    case 'sm': return 'w-5 h-5';
    case 'lg': return 'w-10 h-10';
    default: return 'w-6 h-6';
  }
});

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
