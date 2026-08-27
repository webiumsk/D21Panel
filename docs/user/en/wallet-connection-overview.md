---
title: "Wallet connection overview"
category: wallet-connection
order: 1
meta_description: "Where to find Wallet Connection in the app. What we store (encrypted). That we only use credentials for receiving and watching; we never spend."
---

Wallet Connection is where you add or update the Lightning wallet (Blink or Aqua) that your store uses to receive payments. The credentials you provide are stored encrypted and are used only for receiving and watching; the platform never spends your funds.

**Where to find it**

- **From a store:** Open the store (e.g. from **Stores** in the main menu), then in the **store sidebar** click **LN Wallet Connection** (or **Wallet connection**). The URL is /stores/{store-id}/wallet-connection.

- **From the store dashboard:** If the wallet is not configured, the store overview shows a notice and a link to **Wallet connection**.

- **From the setup wizard:** After creating a store, the “Next steps” / checklist includes a link to **Wallet connection** to finish wallet setup.

Support and admin users also have a **Wallet Connections** area (e.g. under Support) to process connection requests that need manual configuration in BTCPay.

**What we store**

We store only what is needed to connect your store to your wallet in BTCPay:

- **Type** — Whether the connection is Blink or Aqua (descriptor-based).

- **Your credential** — For Blink: the connection string (server URL, API key, wallet ID). For Aqua: the output descriptor (watch-only; no private keys). This value is stored **encrypted** in our database. It is decrypted only when our system or support configures BTCPay (or when support temporarily reveals it to paste into BTCPay). The UI never shows the full secret; you can only replace it or see a masked hint.

We do not store private keys. With Aqua, the descriptor you give is watch-only. With Blink, the connection string allows the server to create addresses and receive on your behalf; it does not grant spend capability to us or to BTCPay.

**Receive and watch only — we never spend**

Your wallet credentials are used **only** so that BTCPay Server can:

- **Receive** — Create addresses and Lightning invoices and receive payments into the wallet you control.

- **Watch** — (For Aqua/descriptor) Derive and watch addresses so the server can match incoming payments.

Neither Satflux nor BTCPay is ever given the ability to **spend** from your wallet. We do not hold private keys; we do not send transactions. You keep full control over when and where your funds move.

For step-by-step setup, see Creating your first store and Choosing your wallet: Blink vs Aqua.
