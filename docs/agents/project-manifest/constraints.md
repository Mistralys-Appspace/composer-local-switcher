# Constraints & Conventions

## Code Style

- **Strict types:** Every PHP file declares `declare(strict_types=1)`.
- **PHP 8.4 baseline:** PHP 8 constructs are fully available — typed properties, union types, constructor promotion, `readonly`, named arguments, match expressions, enums, nullsafe operators. There is no PHP 7 compatibility requirement of any kind. All new code uses PHP 8 constructs; the `@var`-docblock property style in existing `src/` files is legacy, not a convention to copy. Existing code may be modernised opportunistically, in the same pass as any work that already touches it — no separate approval, plan, or cleanup task is required to bring a touched file up to PHP 8 standards. A wholesale sweep of untouched files remains out of scope for any given plan.
- **Namespace:** `Mistralys\ComposerSwitcher` for core classes, `Mistralys\ComposerSwitcher\Utils` for utility classes.

## Error Handling

- All error codes use a `1821xx` numbering scheme — the exception class owns `182101`–`182110`, and the switcher class owns `182201`–`182202`.
- Errors are thrown as `ComposerSwitcherException` with an integer error code constant. No other exception types are used.

## File Conventions

- **Status file path** is derived from the dev config path by replacing `.json` with `.status`.
- **Lock file path** is derived from any `ConfigFile` path by replacing `.json` with `.lock`.
- **Flag file path** is derived from the main file path by appending `.DEV` or `.PROD`.
- These path derivations use simple `str_replace()` — filenames must end in `.json`.
- Paths stored in the status file are canonicalized via `realpath()` (fallback to raw path when the file does not exist yet).

## Three-Path Convention

The `fromProjectRoot()` factory and all built-in Composer entry points assume the standard file layout:

- `<root>/composer.json` — mutable working copy
- `<root>/composer/composer-prod.json` — production baseline
- `<root>/composer/local-repositories.json` — local repository definitions

Projects that follow this convention can wire the built-in entry points directly in `composer.json` with zero PHP glue code.

## Configuration Format

- The dev config file must contain a `local-repositories` key with an array of objects, each having `package-name` (string) and `path` (string). An optional `version` (string) overrides the default `*` version constraint.
- Package names with underscores are also matched with hyphens when looking up existing repository entries (handles GitHub URL normalization).
- When a `local-repositories` package exists in `require-dev` (not `require`) in the PROD config, the DEV switch writes the version constraint to `require-dev` — it does not move the package to `require`.

## Testing

- Tests use **PHPUnit >=13.0** with classmap autoloading from `tests/TestClasses/`.
- Test suite directory: `tests/TestSuites/` (suffix `.php`).
- Each test copies the fixture from `tests/assets/test-project/` into an ephemeral directory under `tests/assets/work-projects/`. The directory is cleaned up on tearDown unless `setKeepWorkFiles()` is called or the test failed.
- The `work-projects/` directory contains only transient test data and should not be committed.
- `tests/bootstrap.php` (wired via `phpunit.xml`'s `bootstrap` attribute) calls `WorkCopy::purgeStale()` against `tests/assets/work-projects` once per PHPUnit process, so abandoned work copies from failed/kept-alive runs (e.g. via `setKeepWorkFiles()`) get swept up on the next run regardless of entry point (`composer test`, `test-file`, `test-filter`, or a direct/IDE invocation) — entries younger than `WorkCopy::STALE_AFTER_SECONDS` (86400s / 24h) are left untouched so concurrent Tier 1/Tier 2 sessions don't purge each other's in-flight work copies.
- `ComposerSwitcherTestCase::tearDown()` determines pass/fail via `$this->status()->isFailure() || $this->status()->isError()`, the PHPUnit 10+ replacement for the removed `hasFailed()` method. The `composer.json` floor of `>=13.0` now matches what this call site requires.
- `phpunit.xml` uses the current PHPUnit 13 attribute set (XSD reference, `cacheDirectory=".phpunit.cache"`) and runs with zero configuration-schema deprecations.
- The dev toolchain (PHPUnit 13) needs PHP `>=8.4.1` to install (`vendor/phpunit/phpunit/composer.json`); the runtime `php` constraint stays `>=8.4` so consumers are unaffected — only a PHP 8.4.0 development environment loses the ability to install the dev toolchain.

### Test Conventions

- Every external process spawned by a test goes through a runner (`ComposerRunner`, `GitRunner`) using Symfony `Process`'s array-argument form — never a shell string, `exec()`, or `shell_exec()`.
- Any test that spawns `git` or Composer belongs in Tier 2 (`tests/IntegrationSuites/`), even if it can run offline.
- Fail-fast Composer calls use `IntegrationTestCase::runComposerChecked()`.
- Placeholder-substituted fixtures are verified by asserting the literal placeholder value, not by asserting the absence of a leak pattern.
- Mtime ordering between files is forced with `touch()`, never `sleep()`.
- Tests never mutate the shared clone cache (`tests/assets/local-clones/`) directly — inject a throwaway cache directory (e.g. via `WorkCopy::allocate()`) instead.
- Adding a new class under `tests/TestClasses/` requires running `composer dump-autoload` (classmap autoloading).

### Test Tiers

- **Tier 1** (`tests/TestSuites/`, run via `composer test`): fast, hermetic tests with no network access or external binaries required. Extend `ComposerSwitcherTestCase` directly and copy the `test-project` fixture.
- **Tier 2** (`tests/IntegrationSuites/`, run via `composer test-integration`): real-world validation against an actual `git` clone and the real `composer` binary. Extend `IntegrationTestCase` (itself a subclass of `ComposerSwitcherTestCase`), which copies the `integration-project` fixture instead of `test-project`.
  - `IntegrationTestCase::setUp()` resolves a cached local package clone via `LocalPackageClone` and calls `markTestSkipped()` (not a failure) with a cause-naming message when git, the network, or the Composer binary is unavailable — Tier 2 suites are expected to skip gracefully in constrained environments (e.g. CI sandboxes without network egress). The invalid-directory skip message additionally names the offending cache directory path and tells the reader to delete it to re-clone.
  - `LocalPackageClone` acquires its cache atomically: `git clone` always lands in a uniquely-named `.partial-*` sibling first and is only `rename()`d onto the cache path once its `composer.json` is verified present, so `REASON_INVALID_DIRECTORY` can only mean a directory that is invalid for some other reason (e.g. hand-edited), never an interrupted clone. Stale `.partial-*` siblings older than `PARTIAL_STALE_AFTER_SECONDS` (600s) are purged on every `ensureAvailable()` call. Both the cache directory and repository URL are constructor-injectable, which is what lets `tests/TestSuites/TestLocalPackageClone.php` (Tier 1) exercise every code path via a throwaway `WorkCopy::allocate()` directory without ever touching the real, shared cache that Tier 2 suites reuse across concurrent sessions.
  - The `integration-project` fixture ships two unresolved placeholders — `__LOCAL_CLONE_PATH__` (in `composer/local-repositories.json`) and `__LIBRARY_SRC_PATH__` (in **both** `composer.json` and `composer/composer-prod.json`) — which `setUp()` substitutes in the ephemeral work copy only; the committed fixture files are never modified.
  - `IntegrationTestCase` exposes `bootstrapProd()` (runs `composer update` once to produce a real `composer.lock` and `vendor/` tree), `runComposer(string ...$arguments): ProcessResult` (delegates to a `ComposerRunner` bound to the work copy), and `setLocalRepositoryVersion(?string $version)` (adds/removes the optional `version` key in the work copy's `local-repositories.json`).
  - `IntegrationTestCase` also owns the shared Tier 2 helper set, hoisted out of the individual suites to remove duplication: `runComposerChecked(string ...$arguments): ProcessResult` (fails the test with the command, exit code, and both output streams on a non-zero exit); `switchToDev(): void` (`bootstrapProd()` then a checked `switch-dev`); `updateDependencies(): void` (a checked `composer update`); `readFile(string $path): string`; `decodeJsonFile(string $path): array` (reads and JSON-decodes, failing the test if the result isn't an array); and `writeJsonFile(string $path, array $data): void` (pretty-printed, unescaped slashes, trailing newline). Every consuming suite under `tests/IntegrationSuites/` must call these inherited methods rather than defining a private equivalent — `bootstrapProd()` itself is implemented in terms of `runComposerChecked('update')`.
  - `ComposerRunner` shells out to the real `composer` binary against a given work copy and returns a `ProcessResult` (exit code, stdout, stderr); it reuses the ambient `COMPOSER_HOME` for cache/auth so runs stay fast, while the test project's own `vendor/` lives inside the ephemeral work directory and is discarded on teardown.
  - `GitRunner` is the single choke-point for every `git` invocation in the harness: it constructs `Symfony\Component\Process\Process` exclusively via the array-argument form (no shell string interpolation), always resolves the `git` binary through PATH (never a user/environment-controllable override), and returns the same `ProcessResult` shape as `ComposerRunner`. `isAvailable()` runs `git --version` and catches `Throwable` rather than letting a missing binary propagate as an uncaught exception.
- Test command reference: `composer test` (Tier 1 only), `composer test-integration` (Tier 2 only), `composer test-file -- <path>`, `composer test-suite -- <name>`, `composer test-filter -- <pattern>`, `composer test-group -- <group>`.

## Bundled Resources

- Non-PHP assets (e.g. shell scripts) live under `resources/` in the library root and are shipped with the Composer package.
- `resources/git-hooks/pre-commit` is the canonical shared pre-commit hook. Consumer projects install it via `ConfigSwitcher::installGitHooks()`.

## Workflow Rules

- **Never edit `composer.json` directly** in a consuming project once the switcher is set up — edit `composer-prod.json` instead. The switcher overwrites `composer.json` on every switch.
- Console output is **off by default** (`ConsoleWriter` starts disabled). Call `setWriteToConsole(true)` to enable verbose logging.
- Flag files are **on by default**. Call `setFlagFileEnabled(false)` to disable.
