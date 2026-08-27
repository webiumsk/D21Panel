---
title: Wallet connection overview
category: wallet-connection
order: 1
meta_description: The three ways to connect a wallet to your store - Lightning address, SamRock QR, and Advanced paste - and what Satflux stores.
---

# Wallet connection overview

Before your store can take payments it needs a wallet. Open **Wallet connection** on the store. Satflux never holds your funds - you connect **your own** wallet and all payments go straight to it.

You will find **Wallet connection** in the store, and the same setup runs during store creation.

## Three ways to connect

Satflux offers three tabs. Most people use the first.

1. **Lightning address** (default, easiest) - paste one Lightning address like `you@wallet.com` and Satflux figures out the rest. See [Connect with any Lightning address](/documentation/connect-with-any-lightning-address).
2. **SamRock (QR)** - the easiest way to connect **Aqua**: generate a one-time QR code and scan it in the Aqua app, which configures BTCPay for you. See [Connect Aqua with SamRock](/documentation/connect-aqua-with-samrock).
3. **Advanced (connection strings)** - one smart-paste box that auto-detects what you paste: a Blink connection string, an Aqua or **Bull Bitcoin** watch-only descriptor, an NWC pairing string, or any Lightning address. Satflux recognizes the format and sets the right type.

The built-in **wallet guide** in the app lists every supported wallet with step-by-step instructions.

## What Satflux stores

For a connection, Satflux keeps only what is needed to configure BTCPay, and any secret is **encrypted at rest**. When you view a connection later you see a **masked** value, and revealing it requires re-authentication. For SamRock/Aqua no private descriptor is stored in Satflux at all - your keys stay in Aqua; BTCPay only uses the watch-only descriptor. See [What we store and how it's protected](/documentation/what-we-store-and-how-its-protected).

## After you connect

Satflux tries to configure BTCPay automatically and marks the connection **connected**. If something needs attention it is flagged so support can help. Once the wallet is ready, the store unlocks and you can add Points of Sale, Pay Buttons, and Lightning addresses.

To change or disconnect later, see [Revoking or changing wallet credentials](/documentation/revoking-or-changing-wallet-credentials).
