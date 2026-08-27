---
title: "Creating your first store"
category: getting-started
order: 2
meta_description: "End-to-end: create store (name, currency, timezone, price source) → choose wallet type (Blink or Aqua) → confirm. Link to “Choosing your wallet” and “Wallet connection” for details."
---

You create a new store in Satflux in three steps: basic info, wallet type, and confirmation. The store is then created in BTCPay Server and a short onboarding checklist is shown so you can finish wallet setup and test a payment.

**Step 1 — Basic info**

- **Store name** — A label for your store (e.g. “Main shop”, “Online store”).

- **Default currency** — The currency used for prices and amounts (e.g. EUR, USD, BTC, SATS).

- **Timezone** — Used for invoices and reports.

- **Preferred price source** — The exchange or source used to convert fiat to BTC (e.g. for displaying amounts). A sensible default is chosen based on your default currency; you can change it here or later in store settings.

Click **Next step** when all fields are filled.

**Step 2 — Choose wallet type**

Choose how this store will receive Lightning (and on-chain) payments:

- **Blink** — Fast setup, good for getting started quickly. You will connect your Blink wallet (e.g. via connection string) in this step or later. See Choosing your wallet for a comparison of Blink and Aqua.

- **Aqua (Aqua + Boltz)** — Non-custodial: your keys, Liquid + Lightning. Uses the Boltz plugin in BTCPay. Setup can be done in this step or completed later. See Choosing your wallet and Wallet connection for details.

You can optionally enter your **connection string** (Blink) or **descriptor** (Aqua) in this step. If you skip it, you complete the connection later in **Wallet connection** and follow the wallet onboarding checklist after the store is created.

Click **Back** to change basic info, or **Next step** to continue.

**Step 3 — Confirm**

Review store name, currency, timezone, price source, and wallet type. Click **Create store** to create the store in BTCPay. You are then redirected to the “Next steps” page with a link to the **wallet onboarding checklist** (e.g. connect wallet, enable Lightning, test an invoice).

**After creation**

- Finish wallet setup and Lightning in **Wallet connection** if you did not do it in step 2. See Wallet connection for step-by-step instructions.

- Create a **Point of Sale (PoS)** app and, if you want, a **Lightning Address** from the store dashboard.
