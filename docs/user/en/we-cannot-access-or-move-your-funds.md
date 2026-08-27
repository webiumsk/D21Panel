---
title: "We cannot access or move your funds"
category: security
order: 1
meta_description: "Plain-language guarantee: Blink = read+receive only, no send; Aqua = watch-only descriptor, no private keys. We never have spend authority. Funds stay in your wallet."
---

Your funds stay in your wallet. Satflux and BTCPay never have the ability to spend, withdraw, or move them.

**Blink: read and receive only, no send**

When you connect Blink, you use an API key with **read** and **receive** permissions only. We do not use - and you must not grant - **send** or **withdraw**.

- We **can**: create addresses and Lightning invoices, and receive payments into your Blink wallet.

- We **cannot**: send, withdraw, or move any of your funds.

So we never have spend authority. All received funds stay in the wallet you control in Blink.

**Aqua: watch-only descriptor, no private keys**

When you connect Aqua, you give us a **watch-only output descriptor**. It contains only public information (e.g. extended public keys). It does **not** contain private keys.

- We **can**: derive addresses and receive payments into the wallet that you control in Aqua.

- We **cannot**: spend, sign transactions, or move funds - we never see or store private keys.

So we never have spend authority. All received funds stay in the wallet you control in Aqua.

**Bull Bitcoin: watch-only descriptor, no private keys**

Bull Bitcoin connects the same way as Aqua - with a **watch-only output descriptor** that contains only public keys. We can derive addresses and receive payments; we can never spend, sign, or move funds.

**Summary**

- **Blink** - Read + receive only; no send. Funds stay in your Blink wallet.

- **Aqua / Bull Bitcoin** - Watch-only descriptor; no private keys. Funds stay in your wallet.

- **NWC / your own node** - You create the connection to your own wallet and choose its permissions; grant only what receiving needs.

We never have spend authority. Your funds stay in your wallet.
