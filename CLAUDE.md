# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**satflux.io** - a multi-tenant BTCPay Server control panel. Users manage their BTCPay stores (invoices, wallets, Lightning, exports) through this SPA backed by Laravel's Greenfield API integration.

## Development Commands

### Frontend

```bash
npm run dev          # Vite dev server (watch mode)
npm run build        # Production build
npm run lint         # ESLint; fails on NEW warnings (ratchet via --max-warnings in package.json - lower it when you burn warnings down, never raise it)
```

### Backend (all via Docker)

```bash
docker compose up -d                              # Start all services
docker compose exec php php artisan migrate       # Run migrations
docker compose exec php php artisan test          # Run all PHPUnit tests
docker compose exec php php artisan test --filter=TestName  # Run single test
docker compose exec php php artisan optimize:clear  # Clear all caches (required after .env changes)
docker compose exec php php artisan tinker        # Interactive shell
docker compose exec php composer install          # Install PHP deps
```

If Git, ESLint, or exports fail with permission errors on `storage/app/private` or `storage/app/exports` (often `www-data` from Docker), fix ownership on the host, e.g. `sudo chown -R "$USER:$USER" storage/app/private storage/app/exports`. Export files are gitignored (`*.csv`, `*.xlsx`).

### Versioning

```bash
npm run version:patch   # Bump patch version
npm run version:minor   # Bump minor version
```

## Architecture

### Request Flow

```
Browser (Vue SPA)
  → Axios (/api, withCredentials: true)
  → Laravel API routes (routes/api.php, all guarded by auth:sanctum)
  → Controllers → Services
    ├─ Eloquent (PostgreSQL)
    └─ BtcPayClient → BTCPay Server Greenfield API
```

### Frontend (`resources/js/`)

- **`app.ts`**: Vue app bootstrap; detects Inertia vs. SPA mode via `data-page` attribute
- **`bootstrap.ts`**: Fetches `/sanctum/csrf-cookie` on boot; sets up Axios with `X-XSRF-TOKEN` header
- **`router/`**: Vue Router with navigation guards - checks `authStore.user` to enforce auth; 401s redirect to login
- **`store/`**: Pinia stores (Composition API style). Key stores: `auth`, `stores` (BTCPay stores + dashboard), `flash`, `onboarding`
- **`pages/`**: Route-level components (auth, stores, admin, etc.)
- **`components/`**: Reusable UI components
- **`services/api.ts`**: Axios instance; removes `Content-Type` for FormData, adds CSRF header
- **`locales/`**: Vue-i18n translation files (multi-language)

### Backend (`app/`)

- **`Http/Controllers/`**: Thin controllers delegating to services
- **`Services/BtcPay/`**: 13 service classes wrapping BTCPay Greenfield API (BtcPayClient is the HTTP client)
- **`Http/Middleware/EnsureStoreOwnership`**: Enforces multi-tenant isolation - validates `user_id` on every store-scoped request
- **`Jobs/`**: Async jobs for exports and webhook processing

### Multi-Tenancy & Security

- Store access is enforced at the application layer via the `stores` table (`user_id` → `btcpay_store_id`)
- Only local UUIDs are ever sent to the frontend - `btcpay_store_id` is never exposed
- Dual API key architecture: server-level key (provisioning) vs. per-merchant encrypted keys (scoped operations)
- Audit logging on sensitive actions (store create/update, exports)

### Authentication

- Laravel Sanctum SPA cookie-based auth (not tokens)
- Browser sends `laravel_session` cookie + `X-XSRF-TOKEN` header on every request
- For local dev: `SESSION_SECURE_COOKIE=false`, and set `SANCTUM_STATEFUL_DOMAINS` to the **exact** browser host:port (e.g. `localhost:8000` for `php artisan serve`, or `localhost:8080` for Docker). Plain `localhost` does not match `localhost:8000` for Sanctum stateful detection, so guest login and SPA sessions will not persist without the port.

## Satflux Tickets (BTCPay plugin)

Events/tickets use **Satflux Tickets** - a standalone fork of Satoshi Tickets by TChukwuleta with its own identity (`BTCPayServer.Plugins.SatfluxTickets`, monorepo `~/apps/bitcoin/BTCPayServerPlugins`, branch `main`). Details: [docs/SATFLUX_TICKETS.md](docs/SATFLUX_TICKETS.md); runbook `Plugins/BTCPayServer.Plugins.SatfluxTickets/FORK_MAINTENANCE.md`. API paths are canonically `satflux-tickets` (legacy `satoshi-tickets` aliases stay functional).

## Key Conventions

- **Typography:** use ASCII hyphen `-` with spaces (`word - word`), not em dash `—`, in UI copy, docs, and comments (see `.cursor/rules/typography-dash.mdc`).
- **Never expose `btcpay_store_id`** to the frontend; always use the local UUID. **Single deliberate exception:** `StoreController::formatStore`/`formatStoreFromBtcPay` include it because the Pay Button embed snippet and API key generation need the real BTCPay store ID (`PayButtonForm.vue`, `ApiKeys.vue`). Do not add new exposures - the `Store` model hides it (`$hidden`) so it never leaks via `toArray()`/`toJson()`.
- **`EnsureStoreOwnership` middleware** must be applied to all store-scoped API routes
- BTCPay API responses are cached with cache keys scoped by user/API key hash
- Webhook signature verification is active when `BTCPAY_WEBHOOK_SECRET` is set
- Test BTCPay HTTP calls using `Http::fake()` (see existing store creation tests for pattern)
- `php artisan optimize:clear` is required after any `.env` or config file changes
