---
title: Connect with NWC (Nostr Wallet Connect)
category: wallet-connection
order: 6
meta_description: Connect your own Lightning node via a Nostr Wallet Connect pairing string, e.g. from Alby Hub.
---

# Connect with NWC (Nostr Wallet Connect)

**NWC** (Nostr Wallet Connect) lets you connect your own Lightning wallet or node through a pairing string - for example from a self-hosted **Alby Hub**. It is for advanced, self-hosting users.

## What you need

- A wallet that issues NWC connections (e.g. your own **Alby Hub**).
- An NWC pairing string that looks like:

```text
nostr+walletconnect://…?relay=…&secret=…
```

## Steps

1. In your NWC wallet (e.g. Alby Hub → Connections → Add Connection), create a connection and copy the `nostr+walletconnect://…` string.
2. In Satflux, open **Wallet connection → Advanced** and paste the string. Satflux detects it as NWC.
3. Save.

## Notes

- The server must have the **BTCPay Nostr plugin** available for NWC connections to work.
- NWC pairing strings from **Cashu ecash wallets** (e.g. Minibits) are not accepted here - those route through [Cashu](/documentation/cashu-lightning-settlement) instead.
- Most merchants do not need NWC. If you just want to receive Lightning quickly, use [any Lightning address](/documentation/connect-with-any-lightning-address) or [Aqua via SamRock](/documentation/connect-aqua-with-samrock).
