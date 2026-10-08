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

## The v3 decision table: one post-dispatch planner

Every `switchTo($mode)` call (and its `switchToDevelopment()`/`switchToProduction()`/
`switchUpdate()`/`previewSwitch()` wrappers) runs through a single private planner,
`switch_plan($mode)`:

1. `switch_cleanLegacyArtifacts()` always runs first, regardless of direction — see
   "Legacy cleanup" below.
2. The planner then dispatches to exactly one of four row-specific methods, based on the
   current status and the target mode:

| Current state | Target mode | Row |
|---|---|---|
| PROD or INITIAL | DEV | `switch_planProdToDev()` |
| DEV | DEV (a refresh) | `switch_planDevRefresh()` |
| DEV | PROD | `switch_planDevToProd()` |
| PROD or INITIAL | PROD | `switch_planProdToProd()` |

Each row returns `array{blocked, command, changes, snapshotHash, appliedRepositories}`. A
**blocked** plan (`switch_blockedPlan()`) has no file effects at all — only the messages
recorded before the block was detected — and `SwitchOutcome::isBlocked()` reports `true`. An
unblocked plan writes its own file effects, computes a `ConfigChangeSet`, and plans at most one
`ComposerCommand` — `ConfigSwitcher` itself never executes Composer; that is the entry-point
edge's job (see `Notes` in the WP-008 ledger entry and the plan's confirmation/execution work).

`switchUpdate()` reads the current mode and dispatches to whichever of
`switchToDevelopment()`/`switchToProduction()` applies: DEV → the DEV→DEV refresh row; PROD
**or INITIAL** → the PROD/INITIAL→PROD row (INITIAL no longer returns a bespoke `MODE_INITIAL`
no-op outcome — it simply runs the PROD/INITIAL→PROD row, which already has no file effects to
begin with).

## Legacy cleanup (every switch, regardless of direction)

`switch_cleanLegacyArtifacts()` removes v2-era artifacts that have no place under the v3
transient-snapshot model, recording `MESSAGE_LEGACY_FILES_FOUND` (182225) if anything was found:

| Legacy artifact | Found when | Cleanup |
|---|---|---|
| `local-repositories.lock` | Always checked | Deleted unconditionally — v2's separate DEV lock backup has no v3 equivalent; `composer.lock` always stays the PROD lock throughout a DEV session |
| Committed-style `composer-prod.json`/`.lock` | Only checked when the switch starts from PROD or INITIAL | Switching **to DEV**: left in place to be overwritten by the normal snapshot step below. Switching **to PROD** (staying in PROD, or INITIAL→PROD): deleted outright — it has no reason to exist outside an active DEV session |

## Row 1 — PROD/INITIAL→DEV (`switch_planProdToDev()`)

| | |
|---|---|
| **Command** | `composer switch-dev` |
| **PHP call** | `switchToDevelopment()` → `switchTo(MODE_DEV)` → `switch_planProdToDev()` |
| **Blocked when** | The main lock's `LockFile::getLockStatus()` is `LockStatus::Stale` — the committed PROD state is already inconsistent, so a partial update would silently update the wrong things. No file effects; `MESSAGE_PROD_LOCK_OUTDATED` (182217) |
| **File effects (unblocked)** | Snapshots `composer.json`/`.lock` → `composer-prod.json`/`.lock` (`ConfigFile::copyTo()` / `LockFile::tryCopyTo()`) — `composer.lock` itself is **not** overwritten and stays the PROD lock throughout the DEV session. Reads the current `local-repositories.json` list (`switch_requireCurrentLocalRepositories()`, throws `ERROR_INVALID_JSON_STRUCTURE` on a malformed list). Writes `DevConfigTransformer::apply($snapshotConfig, $repos, $mainLock)` → `composer.json`. Computes and stores `snapshotHash` (hash of the just-taken snapshot's config+lock content) and `appliedRepositories` in the status file |
| **Command planned** | Main lock `Missing` → full `update` (`MESSAGE_NO_LOCK_FILE_FOUND`, 182201). Otherwise → `update <local package names>` (partial), or no command when there are no local packages |
| **Other messages** | `MESSAGE_USING_DEV_CONFIG` (182203); `MESSAGE_REBUILT_DEV_CONFIG` (182209); `MESSAGE_VERSION_DERIVED` (182226) per package whose alias version was derived from an override or the PROD lock |

## Row 2 — DEV→DEV refresh (`switch_planDevRefresh()`)

| | |
|---|---|
| **Command** | `composer switch-dev` or `composer switch-update` while already in DEV |
| **PHP call** | `switchToDevelopment()` → `switchTo(MODE_DEV)` → `switch_planDevRefresh()` |
| **Blocked when** | The production snapshot's current content no longer matches its recorded `snapshotHash` — it was edited outside the switcher's own control since it was taken. No file effects; `MESSAGE_SNAPSHOT_MODIFIED` (182218). Remedy: switch to PROD and back to DEV to retake the snapshot |
| **File effects (unblocked)** | Computes `effective = DevConfigTransformer::revert($current, $snapshotConfig, $appliedRepos, $prodLock)` (see Row 3 for the three-way revert semantics), then `apply($effective, $currentRepos, $prodLock)`. `composer.json` is rewritten **only when** the resulting `ConfigDiff` is non-empty — with no delta (nothing changed in `local-repositories.json` and no DEV edit to carry through), no write operation is recorded and the file stays byte-for-byte unchanged, including non-canonical formatting |
| **Command planned** | A repository delta (added, path-changed, override-changed, or removed package by name) → partial `update <changed/added names>`, with `--with <removed>:<prod-locked version>` appended per removed package. No delta → `install` unless `InstalledState::fromInstalledPackages(MODE_DEV, ...)` already reports `Matches` (`MESSAGE_ALREADY_INSTALLED`, 182227, no command) |
| **Other messages** | `MESSAGE_USING_DEV_CONFIG`; `MESSAGE_REBUILT_DEV_CONFIG` (only when the file was actually rewritten); `MESSAGE_MANAGED_ENTRY_OVERRIDDEN` (182221) per managed package the user edited directly in DEV (reset to its snapshot value); `MESSAGE_VERSION_DERIVED` |

## Row 3 — DEV→PROD (`switch_planDevToProd()`)

| | |
|---|---|
| **Command** | `composer switch-prod` |
| **PHP call** | `switchToProduction()` → `switchTo(MODE_PROD)` → `switch_planDevToProd()` |
| **Blocked when** | The production snapshot (`composer-prod.json`) is missing entirely (`MESSAGE_SNAPSHOT_MISSING`, 182219 — cannot switch back to PROD safely), **or** its content no longer matches its recorded `snapshotHash` (`MESSAGE_SNAPSHOT_MODIFIED`, 182218). Either way: no file effects |
| **File effects (unblocked)** | `revertResult = DevConfigTransformer::revert($current, $snapshotConfig, $appliedRepos, $prodLock)` — a three-way diff: base = `apply($snapshotConfig, $appliedRepos, $prodLock)`, current = the live DEV `composer.json`, target = `$snapshotConfig`. A **managed** (local) package entry always takes the snapshot value — or is dropped entirely when the snapshot has none — recording `MESSAGE_MANAGED_ENTRY_OVERRIDDEN` if the user edited it anyway directly in DEV. Every **other** top-level key/package takes the current value, so a `composer require`/`remove`/config edit made while in DEV is carried back into production. Writes `revertResult->getEffectiveConfig()` → `composer.json`. If a `composer-prod.lock` backup exists: restores it → `composer.lock`; otherwise force-deletes `composer.lock` (a DEV-session lock cannot be trusted as a PROD lock) and records `MESSAGE_PROD_LOCK_MISSING` (182214). Deletes the now-applied, transient `composer-prod.json`/`.lock` |
| **Command planned** | No snapshot lock backup existed → full `update` (`MESSAGE_PROD_LOCK_MISSING`). Otherwise: changed production packages → partial `update <changed packages>`; else a `Stale` resulting lock (a non-package config change needs a hash refresh) → `update --lock`; else `install` unless `InstalledState::fromInstalledPackages(MODE_PROD, ...)` reports `Matches` (`MESSAGE_ALREADY_INSTALLED`, no command) |
| **Other messages** | `MESSAGE_USING_PROD_CONFIG` (182204); `MESSAGE_DEV_CHANGES_CARRIED_BACK` (182220) when the resulting `ConfigChangeSet`'s `prodConfig` section is non-empty — listing the changed keys and packages |

## Row 4 — PROD/INITIAL→PROD (`switch_planProdToProd()`)

| | |
|---|---|
| **Command** | `composer switch-prod` (already in PROD), or `composer switch-dev`/`switch-update` dispatching here from the INITIAL state |
| **PHP call** | `switchToProduction()` → `switchTo(MODE_PROD)` → `switch_planProdToProd()` |
| **Blocked when** | Never — this row has no preconditions |
| **File effects** | **None**, beyond the unconditional legacy cleanup, status file write, and flag files — `composer.json`/`.lock` and `composer-prod.*` are left entirely alone. There is nothing to reconcile: `composer-prod.json`/`.lock` are a transient DEV-session snapshot under the v3 model, not a second committed baseline that could drift from `composer.json` while staying in PROD |
| **Command planned** | `install` unless `InstalledState::fromInstalledPackages(MODE_PROD, currentLocalPackageNames, installed)` already reports `Matches` (`MESSAGE_ALREADY_INSTALLED`, no command). The current local package names are read leniently (`switch_currentLocalPackageNamesLenient()`, via `LocalRepository::parseListLenient()`) so this file-effect-free row never throws regardless of DEV config state |
| **Other messages** | `MESSAGE_USING_PROD_CONFIG` |

## Describe and preview

| Intended Action | Command | PHP Call | File Effects | Messages Emitted |
|---|---|---|---|---|
| Describe state | `composer switch-describe` / `switch-describe-json` | `describe()` | None (read-only, never throws) | Reports mode `initial`/`dev`/`prod`; `getLockStatus()`/`getInstalledState()` reflect the main lock's own freshness and whether what's installed matches the active mode; `getPendingProdChanges()` (DEV only) reports what a DEV→PROD switch would carry back, `null` plus a warning if it could not be computed; legacy v2 artifacts surface as warnings |
| Preview a switch | `composer switch-preview-dev` / `switch-preview-prod` | `previewSwitch(MODE_DEV \| MODE_PROD)` | Same as the corresponding real row above, routed through the `FileSystem` dry-run overlay — nothing is written to disk. Because it runs the exact same `switch_plan()` code path, `getOperations()`/`getComposerCommand()`/`getConfigChanges()` match exactly what the real switch would do, including from the `INITIAL` state. A PROD-mode preview runs Row 4 (`switch_planProdToProd()`), which has no file effects of its own, so it only ever plans (or skips) an `install` | Same codes as the corresponding real switch, plus `MESSAGE_DRY_RUN_ACTIVE` |

> The standalone `reconcile()`/`verify()` pair (and `composer switch-reconcile`/`switch-verify-config`) that
> previously appeared in this section were removed once `composer-prod.json`/`.lock` became a transient
> DEV-session snapshot rather than a second committed baseline — there is no drift between two editable
> copies left to detect or correct.

## Confirmation (entry-point edge)

`ConfigSwitcher::switchTo()`/`previewSwitch()` never prompt or execute Composer themselves — this is
handled entirely by `Utils\SwitchCommandRunner`, which every `composerSwitch*`/`composerSwitchPreview*`
static entry point delegates to (see [data-flows.md](data-flows.md) §7a for the full sequence). The
rows above determine whether a given switch prompts:

| Row | Prompts? | Default |
|---|---|---|
| Row 1 — PROD/INITIAL→DEV | Yes — writes `composer.json` (the DEV transform) | "no" if the resulting `ConfigChangeSet` has a `[permanent]`/`[discarded]` entry (rare on a fresh DEV switch), "yes" otherwise |
| Row 2 — DEV→DEV refresh | Only if the refresh's `ConfigDiff` is non-empty (an unedited refresh never prompts) | "no" if a managed entry was discarded (`MESSAGE_MANAGED_ENTRY_OVERRIDDEN`), "yes" otherwise |
| Row 3 — DEV→PROD | Yes whenever `MESSAGE_DEV_CHANGES_CARRIED_BACK` would be recorded (the `prodConfig` section is non-empty) — **always "no"** by default, since a DEV→PROD switch is exactly the case `[permanent]`/`[discarded]` markers exist for | "no" |
| Row 4 — PROD/INITIAL→PROD | Never — no `composer.json` change at all | n/a |

A blocked row (see each row's "Blocked when") never reaches confirmation — `SwitchCommandRunner`
renders the blocked outcome and throws `ERROR_SWITCH_BLOCKED` before the confirmation step. `--yes`
skips the prompt unconditionally; a non-interactive context without `--yes` throws
`ERROR_CONFIRMATION_REQUIRED` instead of applying the default.

## Cross-cutting notes

- Every row that "switches" (as opposed to describing) also writes/removes the
  flag files (`composer.json.DEV`/`composer.json.PROD`) and persists the new mode via
  `StatusFile::saveState()` (including `snapshotHash`/`appliedRepositories` for the two DEV-side
  rows) — omitted from the tables above for brevity since it is unconditional across every
  switch, and skipped entirely when the plan is blocked.
- A blocked plan (`switch_blockedPlan()`) never writes the status file, flag files, or any
  config/lock file — a blocked `SwitchOutcome::isBlocked()` outcome is a pure no-op with
  messages only.
- Passing an invalid `$mode` string to `switchTo()`/`switchToDevelopment()`/`switchToProduction()`/`getFlagFile()`
  throws `ComposerSwitcherException::ERROR_INVALID_SWITCH_MODE` (context: `KEY_MODE`,
  `KEY_EXPECTED`) before any file effect occurs, regardless of current state.
- The retired v2 constants `MESSAGE_CREATE_NEW_LOCK_FILE` (182202), `MESSAGE_RUN_INSTALL_PROD`
  (182207) and `MESSAGE_RUN_INSTALL_DEV` (182208) no longer exist — their only callers were the
  four `switch_case_*` methods this decision table's `switch_plan()` planner replaced, along with
  `switch_initProductionFiles()` (dead under v3, since the snapshot is now taken by every switch
  to DEV and a PROD/INITIAL→PROD switch has no file effects to seed). The retired reconcile-family
  constants `MESSAGE_BACKED_UP_MAIN_TO_PROD`/`MESSAGE_RESTORED_PROD_TO_MAIN`/`MESSAGE_ALREADY_IN_SYNC`/
  `MESSAGE_RECONCILE_AMBIGUOUS`/`MESSAGE_DEV_MODE_NOT_RECONCILABLE`/`MESSAGE_INITIAL_NOT_RECONCILABLE`/
  `MESSAGE_PROD_CONFIG_MISSING` (182205/182206/182210–182212/182215/182216) and
  `ERROR_INVALID_RECONCILE_DIRECTION` (182111) also no longer exist — their only caller was the
  now-deleted `reconcile()`/`reconcileCore()`/`verify()` family.
- `switchTo()` owns the facade's dry-run flag and operation list for each call — it clears the
  operation list at the start of every call and restores the dry-run flag to its prior value in a
  `finally` block, even if the call throws partway through (e.g. on a malformed `composer.json`);
  a subsequent real call always sees a clean, non-dry-run facade.
