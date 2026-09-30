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
│   ├── ConfigSwitcher.php       — Main orchestrator: switches between DEV/PROD configs
│   ├── ComposerSwitcherException.php — Exception class with error code constants
│   └── Utils/
│       ├── BaseFile.php         — Abstract base: path, exists, delete, copy, modified date
│       ├── ConfigFile.php       — JSON config file: read/write with getData()/putData()
│       ├── ConsoleWriter.php    — Console output helper with header/line/separator methods
│       ├── FlagFile.php         — Creates mode indicator files (composer.json.DEV / .PROD)
│       ├── LockFile.php         — composer.lock file abstraction (derived from ConfigFile path)
│       └── StatusFile.php       — Persists switching state (mode, date, file paths) as JSON
├── tests/
│   ├── bootstrap.php             — PHPUnit bootstrap: requires the Composer autoloader, then calls `WorkCopy::purgeStale()` against `tests/assets/work-projects` once per process (runs for every PHPUnit entry point, not just `composer test`)
│   ├── TestClasses/
│   │   ├── ComposerSwitcherTestCase.php — Base test case: sets up isolated work directories
│   │   ├── LocalPackageClone.php — Harness: acquires the Tier 2 fixture's switched package clone via an atomic clone-then-`rename()` into a gitignored cache (never runs composer install/update inside it); cache directory and repository URL are constructor-injectable, and stale `.partial-*` siblings (`PARTIAL_STALE_AFTER_SECONDS` = 600) are purged on every `ensureAvailable()` call
│   │   ├── ComposerRunner.php   — Harness: runs the real `composer` binary in a work copy, returning a `ProcessResult`
│   │   ├── GitRunner.php        — Harness: single choke-point for every `git` invocation, returning a `ProcessResult`; array-form `Process` construction, `isAvailable()` catching `Throwable`, always resolves `git` through PATH
│   │   ├── ProcessResult.php    — Value object: exit code, stdout, and stderr of a `ComposerRunner` or `GitRunner` invocation
│   │   ├── IntegrationTestCase.php — Tier 2 base test case: extends `ComposerSwitcherTestCase` to copy the `integration-project` fixture, resolve the local package clone, substitute both fixture placeholders, and expose `bootstrapProd()` / `runComposer()` / `setLocalRepositoryVersion()`
│   │   ├── FixtureFileSystem.php — Harness: symlink-safe static removeDirectory()/copyDirectory() helpers, the latter throwing RuntimeException on an existing destination
│   │   └── WorkCopy.php         — Harness: collision-free work-copy allocation (allocate()), fixture-backed creation, removal, and age-based purgeStale() (STALE_AFTER_SECONDS = 86400)
│   ├── TestSuites/
│   │   ├── TestSwitching.php    — Tests for all switching scenarios
│   │   ├── TestLocalPackageClone.php — Tier 1 tests for LocalPackageClone, each injecting a throwaway cache directory under a fresh WorkCopy::allocate() path so no test reaches a code path invoking git or touches the shared clone cache
│   │   ├── TestHarnessExtensibility.php — Tier 1 tests for the ComposerSwitcherTestCase fixture-source seam and symlinked-directory teardown fix
│   │   ├── TestWorkCopy.php     — Tier 1 tests for WorkCopy: allocation distinctness/PID embedding, collision-throw, the STALE_AFTER_SECONDS threshold, selective purge, symlink-safe purge, no-op on a missing root
│   │   └── TestBootstrap.php    — Tier 1 test spawning `tests/bootstrap.php` as a real PHP subprocess, proving the bootstrap-driven purge runs and respects the 24h age threshold (a 1-hour-old work copy survives)
│   ├── IntegrationSuites/
│   │   ├── TestComposerRunner.php — Tier 2 tests for ComposerRunner against a real Composer binary
│   │   ├── TestGitRunner.php    — Tier 2 tests for GitRunner (no network access required): isAvailable() against the real binary, a successful command's stdout, and a failing command's non-zero exit code with populated stderr
│   │   ├── TestLocalPackageClone.php — Tier 2 tests for LocalPackageClone's real acquisition paths (no network access required): a failed clone against a nonexistent local repository leaves neither the cache directory nor any `.partial-*` sibling behind, and a successful clone against a real local git repository is atomic (cache path complete with `composer.json`, no `.partial-*` sibling remaining)
│   │   ├── TestIntegrationTestCase.php — Tier 2 acceptance tests for IntegrationTestCase (fixture-source override, placeholder substitution, skip paths, bootstrapProd(), setLocalRepositoryVersion())
│   │   ├── TestProdBootstrap.php — Tier 2 tests validating the integration fixture is a valid three-path project and that bootstrapProd()'s real `composer update` establishes the PROD baseline (composer.lock, non-symlinked vendor/, no switcher state artefacts)
│   │   ├── TestDevSwitch.php    — Tier 2 tests proving `composer switch-dev` produces a path repository whose vendor entry is a real symlink into the local clone, edits in the clone are immediately visible through it, and DEV work copy teardown leaves no orphaned directory
│   │   ├── TestRoundTrip.php    — Tier 2 tests proving `composer switch-prod` restores the published package (path repository gone, `^2.0` constraint back, real `vendor/` directory), both saved lock files exist and differ, the active `composer.lock` is byte-identical to the saved lock for each mode after its switch, and `composer install --dry-run` immediately after `switch-prod` reports a pending operation rather than "nothing to install"
│   │   ├── TestVersionOverride.php — Tier 2 tests proving the `version` override property in `local-repositories.json` pins the switched package to a configured version Composer would not otherwise infer, including the underscore-to-hyphen alias branch in `options.versions`
│   │   ├── TestEntryPoints.php  — Tier 2 tests driving all five namespaced `composer switch-*` commands as real invocations, asserting their documented console output and `switch-update`'s no-op / PROD-edit-propagation behaviour
│   │   └── TestGitHooks.php     — Tier 2 tests proving `composer switch-install-hooks` installs an executable `.git/hooks/pre-commit` against a real, ephemeral `git init`-ed work copy, and that the installed hook's DEV-marker and path-repository guards block/pass correctly, with a git-unavailable skip path
│   └── assets/
│       ├── test-project/        — Fixture project with composer.json, lock, and composer/local-repositories.json
│       ├── integration-project/ — Tier 2 fixture project with unresolved `__LOCAL_CLONE_PATH__` / `__LIBRARY_SRC_PATH__` placeholders, exercised against a real Composer binary
│       ├── work-projects/       — Ephemeral per-test working copies (created/cleaned by tests); entries older than `WorkCopy::STALE_AFTER_SECONDS` (24h) are swept by `tests/bootstrap.php` on every PHPUnit run
│       └── local-clones/        — Cached clone of the Tier 2 fixture package (gitignored)
├── resources/
│   └── git-hooks/
│       └── pre-commit       — Bundled pre-commit hook (DEV-mode and path-repo guards)
├── docs/                        — Documentation (this manifest)
└── vendor/                      — Composer dependencies (gitignored)
```
