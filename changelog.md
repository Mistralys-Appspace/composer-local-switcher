# Changelog

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
