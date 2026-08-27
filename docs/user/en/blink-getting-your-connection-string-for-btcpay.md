---
title: "Blink: getting your connection string for BTCPay"
category: wallet-connection
order: 2
meta_description: "Step-by-step: open Blink → create or use an API key with read and receive only (no send). Format: type=blink;server=...;api-key=...;wallet-id=.... Where to paste it in Satflux panel. Emphasise: read+receive only, so we cannot move your funds."
---

To connect your Blink wallet to a store in Satflux, you need a **connection string**. It tells BTCPay how to talk to Blink using an API key that has **read and receive only** — no send. So we can create addresses and receive payments for you; we **cannot** move or spend your funds.

---

**Step 1 — Open Blink and get your connection details**

1. Go to the **Blink dashboard**: [https://dashboard.blink.sv/](https://dashboard.blink.sv/)

1. Log in (or create an account and a wallet).

1. Create an **API key** for this wallet, or use an existing one.

1. Ensure the API key has **read** and **receive** permissions only. Do **not** grant **send** or **withdraw**. That way the key can only create addresses and receive payments; it cannot spend.

1. Note:

- **Server URL** — Usually [https://api.blink.sv/graphql](https://api.blink.sv/graphql)

- **API key** — The key you created (often starts with blink_)

- **Wallet ID** — The ID of the wallet this key is for

---

**Step 2 — Build the connection string**

Use this format (semicolon-separated, no spaces):

type=blink;server=[https://api.blink.sv/graphql;api-key=YOUR_API_KEY;wallet-id=YOUR_WALLET_ID](https://api.blink.sv/graphql;api-key=YOUR_API_KEY;wallet-id=YOUR_WALLET_ID)

Example (with placeholder values):

type=blink;server=[https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id](https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id)

Replace YOUR_API_KEY and YOUR_WALLET_ID with your real values. Keep the order: type=blink, then server=..., then api-key=..., then wallet-id=....

---

**Step 3 — Paste the connection string in the Satflux panel**

You can add the connection string in either place:

- **When creating the store** — In the store creation wizard, **Step 2 (Wallet type)**:

- Choose **Blink**.

- In the **Connection string** field that appears, paste the full string.

- Continue to Step 3 and create the store.

- **After the store exists** — Open the store, then go to **Wallet connection** (or **LN Wallet Connection**) in the store sidebar. Paste the connection string in the form and save.

The value is stored **encrypted**. It is used only so BTCPay can create addresses and receive payments; we never have the ability to spend from your Blink wallet.

---

**Important: read + receive only**

The connection string uses an API key with **read and receive** permissions only. That means:

- BTCPay (and thus Satflux) **can**: create addresses, create Lightning invoices, and receive payments into your Blink wallet.

- BTCPay (and Satflux) **cannot**: send, withdraw, or move your funds.

You keep full control over spending. We use the key only to receive on your behalf.

For an overview of where Wallet connection lives and what we store, see Wallet connection overview. For Blink vs Aqua, see Choosing your wallet.
