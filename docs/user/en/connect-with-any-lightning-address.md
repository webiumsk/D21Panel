---
title: Connect with any Lightning address
category: wallet-connection
order: 2
meta_description: Point your store's Lightning backend at any you@wallet address - Blink, Blitz, Flash, Coinos, or any LUD-21 wallet.
---

# Connect with any Lightning address

The simplest way to connect a wallet is to paste a single **Lightning address** (`you@wallet.com`). Open **Wallet connection**, stay on the **Lightning address** tab, type your address, and connect. Satflux picks the right backend automatically from the address domain.

## What connects natively

- **Blink** (`you@blink.sv`) - non-custodial, receive-only. Recommended for a fast start.
- **Blitz** (`…@blitzwalletapp.com`), **Flash** (`…@flashapp.me`), **Coinos** (`…@coinos.io`) - connect natively.
- **Any wallet that supports LUD-21** payment verification - Satflux checks the address when you connect (a quick "probe") and, if the wallet supports LUD-21, connects it as a standard Lightning address.

## Other addresses - Cashu (beta)

If the address is not one of the above and does not support LUD-21, Satflux can still use it by **settling to that address through a Cashu mint (beta)**. You will see a short beta notice and a consent checkbox to confirm. See [Cashu Lightning settlement](/documentation/cashu-lightning-settlement) for how that works and its limits.

## Good to know

- You only need the address itself - no API key. (Blink also supports a custodial API-key connection, but the non-custodial `@blink.sv` address is the recommended way.)
- The wallet stays **yours**; Satflux only routes BTCPay's Lightning to it.
- Prefer a QR-based, fully non-custodial setup? Use **Aqua** via [SamRock](/documentation/connect-aqua-with-samrock) instead.
- Want a reusable receive address hosted on your own store (`you@your-store`)? That is a different feature - see [Store Lightning addresses](/documentation/store-lightning-addresses).
