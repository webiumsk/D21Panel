---
title: "Wallet security alerts and change monitoring"
category: wallet-connection
order: 8
meta_description: "How Satflux protects your receiving wallet: email-code confirmation for changes, pinned security messages, and automatic detection of configuration changes made outside Satflux."
updated: 2026-09-06
---

Your receiving wallet decides where every payment ends up. Satflux therefore treats every change to it as a security event.

## Changes need an email code

Replacing a **connected** wallet (a new connection string, a new Lightning address, switching to Cashu, or re-pairing with SamRock) first asks for a 6-digit code sent to your account email. The code is valid for 15 minutes and for one change. Guest accounts have no inbox, so they are asked to upgrade to a free account first. See [Revoking or changing wallet credentials](/documentation/revoking-or-changing-wallet-credentials).

## Security messages

Some events create a **security message** in Messages, pinned above the regular payment notifications with a red "Security" label. While you have unread security messages, a red bar is shown under the page header on every page. These events are:

- **Wallet connection replaced** - the receiving wallet of a store was changed (also sent by email).
- **Wallet secret revealed** - the stored connection secret was shown, either by you (after the email code) or by Satflux support.
- **Wallet configuration changed outside Satflux** - see below (also sent by email).
- **Wallet configuration restored** - the configuration matches your wallet again.

If you receive a security message about something you did not do, reconnect your wallet immediately and contact support.

## Automatic detection of changes made outside Satflux

When your wallet is connected, Satflux records a fingerprint of the payment configuration on the payment server. Every 10 minutes, and whenever an invoice on your store receives activity, Satflux reads the live configuration again and compares it with the fingerprint. Merchants do not have direct access to the payment server, so any difference means the configuration was changed by someone else.

When a difference is detected:

- a red warning appears on your store's **Wallet connection** page with the affected payment methods,
- you receive a security message and an email,
- Satflux administrators are alerted and can see the incident in the wallet change log.

**What to do:** open the Wallet connection page, confirm the change with the email code and reconnect your wallet. Reconnecting records a new fingerprint and closes the incident. Then contact support so we can investigate how the configuration was changed.

## Who receives the money - payee verification

Every Lightning invoice is signed by the node that receives the payment. When Satflux connects your wallet it asks the payment server for a tiny test invoice (it is never paid and is archived immediately), reads the signing node from it and remembers it as the node of your wallet. If the test invoice cannot be read, the node behind the first paid invoice is remembered instead.

From then on every settled Lightning payment is checked: the node that signed its invoice must be the node of your wallet. A payment signed by any other node raises a security message and an email that name the invoice and the node, and the Wallet connection page shows a red warning. This check does not depend on what the payment server reports about its configuration - it looks at the invoices that were actually paid. If an invoice cannot be decoded or checked, the failure is logged and the payment is still recorded; such payments are not verified.

If you deliberately moved your wallet to another provider, reconnecting it through Satflux records the new node. Only a Satflux administrator can accept a node or relearn it after investigating the incident with you.

## What Satflux cannot see

Detection compares what the payment server reports with what you connected. It covers changes made through the payment server's own interface or API, including a stolen API key. A fully compromised payment server could lie about its configuration; that is why the payee verification above looks at the paid invoices themselves. A server that also forges invoice data would show payments that never reached you, so keep an eye on your wallet balance against the payments shown in Satflux and contact support if they do not match.
