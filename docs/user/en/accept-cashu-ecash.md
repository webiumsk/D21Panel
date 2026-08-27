---
title: Accept Cashu ecash (CashuMelt)
category: payments
order: 3
meta_description: Let customers pay with Cashu ecash - the CashuMelt plugin melts it to Lightning and pays out to your Lightning address.
---

# Accept Cashu ecash (CashuMelt)

Satflux can accept **Cashu ecash** at checkout through the BTCPay **CashuMelt** plugin. A customer pays with ecash minted at a mint you trust; the plugin then **melts** that ecash to Lightning and pays it out to **your Lightning address**.

> **Beta.** Cashu support is experimental. An invoice may show as paid before the melted funds reach your Lightning wallet. You must tick a consent checkbox before enabling it.

## Set it up

Open the store's **Wallet connection** and the **Cashu** section. Confirm the beta notice, then fill in:

- **Mint URL** - the Cashu mint to accept ecash from (must be `https://`). A default mint is suggested.
- **Lightning Address** - where melted funds are paid out. **Required.**
- **Offer Cashu at checkout** - turn it on.
- **Invoice unit** - `sat` or `usd`.
- Optional: **trusted mints**, and **fee-reserve caps** (a max in sats and/or as a % of the amount) that bound how much Lightning routing fee the melt may spend.

Saving Cashu as your store's method removes the Lightning option at checkout so BTCPay offers only Cashu. Editing an existing Cashu store re-asks for your password (unless you sign in by recovery phrase or passkey).

## Two ways Cashu is used

- **Primary method** - your store is a Cashu store (mint + Lightning address, no Lightning checkout).
- **Automatic fallback** - when you connect a Lightning wallet, CashuMelt can stay enabled in parallel with your Lightning address, so Cashu remains available if the Lightning swap path is temporarily down. Switching to Blink/Aqua turns the fallback back off.

## Watching settlements

A **Cashu settlements** page lists each payment and its state - `SETTLED`, `PENDING`, `FAILED`, or `MELT_COMPLETE` (melt done, BTCPay's record catching up). You can **retry** stuck rows. Common failure reasons are shown in plain language (e.g. routing fee exceeded your cap, or the mint did not confirm the Lightning payment).

## Requirements

- The store's BTCPay needs the **CashuMelt** plugin (a recent version). If you see a plugin error, update it.
- Because Cashu is a mint-backed system, only accept mints you trust.
