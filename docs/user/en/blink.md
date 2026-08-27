---
title: Connect Blink
category: wallet-connection
order: 5
meta_description: Connect the Blink wallet to your store - the fast, non-custodial receive-only address, or the legacy API key.
---

# Connect Blink

**Blink** is a quick way to start receiving Lightning. There are two ways to connect it.

## Recommended: your Blink Lightning address (non-custodial, receive-only)

Just paste your Blink address `you@blink.sv` on the **Lightning address** tab of Wallet connection. This is **receive-only** - Satflux and BTCPay can watch for and receive payments, but cannot send or move your funds. Nothing else to configure.

## Legacy: Blink API key connection string

Blink also supports a full connection string with an API key:

```text
type=blink;server=…;api-key=blink_…;wallet-id=…;
```

You get the API key in the **Blink dashboard → API Keys** (create a key with read + receive permissions). Paste the string on the **Advanced** tab.

> This API-key connection is treated as **EU-legacy**. Satflux may show a migration notice suggesting you move to the non-custodial `@blink.sv` address instead. Blink is not being removed - the address form is simply the recommended, simpler option.

## Which should I use?

- Want the simplest start? Use the **`@blink.sv` address**.
- Already have an API key set up? It still works.
- Want to hold your own keys with a QR setup? Consider **Aqua** via [SamRock](/documentation/connect-aqua-with-samrock).

See [We cannot access or move your funds](/documentation/we-cannot-access-or-move-your-funds) for exactly what "receive-only" means.
