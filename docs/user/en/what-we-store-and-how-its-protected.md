---
title: "What we store and how it’s protected"
category: security
order: 2
meta_description: "Encrypted storage of connection string/descriptor. Who can see what (you, support if needed). No plaintext secrets in logs or to third parties."
---

We store only what is needed to connect your store to your wallet in BTCPay: your **connection string** (Blink) or **output descriptor** (Aqua). That value is treated as a secret and is protected so that you stay in control and we never expose it unnecessarily.

---

**Encrypted storage**

- Your credential (connection string or descriptor) is stored in our database as **encrypted** data.

- Encryption uses the application’s secret key. The same value is never stored in plaintext in the database.

- It is decrypted only when the system or support needs to use it (e.g. to configure BTCPay or to show it temporarily to support so they can paste it into BTCPay). It is not decrypted for normal page loads or for you in the UI.

So: we store one secret per wallet connection, and it is stored encrypted.

---

**Who can see what**

- **You (the merchant)** — You can add, replace, or remove your connection string or descriptor in the Satflux panel. When you **edit or replace** the connection, the form may show the full value so you can change it. When you are only viewing the connection (not editing), you typically see a **masked** hint (e.g. first and last few characters). To **reveal** the stored secret again (e.g. to copy it), you must enter your **password**; we do not show the full secret without that step.

- **Support (if needed)** — When a wallet connection needs manual configuration in BTCPay, support staff can temporarily **reveal** the plaintext secret (support workflows may require authentication). That access is for configuring your store only. Support does not use the secret for anything else, and we do not share it with third parties.

- **No one else** — We do not send your connection string or descriptor to third parties. We do not log it in plaintext.

---

**No plaintext secrets in logs or to third parties**

- We do **not** write the full connection string or descriptor in **logs**. Logs may contain non-sensitive metadata (e.g. that a connection was saved, or its type), but not the secret itself.

- We do **not** send your credential to **third parties**. It is used only inside our system and, when necessary, shown temporarily to support so they can complete the BTCPay configuration. We do not share it with external services, analytics, or anyone else.

If you change or remove your wallet connection, we update or delete the stored secret accordingly. Encryption applies for as long as the secret is stored.

For how we use the credential (receive and watch only, never spend), see We cannot access or move your funds. For where the credential is used in the app, see Wallet connection overview.
