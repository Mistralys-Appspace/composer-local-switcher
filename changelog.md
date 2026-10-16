# Changelog

## v3.3.0 - Moved packages reported once (Minor)

**A managed package moved between `require` and `require-dev` during a DEV session is now reported once, as a move.**
Previously the same package could be reported as an override in both sections when reverting. A
new value object makes this move explicit, and project-root handling has been consolidated.

- Switching: A moved managed package is now reported once, as a move.
- API: Added `State\ManagedEntryOverride` and `RevertResult::getOverrides()`; deprecated
  `getOverriddenManagedEntries()`.
- API: `RevertResult`'s constructor now takes `ManagedEntryOverride[]`. It is only produced by
  `DevConfigTransformer::revert()`; code that reads a `RevertResult` is unaffected.
- Internal: Consolidated project-root derivation.
- Tests: Added Tier 1 and Tier 2 coverage.

## v3.2.0 - Switch output matches the plan (Minor)

**A real switch's console output now matches the layout the v3.1.0 release promised.**
Real `switch-dev`/`switch-prod`/`switch-update` runs no longer show preview wording, name the
transition in the header (`PROD → DEV`, `refresh DEV`), and always close with a framed footer,
even when no Composer command needs to run. The legacy duplicate command line is gone.

- Switching: Real switches no longer print preview/"would" wording; the header names the
  transition being made (`PROD → DEV`, `refresh DEV`).
- Switching: A blocked real switch now renders through the dedicated blocked layout instead of
  the preview-flavored one.
- Switching: Every real switch now closes with a framed "done" footer, including switches that
  need no Composer command or run with `--no-install`.
- Switching: Removed the legacy duplicate "Running `composer …`" line; the command and its
  reason now appear exactly once.
- API: Added `SwitchOutcome::getPreviousMode()` and `ConfigSwitcher::getProjectRoot()`.
- API: `OutcomeRenderer::formatOperation()` now takes an explicit preview flag; `render()` is
  deprecated in favor of the runner's explicit real/preview rendering calls.
- API: `ConfigSwitcher::MESSAGE_COMPOSER_COMMAND` is no longer emitted (kept for back-compat).
- Tests: Added regression coverage for pre-existing gaps (path-repository alias rendering,
  code-0 Detail messages, `composerSwitchProd()`/`composerSwitchUpdate()` false-return path,
  moved-package double-override characterization) and a Tier 2 real-switch transcript check.

## v3.1.0 - Readable switching output (Minor)

**Switch commands now print clear, framed console output and block unsafe version pins.**
A switch is stopped before anything is written when a leftover `version` override, such as an
old 2.x pin, cannot satisfy the package's root constraint. Failed switches now exit non-zero with
the reason printed, plus a hint on how to recover.

- Switching: Switch commands now print readable, framed console output.
- Switching: Added a check that blocks a switch on an unsatisfiable version override (182230).
- Switching: Added a recovery hint after a failed switch (182231).
- Switching: Failures now exit non-zero and print the reason.
- Switching: `-v` now shows file-operation details.
- Switching: Fixed duplicate messages in the output.
- Switching: Fixed a first DEV switch wrongly marking a pruned VCS repository as `[permanent]`.
- Switching: Fixed a package moved between `require` and `require-dev` being duplicated on `switch-prod`.
- API: Added message severity levels, readable on every switch message.
- API: Dev transform results now report version conflicts.
- API: Composer commands can now be rendered as display strings.
- API: Switch entry points now return a success flag.
- Composer: Added `composer/semver` as a dev dependency.

## v3.0.0 - A single source of truth (Breaking-M)

**The production config no longer needs to be maintained by hand.**
Edits made during a DEV session, such as added packages or script changes, are now carried back
into production when you switch back. Switches also run the planned Composer command themselves
and ask for confirmation before changing the main config.

- Switching: The production snapshot is now created and removed automatically during DEV sessions.
- Switching: DEV-time edits are carried back into production when switching to PROD.
- Switching: Local packages are aliased to the version already locked in production.
- Switching: Switch commands now run the planned Composer command; `--no-install` only prints it.
- Switching: Config changes are shown and need confirmation; `--yes` skips it (needed in CI).
- Switching: A retained post-update hook no longer triggers a recursive Composer run.
- Config: The package `version` override is now optional.

### Breaking Changes

The production config and lock files are now transient: untrack them (`git rm --cached`) and add
them to `.gitignore`. The verify and reconcile methods, scripts and result types are removed.
Switch commands now run Composer and require confirmation, so scripts and agent instructions
must pass `-- --yes`. Several message and exception codes are retired, and the switch description
now reports pending production changes instead of reconcile fields. See
[Migrating from 2.x](docs/migrating-from-2x.md) for the full steps.

## v2.0.0 - Observable switching and PHP 8.4 (Breaking-M)

**Switching can now be previewed, inspected and reconciled without touching any files.**
Switch and verify calls return structured results with message codes, so tools and agents can
consume them directly. PHP 8.4 is now required, and Composer scripts follow a consistent
switch-* naming scheme. Projects can also wire up the library directly, with no wrapper code.

- Switching: Added dry-run previews of DEV and PROD switches with zero side effects.
- Switching: Added a full state snapshot, also available as JSON, that never fails.
- Switching: Added PROD drift reconciliation with an explicit direction override.
- Switching: Switch calls now return structured outcomes with numeric message codes.
- Switching: A missing lock file now records a warning instead of aborting the switch.
- Switching: Fixed PROD previews performing real writes when the configs had drifted.
- Switching: Reconcile and update in the initial state now return a clear no-op result.
- Switching: Reconcile no longer fails when the production config is missing.
- Switching: Duplicate VCS entries are pruned and `require-dev` placement is respected in DEV mode.
- Switching: Fixed similarly named packages being matched by mistake.
- Switching: Status file paths are normalized.
- Verification: Added comparison of the main and production configs, returning a result object.
- PHP: Raised the minimum version to 8.4; PHP 7.3–8.3 are no longer supported.
- Scripts: Renamed the verify and hook-install scripts to follow the switch-* convention.
- Scripts: Added switch-describe, switch-preview and switch-reconcile scripts.
- Scripts: Added static entry points and a project-root factory for direct wiring.
- Scripts: Fixed test scripts failing on an unsupported flag.
- Hooks: Added a shared git hook installer with a bundled pre-commit hook.
- Hooks: The pre-commit check no longer false-positives on unrelated path entries.
- Errors: Exceptions now carry structured context, including the native PHP error.
- Files: Copying now overwrites an existing target file.
- Docs: Added a switching decision table covering every state and action.
- Tests: Added an end-to-end suite running the real Composer binary.
- Tests: Hardened the test harness; warnings and notices now fail the run.
- Code: Static analysis now covers the test harness.

### Breaking Changes

PHP 8.4 or newer is now required. The `verify-config` and `install-hooks` scripts are now
`switch-verify-config` and `switch-install-hooks`; update any references. `verify()` returns a
result object instead of an array (use `toArray()` for the old shape), and the switch methods now
return an outcome object instead of nothing. File copying now always overwrites an existing
target, so add an explicit existence check if you relied on the old behavior.

## v1.0.4 - Versioned packages
- Added an optional `version` setting to handle more version constraint setups.

## v1.0.3 - Flag files
- Added flag files to indicate the current configuration mode (DEV or PROD).

## v1.0.2 - Fixed updating lock files
- Fixed an issue where the lock file was not updated when switching configurations.
- Added the `switchUpdate()` method to update the current configuration based on modified dates.

## v1.0.1 - Handling improvements
- Switching PROD to PROD now updates either production configs using modified dates.
- Added some useful messages.
- Improved some error messages.

## v1.0.0 - Initial Release
- First version of the project.
