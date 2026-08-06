import type { AquaBoltzWalletBrand } from './aquaBoltzWalletBrand';
import type { LnAddressWalletBrand } from './lnAddressWalletBrands';

export type StoreWalletBrand = AquaBoltzWalletBrand | LnAddressWalletBrand;

export function resolveStoreWalletBrand(store: {
  wallet_type?: string | null;
  wallet_brand?: StoreWalletBrand | null;
  wallet_connection?: { brand?: StoreWalletBrand | null } | null;
}): StoreWalletBrand | undefined {
  if (store.wallet_type !== 'aqua_boltz' && store.wallet_type !== 'lnaddress') {
    return undefined;
  }

  return store.wallet_brand ?? store.wallet_connection?.brand ?? undefined;
}
