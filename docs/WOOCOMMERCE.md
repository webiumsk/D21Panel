# WooCommerce integration

SATFLUX WooCommerce plugin connects WordPress shops to [satflux.io](https://satflux.io) for BTCPay payments, optional business invoicing, and optional Satflux Tickets.

## Architecture

```
WooCommerce (SATFLUX plugin)
  ├─ Connect (session) → GET /woocommerce/connect
  ├─ Payment → BTCPay Greenfield API (store API key)
  ├─ Invoicing (Pro+) → /api/integrations/woocommerce/*
  └─ Tickets (optional) → BTCPay SatoshiTickets API
```

## Connect flow

1. Merchant opens **WooCommerce → Satflux → Connection** and clicks **Connect to Satflux.io**.
2. Browser redirects to `GET /woocommerce/connect?return_url=...` (HTTPS required, user must be logged in).
3. Satflux redirects back with:
   - `btcpay_url`, `api_key`, `store_id` (BTCPay GUID)
   - `satflux_store_id`, `satflux_company_id` (when linked)
   - `integration_token`, `integration_secret` (for invoicing API + webhooks)
   - `invoicing_enabled=0|1`

Canonical connect URL is `/woocommerce/satflux-tickets/connect`; the legacy `/woocommerce/satoshi-tickets/connect` remains supported for plugins in the field.

## Integration API (invoicing)

Auth: `Authorization: Bearer {integration_token}`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/integrations/woocommerce/connection` | Test + store/company info, inbox deep link paths |
| POST | `/api/integrations/woocommerce/contacts/upsert` | Upsert buyer contact |
| POST | `/api/integrations/woocommerce/documents` | Create draft from WC order |
| POST | `/api/integrations/woocommerce/documents/{id}/issue` | Issue document |
| GET | `/api/integrations/woocommerce/documents/{id}` | Status, PDF URL, pay link |

Rate limit: 60 requests/minute per IP.

## Webhooks to WooCommerce

When a business document linked to a WooCommerce order is marked paid, Satflux POSTs to:

`{site}/wp-json/satflux/v1/invoicing-webhook`

Header: `X-Satflux-Signature: HMAC-SHA256(body, integration_secret)`

Payload: `{ "event": "document.paid", "document_id", "woocommerce_order_id", ... }`

BTCPay payment webhooks for shop orders use:

`{site}/wp-json/satflux/v1/webhook` (event `InvoiceSettled`)

## Integration inbox (local-first)

WooCommerce orders can enqueue to `integration_document_inbox` instead of server `business_documents`.

**Merchant UI:** Invoicing → company → **Invoices** → WooCommerce inbox panel (requires `runs_eshop` in company app settings and linked store).

**Deep link from WooCommerce plugin:**

`https://satflux.io/invoicing/stores/{satflux_store_uuid}/integration-inbox`

- `{satflux_store_uuid}` is `stores.id` (Laravel UUID), **not** the BTCPay store id.
- Legacy links with BTCPay store id still resolve via `GET /api/invoicing/integration-inbox/deeplink?store=...`.
- The SPA landing route redirects to the invoice list and scrolls to the inbox panel.

**Connect callback** must include `satflux_store_id` (`return_satflux_store_id=1` on connect URL). Plugin should persist `data.store.id` from `GET /api/integrations/woocommerce/connection` after connect.

## Data model

- `store_integrations` - per-store WooCommerce token (hashed), encrypted secret, webhook URL
- Business documents from WooCommerce store `internal_note`: `woocommerce_order_id={id}`

## Plan requirements

- **Payment only:** any Satflux store with BTCPay credentials
- **Invoicing:** Pro/Enterprise `business_invoicing` + company linked to store (`stores.company_id`)
- **Tickets:** Satflux Tickets plugin (fork of Satoshi Tickets) on the merchant's BTCPay Server

## Upgrade from v1.x (BTCPay Satoshi Tickets plugin era)

Plugin v2.0 migrates `btcpay_satoshi_*` options to `satflux_*`, keeps ticket gateway ID for existing orders, and auto-enables Tickets module when ticket products exist.
