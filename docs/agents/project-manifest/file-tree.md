# File Tree

```
composer-local-switcher/
├── composer.json                — Package definition & autoload config
├── phpunit.xml                  — PHPUnit configuration
├── .phpunit.cache/              — PHPUnit result cache (gitignored)
├── changelog.md
├── README.md
├── LICENSE
├── src/
│   ├── ConfigSwitcher.php       — Main orchestrator: switches between DEV/PROD configs; `reconcile()` owns the file system facade's dry-run flag and operation list for the whole call (restored in `finally`, even on a mid-reconcile throw) and is never-throwing for its three blocked states (DEV, INITIAL, missing `composer-prod.json` — the last recovered only by an explicit `RECONCILE_TO_PROD`), detected via the private `reconcile_detectBlocker()`; `switch_case_PROD_PROD()`'s nested `reconcileCore(null)` call carries no dry-run flag of its own, inheriting the enclosing `switchTo()`'s
│   ├── ComposerSwitcherException.php — Exception class with error code constants
│   ├── State/
│   │   ├── SwitchMessage.php     — Immutable value object pairing a switch message's text with a `MESSAGE_*` code
│   │   ├── FileOperation.php     — Immutable value object describing a single file operation (copy/write/delete), performed or dry-run-planned
│   │   ├── SwitchOutcome.php     — Immutable value object summarizing a switch/reconcile/preview result: target mode, dry-run flag, messages, file operations
│   │   ├── SwitchDescription.php — Immutable snapshot of the switcher's current state, assembled by `ConfigSwitcher::describe()`
│   │   └── VerificationResult.php — Immutable value object holding the outcome of comparing `composer.json` against `composer-prod.json`
│   └── Utils/
│       ├── BaseFile.php         — Abstract base: path, exists, delete, copyTo()/tryCopyTo(), modified date; every I/O call delegates to the shared FileSystem
│       ├── ConfigFile.php       — JSON config file: read/write with getData()/putData()
│       ├── ConsoleWriter.php    — Console output helper with header/line/separator methods
│       ├── FileSystem.php       — Single write choke-point for every file mutation (write/copy/delete); real I/O in normal mode, in-memory overlay + FileOperation log in dry-run mode; every real-mode native call (`file_get_contents()`/`file_put_contents()`/`unlink()`/`filemtime()`) is routed through a private `runNative()` helper that captures any native warning as an `\ErrorException` via a scoped `set_error_handler()`/`restore_error_handler()`, so no native warning ever escapes the facade — a real-mode failure's thrown `ComposerSwitcherException` carries that captured error under `KEY_NATIVE_ERROR` and chains it as `getPrevious()`
│       ├── FlagFile.php         — Creates mode indicator files (composer.json.DEV / .PROD)
│       ├── LockFile.php         — composer.lock file abstraction (derived from ConfigFile path)
│       └── StatusFile.php       — Persists switching state (mode, date, file paths) as JSON
├── tests/
│   ├── bootstrap.php             — PHPUnit bootstrap: requires the Composer autoloader, then calls `WorkCopy::purgeStale()` against `tests/assets/work-projects` once per process (runs for every PHPUnit entry point, not just `composer test`)
│   ├── TestClasses/
│   │   ├── ComposerSwitcherTestCase.php — Base test case: sets up isolated work directories
│   │   ├── LocalPackageClone.php — Harness: acquires the Tier 2 fixture's switched package clone via an atomic clone-then-`rename()` into a gitignored cache (never runs composer install/update inside it); cache directory and repository URL are constructor-injectable, and stale `.partial-*` siblings (`PARTIAL_STALE_AFTER_SECONDS` = 600) are purged on every `ensureAvailable()` call; `cloneInto()` is `protected` as a deliberate test seam, letting a test subclass materialise a competing cache-path directory between the real clone and the real `@rename()`, so the otherwise-unreachable concurrent-winner branch is exercisable against a real, unstubbed `rename()`
│   │   ├── ComposerRunner.php   — Harness: runs the real `composer` binary in a work copy, returning a `ProcessResult`
│   │   ├── GitRunner.php        — Harness: single choke-point for every `git` invocation, returning a `ProcessResult`; array-form `Process` construction, `isAvailable()` catching `Throwable`, always resolves `git` through PATH
│   │   ├── ProcessResult.php    — Value object: exit code, stdout, and stderr of a `ComposerRunner` or `GitRunner` invocation
│   │   ├── IntegrationTestCase.php — Tier 2 base test case: extends `ComposerSwitcherTestCase` to copy the `integration-project` fixture, resolve the local package clone, substitute both fixture placeholders, and expose `bootstrapProd()` / `runComposer()` / `setLocalRepositoryVersion()`
│   │   ├── FixtureFileSystem.php — Harness: symlink-safe static removeDirectory()/copyDirectory() helpers, the latter throwing RuntimeException on an existing destination; `pathExists()` is the single dangling-symlink-aware path-existence predicate (`is_dir || is_file || is_link`) shared by `copyDirectory()` and `WorkCopy`
│   │   ├── WorkCopy.php         — Harness: collision-free work-copy allocation (allocate()), fixture-backed creation, removal, and age-based purgeStale() (STALE_AFTER_SECONDS = 86400)
│   │   └── MarkerErrorRecorder.php — Harness: records native error/warning messages handed to its `asHandler()` closure via an object property (not a by-reference local variable), so a test can prove a previously installed error handler is restored after `FileSystem` captures its own native warning without PHPStan flagging the later assertion as a provably-impossible comparison
│   ├── TestSuites/
│   │   ├── TestSwitching.php    — Tests for all switching scenarios, including `switchUpdate()`'s INITIAL-state no-op outcome
│   │   ├── TestReconcile.php    — Tests for `reconcile()`: in-sync/main-newer/prod-newer/ambiguous outcomes, explicit-direction resolution, DEV-mode and PROD↔PROD-switch parity, the INITIAL-state no-op (every direction), the missing-`composer-prod.json` blocker and its explicit-`RECONCILE_TO_PROD` recovery (real and dry-run), the missing-prod-config no-throw guarantee on `switchToProduction()`/`switchUpdate()`, and dry-run-flag restoration after a mid-reconcile throw
│   │   ├── TestMessages.php     — Tests for message codes: `getMessages()`/`getMessageTexts()` typing, legacy text/output parity, `setDisplayMessages()` (including its `false` case suppressing output while `getMessages()` stays populated, and `displayMessages()` against an empty log printing nothing)
│   │   ├── TestStateValueObjects.php — Tests for the `State/` value objects (`SwitchMessage`, `FileOperation`, `VerificationResult`, `SwitchOutcome`, `SwitchDescription`) directly against `TestCase` (no filesystem dependency): getters, `toArray()`/`toJSON()` round-tripping, `hasCode()` for positive/zero/negative codes, and empty-collection edge cases
│   │   ├── TestExceptionContext.php — Tests for `ComposerSwitcherException`'s structured context payload: invalid switch mode, missing DEV file, invalid DEV JSON structure, a copy-to-unwritable-target failure, and a write/delete-into-unwritable-directory failure each carry the expected context keys (including `KEY_NATIVE_ERROR`) and chain the captured native error as `getPrevious()`, plus `ConfigFile::getData()`'s read failure carrying a real error code instead of `0`
│   │   ├── TestFileSystem.php   — Tier 1 tests for `FileSystem`: real-mode write/copy/delete/read(-missing) behaviour and operation recording, dry-run overlay semantics, the native-warning-capture guarantee — no native PHP warning escapes a real-mode failure even under a Composer-style throwing error handler, `KEY_NATIVE_ERROR`/`getPrevious()` on the thrown exception, and the previously installed error handler being restored afterwards — and facade-propagation (`ConfigFile::getLockFile()` and every file `ConfigSwitcher` owns sharing the same injected `FileSystem` instance)
│   │   ├── TestDescribe.php     — Tests for `describe()`/`SwitchDescription`: per-state assembly (INITIAL/DEV/PROD), tolerance of a missing/malformed dev file, and `toJSON()`/`toArray()` round-tripping
│   │   ├── TestDryRun.php       — Tests for `previewSwitch()`/`switchTo($mode, $dryRun)`: disk untouched, preview/real operation parity (including from INITIAL and from a drifted PROD state, proving `switch_case_PROD_PROD()`'s nested reconcile inherits the preview's dry-run flag instead of writing for real), applied real-switch operations, dry-run flag restoration after a forced mid-preview failure, and the static `src/` filesystem choke-point guard scan
│   │   ├── TestLocalPackageClone.php — Tier 1 tests for LocalPackageClone, each injecting a throwaway cache directory under a fresh WorkCopy::allocate() path so no test reaches a code path invoking git or touches the shared clone cache
│   │   ├── TestHarnessExtensibility.php — Tier 1 tests for the ComposerSwitcherTestCase fixture-source seam and symlinked-directory teardown fix
│   │   ├── TestWorkCopy.php     — Tier 1 tests for WorkCopy: allocation distinctness/PID embedding, collision-throw (`test_allocate_throwsOnCollision`), the STALE_AFTER_SECONDS threshold, selective purge, symlink-safe purge, no-op on a missing root
│   │   ├── TestFixtureFileSystem.php — Tier 1 tests for FixtureFileSystem: `pathExists()` against a directory/file/dangling symlink, `copyDirectory()`'s collision throw onto an existing directory/file/symlink (destination left untouched), and a nested-tree copy to a fresh destination
│   │   └── TestBootstrap.php    — Tier 1 test spawning `tests/bootstrap.php` as a real PHP subprocess, proving the bootstrap-driven purge runs and respects the 24h age threshold (a 1-hour-old work copy survives)
│   ├── IntegrationSuites/
│   │   ├── TestComposerRunner.php — Tier 2 tests for ComposerRunner against a real Composer binary
│   │   ├── TestGitRunner.php    — Tier 2 tests for GitRunner (no network access required): isAvailable() against the real binary, a successful command's stdout, a failing command's non-zero exit code with populated stderr, a zero-argument call (non-zero exit, usage output), a missing working directory (throws Symfony's RuntimeException), and a read-only working directory (non-zero ProcessResult, via a recursive chmodRecursive() helper, gracefully skipped where chmod has no effect)
│   │   ├── TestLocalPackageClone.php — Tier 2 tests for LocalPackageClone's real acquisition paths (no network access required): a failed clone against a nonexistent local repository leaves neither the cache directory nor any `.partial-*` sibling behind, a successful clone against a real local git repository is atomic (cache path complete with `composer.json`, no `.partial-*` sibling remaining), and the concurrent-winner branch — a competing, non-empty cache-path directory materialised via the protected `cloneInto()` test seam, so the real `@rename()` genuinely loses the race — is adopted when valid and reported as a genuine `REASON_CLONE_FAILED` when not (each test first probes that `rename()` actually fails onto a non-empty directory on this host, skipping with a named reason otherwise)
│   │   ├── TestIntegrationTestCase.php — Tier 2 acceptance tests for IntegrationTestCase (fixture-source override, placeholder substitution, skip paths, bootstrapProd(), setLocalRepositoryVersion())
│   │   ├── TestProdBootstrap.php — Tier 2 tests validating the integration fixture is a valid three-path project and that bootstrapProd()'s real `composer update` establishes the PROD baseline (composer.lock, non-symlinked vendor/, no switcher state artefacts)
│   │   ├── TestDevSwitch.php    — Tier 2 tests proving `composer switch-dev` produces a path repository whose vendor entry is a real symlink into the local clone, edits in the clone are immediately visible through it, and DEV work copy teardown leaves no orphaned directory
│   │   ├── TestRoundTrip.php    — Tier 2 tests proving `composer switch-prod` restores the published package (path repository gone, `^2.0` constraint back, real `vendor/` directory), both saved lock files exist and differ, the active `composer.lock` is byte-identical to the saved lock for each mode after its switch, and `composer install --dry-run` immediately after `switch-prod` reports a pending operation rather than "nothing to install"
│   │   ├── TestVersionOverride.php — Tier 2 tests proving the `version` override property in `local-repositories.json` pins the switched package to a configured version Composer would not otherwise infer, including the underscore-to-hyphen alias branch in `options.versions`
│   │   ├── TestEntryPoints.php  — Tier 2 tests driving all ten namespaced `composer switch-*` commands as real invocations (the original five — `switch-dev`, `switch-prod`, `switch-update`, `switch-verify-config`, `switch-install-hooks` — plus `switch-reconcile`, `switch-describe-json` and `switch-preview-dev`, each exercised through a dedicated test; `switch-describe` and `switch-preview-prod` are wired in the fixture but deliberately not separately driven, since they share rendering code paths with commands that are), asserting their documented console output and `switch-update`'s no-op / PROD-edit-propagation behaviour
│   │   └── TestGitHooks.php     — Tier 2 tests proving `composer switch-install-hooks` installs an executable `.git/hooks/pre-commit` against a real, ephemeral `git init`-ed work copy, and that the installed hook's DEV-marker and path-repository guards block/pass correctly — including Guard 2's `repositories`-key scoping (list and keyed-object form, non-array-entry skipping, ignoring a `"type": "path"` pair elsewhere in the file) and its grep fallback on undecodable JSON, a missing `php` binary, or a crashing one — with a git-unavailable skip path (`createRestrictedPathDirectory()` generalises the restricted-`PATH` setup shared by all of these)
│   └── assets/
│       ├── test-project/        — Fixture project with composer.json, lock, and composer/local-repositories.json
│       ├── integration-project/ — Tier 2 fixture project with unresolved `__LOCAL_CLONE_PATH__` / `__LIBRARY_SRC_PATH__` placeholders, exercised against a real Composer binary
│       ├── work-projects/       — Ephemeral per-test working copies (created/cleaned by tests); entries older than `WorkCopy::STALE_AFTER_SECONDS` (24h) are swept by `tests/bootstrap.php` on every PHPUnit run
│       └── local-clones/        — Cached clone of the Tier 2 fixture package (gitignored)
├── resources/
│   └── git-hooks/
│       └── pre-commit       — Bundled pre-commit hook: Guard 1 (DEV-mode block) and Guard 2 (local path repository block, scoped to the staged `composer.json`'s `repositories` key via a fail-safe `php -r` JSON parse — list or keyed-object form, non-array entries skipped — falling back to a blunt, file-wide grep on undecodable JSON or an unavailable/crashing `php`, which never fails open)
├── docs/
│   └── agents/
│       └── project-manifest/    — This manifest, including switching-decision-table.md (state × action → command → PHP call → file effects → message codes)
└── vendor/                      — Composer dependencies (gitignored)
```
