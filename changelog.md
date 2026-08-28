# Changelog

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
