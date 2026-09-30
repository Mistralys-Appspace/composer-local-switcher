# Switching Decision Table

> Maps **current state** × **intended action** to the exact Composer command,
> the underlying PHP call, the resulting file effects, and the message codes
> `ConfigSwitcher` emits. Every row is traceable to a code path in
> [`src/ConfigSwitcher.php`](../../../src/ConfigSwitcher.php).

Current state is read from the status file (`ConfigSwitcher::getStatus()`):
`INITIAL` means no switch has ever been run (no status file yet), `DEV` and
`PROD` mean `StatusFile::isDEV()`/`isPROD()` is `true`. See
[data-flows.md](data-flows.md) for the narrative walkthroughs this table
condenses, and [api-surface.md](api-surface.md) for full method signatures.

## INITIAL state (no prior switch)

| Intended Action | Command | PHP Call | File Effects | Messages Emitted |
|---|---|---|---|---|
| Switch to DEV | `composer switch-dev` | `switchToDevelopment()` → `switchTo(MODE_DEV)` | `switch_initProductionFiles()` copies `composer.json` → `composer-prod.json` (+ lock, if the main lock exists); `switch_case_PROD_DEV()` then backs up the main lock to the dev lock location (if it exists) or deletes it, and `switch_adjustConfigForDev()` rewrites `composer.json` | `MESSAGE_NO_LOCK_FILE_FOUND` (if no main lock); `MESSAGE_USING_DEV_CONFIG`; `MESSAGE_CREATE_NEW_LOCK_FILE` or `MESSAGE_RUN_INSTALL_DEV`; `MESSAGE_REBUILT_DEV_CONFIG` |
| Switch to PROD | `composer switch-prod` | `switchToProduction()` → `switchTo(MODE_PROD)` | `switch_initProductionFiles()` (no-op if `composer-prod.json` already exists); `switch_case_DEV_PROD()` backs up the main lock to the dev lock location (if it exists), restores `composer-prod.json` → `composer.json`, then copies the prod lock → main lock if one exists, or deletes the main lock otherwise | `MESSAGE_NO_LOCK_FILE_FOUND` (if no main lock); `MESSAGE_USING_PROD_CONFIG`; `MESSAGE_RUN_INSTALL_PROD` or `MESSAGE_PROD_LOCK_MISSING` |
| Update current config | `composer switch-update` | `switchUpdate()` | None — `isDEV()`/`isPROD()` are both `false`, so neither branch dispatches | *(none)* — returns a `SwitchOutcome` with mode `MODE_INITIAL` and no operations, rather than a silent no-op |
| Reconcile | `composer switch-reconcile` | `reconcile()` | `verify()` runs the normal (non-DEV) comparison; if `composer-prod.json` does not exist yet, `resolveReconcileDirection()` calls `requireModifiedDate()` on it and **throws `ComposerSwitcherException::ERROR_CANNOT_GET_MODIFIED_DATE`** — `reconcile()` has no INITIAL-specific short-circuit the way `verify()` special-cases DEV mode. Only safe to call after at least one switch has created `composer-prod.json`. | `MESSAGE_ALREADY_IN_SYNC` if the (likely non-existent) prod file happens to compare equal; otherwise the exception above |
| Describe state | `composer switch-describe` / `switch-describe-json` | `describe()` | None (read-only, never throws) | Reports mode `initial` |
| Preview a switch | `composer switch-preview-dev` / `switch-preview-prod` | `previewSwitch(MODE_DEV \| MODE_PROD)` | Same as the corresponding real switch above, routed through the `FileSystem` dry-run overlay — nothing is written to disk | Same codes as the corresponding real switch, plus `MESSAGE_DRY_RUN_ACTIVE` |

## DEV state

| Intended Action | Command | PHP Call | File Effects | Messages Emitted |
|---|---|---|---|---|
| Switch to DEV (refresh) | `composer switch-dev` | `switchToDevelopment()` → `switchTo(MODE_DEV)` → `switch_case_DEV_DEV()` | `switch_adjustConfigForDev()` rewrites `composer.json` from the prod baseline plus the current `local-repositories.json`. No lock-file copying (already DEV) | `MESSAGE_NO_LOCK_FILE_FOUND` (if no main lock); `MESSAGE_USING_DEV_CONFIG`; `MESSAGE_REBUILT_DEV_CONFIG` |
| Switch to PROD | `composer switch-prod` | `switchToProduction()` → `switchTo(MODE_PROD)` → `switch_case_DEV_PROD()` | Backs up the main (DEV) lock to the dev lock location if it exists; restores `composer-prod.json` → `composer.json`; copies the prod lock → main lock if one exists, or deletes the main lock otherwise | `MESSAGE_NO_LOCK_FILE_FOUND` (if no main lock); `MESSAGE_USING_PROD_CONFIG`; `MESSAGE_RUN_INSTALL_PROD` or `MESSAGE_PROD_LOCK_MISSING` |
| Update current config | `composer switch-update` | `switchUpdate()` | Dispatches to `switchToDevelopment()` — identical effects to the "Switch to DEV (refresh)" row above | Same as "Switch to DEV (refresh)" |
| Reconcile | `composer switch-reconcile` | `reconcile()` | None — `composer.json` has been rewritten for local repositories and is not meaningful to compare | `MESSAGE_DEV_MODE_NOT_RECONCILABLE` |
| Describe state | `composer switch-describe` / `switch-describe-json` | `describe()` | None (read-only, never throws) | Reports mode `dev`; `getVerification()->isDevMode()` is `true` |
| Preview a switch | `composer switch-preview-dev` / `switch-preview-prod` | `previewSwitch(MODE_DEV \| MODE_PROD)` | Same as the corresponding real switch above, via the `FileSystem` dry-run overlay | Same codes as the corresponding real switch, plus `MESSAGE_DRY_RUN_ACTIVE` |

## PROD state

| Intended Action | Command | PHP Call | File Effects | Messages Emitted |
|---|---|---|---|---|
| Switch to DEV | `composer switch-dev` | `switchToDevelopment()` → `switchTo(MODE_DEV)` → `switch_case_PROD_DEV()` | Backs up the main (PROD) lock to the prod lock location if it exists; restores the dev lock → main lock if it exists, or deletes the main lock otherwise; `switch_adjustConfigForDev()` rewrites `composer.json` | `MESSAGE_NO_LOCK_FILE_FOUND` (if no main lock); `MESSAGE_USING_DEV_CONFIG`; `MESSAGE_CREATE_NEW_LOCK_FILE` or `MESSAGE_RUN_INSTALL_DEV`; `MESSAGE_REBUILT_DEV_CONFIG` |
| Switch to PROD (reconcile in place) | `composer switch-prod` | `switchToProduction()` → `switchTo(MODE_PROD)` → `switch_case_PROD_PROD()` → `reconcileCore(null, false)` | Delegates to the same reconciliation core `reconcile()` uses — see the "Reconcile" row below for the exact effects per sub-case | `MESSAGE_USING_PROD_CONFIG` plus whichever reconciliation message applies below |
| Update current config | `composer switch-update` | `switchUpdate()` | Dispatches to `switchToProduction()` — identical effects to the row above | Same as "Switch to PROD (reconcile in place)" |
| Reconcile — already in sync | `composer switch-reconcile` | `reconcile()` | None | `MESSAGE_ALREADY_IN_SYNC` |
| Reconcile — main newer | `composer switch-reconcile` | `reconcile()` (no explicit direction) | `composer.json` → `composer-prod.json` (+ lock, via `tryCopyTo()`) | `MESSAGE_BACKED_UP_MAIN_TO_PROD` |
| Reconcile — prod newer | `composer switch-reconcile` | `reconcile()` (no explicit direction) | `composer-prod.json` → `composer.json` (+ lock, via `tryCopyTo()`) | `MESSAGE_RESTORED_PROD_TO_MAIN` |
| Reconcile — ambiguous (equal mtimes, differing content) | `composer switch-reconcile` | `reconcile()` (no explicit direction) | None — no automatic direction can be chosen | `MESSAGE_RECONCILE_AMBIGUOUS` |
| Reconcile — forced direction | *(programmatic only)* | `reconcile(ConfigSwitcher::RECONCILE_TO_PROD \| RECONCILE_TO_MAIN)` | Same as the corresponding "main newer"/"prod newer" row, regardless of actual mtimes — this is how the ambiguous case is resolved explicitly | `MESSAGE_BACKED_UP_MAIN_TO_PROD` or `MESSAGE_RESTORED_PROD_TO_MAIN` |
| Reconcile — invalid direction | *(programmatic only)* | `reconcile('bogus-value')` | None | Throws `ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION` (context: `KEY_DIRECTION`, `KEY_EXPECTED`) |
| Describe state | `composer switch-describe` / `switch-describe-json` | `describe()` | None (read-only, never throws) | Reports mode `prod`; `getVerification()` reflects the current sync state |
| Preview a switch | `composer switch-preview-dev` / `switch-preview-prod` | `previewSwitch(MODE_DEV \| MODE_PROD)` | Same as the corresponding real switch/reconcile above, via the `FileSystem` dry-run overlay | Same codes as the corresponding real switch, plus `MESSAGE_DRY_RUN_ACTIVE` |

## Cross-cutting notes

- Every row that "switches" (as opposed to reconciling or describing) also writes/removes the flag files (`composer.json.DEV`/`composer.json.PROD`) and persists the new mode via `StatusFile::saveState()` — omitted from the File Effects column above for brevity since it is unconditional across every switch.
- `previewSwitch()` never has file effects of its own beyond the `FileSystem` in-memory overlay: it runs the exact same code path as the real switch, so its `SwitchOutcome::getOperations()` list matches what the real switch would perform, with every `FileOperation::isApplied()` reporting `false`.
- Passing an invalid `$mode` string to `switchTo()`/`switchToDevelopment()`/`switchToProduction()`/`getFlagFile()` throws `ComposerSwitcherException::ERROR_INVALID_SWITCH_MODE` (context: `KEY_MODE`, `KEY_EXPECTED`) before any file effect occurs, regardless of current state.
