# Changelog

## v2.0.0 - Observable switching and PHP 8.4 (Breaking-M)

**Switching can now be previewed, inspected and reconciled without touching any files.**
Switch calls return structured results, and their messages carry numeric codes, so tools and
agents can consume them directly. PHP 8.4 is now required. Verification, git hooks and direct
wiring ship with the library, and existing hand-written wrapper classes keep working.

- Switching: Added dry-run previews of DEV and PROD switches with zero side effects.
- Switching: Added a full state snapshot, also available as JSON, that never fails.
- Switching: Added PROD drift reconciliation with an explicit direction override.
- Switching: Switch calls now return structured outcomes; messages are objects with codes.
- Switching: A missing lock file now records a warning instead of aborting the switch.
- Switching: Update in the initial state now returns a clear no-op result.
- Switching: Duplicate VCS entries are pruned and `require-dev` placement is respected in DEV mode.
- Switching: Fixed similarly named packages being matched by mistake.
- Switching: Status file paths are normalized.
- Verification: Added comparison of the main and production configs, returning a result object.
- PHP: Raised the minimum version to 8.4; PHP 7.3–8.3 are no longer supported.
- Scripts: Added switch-verify-config and switch-install-hooks scripts.
- Scripts: Added switch-describe, switch-preview and switch-reconcile scripts.
- Scripts: Added static entry points and a project-root factory for direct wiring.
- Hooks: Added a shared git hook installer with a bundled pre-commit hook.
- Errors: Exceptions now carry structured context, including the native PHP error.
- Files: Conditional copying now only requires the source file to exist.
- Docs: Added a switching decision table covering every state and action.
- Docs: Added a guide for migrating from 1.x.
- Tests: Added an end-to-end suite running the real Composer binary.
- Tests: Warnings and notices now fail the test run.
- Code: Added static analysis covering sources and tests.

### Breaking Changes

PHP 8.4 or newer is now required. `switchTo()`, `switchToDevelopment()`, `switchToProduction()`
and `switchUpdate()` return a `SwitchOutcome` instead of nothing, so update any override that
declares a `void` return type. `getMessages()` returns `SwitchMessage` objects instead of strings;
use `getMessageTexts()` for plain text. `BaseFile::tryCopyTo()` now copies whenever the source
exists, even if the target does not; add an existence check if you relied on the old behavior.
Existing wrapper classes and the `switch-dev`, `switch-prod` and `switch-update` scripts keep
working. See `docs/migrating-from-1x.md`.

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
