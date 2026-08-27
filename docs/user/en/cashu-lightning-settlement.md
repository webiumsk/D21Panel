---
title: Cashu Lightning settlement (CashuMelt)
category: payments
order: 3
meta_description: A Cashu mint used only as a Lightning settlement bridge - your invoices stay Lightning; the CashuMelt plugin pays out sats to your Lightning address.
---

# Cashu Lightning settlement (CashuMelt)

Satflux does **not** issue Cashu invoices, and customers **never pay with ecash tokens**. Your store's invoices are **Lightning**, like any other. **Cashu (through the BTCPay CashuMelt plugin) is only a settlement bridge**: it uses a Cashu mint behind the scenes to convert the received sats and pay them out to a **Lightning address you choose**.

In short: **Lightning in → mint converts → sats out to your Lightning address.** The mint is plumbing, not a customer-facing payment method.

> **Beta.** This path is experimental. An invoice may show as paid before the settled funds reach your Lightning wallet; a mint outage or routing issue can cause delay or loss. You must tick a consent checkbox before enabling it.

## Set it up

Open the store's **Wallet connection** and the **Cashu** section. Confirm the beta notice, then fill in:

- **Mint URL** - the Cashu mint used for the conversion (must be `https://`). A default mint is suggested.
- **Lightning Address** - where the settled sats are paid out. **Required.**
- **Invoice unit** - `sat` or `usd` (how amounts are interpreted).
- **Settle payments via Cashu → Lightning** - keep it on so the store uses this settlement path.
- Optional: **trusted mints**, and **fee-reserve caps** (a max in sats and/or as a % of the amount) that bound how much Lightning routing fee the payout may spend.

Editing an existing Cashu store re-asks for your password (unless you sign in by recovery phrase or passkey).

## Two ways it is used

- **Primary** - the store settles through the mint to your Lightning address, without a separate Lightning wallet connection.
- **Automatic fallback** - when you connect a Lightning wallet, CashuMelt can stay enabled in parallel with your Lightning address, so settlement still works if the main Lightning path is temporarily down. Switching to Blink/Aqua turns the fallback back off.

## Watching settlements

A **Cashu settlements** page lists each settlement and its state - `SETTLED`, `PENDING`, `FAILED`, or `MELT_COMPLETE` (converted, BTCPay's record catching up). You can **retry** stuck rows. Common failure reasons are shown in plain language (e.g. the routing fee exceeded your cap, or the mint did not confirm the Lightning payout).

## Good to know

- The store's BTCPay needs the **CashuMelt** plugin (a recent version). If you see a plugin error, update it.
- Funds settle to your Lightning address, but they pass **through the mint** during conversion - a brief trust point - so only use a mint you trust.
