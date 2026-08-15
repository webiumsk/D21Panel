# Dependabot maintenance

Part of P4. How dependency-update PRs are configured, triaged and merged.

## Design

The existing CI (`.github/workflows/ci.yml`) already runs the full gate on
every PR - vitest, PHPUnit, Playwright e2e, ESLint, typecheck, build,
locale-parity, Pint, PHPStan and both security audits. So Dependabot PRs are
**not** re-tested by a separate script; that would just double CI time and
drift from the real gate. Instead a small triage workflow reuses CI as the
gate and only decides labelling + auto-merge based on the update's semver
level.

Policy: **patch** updates auto-merge once CI is green; **minor** and **major**
(and anything unrecognised) are labelled for manual review and never
auto-merged.

## Configuration - `.github/dependabot.yml`

| Ecosystem | Interval | Notes |
|---|---|---|
| npm | weekly | patch bumps grouped into one PR (`npm-patch`) |
| composer | monthly | patch bumps grouped (`composer-patch`) |
| github-actions | monthly | patch bumps grouped (`actions-patch`) |

Each ecosystem carries `dependencies` + an ecosystem label, limit 10 open PRs.
Grouping patch updates into a single PR keeps noise down and lets the whole
patch batch auto-merge together. Minor and major updates arrive as individual
PRs so each can be reviewed on its own.

## Triage workflow - `.github/workflows/dependabot-auto.yml`

Runs on Dependabot PRs only (`github.actor == 'dependabot[bot]'`):

1. `dependabot/fetch-metadata` reads the update-type.
2. `node scripts/dependency-review.mjs "<update-type>"` classifies it (the
   single source of the policy, unit-tested).
3. Adds `dependencies-approved` (patch) or `dependencies-needs-review`
   (minor/major/unknown). The labels are created if missing.
4. For patch updates: approves and enables GitHub **auto-merge** (`--squash`).
   Auto-merge only completes when branch-protection required checks pass.

The policy function lives in `scripts/dependency-review.mjs`
(`classifyDependencyUpdate` / `normalizeSemverLevel`) and is covered by
`resources/js/__tests__/dependencyReview.test.ts`. Run it directly:

```bash
node scripts/dependency-review.mjs "version-update:semver-patch"
# semver=patch / label=dependencies-approved / automerge=true
npm run test -- dependencyReview
```

## Required repo settings (manual - GitHub admin, not code)

Auto-merge and branch protection are repository settings that cannot be set
from the repo files; enable them once in the GitHub UI:

1. **Settings > General > Pull Requests > Allow auto-merge** - on.
   Without this, the workflow logs a warning and leaves the PR for manual merge.
2. **Settings > Branches > branch protection rule for `master`**:
   - Require status checks to pass before merging, and mark the CI jobs
     (**PHP (tests)**, **E2E (Playwright)**, **Node (lint + build)**) as
     required. This is what makes auto-merge safe - a patch PR only merges once
     these are green.
   - Require a pull request before merging.
3. **Settings > Actions > General > "Allow GitHub Actions to create and approve
   pull requests"** - on, only if branch protection requires an approval (lets
   the workflow's approve step satisfy it for patch PRs).

Major/minor PRs (`dependencies-needs-review`) are reviewed and merged by a
maintainer as usual.

## Runbook

- **Weekly:** skim open Dependabot PRs. `dependencies-approved` ones should be
  auto-merging on green CI; investigate any that are red.
- **Monthly:** review `dependencies-needs-review` (minor/major) PRs - read the
  changelog/release notes, let CI run, merge or close.
- **A patch group is red:** open the failing PR, reproduce locally
  (`npm ci` / `composer install` on the branch, run the failing gate), fix
  forward or close the PR if the update is bad.
- **Security advisories:** Dependabot security PRs may be minor/major; treat as
  high priority regardless of the label.

## Deferred majors (ignored)

These major updates are `ignore`d in `dependabot.yml` (minor/patch still flow).
Each is a dedicated migration, not a dependency bump - triaged and confirmed
non-trivial. Drop the corresponding `ignore` entry when tackling one.

| Package | Why deferred |
|---|---|
| `vite` (5 -> 8) | Rolldown/Oxc migration - esbuild no longer bundled; `optimizeDeps.esbuildOptions` -> `rolldownOptions`, `transformWithEsbuild` removed. Build fails until the config is migrated. |
| `laravel-vite-plugin` (1 -> 3) | v3 requires `vite ^8` - coupled with the vite migration. |
| `vue-router` (4 -> 5) | v5 is a build-time/typed router (peers on vite 8, pinia 3, `@pinia/colada`, `@vue-macros`); we use the v4 runtime `createRouter` API. Needs the vite migration + pinia 3 + a routing rewrite. |
| `@tiptap/*` (2 -> 3) | tiptap 3 must be upgraded across all 16 `@tiptap/*` packages together (extension 3.x peers `@tiptap/core@3`); partial bumps ERESOLVE. |
| `@vue/eslint-config-typescript` (13 -> 14) | Requires ESLint `^9` (flat-config migration); project is on ESLint 8. |
| `laravel/framework` (12 -> 13) | Full framework major (breaking changes) and its stricter Pint preset reformats ~160 files (`fully_qualified_strict_types` etc.). Master stays Pint-clean on the current version; do the upgrade + reformat as one deliberate migration. |

## Known limitations

- The npm/composer security audits run in CI (`npm audit`, `composer audit`)
  but are not wired into this triage flow; a failing audit surfaces as a red CI
  check on the PR.
- Auto-merge covers patch only by design. Widening to minor dev-dependencies is
  possible later by extending the policy in `scripts/dependency-review.mjs`
  (and its test).
