# Changelog

## v3.0.0 - Observable, programmatically consumable switching

**BREAKING:**
- `ConfigSwitcher::verify()` now returns a `VerificationResult` object instead of an array. Replace array-key access (`$result['inSync']`, `$result['differences']`) with `$result->isInSync()`/`$result->getDifferences()`; `$result->isDevMode()`/`isComparable()` are new. `toArray()` returns the equivalent array shape for consumers that need it.
- `ConfigSwitcher::switchTo()`, `switchToDevelopment()`, `switchToProduction()`, and `switchUpdate()` now return a `SwitchOutcome` object instead of `void`. Any consumer relying on these methods returning nothing (e.g. type-hinting `void` in an override, or checking a return value they never expected to exist) must update to the new type; `getMessages()`/`getMessageTexts()` on `ConfigSwitcher` itself are unaffected and still reflect the most recent call.
- `Utils\BaseFile::tryCopyTo()` no longer silently no-ops when the *target* already exists — it now always copies when the *source* exists (matching its docblock), and only no-ops when the source is missing. Consumers that relied on `tryCopyTo()` refusing to overwrite an existing target must switch to an explicit `$target->exists()` guard before calling it.

**Added:**
- New `Mistralys\ComposerSwitcher\State` namespace of immutable value objects: `SwitchOutcome`, `SwitchMessage`, `SwitchDescription`, `VerificationResult`, `FileOperation` — each with a `toArray()` (and `SwitchDescription` additionally `toJSON()`) for programmatic/agent consumption.
- New `Utils\FileSystem` class: a single choke-point for every file mutation, with a dry-run overlay mode that enables true, zero-side-effect switch previews.
- `ConfigSwitcher::describe()` (+ `composerSwitchDescribe()`/`composerSwitchDescribeJson()` entry points and `switch-describe`/`switch-describe-json` scripts): a full state-of-the-world snapshot — mode, last switch date, per-file existence/modification dates, active flag, verification result, and parsed local repositories — that never throws.
- `ConfigSwitcher::previewSwitch()` (+ `composerSwitchPreviewDev()`/`composerSwitchPreviewProd()` entry points and `switch-preview-dev`/`switch-preview-prod` scripts): dry-run preview of a switch, returning the exact `FileOperation`s a real switch would perform.
- `ConfigSwitcher::reconcile()` (+ `composerSwitchReconcile()` entry point and `switch-reconcile` script): unifies drift detection and correction in PROD mode — content decides whether to act, modification time decides which direction, with an explicit direction override (`RECONCILE_TO_MAIN`/`RECONCILE_TO_PROD`) for the ambiguous case. `composer switch-update` now calls this internally in PROD mode instead of a separate, narrower reconciliation path.
- Structured message codes: every switch/reconcile message now carries a numeric `ConfigSwitcher::MESSAGE_*` code alongside its text, exposed via `getMessages(): SwitchMessage[]` (previously `getMessageTexts()`'s plain-string predecessor was the only option).
- Structured exception context: `ComposerSwitcherException::setContext()`/`getContext()`/`getContextValue()` attach a typed payload (file paths, offending mode/direction values, expected/actual pairs) to every thrown exception, in addition to its free-form message.
- A missing lock file no longer aborts a switch: `switchTo()` completes in full (config rewrite, status file, flag file) and records a warning message (`MESSAGE_NO_LOCK_FILE_FOUND`/`MESSAGE_PROD_LOCK_MISSING`) instead.
- `switchUpdate()` in the `INITIAL` state (no switch ever run) now returns an explicit `SwitchOutcome` with mode `ConfigSwitcher::MODE_INITIAL` and no operations, instead of a silent no-op with no return value.
- New `docs/agents/project-manifest/switching-decision-table.md` mapping every state × action combination to its command, PHP call, file effects, and message codes.

**Non-breaking:**
- Test-harness hardening: a `Utils\FileSystem`-equivalent write choke-point in the test harness (`FixtureFileSystem`), atomic `LocalPackageClone` acquisition, a 24-hour stale work-copy purge, a `GitRunner` process choke-point (replacing ad hoc `git` invocations), and new Tier 1/Tier 2 coverage for all of the above. See [file-tree.md](docs/agents/project-manifest/file-tree.md) for the full list of new test suites.

## v2.0.0 - PHP 8.4 and switch-* script namespace
- Raised the PHP requirement from `>=7.3` to `>=8.4` and removed the `config.platform.php` pin (previously `7.3`) from `composer.json`. This is a breaking change: PHP 7.3–8.3 are no longer supported.
- Renamed the Composer script keys `verify-config` → `switch-verify-config` and `install-hooks` → `switch-install-hooks` to follow the `switch-*` namespace convention already used by `switch-dev`, `switch-prod`, and `switch-update`. The underlying static entry points (`ConfigSwitcher::composerVerifyConfig`, `ConfigSwitcher::composerInstallHooks`) and the programmatic API (`verify()`, `installGitHooks()`) are unchanged.
- Added a new Tier 2 integration test suite that runs the real `composer` binary against a cloned dependency to prove entry-point dispatch, DEV/PROD switching, symlink resolution, version overrides, and git hook installation end-to-end, alongside the existing offline Tier 1 suite.
- Raised the `phpunit/phpunit` dev-dependency floor to `>=13.0` and added `tests/bootstrap.php`, resolving prior floor-mismatch and deprecated-schema issues (dev toolchain now needs PHP `>=8.4.1`; runtime floor stays `>=8.4`).
- Extended PHPStan static analysis to cover the test harness (`tests/`) alongside `src/`.
- Added a 24-hour stale work-copy purge (`WorkCopy::STALE_AFTER_SECONDS`) and collision-free work-copy names; retain-on-failure behavior is unchanged.
- Made `LocalPackageClone` acquisition atomic with an injectable cache directory, fixing a clone-cache deletion bug.
- Introduced a `GitRunner` process choke-point and renamed `ComposerResult` to `ProcessResult`, consolidating git- and Composer-spawning test code.
- Hoisted shared Tier 2 helpers into `IntegrationTestCase` and added new Tier 1 and Tier 2 coverage: hyphenated package aliases, malformed version handling, git hook installation, and a full DEV/PROD round trip.
- Made the prod-edit propagation test deterministic, replacing `sleep()`-based timing.

## v1.1.1 - Switching gaps and sync hardening
- Fixed PHPStan errors: simplified redundant `else if(!condition)` to `else`, added `@param` docblock on `addMessage()`.
- Fixed `@subackage` typo in PHPDoc headers (now `@subpackage`).
- Tightened VCS URL matching with a boundary check via `urlMatchesPackageName()` to prevent substring collisions (e.g., `application-utils` no longer matches `application-utils-core`).
- Removed invalid `--no-progress` flag from Composer test scripts (`test-file`, `test-suite`, `test-filter`, `test-group`) — the flag does not exist in PHPUnit 9.6.

## v1.1.0 - Sync hardening and Composer script entry points
- Added static Composer script entry points (`composerSwitchDev`, `composerSwitchProd`, `composerSwitchUpdate`, `composerVerifyConfig`, `composerInstallHooks`) so consumers can wire directly to the library without PHP wrapper boilerplate.
- Added a `fromProjectRoot()` static factory encoding the three-path convention shared by consumer projects.
- Added a `verify()` method that compares `composer.json` with `composer-prod.json` and reports differing top-level keys.
- Added a shared git-hook installer (`installGitHooks()`) with a bundled `pre-commit` hook resource.
- DEV switch now prunes duplicate VCS repository entries matching a switched package within the same loop pass.
- DEV switch now respects `require-dev` placement — packages in `require-dev` in the production config stay in `require-dev` during DEV mode.
- Status file paths are now normalized via `realpath()` to eliminate `/../` segments.
- HCP Editor and Mailforge rewired to use library entry points directly; per-consumer wrapper boilerplate removed.
- Removed stale `composer/composer-dev.lock` and `composer/composer-dev.status` entries from Mailforge's `.gitignore`.

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
