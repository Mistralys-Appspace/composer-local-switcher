# Migrating from 2.x

Version 3.0.0 removes PROD configuration drift as a concept. `composer/composer-prod.json` and
`composer/composer-prod.lock` are no longer a second, committed, hand-editable baseline that could
drift from `composer.json` - they are now a transient snapshot of a DEV session, created automatically
on a PROD/INITIAL→DEV switch and deleted again once a DEV→PROD switch has applied them back.
`composer.json` itself is the single source of truth at all times.

## Consumer migration steps

Follow these steps in each project that uses the library (see the plan's Human Actions for the exact
list of consumer repositories):

1. **Switch to PROD first**, with whatever version of the library you currently have, so
   `composer/composer-prod.json`/`.lock` are no longer needed on disk:
   ```bash
   composer switch-prod -- --yes
   ```
2. **Untrack the production snapshot files** - they must never be committed under 3.0.0:
   ```bash
   git rm --cached composer/composer-prod.json composer/composer-prod.lock
   ```
3. **Add both to `.gitignore`**:
   ```
   composer/composer-prod.json
   composer/composer-prod.lock
   ```
4. **Remove the `post-update-cmd` hook** that ran `@composer switch-update` after every
   `composer update`/`require`/`remove`. It is no longer required - see
   ["What a retained `post-update-cmd` hook does under v3"](#what-a-retained-post-update-cmd-hook-does-under-v3)
   if you choose to leave it in place temporarily.
5. **Drop the `version` override** from each entry in `local-repositories.json`, unless you deliberately
   want to pin a package to a version other than the one your production lock file already resolved for
   it. Under 3.0.0 the alias is derived automatically from `composer.lock`, so the override is optional.
6. **Raise the version constraint** on `mistralys/composer-local-switcher` in your `composer.json` to
   `^3.0`.
7. **Re-run the hook installer** so the bundled pre-commit hook is reinstalled from the updated
   library version:
   ```bash
   composer switch-install-hooks
   ```
8. **Update agent instructions** (e.g. your project's `AGENTS.md`) to run switch commands
   non-interactively with `-- --yes` (e.g. `composer switch-dev -- --yes`), since every switch that
   changes `composer.json` now asks for confirmation by default.

## Upgrading while in DEV mode

If a project is already in DEV mode under 2.x when it upgrades to 3.0.0, its existing
`composer/composer-prod.json`/`.lock` (written by the 2.x switcher) is consumed directly as the v3
snapshot - there is no separate migration step for the files themselves. A legacy status file written
before the `snapshotHash`/`appliedRepositories` fields existed skips the tamper check (with a warning)
instead of failing, and a legacy snapshot with no recorded `appliedRepositories` falls back to the
current `local-repositories.json` list. Switching to PROD from this state (`composer switch-prod`)
applies the snapshot back and deletes it exactly as it would for a snapshot created entirely under 3.0.0.

## API removals and their replacements

| Removed in 3.0.0 | Replacement |
|---|---|
| `ConfigSwitcher::reconcile()` / `reconcileCore()` | Nothing - there is no second committed copy left to reconcile. A DEV→PROD switch carries DEV-time edits back automatically via the three-way revert. |
| `ConfigSwitcher::verify()` | Nothing - `describe()`/`switch-describe` reports the current state (lock freshness, installed state, pending carry-back) without comparing two committed configs. |
| `VerificationResult` | Replaced for the carry-back use case by `SwitchDescription::getPendingProdChanges()` (a `ConfigChangeSet`) and by `SwitchOutcome::getConfigChanges()` on a real DEV→PROD switch. |
| `RECONCILE_TO_MAIN` / `RECONCILE_TO_PROD` | Removed - there is no direction to choose; the three-way revert decides per key/package automatically. |
| `composerSwitchReconcile()` / `composer switch-reconcile` | Removed. Run `composer switch-prod` to carry DEV edits back into production. |
| `composerVerifyConfig()` / `composer switch-verify-config` | Removed. Run `composer switch-describe` to inspect the current state instead. |
| `ERROR_INVALID_RECONCILE_DIRECTION` (182111) | Retired, not reused. |

See [api-surface.md](agents/project-manifest/api-surface.md) for the full list of retired message
codes.

## What a retained `post-update-cmd` hook does under v3

Removing the `post-update-cmd: @composer switch-update` hook is recommended, but leaving it in place
is harmless:

- **Inside a Composer process the switcher itself launched** (e.g. the `update`/`install` a
  `switch-dev`/`switch-prod` command runs), `Utils\ComposerProcess` sets `COMPOSER_SWITCHER_NESTED=1`
  in the child environment. The hook's own entry-point sees the guard and no-ops instead of recursing.
- **After a user-run `composer update`/`require`/`remove`** (the guard is not set), the hook runs as an
  ordinary `switch-update` refresh. With an unchanged local-repositories list this is idempotent on
  `composer.json` - the three-way revert reproduces the same file, the write is skipped, and the bytes
  stay untouched - so it neither prompts nor needs `--yes`. Only a pending `local-repositories.json`
  edit makes it show changes and, because the hook runs non-interactively, fail with
  `ERROR_CONFIRMATION_REQUIRED` (nothing is written; the message names `--yes`).

Because of this, upgrading does not require removing the hook as a hard precondition - but the
migration steps above recommend doing so to avoid the extra Composer invocation and the confirmation
failure case.
