---
title: "Aqua: getting your output descriptor"
category: wallet-connection
order: 4
meta_description: "Step-by-step: Aqua wallet + Boltz plugin → export output descriptor (watch-only). Clarify: no private keys, only public/descriptor. Where to paste it in D21 Panel. One descriptor per store (BTCPay limitation)."
---

To connect your Aqua wallet to a store in Satflux, you need a **watch-only output descriptor**. It describes your wallet’s addresses using public data only — no private keys. BTCPay uses this with the **Boltz** plugin so the store can receive Bitcoin and Lightning payments. You keep full control: we can create addresses and receive funds, but we **cannot** spend.

---

**Step 1 — Aqua wallet and Boltz plugin**

1. Use **Aqua** (Liquid + Lightning wallet) and ensure it’s set up.
2. Export an **output descriptor** from Aqua. In Aqua, this is typically under Settings or Export. Choose the **watch-only** / **public descriptor** option. Do **not** export anything that includes private keys.

---

**Step 2 — What you export: public / descriptor only, no private keys**

The descriptor is a string that describes how addresses are derived from your wallet’s **extended public keys** (xpub, ypub, zpub). It contains:

- **Public information only** — Extended public keys, derivation paths, descriptor functions (e.g. wpkh, ct, slip77, elsh).

- **No private keys** — No xprv, yprv, zprv, prv, or similar. If your export includes private keys, do **not** use it. Use the watch-only/public descriptor instead.

Example shape (simplified):

ct(slip77(...),elsh(wpkh(xpub6...)))

Your real descriptor will be longer and include your xpub. Paste the full string as exported from Aqua.

---

**Step 3 — Where to paste the descriptor in the Satflux panel**

You can add it in either place:

- **When creating the store** — In the store creation wizard, **Step 2 (Wallet type)**:

- Choose **Aqua Wallet** (Aqua + Boltz).

- In the **Descriptor** field that appears, paste the full descriptor (one line, no extra spaces).

- Continue to Step 3 and create the store.

- **After the store exists** — Open the store, then go to **Wallet connection** (LN Wallet Connection) in the store sidebar. Paste the descriptor in the form and save.

The value is stored **encrypted**. It is used only so BTCPay can derive addresses and receive payments; we never see or store private keys.

---

**BTCPay limitation: one descriptor per use**

BTCPay allows each output descriptor to be used **only once** per BTCPay instance. If you try to use the same descriptor for another store (or another BTCPay setup), you will get an error such as:

- *"This descriptor is already in use"*

- *"BTCPay allows each descriptor to be used only once. Please use a different wallet/descriptor."*

**What this means:**

- Each Satflux store needs its **own** descriptor.

- If you run multiple stores with Aqua, use a **different Aqua wallet** (and thus a different descriptor) for each store, or create additional descriptors from Aqua if it supports multiple exportable descriptors.

- You cannot reuse the same descriptor across stores on the same BTCPay server.

For more on Aqua and Boltz, see the Aqua/Boltz descriptor guide in the app (e.g. under Documentation). For Blink vs Aqua, see Choosing your wallet. For where Wallet connection lives, see Wallet connection overview.
