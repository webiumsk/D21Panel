---
title: Bank payment matching
category: invoicing
order: 5
meta_description: Import bank statements and reconcile payments to your invoices automatically.
---

# Bank payment matching

Bank payment matching reconciles incoming payments against your issued documents, so you can see at a glance what has been paid.

## Import your transactions

Open **Payments** in your company and import a bank statement. Satflux understands common formats:

- **CSV** exports from your bank
- **CAMT** (ISO 20022) files
- **Wise** statements
- Notification e-mails from some banks (for example Slovak banks)

Duplicate transactions are detected and skipped, so re-importing an overlapping statement is safe.

## Automatic matching

As transactions come in, Satflux matches them to invoices using the **variable symbol**, amount and other references. Matched invoices are marked paid; anything ambiguous is left for you to confirm or match by hand.

## Expenses

Outgoing payments can be turned into **expenses** so your books stay complete for the accountant export.
