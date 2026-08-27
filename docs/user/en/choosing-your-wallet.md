---
title: Choosing your wallet
category: getting-started
order: 4
meta_description: Blink, Aqua, Bull Bitcoin, any Lightning address, Cashu, or NWC - how to pick how your store receives Lightning.
---

# Choosing your wallet

Satflux connects **your own** wallet to your store - it never holds your funds. You have several options; here is how to pick.

## The quick choices

| Option | Custody | How you connect | Good for |
|---|---|---|---|
| **Blink address** (`you@blink.sv`) | Non-custodial, receive-only | Paste the address | The fastest start |
| **Any Lightning address** | Depends on the wallet | Paste `you@wallet.com` | Blitz, Flash, Coinos, or any LUD-21 wallet |
| **Aqua** (via SamRock) | You hold the keys | Scan a QR in Aqua | Easiest fully self-custodial setup |
| **Bull Bitcoin** | You hold the keys | Paste a watch-only descriptor | Self-custody with Bull Bitcoin |
| **Cashu** (beta) | Mint-backed ecash | Mint URL + Lightning address | Accepting ecash payments |
| **NWC** (e.g. Alby Hub) | Your own node/hub | Paste a pairing string | Advanced self-hosters |

## How to decide

- **Just want to start fast?** Paste your **Blink** `@blink.sv` address, or [any Lightning address](/documentation/connect-with-any-lightning-address) from a wallet you already use.
- **Want to hold your own keys, easily?** Connect **Aqua** with [SamRock](/documentation/connect-aqua-with-samrock) - scan a QR, done.
- **Use Bull Bitcoin?** Paste its [watch-only descriptor](/documentation/aqua-and-bull-descriptor).
- **Want to accept ecash?** See [Cashu (CashuMelt)](/documentation/accept-cashu-ecash) - note it is beta.
- **Run your own Alby Hub?** Use [NWC](/documentation/connect-with-nwc).

You can change your wallet later - see [Revoking or changing wallet credentials](/documentation/revoking-or-changing-wallet-credentials). Whatever you choose, Satflux never takes custody of your wallet - see [We cannot access or move your funds](/documentation/we-cannot-access-or-move-your-funds). (Cashu is different in kind: it is mint-backed ecash that is melted to your Lightning address.)
