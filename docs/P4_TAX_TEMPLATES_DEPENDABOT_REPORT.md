# P4 report - VAT reporting, limits, invoice templates, Dependabot

Local report (not committed). Consolidates the P4 program after P0-P3
(security / reliability / UX / auth+testing). Everything below is merged to
`master`.

## 1. Summary of changes

Added to the local-first invoicing module: a **VAT summary report** (per-rate,
CSV + print-to-PDF), **VAT-registration turnover-limit** tracking with a
proactive alert, **invoice templates** (save + apply), and **Dependabot
triage automation**. Delivered as 6 audit-first PRs, each with tests, docs and
full gates. Favorites were deliberately dropped as redundant with stock items.

## 2. Problems solved (by PR / branch)

| Feature | Branch | Status |
|---|---|---|
| VAT report data layer + UI + CSV/print-PDF + route/nav | `feat/invoicing-vat-report` | merged |
| VAT turnover-limit setting + `TaxLimitAlert` | `feat/invoicing-vat-limit` | merged |
| VAT reporting docs | `docs/tax-reporting-and-limits` | merged |
| Dependabot triage automation | `chore/dependabot-automation` | merged |
| Invoice templates (save/apply) | `feat/invoicing-templates` | merged |

Audit-first: each feature started from a read-only code audit; several brief
premises were corrected (VAT data is client-side only; per-rate breakdown is
not stored; no turnover-limit existed; favorites overlap stock items;
custom-rate config would break jurisdiction-rules).

## 3. Tests (36 new, suite 330 passing)

- `vatReport.test.ts` (16) - aggregation, discount reconciliation, credit-note
  subtraction, multi-currency, filters, CSV, `vatLimitProgress`.
- `vatReportPage.test.ts` (2) - report page render + empty state.
- `taxLimitAlert.test.ts` (5) - 80% / 95% / exceeded / below / no-limit.
- `dependencyReview.test.ts` (7) - patch/minor/major/unknown triage policy.
- `invoiceTemplate.test.ts` (6) - snapshot drop/roundtrip/parse-guards/draft.

## 4. Documentation

`docs/TAX_REPORTING_AND_LIMITS.md`, `docs/DEPENDABOT_MAINTENANCE.md`,
`docs/INVOICE_TEMPLATES_AND_FAVORITES.md`. (This P4 report is local-only.)

## 5. Configuration

- `.github/dependabot.yml` - composer moved to monthly; patch updates grouped
  per ecosystem.
- `.github/workflows/dependabot-auto.yml` + `scripts/dependency-review.mjs` -
  label + auto-merge policy.
- New Evolu artefacts (additive, auto-migrated): company field
  `vatTurnoverLimit`, table `invoiceTemplate`.

## 6. Monitoring

No new runtime monitoring (client-side local-first features). Dependabot
auto-merge is observable via existing CI required checks + the
`dependencies-approved` / `dependencies-needs-review` PR labels. Real-world
check: Dependabot already opened a grouped `composer-patch` PR (#192), so the
grouping config is live.

## 7. Logging

No new server logging. VAT report load failures surface via the existing
`invoicing.local_db_load_failed_*` UI with a retry; template save/apply errors
via the flash store.

## 8. Deployment

Frontend build only (part of the normal `npm run build` deploy). PHP untouched;
no DB migrations (Evolu auto-migrates additive columns). One manual admin step
for Dependabot auto-merge (see rollout).

## 9. Rollback plan

Each feature is an isolated PR - reverting one removes it without affecting the
others. The Evolu additions (`vatTurnoverLimit`, `invoiceTemplate`) are additive
and backward-compatible (older clients ignore them; no destructive migration).
Dependabot automation: delete `.github/workflows/dependabot-auto.yml` (and, if
desired, revert `dependabot.yml`).

## 10. Known limitations

- VAT report groups currencies, never converts (no FX source); the limit uses
  net turnover in the company default currency.
- PDF export is browser print (isolated iframe), not a branded server template.
- Templates: save + apply in the invoice form; delete exists in the composable
  but there is no dedicated management UI yet.
- Per-rate breakdown reconciles with stored totals within rounding (lineTotal
  is stored at 2 decimals), not by a residual-allocation pass.
- Configurable VAT rates intentionally out of scope (stay in jurisdiction
  rules).

## 11. Open items

None blocking. Possible follow-ups: `TaxLimitAlert` on the main dashboard;
branded server-rendered VAT PDF; template management UI; widen auto-merge to
minor dev-dependencies; align the `evolu/vatReport.ts` comment wording to
"within rounding".

## 12. Usage

- **VAT report:** invoicing -> Tools -> VAT report; pick period; Export CSV /
  Print (Save as PDF).
- **VAT limit:** Tools -> Profile, VAT block, set annual limit (0 disables);
  alert appears at 80% / 95% / 100% of current-year net turnover.
- **Templates:** on an invoice, "Save as template"; on a new/draft invoice,
  "Load template" prefills everything except number and date.

## 13. Deployment risks and recommended rollout

Low risk (additive, isolated, tested). Recommended:

1. All feature PRs are merged; ship with the next frontend build.
2. In GitHub UI, enable the Dependabot auto-merge prerequisites (Allow
   auto-merge; branch-protection required checks: PHP tests / E2E / Node
   lint+build; optionally allow Actions to approve PRs). Until then the workflow
   only labels and leaves PRs for manual merge - a safe default.
3. Watch the first one or two patch Dependabot PRs to confirm auto-merge fires
   on green CI, then let it run.
