# Key Data Flows

## 1. Switch to Development Mode

The v3 decision table replaces the old single linear flow with one post-dispatch planner,
`switch_plan()`, which always runs legacy-artifact cleanup first and then dispatches on the
current status × the target mode. Switching to DEV dispatches to one of two rows depending on
whether a DEV session is already active.

```
User calls ConfigSwitcher::switchToDevelopment(): SwitchOutcome
  → switchTo(MODE_DEV): SwitchOutcome
    → Validates mode string
    → switch_plan(MODE_DEV)
      → switch_cleanLegacyArtifacts(): deletes a leftover local-repositories.lock (v2's
        separate DEV lock, which no longer exists under v3); in PROD/INITIAL, also deletes a
        committed-style composer-prod.json/.lock found outside an active DEV session
        (MESSAGE_LEGACY_FILES_FOUND on either)
      → Not already DEV (PROD or INITIAL) → switch_planProdToDev():
        → If the main lock's LockFile::getLockStatus() is Stale: blocked
          (MESSAGE_PROD_LOCK_OUTDATED) — no file effects, run `composer update`/`update --lock`
          first
        → Otherwise:
          → Reads the current local-repositories list (switch_requireCurrentLocalRepositories(),
            throws on a malformed list)
          → Delegates to Utils\DevConfigTransformer::apply($mainConfig, $repos, $mainLock):
            DevTransformResult (a pure, static transform) — run BEFORE any snapshot copy or
            write, so a block records no file operations:
            → Reads the snapshot config as base
            → For each local repo entry, derives the alias version (explicit override, else
              LockFile::getLockedVersion() against the just-snapshotted lock, else null),
              keeps/overwrites the root require/require-dev constraint, preserves
              require-dev placement, and builds/merges the path repository entry via the
              (private, ported) urlMatchesPackageName() boundary check — see api-surface.md
              for the full algorithm
            → Alias validation: every SOURCE_OVERRIDE alias (an explicit `version` in
              local-repositories.json) is checked by Utils\VersionConstraintChecker against the
              package's root require/require-dev constraint; a Violated or Invalid result is
              recorded in DevTransformResult::getVersionConflicts() (a SOURCE_LOCKED alias is
              never checked; Unverifiable — no semver engine loaded, or an unprovable dev alias —
              never conflicts)
          → switch_reportVersionConflicts(): any conflict adds one MESSAGE_VERSION_OVERRIDE_CONFLICT
            (182230, Warning) per package and BLOCKS the switch (switch_blockedPlan()) — nothing
            has been written, no operation recorded
          → Snapshots composer.json/.lock → composer-prod.json/.lock (ConfigFile::copyTo() /
            LockFile::tryCopyTo()) — composer.lock itself stays the PROD lock throughout the
            DEV session
          → Writes DevTransformResult::getConfig() → composer.json; records version
            derivations (MESSAGE_VERSION_DERIVED, Detail level) and stores snapshotHash +
            appliedRepositories in the plan result (persisted below)
          → Plans the command: a Missing main lock plans a full `update`
            (MESSAGE_NO_LOCK_FILE_FOUND); otherwise `update <local package names>` (or no
            command when there are none)
      → Already DEV (a refresh) → switch_planDevRefresh():
        → If the snapshot's content no longer matches its recorded snapshotHash: blocked
          (MESSAGE_SNAPSHOT_MODIFIED) — no file effects, switch to PROD and back to DEV to
          retake it
        → Otherwise: computes effective = DevConfigTransformer::revert(current, snapshot,
          appliedRepos, prodLock) (see the carry-back description below), then
          apply(effective, currentRepos, prodLock) — the same alias validation as above, run
          before composer.json is rewritten, blocking with MESSAGE_VERSION_OVERRIDE_CONFLICT
          (182230) on a conflict; composer.json is rewritten only when the
          resulting ConfigDiff is non-empty — an unedited refresh leaves the file
          byte-for-byte untouched, including non-canonical formatting
        → Plans the command: a repository delta (added/path-changed/override-changed/removed
          package) plans a partial `update` (with `--with <removed>:<prod-locked version>` per
          removed package); with no delta, `install` unless InstalledState::Matches
          (MESSAGE_ALREADY_INSTALLED)
    → If not blocked: StatusFile::saveState('dev', ..., snapshotHash, appliedRepositories) —
      persists mode + timestamp + file paths + the tamper-detection hash as JSON
    → Writes flag files: creates composer.json.DEV, deletes composer.json.PROD
    → Displays collected messages (ConfigSwitcher's legacy echo channel, via
      autoDisplayMessages(); SwitchCommandRunner turns it off around its own real switchTo(), see §7a)
    → Returns a SwitchOutcome (mode, dry-run flag, messages, file operations, the planned
      ComposerCommand (if any), isBlocked(), the ConfigChangeSet, previousMode — the mode read
      from StatusFile::getMode() ?? MODE_INITIAL before this switch overwrote it) — a blocked
      outcome carries no file effects
```

## 2. Switch to Production Mode

Switching to PROD also dispatches on whether a DEV session is active: a DEV→PROD switch carries
DEV-time edits back into production via a three-way revert; a PROD/INITIAL→PROD switch has no
file effects on the configs at all.

```
User calls ConfigSwitcher::switchToProduction(): SwitchOutcome
  → switchTo(MODE_PROD): SwitchOutcome
    → Validates mode string
    → switch_plan(MODE_PROD)
      → switch_cleanLegacyArtifacts()  — same as §1
      → Already DEV → switch_planDevToProd() — see §4 (DEV→PROD carry-back)
      → PROD or INITIAL → switch_planProdToProd():
        → No file effects beyond legacy cleanup/status/flags — composer.json/.lock and
          composer-prod.* are left entirely alone
        → Plans `install` unless InstalledState::fromInstalledPackages(MODE_PROD,
          currentLocalPackageNames, installed) already reports Matches
          (MESSAGE_ALREADY_INSTALLED, no command)
    → If not blocked: StatusFile::saveState('prod', ...)
    → Writes flag files: creates composer.json.PROD, deletes composer.json.DEV
    → Displays collected messages
    → Returns a SwitchOutcome (mode, dry-run flag, messages, file operations, planned
      ComposerCommand, isBlocked(), ConfigChangeSet, previousMode)
```

## 3. Update Current Configuration

```
User calls ConfigSwitcher::switchUpdate(): SwitchOutcome
  → Reads StatusFile to determine current mode
  → If DEV: calls switchToDevelopment() (the DEV→DEV refresh row of §1)
  → If PROD or INITIAL: calls switchToProduction() (the PROD/INITIAL→PROD row of §2 — INITIAL
    no longer returns a bespoke MODE_INITIAL no-op outcome; it runs the same row as an
    established PROD state, which has no file effects to begin with)
```

## 4. DEV→PROD Carry-Back

`switch_planDevToProd()` is the DEV→PROD row of the v3 decision table (dispatched from §2 above).
It replaces the old restore-from-backup flow with a three-way revert that carries DEV-time edits
back into production instead of discarding them, and the production snapshot is consumed
(deleted) rather than kept as a committed baseline. The standalone `reconcile()`/`verify()`
entry points that previously appeared in this section (and the `composer switch-reconcile`/
`switch-verify-config` commands that drove them) have been removed entirely: they existed only to
reconcile two committed, editable copies of the production config, a state the transient-snapshot
model described above no longer has.

```
switch_planDevToProd(): array{blocked, command, changes, snapshotHash, appliedRepositories}
  → Adds MESSAGE_USING_PROD_CONFIG
  → If the production snapshot (composer-prod.json) does not exist: blocked
    (MESSAGE_SNAPSHOT_MISSING) — no file effects, cannot switch back to PROD safely
  → If the snapshot's content no longer matches its recorded snapshotHash: blocked
    (MESSAGE_SNAPSHOT_MODIFIED) — no file effects
  → Otherwise:
    → revertResult = DevConfigTransformer::revert($current, $snapshotConfig, $appliedRepos,
      $prodLock) — base = apply($snapshotConfig, $appliedRepos, $prodLock), current = the live
      DEV composer.json, target = $snapshotConfig; a managed (local) package entry always takes
      the snapshot value (recording MESSAGE_MANAGED_ENTRY_OVERRIDDEN if the user edited it
      anyway); every other key/package takes the current value, carrying `composer
      require`/`remove`/config edits back. revert() identifies a managed package across both
      `require` and `require-dev` before applying this snapshot-value override, so a package the
      user moved between the two sections during DEV is restored once, in its snapshot section —
      never duplicated into both — and reported once, as a move (a single ManagedEntryOverride
      with isMove() === true, naming both the section it moved from and the one it moved to),
      never as two separate overrides
    → Writes revertResult->getEffectiveConfig() → composer.json
    → If a composer-prod.lock backup exists: restores it → composer.lock; otherwise
      force-deletes composer.lock (a DEV-session lock cannot be trusted as a PROD lock) and
      records MESSAGE_PROD_LOCK_MISSING
    → Deletes the now-applied, transient composer-prod.json/.lock
    → If the resulting ConfigChangeSet's prodConfig section is non-empty: adds
      MESSAGE_DEV_CHANGES_CARRIED_BACK, listing the changed keys/packages
    → Plans the command: no snapshot lock backup → full `update`; else a changed package list
      → partial `update <packages>`; else a Stale resulting lock → `update --lock`; else
      `install` unless InstalledState::Matches (MESSAGE_ALREADY_INSTALLED)
```

## 5a. Describe Current State

```
User calls ConfigSwitcher::describe(): SwitchDescription
  → Reads StatusFile: mode, last switch date
  → Builds a per-file record (label, path, exists, modifiedDate) for:
    main/prod/dev configs, the status file, and all three lock files
  → Determines the active flag file (dev/prod/none)
  → Reads the dev config's local-repositories list via
    LocalRepository::parseListLenient() (src/State/LocalRepository.php)
    → If the dev file is missing or malformed: returns an empty list
      and attaches a warning instead of throwing
    → Each entry's derivedVersion is its versionOverride, or else the
      reference lock's (the prod snapshot's lock in DEV, the main
      lock otherwise) getLockedVersion() for that package
  → Computes installedState: InstalledState::fromInstalledPackages(),
    mode-aware (DEV: every local package must be path-installed;
    PROD/INITIAL: none may be) against switch_installedPackages()
  → Computes pendingProdChanges (DEV only): reuses the same
    revert() + ConfigDiff + makeOriginClassifier() pipeline
    switch_planDevToProd() uses (prodConfig section only) —
    degrades to null plus a warning (never throws) when the
    snapshot is missing, modified, or unreadable
  → Surfaces legacy v2 artifacts (a leftover local-repositories.lock,
    or a composer-prod.json found outside an active DEV session) as
    warnings — read-only, describe() never cleans them up itself
  → Returns a new SwitchDescription(mode, lastSwitchDate, files,
    activeFlag, lockStatus, installedState, pendingProdChanges,
    localRepositories, warnings)
  → No files are modified (read-only), never throws

SwitchDescription::toJSON(): string
  → Encodes toArray() with JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
```

## 5b. Preview a Switch (Dry Run)

```
User calls ConfigSwitcher::previewSwitch(string $mode): SwitchOutcome
  → Validates $mode
  → Suppresses message display for the duration of the call
  → Calls switchTo($mode, dryRun: true)
    → Sets the facade's dry-run flag and adds MESSAGE_DRY_RUN_ACTIVE
    → Runs the exact same switch code path as a real switchTo() call,
      routed through the FileSystem dry-run overlay so no file is
      actually copied/written/deleted
    → Restores the previous dry-run flag in a finally block, even if
      an exception is thrown mid-switch
  → Returns a SwitchOutcome whose getOperations()/getComposerCommand()/getConfigChanges()
    match exactly what a real switch from the same starting state would perform (every
    FileOperation reports isApplied(): false) — including from the INITIAL state, where
    composer-prod.json does not yet exist. A `previewSwitch(MODE_PROD)` preview in PROD mode
    runs switch_planProdToProd(), which has no file effects of its own beyond legacy
    cleanup/status/flags (composer-prod.json/.lock are a transient DEV-session snapshot, not a
    committed baseline to compare against — see §4), so it only ever plans (or skips) an `install`
```

## 6. Install Git Hooks

```
User calls ConfigSwitcher::installGitHooks($projectRoot)
  → Checks if $projectRoot/.git/hooks/ directory exists
  → If missing: returns false (no directory creation, no exception)
  → Copies resources/git-hooks/pre-commit → $projectRoot/.git/hooks/pre-commit
  → Sets permissions to 0755
  → Returns true
```

## 7. Composer Script Entry Points

```
Consumer wires a built-in entry point in composer.json scripts
  → Composer invokes e.g. ConfigSwitcher::composerSwitchDev(?object $event = null)
    → ConfigSwitcher::guardEntryPoint($event, $action) (switch/update) or buildRunner($event)
      (preview), both ending in buildRunnerFromContext($context):
      → EventContext::fromEvent($event) — the only place the library duck-types
        Composer's event/IO (see §7a)
      → fromProjectRoot(getcwd()) — a ConfigSwitcher for the standard three-path layout
      → new ComposerProcess() — the real process runner
      → new OutcomeRenderer($context, new ConfigChangeFormatter(<project root>))
      → Returns a Utils\SwitchCommandRunner wired from all four
    → Delegates to the runner: runSwitch($mode) / runUpdate() / runPreview($mode)
```

`composerSwitchDev`/`Prod`/`Update` route through `guardEntryPoint()`, which catches `ComposerSwitcherException` codes `ERROR_SWITCH_BLOCKED`, `ERROR_CONFIRMATION_REQUIRED`, `ERROR_INPUTS_CHANGED` and `ERROR_COMPOSER_COMMAND_FAILED`, writes the message as a styled line and returns `false` (Composer then exits non-zero without a stack box); success returns `true`, and any other exception propagates.

Available entry points: `composerSwitchDev`, `composerSwitchProd`, `composerSwitchUpdate`, `composerInstallHooks`, `composerSwitchDescribe`, `composerSwitchDescribeJson`, `composerSwitchPreviewDev`, `composerSwitchPreviewProd`. The switch/update entry points return `bool` (see above); the describe/preview ones return `void`. Every one of these (other than `composerInstallHooks`, which just calls `installGitHooks()` directly) now takes `?object $event = null` and is a one-line delegation — either to `buildRunner($event)->runSwitch()/runUpdate()/runPreview()`, or, for the two describe statics, to an `EventContext`-backed `OutcomeRenderer::renderDescription()`/`EventContext::write(describe()->toJSON())`. `ConfigSwitcher` itself never executes Composer or prompts the user — `Utils\SwitchCommandRunner` (see §7a) is the only place that does. `composerVerifyConfig`/`composerSwitchReconcile` were removed along with `verify()`/`reconcile()`.

## 7a. Confirmation Sequence (`Utils\SwitchCommandRunner`)

Every switch/update entry point above delegates to `SwitchCommandRunner::runSwitch($mode)` (or
`runUpdate()`, which resolves the current mode and calls `runSwitch()`), the only place in the
library that executes Composer or prompts the user. A preview entry point
(`composerSwitchPreviewDev`/`Prod`) calls `runPreview($mode)` instead, which previews and renders
via `renderPlanned($outcome, true)` — no nested-run guard, no confirmation, no command execution.
`renderPlanned(SwitchOutcome $outcome, bool $isPreview)` is `SwitchCommandRunner`'s single
dispatch point for both planning moments (`runSwitch()`'s pre-confirmation plan and the whole of
`runPreview()`): `renderer->renderBlocked($outcome)` when blocked, else
`renderer->renderPlan($outcome, $isPreview)` — so a true preview still reads "preview of switch …
(nothing will be changed)" and "Would run:", while a real switch's own plan (`$isPreview = false`)
reads "switch …" and "Next:".

```
SwitchCommandRunner::runSwitch(string $mode): void
  → If COMPOSER_SWITCHER_NESTED is set (this process was itself launched by the switcher,
    e.g. a leftover post-update-cmd hook firing during the switcher's own `composer update`):
    writes MESSAGE_NESTED_RUN_SKIPPED and returns — no preview, no file effects
  → first = $switcher->previewSwitch($mode)
  → renderPlanned($first, false) — SwitchCommandRunner's own private dispatch: calls
    OutcomeRenderer::renderBlocked($first) if blocked, otherwise renderPlan($first, false) —
    always, with or without --yes. The `false` here is runSwitch()'s own moment (a real switch's
    pre-confirmation plan), deliberately not $first->isDryRun() (which is always true for a
    pre-rendered outcome and which the deprecated OutcomeRenderer::render() wrongly conflates with
    "preview" — see §"OutcomeRenderer" in api-surface.md). So this block's header reads
    "Composer Local Switcher — switch PROD → DEV" (the transition named by the private
    describeTransition(), reading $first->getPreviousMode() — "refresh DEV" for a same-mode
    refresh, the legacy "to DEV" wording only for an outcome built with no previous mode) with no
    "preview"/"nothing will be changed" wording, and the command line is labelled "Next:". It
    shows: the ConfigChangeSet (one readable line per change via ConfigChangeFormatter,
    [permanent]/[discarded] markers), Info/Warning messages (Warning prefixed "!"), the planned
    command via ComposerCommand::toDisplayString(), and — at -v only — the file operations
    (formatOperation($operation, false) → "will <type>: …") and Detail messages (the latter with
    their [code] prefix)
  → If $first->isBlocked(): renderBlocked() has already written
    "Composer Local Switcher — switch PROD → DEV blocked, nothing was changed" plus the messages
    explaining why; then throws ERROR_SWITCH_BLOCKED — stops here, nothing written (e.g. a
    182230 version-override conflict, 182217 stale lock, 182218/182219 snapshot problems)
  → If $first->requiresConfirmation() (composer.json itself would change):
    → confirmSwitch($first):
      → --yes flag present: confirmed, no prompt
      → Context is interactive: EventContext::confirm($question, $default), $default = "no"
        when the change set has a [permanent]/[discarded] entry (CarriedBack/Discarded origin,
        or ConfigChangeSet::hasPermanentChanges()), "yes" otherwise
      → Context is non-interactive without --yes: writes MESSAGE_CONFIRMATION_REQUIRED,
        throws ERROR_CONFIRMATION_REQUIRED — nothing written
      → Declined: writes MESSAGE_SWITCH_CANCELLED, returns false — runSwitch() returns
        normally (exit 0), nothing written
    → If declined: return
  → second = $switcher->previewSwitch($mode) — a second, independent preview
  → If !$second->hasSameEffectsAs($first): throws ERROR_INPUTS_CHANGED — the shown-vs-applied
    guard; nothing written even though $first was already rendered
  → previous = $switcher->isDisplayMessagesEnabled(); $switcher->setDisplayMessages(false)
    — so the legacy echo channel does not print every message a second time
  → outcome = $switcher->switchTo($mode) — the real switch, now writes to disk; `finally`
    restores setDisplayMessages(previous), also when switchTo() throws
  → runCommand($outcome):
    → No planned command: OutcomeRenderer::renderDone() ("… switch to DEV done") and return —
      one of the two footer-only exits (see below) now that every path through this method ends
      framed
    → --no-install flag: writes MESSAGE_COMPOSER_SKIPPED (prints the command instead of
      running it), then OutcomeRenderer::renderDone(), and returns
    → --with-dependencies flag, and the command is a partial `update <packages>` (not a full
      `update` or `install`): appends --with-dependencies
    → Parent context is non-interactive: appends --no-interaction, so the child process never
      blocks on a prompt the parent couldn't have answered either
    → OutcomeRenderer::renderCommandStart() ("Composer Local Switcher — running `composer …`"),
      then ComposerProcess::run() spawns the real `composer` child process
      (COMPOSER_SWITCHER_NESTED=1 in its environment — see §6/§7); the legacy
      MESSAGE_COMPOSER_COMMAND announcement is no longer written here, since
      renderCommandStart() already names the command and its reason
    → Exit code 0: OutcomeRenderer::renderDone() ("… switch to DEV done") — the third and last
      path to this same footer
    → Non-zero exit code (failure/recovery flow): OutcomeRenderer::renderFailure() ("… composer
      command failed (exit code N)" plus a command/reason recap), then MESSAGE_COMPOSER_RECOVERY
      (182231, Warning) naming the mode now active and the two ways forward — fix the cause and
      run `composer switch-update`, or `composer switch-prod` to fall back — then throws
      ERROR_COMPOSER_COMMAND_FAILED (context: command, exitCode). No rollback is attempted:
      Composer may already have written a new lock, and an automatic restore would be an
      unconfirmed second transition
```

At the entry point, `guardEntryPoint()` catches ERROR_SWITCH_BLOCKED, ERROR_CONFIRMATION_REQUIRED,
ERROR_INPUTS_CHANGED and ERROR_COMPOSER_COMMAND_FAILED — the runner has already printed the
reason — writes the exception message as one styled line and returns `false`, so Composer exits
non-zero without a PHP stack box. The OutcomeRenderer output moments, in order of a successful
real switch, are therefore: plan block (real-switch header naming the transition, "Next:" command
line, no preview wording) → confirmation prompt → command start → Composer's own output → done
footer.

`EventContext::fromEvent(?object $event)` is the sole duck-typing site for Composer's
event/IO (`getArguments()`/`getIO()`/`isInteractive()`/`isVerbose()`/`askConfirmation()`/`write()`, each
probed once via `method_exists()`); everything above consumes the typed `EventContext` instead.
`hasFlag('--yes'|'--no-install'|'--with-dependencies')` reads `$event->getArguments()`
(string-filtered), so these flags are passed after Composer's own `--` separator (e.g.
`composer switch-dev -- --yes`).

## File Relationships

```
composer.json              ← mutable working copy — the PROD source of truth; rewritten to
                              DEV content for the duration of a DEV session
composer.lock               ← follows the active configuration (stays the PROD lock
                              throughout a DEV session — never a separate DEV lock)
composer-prod.json          ← transient production snapshot — exists ONLY during an active
                              DEV session; created by switch_planProdToDev(), consumed and
                              deleted by switch_planDevToProd() (not committed to git)
composer-prod.lock          ← the snapshot's lock file backup, same transient lifetime
local-repositories.json     ← local-repositories list (input only, never modified)
local-repositories.status   ← JSON status file (mode, date, file paths, snapshotHash,
                              appliedRepositories)
composer.json.DEV           ← flag file (exists only in DEV mode)
composer.json.PROD          ← flag file (exists only in PROD mode)
```

A committed-style `composer-prod.json`/`.lock` found outside an active DEV session, or a
leftover `local-repositories.lock` (v2's separate DEV lock, which has no v3 equivalent — see
§1/§2), are v2-era legacy artifacts: `switch_cleanLegacyArtifacts()` removes them at the start
of every switch, regardless of direction, recording `MESSAGE_LEGACY_FILES_FOUND`.
