---
title: "Choosing your wallet: Blink vs Aqua"
category: getting-started
order: 3
meta_description: "When to use Blink (speed, reliability, read+receive API). When to use Aqua (Boltz, descriptor-based, non-custodial). No private keys or spend capability given to the platform."
---

**Choosing your wallet: Blink vs Aqua**

When you create a store in Satflux, you choose how it will receive Bitcoin and Lightning: **Blink** or **Aqua** (with the Boltz plugin). Both options work with BTCPay Server; the difference is how the wallet is connected and who controls the keys.

**Important: your keys, your coins**

With both Blink and Aqua, **no private keys and no spend capability are given to Satflux or BTCPay**. The platform never has the ability to move your funds. You connect your own wallet; invoices are created in BTCPay and payments are received by the wallet you control.

---

**When to use Blink**

Blink is a Lightning wallet service that you connect to BTCPay via a **read + receive** API: the server can create addresses and receive payments on your behalf, but it cannot spend. Connection is done with a connection string (server URL, API key, wallet ID) that you get from the Blink dashboard.

- **Speed and simplicity** — Setup is quick. Once you add the connection string in the store creation wizard or in Wallet connection, Lightning is ready after a short checklist (enable Lightning, test invoice).

- **Reliability** — Blink is built for merchant use and is widely used with BTCPay for in-person and online payments.

- **Good fit if** — You want to go live fast, prefer a managed Lightning backend, and are comfortable using Blink’s API for receive-only access (no keys or spend rights leave your control).

For step-by-step connection, see Wallet connection and the Blink setup steps in the store checklist.

---

**When to use Aqua**

Aqua is a non-custodial wallet (Liquid and Lightning). You connect it to BTCPay using the **Boltz** plugin and a **descriptor**: a watch-only output descriptor that describes your wallet’s addresses. The descriptor does not include private keys, so BTCPay (and Satflux) never see or hold anything that can spend your funds.

- **Non-custodial** — You hold the keys in Aqua. The platform only sees public/descriptor information and can create addresses and receive payments; it cannot spend.

- **Descriptor-based** — You export a descriptor from Aqua and paste it into the store wizard or Wallet connection. BTCPay uses it to derive addresses and work with Boltz for Lightning.

- **Good fit if** — You want full self-custody (your keys, your coins), are okay with a bit more setup (Boltz plugin, descriptor), and want Liquid + Lightning in one wallet.

For setup details, see Wallet connection and the Aqua/Boltz checklist after store creation.

---

**Quick comparison**

|  | Blink | Aqua (Boltz) |
|---|---|---|
| **Custody** | Blink holds keys; you use read+receive API | You hold keys in Aqua; descriptor is watch-only |
| **Setup** | Connection string from Blink dashboard | Descriptor from Aqua + Boltz plugin in BTCPay |
| **Strength** | Speed, reliability, minimal setup | Non-custodial, your keys, Liquid + Lightning |

In both cases, **no private keys or spend capability are given to the platform**. Choosing Blink or Aqua is about who holds the keys and how much setup you want, not about trusting Satflux or BTCPay with the ability to spend.
