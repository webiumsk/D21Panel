---
title: "Blink: connection string format and troubleshooting"
category: wallet-connection
order: 3
meta_description: "Exact format, common mistakes (wrong permissions, missing fields). What “read and receive” means in Blink. How to revoke or rotate the key if needed."
---

**Blink: connection string format and troubleshooting**

This page covers the **exact format** of the Blink connection string, **common mistakes**, what **“read and receive”** means in Blink, and how to **revoke or rotate** the API key if needed.

---

**Exact format**

The connection string is a single line, **semicolon-separated**, **no spaces** around the = or ;. Order matters.

type=blink;server=URL;api-key=KEY;wallet-id=ID

**Required fields:**

| Part | Description | Example |
|---|---|---|
| type | Must be exactly blink (lowercase). | type=blink |
| server | Blink GraphQL API URL. Use HTTPS. | server=[https://api.blink.sv/graphql](https://api.blink.sv/graphql) |
| api-key | Your Blink API key (e.g. starts with blink_). | api-key=blink_xxxxxxxx |
| wallet-id | The Blink wallet ID this key is for. | wallet-id=abc123 |

**Full example:**

type=blink;server=[https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id](https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id)

- Do **not** add spaces, line breaks, or quotes.

- Use **semicolons** (;) between parts, not commas or newlines.

- All four parts must be present. If one is missing, validation will fail.

---

**Common mistakes**

- **Wrong or missing permissions** — The API key must have **read** and **receive** only. If it has **send** or **withdraw**, we still only use it to receive; but for safety and principle, create a key with read+receive only and use that. If the key has no receive permission, BTCPay will not be able to receive payments and you may see errors when creating or paying invoices.

- **Missing fields** — Forgetting type=blink, server, api-key, or wallet-id causes “Invalid connection string format” or similar. Double-check that all four parts are present and spelled correctly (e.g. wallet-id with a hyphen, not wallet_id).

- **Spaces or extra characters** — Spaces before/after = or ;, or pasting the string with line breaks or quotes, can break parsing. Paste one clean line with no leading/trailing spaces.

- **Wrong server URL** — Use [https://api.blink.sv/graphql](https://api.blink.sv/graphql). HTTP (no “s”) or a typo in the host or path can cause connection failures.

- **Expired or revoked key** — If the key was revoked or deleted in the Blink dashboard, the connection will stop working. Create a new key and update the connection string in Satflux (see “Revoking or rotating the key” below).

---

**What “read and receive” means in Blink**

When we say the API key should have **read** and **receive** only:

- **Read** — The key can read wallet state (e.g. addresses, balance, transaction list). BTCPay uses this to know where to receive and to match incoming payments.

- **Receive** — The key can create new addresses and receive payments (on-chain and Lightning). No **send** or **withdraw** permission is needed or desired.

So: we can **create addresses and receive** funds into your Blink wallet. We **cannot** send, withdraw, or move funds. You keep full control over spending; the key is receive-only.

---

**Revoking or rotating the key**

If the key is compromised, or you want to rotate it for security:

1. **In the Blink dashboard** 1:

- Open the wallet and go to API keys (or similar).

- **Revoke** (delete) the old key, and/or **create a new** API key with read and receive only.

1. **In the Satflux panel:**

- Open the store → **Wallet connection** (LN Wallet Connection).

- Replace the old connection string with a new one that uses the new API key (same format: type=blink;server=...;api-key=NEW_KEY;wallet-id=...).

- Save. Support may need to re-apply the new connection in BTCPay if your instance uses a manual step; after that, new invoices will use the new key.

Until the new string is saved in Satflux and (if applicable) applied in BTCPay, the old key is still in use. After you revoke the old key in Blink, that connection will stop working until the new one is in place — so it’s often easier to create the new key first, update Satflux (and BTCPay if needed), then revoke the old key.

For step-by-step setup, see Blink: getting your connection string for BTCPay.
