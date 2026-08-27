---
title: Aqua & Bull Bitcoin descriptors
category: wallet-connection
order: 4
meta_description: Connect Aqua or Bull Bitcoin by pasting a watch-only output descriptor - fully non-custodial.
---

# Aqua & Bull Bitcoin descriptors

Both **Aqua** and **Bull Bitcoin** connect by pasting a **watch-only output descriptor**. This is fully non-custodial: the descriptor lets BTCPay watch for payments, but it holds **no private keys** - your keys stay in the wallet. Satflux rejects anything containing private keys.

For Aqua, the QR method is easier - see [Connect Aqua with SamRock](/documentation/connect-aqua-with-samrock). Bull Bitcoin uses the descriptor method described here.

## Where to get the descriptor

- **Aqua**: Settings → Boltz → export the watch-only output descriptor. It looks like `ct(slip77(...),elsh(wpkh(...)))`.
- **Bull Bitcoin**: wallet settings → export the watch-only descriptor. Bull's descriptor uses the `elwpkh(...)` format.

Satflux tells the two apart automatically from the descriptor shape.

## Steps

1. Open **Wallet connection** and choose the **Advanced (connection strings)** tab.
2. Paste the descriptor into the box. Satflux detects whether it is Aqua or Bull Bitcoin and validates the format.
3. Save. Satflux configures BTCPay and enables Lightning through the **Boltz** swap (Lightning over Liquid).

## Troubleshooting

- **"Contains a private key"** - you exported the wrong string. Export the **watch-only** descriptor, never a seed or `xprv`/`zprv`.
- **"Already in use"** - a descriptor can be connected to only one store. Disconnect it elsewhere first.
- **Wrong format** - Aqua descriptors are `elsh(wpkh(...))`, Bull are `elwpkh(...)`. Make sure you copied the whole string, unbroken.
- **Lightning not working yet** - descriptor connections enable Lightning via Boltz; check the Boltz readiness indicator on the store. It can take a moment to become ready.

To change or remove it later, see [Revoking or changing wallet credentials](/documentation/revoking-or-changing-wallet-credentials).
