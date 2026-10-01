# Plan

## Plan Audit Cycles
- Audits: 1 (Sonnet 5.5 ×1) — Plan Auditor v1.9.3
- Architectural Reviews: 1 (Sonnet 5.5 ×1) — Plan Architect Reviewer v2.3.3

## Prior Project Context

- **Predecessor:** `docs/agents/plans/2026-09-28-self-sufficient-test-surface/` (COMPLETE, 16/16 WPs) built the Tier 2 integration suite. Its `synthesis.md` is the input to this rework. Its one rework cycle came from a Tier 1 test invoking the real Composer binary. This plan therefore keeps every git- or Composer-invoking test in Tier 2, even where the invocation is offline.
- **Strategic vision:** none recorded for the repository.
- **Insights that shaped the design:**
  - `a35e83b2-5ab6-4608-bb0a-2d20dcc30b41`: a partial clone makes `LocalPackageClone` misreport its failure reason. Fixed here by an atomic clone.
  - `12cc032f-1017-4b84-9190-755840481d8d`: Symfony `Process` forwards `putenv()` changes only for variables that existed at start. So `GitRunner` resolves `git` through `PATH` and adds no new environment variable.
  - `9f68772d-77bf-460c-bcb4-93ca6e977012`: assert the exact placeholder, not the absence of a leak pattern. Codified in `constraints.md`.
  - `7d13934f-9d72-4156-9616-15741f3e8f94`: validation has a hard ceiling. The malformed-version tests *characterise* the library's non-validation; they do not require validation.
  - `d215102a-9dc8-4377-b6af-7652f99305d1`: `switch-prod` no-ops without a lock file. The new negative tests stop at the failed `composer update`, because `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md` (v3.0.0, AC-13) changes what happens next.
  - `2cd87f16-2556-4523-af27-0b88a6adbf0b`: pre-commit hooks as guard registries. The reshape stays out of scope. The new hook tests pin current behaviour so that reshape has a safety net.
  - `d9b43ae2-3766-48d9-973f-3a82b3a051ee`: `finally` does not run on fatal errors. We weighed it against the WP-013 `putenv` note and rejected acting on it (see Deferred Items).

## Knowledge Base Reconciliation

| Insight ID | Title | What the plan overtakes | Executed by |
|------------|-------|-------------------------|-------------|
| a35e83b2-5ab6-4608-bb0a-2d20dcc30b41 | LocalPackageClone::ensureAvailable() misreports the failure reason after a partial clone is left on disk | Step 6 makes cloning atomic: clone into a temporary sibling, then `rename()`. The cache directory therefore only ever exists complete, and a failed clone reports `REASON_CLONE_FAILED` on every call. The entry's claim that the misreport "persists until the directory is manually deleted", and its "still open (deferred)" status, stop being true. Retire it, or rewrite it as a resolved design note. | Ledger Knowledge Curator v1.4.1 (Targeted Reconciliation) |

## Summary

This is a Synthesis Rework of `docs/agents/plans/2026-09-28-self-sufficient-test-surface/synthesis.md`. It covers every actionable item in that synthesis: the deferred debt items, the Strategic Recommendations that can be applied inside this repository, and the Code Insights follow-ups. The work stays inside the test infrastructure, the PHPUnit/PHPStan configuration and the docs:

- migrate `phpunit.xml` off the deprecated schema and make the PHPUnit floor match the APIs the harness already uses;
- bring the test harness under PHPStan;
- give the work-copy lifecycle a single owner that allocates collision-free names and purges orphaned work copies;
- route every git invocation through one choke-point, alongside `ComposerRunner`;
- make local-clone acquisition atomic and injectable. This fixes the misreport, and also a problem the synthesis did not report: the offline Tier 1 run **deletes the shared Tier 2 clone cache** every time (verified live on 2026-09-29);
- hoist the six-times-duplicated Tier 2 helpers into `IntegrationTestCase`;
- replace the `sleep(1)` mtime hack with a deterministic `touch()`;
- close the listed coverage gaps (hook overwrite, Guard 2 scope, second round trip, malformed version, hyphen-alias negative case);
- codify the array-form `Process` convention and the other house patterns the synthesis recommends.

Nothing in `src/` or `resources/` changes. The release impact is recorded under the still-untagged v2.0.0 entry.

## Architectural Context

- **Two test tiers** (`docs/agents/project-manifest/constraints.md` L47–L55):
  - Tier 1: `tests/TestSuites/` over `tests/assets/test-project/`, offline, run by `composer test`.
  - Tier 2: `tests/IntegrationSuites/` over `tests/assets/integration-project/`, using a real `composer` binary and a cached git clone of `mistralys/simple_html_dom`, run by `composer test-integration`.
  - `phpunit.xml` declares the two testsuites; `composer.json` L41–L42 selects them.
- **Harness classes** live in `tests/TestClasses/` (namespace `Mistralys\ComposerSwitcher\Tests\TestClasses`, classmap `autoload-dev`):
  - `ComposerSwitcherTestCase` does the fixture copy into `tests/assets/work-projects/<name>`, keeps the work copy when a test fails, and has a private symlink-safe `removeDirectory()`.
  - `IntegrationTestCase` (extends it) handles clone resolution, placeholder substitution, `bootstrapProd()`, `runComposer()` and `setLocalRepositoryVersion()`.
  - `ComposerRunner` + `ComposerResult` is the Composer choke-point.
  - `LocalPackageClone` handles clone acquisition via `exec()`.
- **Harness debt verified in the research brief:**
  - the private `removeDirectory()` is reached by reflection from three suites, and a fourth, non-symlink-safe copy exists;
  - git runs through two `exec()` shell strings and one ad-hoc `Process` call;
  - `decodeJsonFile()` exists in five suites (inlined in two more), and the "fail with both streams" block in seven places;
  - work-copy names have minute resolution;
  - 33 orphaned work copies sit on disk;
  - `TestLocalPackageClone` deletes the real `tests/assets/local-clones/simple_html_dom` cache.
- **Tooling:** PHPUnit 13.3.5 is installed, but `composer.json` declares `>=9.6`, which the harness cannot run on (`status()`, `SkippedWithMessageException`). PHPStan level 6 analyses `src/` only.
- **Neighbouring plan:** `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md` (unexecuted, v3.0.0) changes `src/`, adds five Tier 1 suites extending `ComposerSwitcherTestCase`, edits `tests/TestSuites/TestSwitching.php` L214–L252, and edits the same manifest files.

## Approach / Architecture

Four seams are introduced in `tests/TestClasses/`, and every suite is moved onto them.

1. **`FixtureFileSystem`** (new, static, stateless) is the only symlink-safe `removeDirectory()` and `copyDirectory()`. It replaces the private method in `ComposerSwitcherTestCase`, the three reflection calls and the unsafe copy in `TestLocalPackageClone`.
2. **`WorkCopy`** (new) owns the work-copy lifecycle:
   - `WorkCopy::allocate(string $workRoot)` builds a collision-free name `YmdHis-<pid>-<counter>`, and throws if the path already exists instead of merging into it;
   - `createFromFixture(string $fixtureDir)`, `getPath()` and `remove()`;
   - `WorkCopy::purgeStale(string $workRoot, int $maxAgeSeconds)` removes top-level entries whose mtime is older than the threshold (constant `STALE_AFTER_SECONDS = 86400`, 24 hours), unlinking symlinks without following them.

   `ComposerSwitcherTestCase` holds a `WorkCopy` and keeps its protected surface (`$assetsFolder`, `$testSource`, `$testTarget`, `getFixtureSourceDir()`, `setKeepWorkFiles()`, `createSwitcher()`) unchanged in name and meaning. This matters because the ergonomics plan builds on it.
3. **`tests/bootstrap.php`** (new) is the PHPUnit bootstrap. It requires `vendor/autoload.php`, then calls `WorkCopy::purgeStale()` once per PHPUnit process. The 24-hour threshold keeps the work copies of concurrent sessions and of failures from the previous working day, and removes everything older. The 33 legacy-named orphans present today are removed once by step 4, independent of their age.
4. **`GitRunner` + `ProcessResult`**:
   - `ComposerResult` is renamed to `ProcessResult`, a generic exit-code/stdout/stderr value object, and `ComposerRunner::run()` returns it.
   - `GitRunner` (new) mirrors `ComposerRunner`: constructor-promoted `readonly ?string $workingDirectory`, `run(string ...$arguments): ProcessResult` in array form, `isAvailable()`. It always resolves `git` through `PATH`, which keeps `TestGitHooks::test_skipsWhenGitUnavailable()`'s PATH substitution valid (insight `12cc032f`).
   - `LocalPackageClone` and `TestGitHooks` become its consumers. The remaining `shell_exec('command -v …')` calls in `TestGitHooks` move to Symfony's `ExecutableFinder`, which ships with the existing `symfony/process` dependency.

`LocalPackageClone` becomes injectable and atomic. Its constructor is `__construct(?string $cacheDirectory = null, string $repositoryUrl = self::REPOSITORY_URL)`; null means the current default path. It constructs its `GitRunner` internally (`new GitRunner()`, no working directory, since the clone passes absolute paths and runs before the target exists), matching how `IntegrationTestCase` constructs `ComposerRunner`. No test needs a substitute runner, so no injection point is added for one. `ensureAvailable()` works as follows:

1. Purge sibling `<cache>.partial-*` directories older than `PARTIAL_STALE_AFTER_SECONDS = 600`.
2. If the cache is absent: check git, clone with `--depth 1` into `<cache>.partial-<uniqid>`, and verify that `composer.json` exists there.
3. `rename()` the temporary directory onto the cache path. If the rename fails because a concurrent session won the race and a valid cache now exists, discard our copy and use theirs. On any failure, remove the temporary directory and report `REASON_CLONE_FAILED`.
4. An existing cache without `composer.json` is then genuinely foreign damage, and `REASON_INVALID_DIRECTORY` is accurate. The skip message names the path and says to delete it.

Tier 1 tests inject a throwaway cache directory, so the shared cache is never touched by `composer test`. Clone success and failure are tested in Tier 2 against a local `git init`-ed repository and a nonexistent local path. Both are offline, but both invoke git, which is why they are Tier 2.

`IntegrationTestCase` gains the shared helpers:

- `runComposerChecked(string ...$arguments): ProcessResult` (the Reviewer's gold-nugget pattern, now also used by `bootstrapProd()`);
- `switchToDev()` (bootstrap, then a checked `switch-dev`);
- `updateDependencies()` (checked `composer update`);
- `readFile()`, `decodeJsonFile()`, `writeJsonFile()`.

All private duplicates are removed from the suites.

Finally, `phpstan.neon` analyses the three test directories, and new tests fill the enumerated gaps (see Test Plan).

## Rationale

- **Fix the declared floor rather than the call sites.** The harness already needs PHPUnit 10+. Making the constraint true (`>=13.0`, the version the migrated configuration was produced and verified with) is cheaper and more honest than keeping a `>=9.6` floor nobody can run. The PHP floor is already `>=8.4`. PHPUnit 13 itself needs PHP `>=8.4.1` (`vendor/phpunit/phpunit/composer.json` L31), so only a PHP 8.4.0 development environment loses the ability to install the dev toolchain. The runtime `php` constraint stays `>=8.4`: raising it would narrow what consumers can install to fix a dev-only mismatch. The dev requirement is documented instead (see Documentation Updates).
- **Own the lifecycle before adding a purge.** A purge bolted onto a `composer test` script would miss `composer test-file` / `test-filter` / IDE runs, and would duplicate the removal logic a fifth time. A bootstrap-driven `WorkCopy::purgeStale()` runs for every PHPUnit entry point and reuses the single removal implementation.
- **Age-based purge instead of purge-all.** Purging everything at start-up would delete a concurrently running session's work copies, and Tier 1 and Tier 2 are routinely run side by side. Concurrency safety alone needs only a threshold longer than one run (minutes). The binding requirement is retain-on-failure: a developer often inspects a failure the next morning, and a one-hour threshold would delete it before then. A 24-hour threshold (`STALE_AFTER_SECONDS = 86400`) preserves overnight inspection while still bounding growth to about a day's worth of failures. The value is a named constant, so a longer retention (e.g. across a weekend) is a one-number change. A PID-liveness check was weighed and rejected as heavier and POSIX-specific for no gain over the age rule.
- **Atomic clone instead of "detect and repair".** Detecting a partial clone after the fact means guessing what "complete" looks like. Clone-then-rename makes an incomplete cache unrepresentable, so the reason codes become accurate by construction.
- **Injectable cache directory.** This is the only way to test `LocalPackageClone` without mutating state that `TestIntegrationTestCase` already documents as shared across sessions. It also removes the live cache-deletion bug.
- **Generic `ProcessResult`.** Two runners now return the same shape. Naming it after one of them would make every git call site read wrong.
- **Characterisation tests for the hook.** The overwrite behaviour and Guard 2's file-wide match are shipped behaviour in consumers' `.git/hooks/`. Changing them is the out-of-scope guard-registry reshape. Pinning them in tests turns the next plan's behavioural change into a visible, deliberate test update instead of a silent one.
- **Stop negative tests at the failed `composer update`.** What `switch-prod` does next is being redesigned by the ergonomics plan (AC-13). Asserting it here would create a cross-plan conflict.
- **PHPStan on tests now.** The probe showed only three errors, all trivial. This plan rewrites most harness files, so this is the cheapest moment the harness will ever have to become analysed.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| PHPUnit floor | `>=13.0` with the migrated schema | Keep `>=9.6`; `>=10.5` | `>=9.6` is false today. `>=10.5` claims compatibility with majors nobody tests. The floor should be the version the configuration and suite are verified on. |
| Orphaned work-copy cleanup | `WorkCopy::purgeStale()` from a PHPUnit bootstrap, age-based | A `rm -rf` pre-step in the `composer test` script; purge-all on start-up; a PHPUnit extension | A Composer script misses direct/IDE PHPUnit runs and is shell- and OS-bound. Purge-all breaks concurrent sessions. An extension adds API surface for the same effect a bootstrap gives. |
| Stale threshold | 24 hours (`STALE_AFTER_SECONDS = 86400`) | 1 hour; purge only entries whose embedded PID is no longer running | One hour deletes a retained failure before the next-morning inspection it exists for. PID liveness is heavier and POSIX-specific, and still needs an age fallback for reused PIDs. |
| Directory helpers | New `tests/TestClasses/FixtureFileSystem.php` | Add `symfony/filesystem` as a dev dependency; wait for the ergonomics plan's `src/Utils/FileSystem.php` | The logic exists and is proven symlink-safe. A new dependency adds nothing. The ergonomics class does not exist yet and belongs to `src/`, where test-only helpers should not live. |
| Partial-clone fix | Clone into `.partial-<uniqid>` then `rename()` | Check `.git/HEAD` / `composer.json` completeness after the fact; delete and re-clone on `REASON_INVALID_DIRECTORY` | After-the-fact checks guess at completeness. Auto-deleting an "invalid" directory could destroy a clone a developer edited by hand. Atomic rename prevents the state instead. |
| Clone testability | Constructor-injected cache path and repository URL; `GitRunner` constructed internally | Keep the hard-coded path and mock the filesystem; move all clone tests to Tier 2 against the real cache; also inject a `?GitRunner` | Mocking is heavier than one optional parameter. Real-cache tests are what cause the current deletion bug. An injectable runner has no test consumer (failure uses a nonexistent path, success a real local repository), and `GitRunner`'s constructor-bound working directory makes a shared instance awkward; add it when a test needs it. |
| git choke-point result type | Rename `ComposerResult` → `ProcessResult` | `GitResult` alongside `ComposerResult`; `ComposerResult extends ProcessResult` | Two identical value objects duplicate code. A subclass with no added behaviour is ceremony. A rename touches only files this plan edits anyway. |
| Guard 2 file-wide match | Characterisation test documenting the current match | Fix the regex to scope it to `repositories` | Scoping a JSON key in `grep` is fragile, and it changes a shipped resource. That is the rejected reshape. |
| Clone ref pinning (WP-005 security note) | Not pinned (Deferred Items) | `git clone --branch 2.0.0` | A detached tag changes Composer's inferred version from `dev-master` to `2.0.0`, which breaks the distinguishing assertions in `tests/IntegrationSuites/TestVersionOverride.php` L41–L78. |

## Pattern Alignment

- **Follows** the single-choke-point-per-binary pattern of `tests/TestClasses/ComposerRunner.php` for `GitRunner`.
- **Follows** array-form `Process` invocation (`tests/TestClasses/ComposerRunner.php` L48). Extends it to the whole test tree and codifies it in `constraints.md`.
- **Follows** the fail-fast wrapper `tests/IntegrationSuites/TestRoundTrip.php` L228 (`runComposerChecked()`), hoisted to `IntegrationTestCase`.
- **Follows** the `getFixtureSourceDir()` seam (`tests/TestClasses/ComposerSwitcherTestCase.php` L61) and the protected member surface. Names and semantics are preserved.
- **Follows** the `// region: _Tests` / `// region: Support methods` suite layout (e.g. `tests/IntegrationSuites/TestRoundTrip.php`).
- **Follows** the Tier 1/Tier 2 split (`constraints.md` L47–L55). Every new test that spawns git or Composer goes to `tests/IntegrationSuites/`.
- **Follows** the Tier 1/Tier 2 file-pair naming already used for `TestComposerRunner.php`: a new `tests/IntegrationSuites/TestLocalPackageClone.php` sits beside the existing Tier 1 file of the same name, in a different namespace.
- **Follows** the PHP 8.4 policy (`constraints.md` Code Style): touched harness files move to typed and promoted properties and `match`, and new classes use them. The `@var`-docblock style and the `switch` in `composeCloneSkipMessage()` are legacy, not patterns to copy.
- **Departs** from the reflection-driven access to private harness methods (`TestHarnessExtensibility.php` L109, `TestIntegrationTestCase.php` L201, `TestGitHooks.php` L177). Those calls exist only because the helper was private to the wrong class. `FixtureFileSystem` makes them plain static calls. The reflection-driven *lifecycle* harness subclasses stay, because they test the TestCase lifecycle itself.
- **Departs** from `bootstrap="vendor/autoload.php"` by introducing `tests/bootstrap.php`. It is justified by the need to purge once per process for every entry point, and it still requires the Composer autoloader first.

## Structural Improvements

| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `composer.json` L33 | `phpunit/phpunit >=9.6` admits a version the harness cannot run on | Promoted to step 1 | The floor must match the migrated configuration and the APIs in use. |
| `.gitignore` L5 | Ignores the PHPUnit 9 cache name; `.phpunit.cache/` is not ignored | Promoted to step 1 | Otherwise the migration makes a new untracked directory appear. |
| `phpstan.neon` | Tests are not analysed | Promoted to step 2 | Three trivial errors today; this plan rewrites most harness files. |
| `tests/TestClasses/ComposerSwitcherTestCase.php` L99–L149 | Private removal/copy helpers with four external consumers (three via reflection) | Promoted to step 3 | A single symlink-safe implementation, reachable without reflection. |
| `tests/TestClasses/ComposerSwitcherTestCase.php` L47 | Minute-resolution names; silent merge on collision | Promoted to step 3 | Collisions are silent test pollution. |
| `tests/TestClasses/ComposerSwitcherTestCase.php` L17–L35 | `@var`-docblock property style | Promoted to step 3 | Touched-file modernisation policy. |
| `tests/assets/work-projects/` | 33 orphaned legacy-named work copies, unbounded growth | Promoted to step 4 | A named synthesis deferred item; the purge seam comes from step 3, and step 4 removes the legacy orphans once. |
| `tests/TestSuites/TestLocalPackageClone.php` L34–L48, L94–L114 | Deletes the shared clone cache; non-symlink-safe helper copy | Promoted to step 6 | Live bug, verified 2026-09-29. |
| `tests/TestClasses/ComposerResult.php` | Generic result under a Composer-specific name | Promoted to step 5 | Gains a second consumer (`GitRunner`). |
| `tests/TestClasses/ComposerRunner.php` L20–L25 | Non-promoted constructor property | Promoted to step 5 | Touched-file modernisation policy. |
| `tests/TestClasses/LocalPackageClone.php` L94–L118 | `exec()` shell strings; duplicated git probe | Promoted to steps 5 and 6 | Array-form house style; `symfony/process` now exists. |
| `tests/TestClasses/LocalPackageClone.php` L40–L79 | Hard-coded cache path; non-atomic clone | Promoted to step 6 | Fixes the misreport and the testability gap. |
| `tests/TestClasses/IntegrationTestCase.php` L166–L182 | `switch` statement; skip message gives no remedy | Promoted to step 6 | Touched-file modernisation; the message must name the path now that the reason is accurate. |
| `tests/IntegrationSuites/*.php` private helpers | `decodeJsonFile()` ×5 (+2 inline), `readFile()` ×2, `writeJsonFile()` ×1 (+1 inline), the fail-with-streams block ×7 | Promoted to step 7 | A synthesis Next Step; several Tier 2 suites now duplicate the helpers. |
| Placeholder harness methods (`TestDevSwitch.php` L41, `TestIntegrationTestCase.php` L30, `TestHarnessExtensibility.php` L26) | `assertTrue(true)` flagged by PHPStan | Promoted to step 2 | Replace with `expectNotToPerformAssertions()`. |
| `tests/IntegrationSuites/TestEntryPoints.php` L94 | `sleep(1)` timing dependency | Promoted to step 8 | A synthesis deferred item. |
| `tests/IntegrationSuites/TestGitHooks.php` L199–L247 | `shell_exec('command -v …')`, duplicated git probe | Promoted to step 5 | Array-form house style; `ExecutableFinder` exists in the dependency already present. |
| `src/ConfigSwitcher.php` L312–L336 | Early return on missing lock (`switch-prod` no-op) | Rejected | Behavioural change to `src/`, owned by `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md` AC-13. |
| `src/ConfigSwitcher.php` L254–L269 | `copy()` result unchecked in `installGitHooks()` | Rejected | `src/` is outside this plan's blast radius. The ergonomics plan rewrites file operations there. |
| `resources/git-hooks/pre-commit` | Guard 2 matches file-wide; untestable inline shell | Rejected | The guard-registry reshape is explicitly out of scope per the synthesis (changes a resource installed in consumers). This plan adds characterisation tests only. |
| `src/ConfigSwitcher.php` L240 | By-reference `foreach` (reference-loop hygiene recommendation) | Rejected | Outside the blast radius. The only by-reference loop in touched files (`IntegrationTestCase.php` L115) already follows the pattern. |

## Detailed Steps

1. **PHPUnit configuration and floor.**
   - Replace `phpunit.xml` with the migrated PHPUnit 13 shape verified in the research brief: `backupGlobals="false" colors="true" processIsolation="false" stopOnFailure="false" cacheDirectory=".phpunit.cache" backupStaticProperties="false"`, plus `xmlns:xsi` and `xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"`. Keep both testsuites unchanged; keep `bootstrap` for now (step 4 changes it).
   - In `composer.json`, set `"phpunit/phpunit": ">=13.0"`, then run `composer update --lock` so the lock content-hash matches (no package versions change; 13.3.5 is already locked).
   - In `.gitignore`, replace `.phpunit.result.cache` with `/.phpunit.cache/`, and delete the stale root `.phpunit.result.cache` file.
   - Verify that `composer test` reports no "PHPUnit Deprecations".
2. **PHPStan over the harness.**
   - Add `tests/TestClasses`, `tests/TestSuites` and `tests/IntegrationSuites` to `paths` in `phpstan.neon`.
   - Replace the three placeholder `assertTrue(true)` bodies (`tests/IntegrationSuites/TestDevSwitch.php` L41, `tests/IntegrationSuites/TestIntegrationTestCase.php` L30, `tests/TestSuites/TestHarnessExtensibility.php` L26) with `$this->expectNotToPerformAssertions();`.
   - `composer analyze` must report zero errors, and must keep doing so after every later step.
3. **Extract `FixtureFileSystem` and `WorkCopy`.**
   - Create `tests/TestClasses/FixtureFileSystem.php` (new): `final class` with static `removeDirectory(string $dir): void` (moved verbatim from `ComposerSwitcherTestCase` L99–L124, symlink-safe) and `copyDirectory(string $src, string $dst): void` (moved from L126–L149, now throwing a `RuntimeException` if `$dst` already exists).
   - Create `tests/TestClasses/WorkCopy.php` (new), `final class`:
     - `public const STALE_AFTER_SECONDS = 86400` (24 hours; docblock states it is sized for next-day inspection of retained failures, not for concurrency);
     - `public function __construct(private readonly string $path)`;
     - `static allocate(string $workRoot): self` names the path `date('YmdHis') . '-' . getmypid() . '-' . <static counter>` and throws `RuntimeException` if it exists. It does not create the directory: the caller either calls `createFromFixture()` or, where no fixture applies (the step 6 and step 10 clone tests), `mkdir()`s the path itself;
     - `createFromFixture(string $fixtureDir): void` throws `RuntimeException` if the path already exists (delegating to `FixtureFileSystem::copyDirectory()`), so a collision can never merge into a retained work copy;
     - `getPath(): string`, `remove(): void`;
     - `static purgeStale(string $workRoot, int $maxAgeSeconds = self::STALE_AFTER_SECONDS): int` removes top-level entries older than the threshold by `filemtime()`, unlinking symlink entries, and returns the count. It is a no-op when `$workRoot` does not exist.
   - Refactor `tests/TestClasses/ComposerSwitcherTestCase.php`:
     - hold a `private WorkCopy $workCopy`, and derive `$testTarget` from it in `setUp()`;
     - `tearDown()` calls `$this->workCopy->remove()` under the unchanged pass/fail condition;
     - drop the private helpers;
     - convert properties to typed declarations (`protected string $assetsFolder`, etc.). Names and visibility stay unchanged.
   - Replace the reflection-based `callRemoveDirectory()` helpers in `tests/TestSuites/TestHarnessExtensibility.php`, `tests/IntegrationSuites/TestIntegrationTestCase.php` and `tests/IntegrationSuites/TestGitHooks.php` with `FixtureFileSystem::removeDirectory()`. Delete `TestGitHooks::getWorkTargetOf()` and its `ReflectionProperty`. `$instance` is a `TestGitHooks` created via `new self(...)`, so `$instance->testTarget` is directly readable (same-class protected access).
   - Create `tests/TestSuites/TestWorkCopy.php` (new, Tier 1), covering `WorkCopy` and `FixtureFileSystem::copyDirectory()` directly: `test_allocateYieldsDistinctPathsContainingPid`, `test_createFromFixtureThrowsWhenPathExists`, `test_purgeStaleRemovesOnlyOldEntries`, `test_staleThresholdCoversNextDayInspection`, `test_purgeStaleUnlinksSymlinksWithoutFollowing`, `test_purgeStaleIsNoOpForMissingRoot` (see Test Plan for each test's assertions).
   - Run `composer dump-autoload`.
4. **Stale work-copy purge.**
   - Create `tests/bootstrap.php` (new): `declare(strict_types=1)`, `require __DIR__ . '/../vendor/autoload.php'`, then `WorkCopy::purgeStale(__DIR__ . '/assets/work-projects')`.
   - Point `phpunit.xml` `bootstrap` at `tests/bootstrap.php`.
   - One-time cleanup: delete the 33 pre-existing orphans. They all carry the legacy `YmdHi-<n>` name (12 digits, a hyphen, a counter, no PID segment) that the new allocator can no longer produce, so no session running the reshaped harness owns them. They date from 2026-09-28 15:39–16:20, so the 24-hour purge alone would only catch them on a run after 2026-09-29 16:20; the explicit removal makes the outcome independent of when the plan executes. This is an execution-time action, not code — `purgeStale()` stays purely age-based.
5. **Process choke-points.**
   - Rename `tests/TestClasses/ComposerResult.php` → `tests/TestClasses/ProcessResult.php` (class `ProcessResult`, constructor-promoted `readonly` properties, docblock generalised). Update every reference (`ComposerRunner.php`, `IntegrationTestCase.php`, `TestDevSwitch.php`, `TestEntryPoints.php`, `TestRoundTrip.php`, `TestProdBootstrap.php`).
   - Modernise `ComposerRunner` (promoted `private readonly string $workingDirectory`).
   - Create `tests/TestClasses/GitRunner.php` (new): constructor `public function __construct(private readonly ?string $workingDirectory = null)`, `run(string ...$arguments): ProcessResult` via `new Process(array_merge(['git'], $arguments), $this->workingDirectory)`, and `isAvailable(): bool` (`git --version`, catching `Throwable`). `git` is always resolved through `PATH`.
   - In `tests/IntegrationSuites/TestGitHooks.php`:
     - replace `isGitAvailable()` and `runGit()` with `GitRunner`, adapting `stageComposerJson()` to `ProcessResult`;
     - have `runHookScript()` return a `ProcessResult` built from its `Process`;
     - replace both `shell_exec('command -v …')` calls in `resolveComposerBinaryPath()` / `createPhpOnlyPathDirectory()` with `(new ExecutableFinder())->find(...)`;
     - replace the `rm -rf` `Process` in `test_skipsWhenGitUnavailable()` with `FixtureFileSystem::removeDirectory()`.
   - Run `composer dump-autoload`.
6. **Atomic, injectable `LocalPackageClone`.**
   - Rewrite `tests/TestClasses/LocalPackageClone.php`:
     - constructor `__construct(?string $cacheDirectory = null, private readonly string $repositoryUrl = self::REPOSITORY_URL)`, with promoted and typed state, and `private string $unavailableReason = self::REASON_NONE`. The `GitRunner` is created internally (`new GitRunner()`, working directory `null`; every git call passes absolute paths). No runner injection point is added;
     - `getCacheDirectory()` returns the injected path or the current default;
     - `ensureAvailable()` follows the algorithm in Approach: purge `.partial-*` siblings older than `PARTIAL_STALE_AFTER_SECONDS = 600`, git check, clone `--depth 1` into a unique `.partial-` sibling via `GitRunner`, verify `composer.json`, `rename()` with concurrent-winner handling, clean up on every failure path;
     - keep the three `REASON_*` constants and their semantics;
     - update the class docblock to state the atomicity guarantee.
   - Rewrite `tests/TestSuites/TestLocalPackageClone.php` so that every test injects a cache directory under a fresh `WorkCopy::allocate()` path (removed in `finally`), uses `FixtureFileSystem`, and never references the default cache. No test in this file may reach a code path that invokes git. States are arranged so the cache exists (valid or invalid) before `ensureAvailable()` runs.
     - `allocate()` only reserves a path that does not exist yet; `createFromFixture()` is not used here. Each test therefore creates its own directories: it `mkdir()`s the allocated path, then `mkdir()`s the injected cache directory beneath it (e.g. `<allocated>/simple_html_dom`), and, for the valid-cache cases, writes a minimal `composer.json` into it with `file_put_contents()`. The `.partial-*` siblings in the purge test are likewise `mkdir()`ed beneath the allocated path, next to the cache directory. No `WorkCopy::create()` helper is added; plain `mkdir()` is enough for these few call sites.
   - In `tests/TestClasses/IntegrationTestCase.php`, change the private helper's signature to `composeCloneSkipMessage(string $reason, string $cacheDirectory): string`, convert it to a `match`, and make the `REASON_INVALID_DIRECTORY` message name `$cacheDirectory` and say "delete it to re-clone". The sole caller in `setUp()` (L47) passes `$clone->getUnavailableReason()` and `$clone->getCacheDirectory()`. The three reflective `invoke()` calls in `tests/IntegrationSuites/TestIntegrationTestCase.php::test_composeCloneSkipMessageNamesEachUnavailabilityCause()` (L169–L171) are updated to pass a second argument, a fixed sample path, which the invalid-directory assertion then expects to find in the message.
7. **Hoist the Tier 2 helpers.**
   - Add to `tests/TestClasses/IntegrationTestCase.php`, as `protected` methods:
     - `runComposerChecked(string ...$arguments): ProcessResult`, failing with the command, exit code and both streams;
     - `switchToDev(): void` (`bootstrapProd()` then `runComposerChecked('switch-dev')`);
     - `updateDependencies(): void` (`runComposerChecked('update')`);
     - `readFile(string $path): string`;
     - `decodeJsonFile(string $path): array<string,mixed>`;
     - `writeJsonFile(string $path, array<string,mixed> $data): void`, using pretty-printed, unescaped slashes, trailing `PHP_EOL`.
   - Re-implement `bootstrapProd()` as `runComposerChecked('update')`, using the message format of the hoisted method.
   - Delete the private duplicates:
     - `tests/IntegrationSuites/TestDevSwitch.php`: `switchToDev`, `updateInDev`, `decodeJsonFile`;
     - `tests/IntegrationSuites/TestEntryPoints.php`: `switchToDev`, `updateInDev`, `decodeJsonFile`, `readFile`, and the inline write in `addProdDescriptionKey`;
     - `tests/IntegrationSuites/TestRoundTrip.php`: `runComposerChecked`, `decodeJsonFile`;
     - `tests/IntegrationSuites/TestGitHooks.php`: `decodeJsonFile`, `writeJsonFile`, and `installHooks`'s inline block, which becomes a `runComposerChecked` call;
     - `tests/IntegrationSuites/TestProdBootstrap.php`: `decodeJsonFile`, `readFile`;
     - `tests/IntegrationSuites/TestVersionOverride.php`: `switchToDevAndUpdate` becomes `switchToDev()` + `updateDependencies()`, and `getPackageRepositoryEntry` uses `decodeJsonFile`;
     - `tests/IntegrationSuites/TestIntegrationTestCase.php`: `getFirstLocalRepositoryEntry` uses `decodeJsonFile`.
   - Update each suite's call sites. Assertions stay behaviourally identical.
8. **Deterministic mtime.** In `tests/IntegrationSuites/TestEntryPoints.php::test_switchUpdatePropagatesProdEdit()`, replace `sleep(1)` with `touch()` backdating `composer.json` to `time() - 60`, then `clearstatcache()`, and only then apply the prod edit. The prod file is then strictly newer whatever the filesystem timestamp resolution. Update the explanatory comment to match.
9. **Tier 1 coverage additions** in `tests/TestSuites/TestSwitching.php`, appended after `test_specificPackageVersion()` (L125). Leave the L214–L252 range alone, since the ergonomics plan edits it.
   - `test_hyphenAliasOmittedForNonUnderscorePackage()`: after a DEV switch, the `mistralys/application-utils-core` path entry's `options.versions` holds exactly one key, the package name itself, with `2.3.14`.
   - `test_malformedVersionIsWrittenVerbatim()`: with `mistralys/application-utils` given `"version": "not-a-version"` in the work copy's `local-repositories.json`, the DEV `composer.json` carries `not-a-version` in `require` and in `options.versions`. The docblock cites the deliberate non-validation boundary.
10. **Tier 2 coverage additions.**
    - `tests/IntegrationSuites/TestGitHooks.php`:
      - `test_installHooksOverwritesExistingCustomHook()`: a pre-existing custom `.git/hooks/pre-commit` is replaced byte-for-byte by `resources/git-hooks/pre-commit`, and the result is executable;
      - `test_guard2MatchesTypePathOutsideRepositories()`: a staged `composer.json` whose only `"type": "path"` pair sits under `extra` is blocked. The docblock marks this as a characterisation of a known limitation, to be flipped deliberately by the guard-registry reshape (insight `2cd87f16`).
    - `tests/IntegrationSuites/TestRoundTrip.php::test_secondRoundTripRestoresIdenticalProdLock()`: after two full cycles (bootstrap → `switch-dev` → update → `switch-prod` → update → `switch-dev` → `install` → `switch-prod`), `composer.lock` is byte-identical to `composer/composer-prod.lock`, `composer.json` has no `type: path` repository, and `require` holds `^2.0`.
    - `tests/IntegrationSuites/TestVersionOverride.php::test_malformedVersionFailsComposerUpdate()`: with `setLocalRepositoryVersion('not-a-version')`, `switch-dev` succeeds and the following `composer update` exits non-zero with output naming the invalid constraint (`containsOutput('not-a-version')`). The test asserts nothing after the failed update.
    - `tests/IntegrationSuites/TestGitRunner.php` (new), mirroring `tests/IntegrationSuites/TestComposerRunner.php`: `isAvailable()` is true for the real binary; `run('--version')` succeeds with output on stdout; a failing command (`run('rev-parse', '--verify', 'no-such-ref')` in an empty `git init`-ed work copy) returns non-zero with stderr populated.
    - `tests/IntegrationSuites/TestLocalPackageClone.php` (new, namespace `Mistralys\ComposerSwitcher\IntegrationSuites`):
      - `test_failedCloneReportsCloneFailedAndLeavesNothingBehind()`: repository URL = a nonexistent local path, called twice. Both calls report `REASON_CLONE_FAILED`, and neither the cache directory nor any `.partial-*` sibling exists afterwards;
      - `test_successfulCloneIsAtomic()`: repository URL = a local repository created with `GitRunner` (`init`, `add`, then a commit of a `composer.json`). It returns the cache path, `composer.json` exists, and no `.partial-*` sibling remains. The commit passes an explicit committer identity (`run('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', ...)`), so it does not depend on a global git configuration that CI or a fresh machine may lack.
    - Both new suites (`TestGitRunner`, `TestLocalPackageClone`) extend plain `PHPUnit\Framework\TestCase`, following `tests/IntegrationSuites/TestComposerRunner.php`. They must **not** extend `IntegrationTestCase`: its `setUp()` (`tests/TestClasses/IntegrationTestCase.php` L39–L48) resolves the shared clone and skips when it is unavailable, which would skip these tests without network access and defeat AC-14.
    - Both suites use injected directories under `WorkCopy::allocate(<tests/assets/work-projects>)` paths and never touch the shared cache. As in step 6, the test itself `mkdir()`s the allocated path before use (the git repository and the cache's parent directory live beneath it) and removes it in `finally` via `FixtureFileSystem::removeDirectory()`.
11. **Documentation.** Apply every entry in `## Documentation Updates`.
12. **Verification gate.** Run and record:
    - `composer test`: all green, zero PHPUnit deprecations, and `tests/assets/local-clones/simple_html_dom` present and unmodified before and after (checked by listing and comparing its `.git/HEAD` and top-level entry count);
    - `composer test-integration`: all green;
    - `composer analyze`: zero errors;
    - `grep -rnE "exec\(|shell_exec|proc_open|passthru|system\(|\bsleep\(" tests/TestClasses tests/TestSuites tests/IntegrationSuites | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|/\*)'`: no matches. The second filter drops comment and docblock lines, so the gate checks calls only. Two comments are known to match the first grep and are expected to be filtered out, not edited: the `proc_open()` mention in the docblock at `tests/TestClasses/ComposerRunner.php` L16 and the one in the comment at `tests/IntegrationSuites/TestComposerRunner.php` L61. The code matches today (`LocalPackageClone.php` L96/L115, `TestEntryPoints.php` L94, `TestGitHooks.php` L207/L223/L244) are all removed by steps 5, 6 and 8;
    - `git status` / `git diff --stat`: changes confined to the files in `## Required Components`, with `src/` and `resources/` unchanged;
    - `tests/assets/work-projects/` holds no entry older than `WorkCopy::STALE_AFTER_SECONDS` (24 hours) and no legacy-named (`YmdHi-<n>`) entry after the run.

## Dependencies

- Steps are sequential in the order given, with these hard edges:
  - 1 → 4 (bootstrap edits the migrated `phpunit.xml`);
  - 3 → 4, 6, 10 (`WorkCopy` / `FixtureFileSystem` are used there);
  - 5 → 6, 7, 10 (`ProcessResult` / `GitRunner`);
  - 7 → 8, 10 (hoisted helpers);
  - 2 is a standing gate for every later step;
  - 11 after 1–10; 12 last.
- The existing `symfony/process` dev dependency (`composer.json` L35) supplies `Process` and `ExecutableFinder`. No new package is added.
- Tier 2 runs need network access for the first clone of `mistralys/simple_html_dom` (run 1 re-clones, because the live Tier 1 bug deleted the cache during research), plus `git` and `composer` on `PATH`.
- **Cross-plan sequencing:** execute this plan **before** `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md`. Its five new Tier 1 suites extend `ComposerSwitcherTestCase` and should land on the reshaped harness. Both plans edit `changelog.md`, `constraints.md`, `file-tree.md`, `tech-stack.md` and `tests/TestSuites/TestSwitching.php`, in disjoint regions.

## Required Components

**New:**
- `tests/bootstrap.php`
- `tests/TestClasses/FixtureFileSystem.php`
- `tests/TestClasses/WorkCopy.php`
- `tests/TestClasses/GitRunner.php`
- `tests/TestClasses/ProcessResult.php` (renamed from `ComposerResult.php`)
- `tests/TestSuites/TestWorkCopy.php`
- `tests/IntegrationSuites/TestGitRunner.php`
- `tests/IntegrationSuites/TestLocalPackageClone.php`

**Modified:**
- `phpunit.xml`, `composer.json`, `composer.lock` (content-hash only), `phpstan.neon`, `.gitignore`
- `tests/TestClasses/ComposerSwitcherTestCase.php`, `tests/TestClasses/IntegrationTestCase.php`, `tests/TestClasses/ComposerRunner.php`, `tests/TestClasses/LocalPackageClone.php`
- `tests/TestSuites/TestSwitching.php`, `tests/TestSuites/TestLocalPackageClone.php`, `tests/TestSuites/TestHarnessExtensibility.php`
- `tests/IntegrationSuites/TestDevSwitch.php`, `TestEntryPoints.php`, `TestRoundTrip.php`, `TestGitHooks.php`, `TestProdBootstrap.php`, `TestVersionOverride.php`, `TestIntegrationTestCase.php`
- `AGENTS.md`, `README.md`, `changelog.md`
- `docs/agents/project-manifest/constraints.md`, `docs/agents/project-manifest/file-tree.md`, `docs/agents/project-manifest/tech-stack.md`

**Deleted:**
- `tests/TestClasses/ComposerResult.php` (by the rename)
- root `.phpunit.result.cache` (untracked, gitignored)

## Assumptions

- PHPUnit 13.x accepts the migrated attribute set (verified by the trial migration on a scratch copy) and `expectNotToPerformAssertions()`.
- `rename()` of a directory within the same parent (`tests/assets/local-clones/`) is atomic on the supported POSIX filesystems, and fails when the target is a non-empty directory.
- `git clone` of a nonexistent local path fails quickly without network access.
- A work-copy directory's `filemtime()` reflects its creation or last direct-child change. A 24-hour threshold exceeds any single Tier 2 run by orders of magnitude.
- Development environments run PHP `>=8.4.1`, the minimum PHPUnit 13 installs on (`vendor/phpunit/phpunit/composer.json` L31). On PHP 8.4.0, `composer install` of the dev dependencies fails; the library itself still installs for consumers.
- Composer's error for an unparsable constraint includes the constraint text. The Tier 2 malformed-version test asserts only that substring and a non-zero exit.

## Constraints

- No change to `src/` or `resources/`. The library's runtime behaviour is untouched.
- The runtime `"php": ">=8.4"` constraint in `composer.json` stays as it is; the dev-only PHP `>=8.4.1` requirement introduced by PHPUnit 13 is documented, not enforced through the runtime floor.
- Tier 1 (`composer test`) must stay free of network access and of git/Composer process spawning (`constraints.md` L49).
- The protected surface of `ComposerSwitcherTestCase` keeps its names and semantics.
- Nothing runs `composer install` / `update` inside the clone.
- New and touched code follows the PHP 8.4 policy (typed/promoted/`readonly` properties, `match`), without sweeping untouched files.
- `composer dump-autoload` is required after adding or renaming a class in `tests/TestClasses/` (classmap autoloading).
- No work in `../hcp-editor` or `../mailforge`.

## Out of Scope

- Reshaping `resources/git-hooks/pre-commit` into a guard registry: explicitly out of scope per the synthesis. This plan adds characterisation coverage only.
- Reproducing the "has higher repository priority" resolver error: explicitly out of scope per the synthesis (needs a third package).
- Any `src/` behaviour change, including the `switch-prod` no-lock no-op. That is owned by `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md`.
- Investigating concurrent Developer sessions in the ledger/orchestration layer. That is not code in this repository.
- Consumer adoption in `../hcp-editor` / `../mailforge`.

## Human Actions

| # | Action | When | Why an agent cannot do it |
|---|--------|------|---------------------------|
| 1 | Review and commit the working tree (this plan's changes sit on top of the predecessor's still-uncommitted changes). | After the run | Git write operations belong to the user. |
| 2 | Tag and publish v2.0.0 (git tag + Packagist) once satisfied with the combined v2.0.0 changelog entry. | After the run | Tagging is a git write; Packagist publishing needs the maintainer's credentials. |

## Acceptance Criteria

- AC-01: `phpunit.xml` uses the PHPUnit 13 attribute set with an XSD reference and `cacheDirectory=".phpunit.cache"`. `composer test` and `composer test-integration` report zero PHPUnit deprecations.
- AC-02: `composer.json` declares `"phpunit/phpunit": ">=13.0"`, `composer validate` reports the lock file up to date, and `.gitignore` ignores `/.phpunit.cache/` instead of `.phpunit.result.cache`.
- AC-03: `phpstan.neon` analyses `src`, `tests/TestClasses`, `tests/TestSuites` and `tests/IntegrationSuites`, and `composer analyze` reports zero errors.
- AC-04: Directory removal and copying exist only in `FixtureFileSystem`. No test file uses reflection to reach `removeDirectory()`, `ComposerSwitcherTestCase::$testTarget` or any other private harness member for filesystem cleanup.
- AC-05: `WorkCopy::allocate()` yields distinct paths for successive allocations in one process and embeds the PID. Neither `allocate()`, `createFromFixture()` nor `FixtureFileSystem::copyDirectory()` ever merges into an existing directory — each throws instead.
- AC-06: `WorkCopy::STALE_AFTER_SECONDS` is `86400` (24 hours). `WorkCopy::purgeStale()` (run from `tests/bootstrap.php`) removes entries older than it, keeps newer ones (including an hour-old retained failure), and unlinks symlinked entries without touching their targets. None of the 33 pre-existing legacy-named orphans remains after step 4.
- AC-07: A `composer test` run leaves `tests/assets/local-clones/simple_html_dom` present and unmodified. No Tier 1 test references the default clone cache.
- AC-08: A failed clone reports `REASON_CLONE_FAILED` on every subsequent call and leaves neither the cache directory nor a `.partial-*` sibling. A successful clone appears at the cache path only after it is complete. Stale `.partial-*` siblings are purged.
- AC-09: Every git invocation in `tests/` goes through `GitRunner`, and `ComposerResult` is replaced by `ProcessResult`. No `exec(`, `shell_exec`, `proc_open`, `passthru` or `system(` call remains in `tests/TestClasses`, `tests/TestSuites` or `tests/IntegrationSuites`, as checked by the comment-excluding grep gate in step 12 (mentions in comments and docblocks are not calls).
- AC-10: `runComposerChecked()`, `switchToDev()`, `updateDependencies()`, `readFile()`, `decodeJsonFile()` and `writeJsonFile()` are defined once, in `IntegrationTestCase`, and no Tier 2 suite defines a private equivalent.
- AC-11: No test calls `sleep()` (checked by the step 12 grep gate), and `test_switchUpdatePropagatesProdEdit()` forces the mtime ordering with `touch()`.
- AC-12: Tier 1 proves that a non-underscore package's `options.versions` holds no hyphen alias, and that a malformed version string is written verbatim.
- AC-13: Tier 2 proves hook overwrite of a pre-existing custom hook, Guard 2's file-wide match (as characterisation), lock fidelity across a second full round trip, and that a malformed version makes `composer update` fail with the constraint named.
- AC-14: `GitRunner` and `LocalPackageClone` acquisition (success and failure) are covered in Tier 2 without network access and without touching the shared clone cache.
- AC-15: `src/` and `resources/` are unchanged, and every changed file is listed in Required Components.
- AC-16: The manifest, `AGENTS.md`, `README.md` and `changelog.md` reflect all of the above, per `## Documentation Updates`.

## Testing Strategy

The rework is to the test harness itself, so each step is verified by the suites it reshapes:

- `composer analyze` runs after every step as a standing gate (from step 2).
- The refactor steps (3, 5, 7) must leave both suites green with unchanged assertion semantics.
- New harness classes get direct tests. Tier 1 covers the pure filesystem parts (`WorkCopy`, `FixtureFileSystem`, cache-dir injection). Tier 2 covers anything spawning git (`GitRunner`, clone acquisition).
- The coverage-gap tests are characterisation-style: they pin current, shipped behaviour and name the plan expected to change it.
- The final gate (step 12) also checks the non-functional promises by inspection: clone cache survival, no shell-string invocations, no `sleep()`, no stale work copies, scope confinement.

## Test Plan

- `tests/TestSuites/TestWorkCopy.php` (new, Tier 1) — `test_allocateYieldsDistinctPathsContainingPid`: two allocations differ and both contain `getmypid()` — AC-05.
- `tests/TestSuites/TestWorkCopy.php` — `test_createFromFixtureThrowsWhenPathExists`: `(new WorkCopy($preCreatedPath))->createFromFixture(...)` throws `RuntimeException` and leaves the pre-existing directory's contents untouched — AC-05.
- `tests/TestSuites/TestWorkCopy.php` — `test_purgeStaleRemovesOnlyOldEntries`: under an isolated work root, one entry backdated with `touch()` to `time() - WorkCopy::STALE_AFTER_SECONDS - 60`, one backdated to `time() - 3600` (an hour-old retained failure), and one fresh; purge removes exactly the first and returns `1` — AC-06.
- `tests/TestSuites/TestWorkCopy.php` — `test_staleThresholdCoversNextDayInspection`: `WorkCopy::STALE_AFTER_SECONDS` equals `86400`, pinning the retention decision so a change to it is a deliberate test update — AC-06.
- `tests/TestSuites/TestWorkCopy.php` — `test_purgeStaleUnlinksSymlinksWithoutFollowing`: a stale entry containing a symlink to an outside directory is removed, and the outside directory and its file survive — AC-06, AC-04.
- `tests/TestSuites/TestWorkCopy.php` — `test_purgeStaleIsNoOpForMissingRoot`: returns `0` for a nonexistent root — AC-06.
- `tests/TestSuites/TestHarnessExtensibility.php` — `test_removeDirectoryHandlesSymlinkedDirectory` rewritten against `FixtureFileSystem::removeDirectory()` with no reflection; `test_fixtureSourceSeamIsOverridable` cleanup via `FixtureFileSystem` — AC-04.
- `tests/TestSuites/TestHarnessExtensibility.php` — `test_copyDirectoryRefusesExistingTarget`: `FixtureFileSystem::copyDirectory()` throws when the destination exists — AC-05.
- `tests/TestSuites/TestLocalPackageClone.php` — `test_getCacheDirectoryReturnsInjectedPath` / `test_getCacheDirectoryDefaultsToSharedCache` (the second asserts the path string only, never touching the filesystem) — AC-07.
- `tests/TestSuites/TestLocalPackageClone.php` — `test_ensureAvailableReportsInvalidDirectoryForInjectedCacheWithoutComposerJson` (replaces the current real-cache test) — AC-07, AC-08.
- `tests/TestSuites/TestLocalPackageClone.php` — `test_ensureAvailableReturnsInjectedValidCache`: an injected directory containing `composer.json` is returned without invoking git — AC-07.
- `tests/TestSuites/TestLocalPackageClone.php` — `test_ensureAvailablePurgesStalePartialSiblings`: with a valid injected cache, a `.partial-x` sibling backdated beyond `PARTIAL_STALE_AFTER_SECONDS` is removed, and a fresh one is kept — AC-08.
- `tests/TestSuites/TestLocalPackageClone.php` — `test_getUnavailableReasonIsEmptyBeforeAnyCall` (kept) — AC-08.
- `tests/TestSuites/TestSwitching.php` — `test_hyphenAliasOmittedForNonUnderscorePackage` — AC-12.
- `tests/TestSuites/TestSwitching.php` — `test_malformedVersionIsWrittenVerbatim` — AC-12.
- `tests/IntegrationSuites/TestGitRunner.php` (new, extends `PHPUnit\Framework\TestCase`) — `test_isAvailableReturnsTrueForRealBinary`, `test_runReturnsStdoutForSuccessfulCommand`, `test_runReturnsNonZeroAndStderrForFailingCommand` — AC-09, AC-14.
- `tests/IntegrationSuites/TestLocalPackageClone.php` (new, extends `PHPUnit\Framework\TestCase`, so it runs without the shared clone or network) — `test_failedCloneReportsCloneFailedAndLeavesNothingBehind` — AC-08, AC-14.
- `tests/IntegrationSuites/TestLocalPackageClone.php` — `test_successfulCloneIsAtomic` (local commit with an explicit `-c user.name=… -c user.email=…` identity) — AC-08, AC-14.
- `tests/IntegrationSuites/TestIntegrationTestCase.php` — `test_composeCloneSkipMessageNamesEachUnavailabilityCause` updated: its reflective calls pass the new second argument (a sample cache path), and the invalid-directory message contains that path and "delete" — AC-08.
- `tests/IntegrationSuites/TestIntegrationTestCase.php` — `test_runComposerCheckedFailsWithBothStreams` (new): a failing command (`runComposerChecked('no-such-command')`) raises an `AssertionFailedError` whose message contains the command and the exit code — AC-10.
- `tests/IntegrationSuites/TestIntegrationTestCase.php` — `test_writeJsonFileRoundTripsThroughDecodeJsonFile` (new) — AC-10.
- `tests/IntegrationSuites/TestEntryPoints.php` — `test_switchUpdatePropagatesProdEdit` rewritten without `sleep()` — AC-11.
- `tests/IntegrationSuites/TestGitHooks.php` — `test_installHooksOverwritesExistingCustomHook` — AC-13.
- `tests/IntegrationSuites/TestGitHooks.php` — `test_guard2MatchesTypePathOutsideRepositories` — AC-13.
- `tests/IntegrationSuites/TestGitHooks.php` — `test_skipsWhenGitUnavailable` still passes after the `GitRunner` / `ExecutableFinder` migration — AC-09.
- `tests/IntegrationSuites/TestRoundTrip.php` — `test_secondRoundTripRestoresIdenticalProdLock` — AC-13.
- `tests/IntegrationSuites/TestVersionOverride.php` — `test_malformedVersionFailsComposerUpdate` — AC-13.
- All existing Tier 1 and Tier 2 tests keep passing after steps 3, 5 and 7 (regression) — AC-04, AC-09, AC-10.
- Configuration/inspection checks in step 12 (deprecation count, `composer validate`, `composer analyze`, grep for shell invocations and `sleep(`, clone-cache survival, work-projects contents holding no stale or legacy-named entry, `git diff --stat` scope) — AC-01, AC-02, AC-03, AC-06, AC-07, AC-09, AC-11, AC-15.
- Documentation review against `## Documentation Updates` (Documentation stage) — AC-16.

## Documentation Updates

- `docs/agents/project-manifest/constraints.md` —
  - Testing section: PHPUnit floor `>=13.0`; delete the L44 floor-mismatch note and the L45 deprecated-schema note;
  - describe `tests/bootstrap.php` and the 24-hour stale purge (`WorkCopy::STALE_AFTER_SECONDS`, sized for next-day inspection) alongside the retain-on-failure rule (L42);
  - note that the dev toolchain (PHPUnit 13) needs PHP `>=8.4.1`, while the runtime floor stays `>=8.4`;
  - Test Tiers: rename `ComposerResult` → `ProcessResult` (L53–L54); add `GitRunner`, the hoisted `IntegrationTestCase` helpers, and `LocalPackageClone`'s injectable cache and atomic clone;
  - add explicit conventions: (a) every external process in tests goes through a runner using Symfony `Process` array form, never a shell string, `exec()` or `shell_exec()`; (b) any test that spawns git or Composer belongs in Tier 2, even if offline; (c) fail-fast Composer calls use `runComposerChecked()`; (d) placeholder-substituted fixtures are verified by asserting the literal placeholder, not by leak-pattern absence; (e) mtime ordering is forced with `touch()`, never `sleep()`; (f) tests never mutate the shared clone cache and inject a cache directory instead; (g) new classes in `tests/TestClasses/` require `composer dump-autoload`.
- `docs/agents/project-manifest/file-tree.md` — `tests/` tree:
  - add `bootstrap.php`, `FixtureFileSystem.php`, `WorkCopy.php`, `GitRunner.php`, `ProcessResult.php` (removing `ComposerResult.php`), `TestSuites/TestWorkCopy.php`, `IntegrationSuites/TestGitRunner.php`, `IntegrationSuites/TestLocalPackageClone.php`;
  - update the one-line descriptions of `ComposerSwitcherTestCase`, `LocalPackageClone`, `IntegrationTestCase`, `TestLocalPackageClone.php` (Tier 1) and `TestHarnessExtensibility.php`;
  - update the `work-projects/` note to mention the stale purge;
  - add `.phpunit.cache/` if the tree lists root artefacts.
- `docs/agents/project-manifest/tech-stack.md` — L18 PHPUnit `>=13.0`, with a note that it requires PHP `>=8.4.1` for development; L22 `symfony/process` row names `ComposerRunner`, `GitRunner` and `ExecutableFinder`; Build & QA Tools: PHPUnit bootstrap `tests/bootstrap.php`, PHPStan now covers the test directories.
- `AGENTS.md` — §5 Project Stats: "Test Framework | PHPUnit >=13.0"; "Static Analysis" row notes that `src/` and the test directories are analysed.
- `README.md` — Git hooks section (L316–L317): state that `switch-install-hooks` / `installGitHooks()` overwrites an existing `.git/hooks/pre-commit`.
- `changelog.md` — under the existing, untagged `## v2.0.0` entry, add bullets: PHPUnit dev floor raised to `>=13.0` with the configuration migrated (development now needs PHP `>=8.4.1`); test harness under PHPStan; stale work-copy purge and collision-free work-copy names; atomic local-clone acquisition and the fix for Tier 1 deleting the clone cache; git choke-point; new coverage (hook overwrite, Guard 2 scope, second round trip, malformed version, hyphen alias). No new version heading.
- `docs/agents/project-manifest/api-surface.md` — no change (it documents `src/` public API only; confirmed in the research brief).
- `docs/agents/project-manifest/README.md` — no change (version line already `2.0.0`).

## Deferred Items

| # | Deferred Item | Origin | Reason Deferred | Notes |
|---|---------------|--------|-----------------|-------|
| 1 | Pin the local clone to a tag/commit | Synthesis, Code Insights — Security Auditor WP-005 | A detached checkout changes Composer's inferred version from `dev-master`, which `tests/IntegrationSuites/TestVersionOverride.php` L41–L78 relies on to tell wildcard and pinned resolution apart. | Reconsider if upstream `master` ever gains a live dependency or breaks the fixture. Pin to a branch-like ref (or re-derive the version assertions) at that point. |
| 2 | `putenv` leak-on-fatal hardening in `test_skipsWhenGitUnavailable` | Synthesis, Code Insights — Security Auditor WP-013 | Not a defect. `putenv()` changes live only in the PHPUnit process, and a fatal error ends that process and its environment with it. `finally` already covers exception unwinds. Insight `d9b43ae2` applies to cross-process signals, which this is not. | None. |
| 3 | Dev-only `composer.lock` bumps with no advisories | Synthesis, Code Insights — Security Auditor WP-005 | Informational; nothing to act on. | `roave/security-advisories` keeps guarding future bumps. |
| 4 | Propagate the reference-loop `unset()` hygiene to other array-mutation code | Synthesis, Strategic Recommendations (WP-008 review) | The only other by-reference loop, `src/ConfigSwitcher.php` L240 `recursiveKsort()`, is outside this plan's blast radius. | Fold into whichever plan next edits `recursiveKsort()` (the ergonomics plan touches `verify()` nearby). |

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **The purge deletes a work copy still in use by a long concurrent session** | The 24-hour threshold is far above any Tier 2 run. Only top-level entries of `work-projects/` are considered, and the threshold is a named constant for adjustment. |
| **The purge deletes a retained failure before the developer inspects it** | 24 hours covers next-day inspection, and the purge runs only when PHPUnit starts, so an idle period deletes nothing until the next run. A test pins the constant (`test_staleThresholdCoversNextDayInspection`); longer retention, e.g. over a weekend, is a one-number change. |
| **A PHP 8.4.0 development environment cannot install PHPUnit 13** | Documented as a dev requirement (`tech-stack.md`, `constraints.md`, `changelog.md`); the runtime floor is untouched, so consumers are unaffected. |
| **Refactoring the base TestCase breaks the ergonomics plan's assumptions** | The protected surface keeps its names and semantics (Constraints). Cross-plan sequencing puts this plan first. |
| **Atomic `rename()` fails across a concurrent clone race** | Rename-onto-existing is treated as "someone else won": validate their cache, discard ours, return theirs. Covered by the clone-success test path and code review. |
| **Classmap autoloading misses new or renamed classes** | Steps 3 and 5 run `composer dump-autoload`, and the rule is codified in `constraints.md`. |
| **Composer's malformed-constraint message changes wording** | The test asserts only a non-zero exit and the presence of the constraint text, not Composer's phrasing. |
| **The first Tier 2 run needs network access (cache was deleted by the live bug)** | Tier 2 skips with a cause-naming message when the clone is unavailable. After this plan, Tier 1 can no longer delete the cache. |
| **Characterisation tests are mistaken for endorsed behaviour** | Their docblocks name the limitation and the insight/plan expected to change it. `README.md` documents the overwrite. |
| **Both plans edit the same docs and `TestSwitching.php`** | The regions are disjoint (tests appended after L125; ergonomics edits L214–L252). This plan lands first. |

## Recommended Workflow
- **Workflow:** ledger
- **Rationale:** Twelve steps across configuration, a reshaped shared harness, two new choke-point classes, fifteen suites and the manifest, with harness-wide regression risk and a process-invocation convention worth a security audit. That warrants formal QA, security audit and review stages.
