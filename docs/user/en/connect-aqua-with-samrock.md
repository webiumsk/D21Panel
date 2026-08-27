---
title: Connect Aqua with SamRock
category: wallet-connection
order: 3
meta_description: The easiest, fully non-custodial way to connect the Aqua wallet - scan a one-time QR code.
---

# Connect Aqua with SamRock

**SamRock** is the easiest way to connect the **Aqua** wallet, and it is fully non-custodial - your keys stay in Aqua. Instead of exporting and pasting a descriptor, you scan a one-time QR code and the Aqua app configures BTCPay for you.

## Steps

1. Open **Wallet connection** and choose the **SamRock (QR)** tab.
2. Enter a **fallback Lightning address**. This is kept as a Cashu payout fallback so payments still land if the Lightning swap path is temporarily down.
3. Click **Generate QR**. Satflux shows a one-time code (valid for a few minutes).
4. In the **Aqua** app, scan the QR. Aqua configures Bitcoin, Lightning (via Boltz), and optionally Liquid on your BTCPay store.
5. Satflux detects when the pairing succeeds and finishes the connection.

That's it - no descriptor to copy. Satflux stores no private descriptor for a SamRock connection; your keys stay in Aqua; BTCPay only uses the watch-only descriptor.

## Notes

- **Bull Bitcoin** does not use SamRock - connect it by pasting its watch-only descriptor. See [Aqua & Bull Bitcoin descriptors](/documentation/aqua-and-bull-descriptor).
- SamRock configures Lightning through the **Boltz** swap (Lightning over Liquid). Satflux shows a Boltz readiness indicator so you can confirm it is working.
- If your store is currently on Cashu only, switch it to a Lightning setup first before using SamRock.

## Prefer to paste a descriptor?

You can still connect Aqua the manual way with a watch-only output descriptor - see [Aqua & Bull Bitcoin descriptors](/documentation/aqua-and-bull-descriptor).
