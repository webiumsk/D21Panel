---
title: "Revoking or changing wallet credentials"
category: wallet-connection
order: 7
meta_description: "How to disconnect or update Blink API key / Aqua descriptor. That you can revoke in the wallet app and reconnect with a new credential."
---

You can **change** your wallet credential (Blink connection string or Aqua descriptor) at any time by submitting a new one in the Satflux panel. We do not offer a “disconnect” action: in BTCPay you can only **replace** the connection with a new credential, not remove it without replacing. So to stop using a wallet you revoke or change it in the wallet app, then connect the store to a **new** credential.

---

**Confirming a change of a connected wallet**

Replacing a wallet that is already **connected** is a sensitive change, so Satflux asks you to confirm it before the form opens:

1. Open the store → **Wallet connection** and press **Change connection**.
2. Enter your account password (accounts that sign in with a recovery phrase skip this step).
3. We email a **6-digit code** to your account address. Enter it in the form - the code expires after 10 minutes and you can request a new one after a short countdown.
4. The form opens with the current credential filled in. Replace it and save. The confirmation is valid for 15 minutes; if it runs out, Satflux asks for a new code before saving.

Nothing on your store changes until the code is confirmed, and the same confirmation covers re-pairing with SamRock and switching a Lightning store to Cashu. A **guest account** has no email to receive the code, so it must first be upgraded with an email address (see [Create your account](/documentation/create-account)). The first wallet connection of a new store needs no code.

---

**Blink: revoke or rotate the API key, then update in Satflux**

1. **In the Blink dashboard** 1: Revoke (delete) the old API key and/or create a new one with **read and receive** only.

1. **In Satflux:** Open the store → **Wallet connection** (LN Wallet Connection). In the form, **replace** the old connection string with a new one: type=blink;server=...;api-key=NEW_KEY;wallet-id=.... Save.

1. **In BTCPay:** The connection is the one currently configured for the store. We do not offer “disconnect”; you **change** it by submitting the new connection string in Satflux. Support may need to apply the new string in BTCPay if your setup uses a manual step. After that, the store uses the new key.

So: revoke or rotate in Blink, then reconnect the store with a new credential in Satflux (and BTCPay if applicable).

---

**Aqua: use a different descriptor, then update in Satflux**

1. **In Aqua:** Export a **new** watch-only descriptor (e.g. from another wallet or another derivation) if you want to stop using the current one. BTCPay allows each descriptor only once per instance, so a “new” credential means a different descriptor (different wallet or different export).

1. **In Satflux:** Open the store → **Wallet connection**. **Replace** the old descriptor with the new one in the form and save.

1. **In BTCPay:** As with Blink, there is no “disconnect” - you **replace** the Lightning/wallet configuration with the new descriptor. Support may need to apply it in BTCPay if your setup requires a manual step.

So: revoke or change in the wallet app (new key or new descriptor), then reconnect by submitting the new credential in Satflux.

---

**Summary**

- We do **not** offer “disconnect” in Satflux or in BTCPay - only **change/replace** the connection.

- To stop using a credential: revoke or change it in **Blink** or **Aqua**, then in Satflux submit a **new** connection string or descriptor so the store uses the new credential.

- The store always has one active wallet connection; you change it by replacing it with another.

For Blink key format and rotation, see Blink: connection string format and troubleshooting. For where to paste the credential, see Wallet connection overview.
