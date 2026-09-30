# Research Brief

Source: `docs/agents/implementation-history/2026-09-30-switcher-ergonomics/synthesis.md` (Synthesis Rework mode).
All paths are relative to the `composer-local-switcher` repository root. Line numbers were
verified against the working tree on 2026-09-30.

## Scope Sketch

- Reconciliation guards — `src/ConfigSwitcher.php` — modification (INITIAL-state and missing-prod-config guards, dry-run flag restoration)
- File-system facade error handling — `src/Utils/FileSystem.php`, `src/ComposerSwitcherException.php` — modification (native PHP warnings escape the facade)
- Test-runner strictness — `phpunit.xml` — modification
- Tier 1 coverage gaps — `tests/TestSuites/` — modification (value objects, messages, facade propagation, `switchUpdate()` INITIAL, reconcile guards)
- Tier 2 entry-point coverage — `tests/assets/integration-project/composer.json`, `tests/IntegrationSuites/TestEntryPoints.php` — modification
- Pre-commit hook Guard 2 scoping — `resources/git-hooks/pre-commit`, `tests/IntegrationSuites/TestGitHooks.php` — modification
- `LocalPackageClone` concurrent-winner branch — `tests/TestClasses/LocalPackageClone.php`, `tests/IntegrationSuites/TestLocalPackageClone.php` — modification
- Documentation — `README.md`, `changelog.md`, `docs/agents/project-manifest/*.md` — modification

## Area: Reconciliation guards

### Verified References
- `src/ConfigSwitcher.php` (L37): `MODE_INITIAL = 'initial'`. Its docblock (L32–L36) says it is only the mode `switchUpdate()` reports when no switch has run.
- `src/ConfigSwitcher.php` (L39–L52): `MESSAGE_*` constants `182201`–`182214`. The next free codes are `182215` and `182216`.
- `src/ConfigSwitcher.php` (L252–L259): `composerSwitchReconcile()` calls `fromProjectRoot(getcwd())->reconcile()` and echoes `getMessageTexts()`. It passes no direction, so a CLI user cannot request an explicit `RECONCILE_TO_PROD` through `composer switch-reconcile`.
- `src/ConfigSwitcher.php` (L617–L640): `switchUpdate()` returns `new SwitchOutcome(MODE_INITIAL, isDryRun(), messages, operations)` when neither `isDEV()` nor `isPROD()` holds.
- `src/ConfigSwitcher.php` (L674–L719): `switchTo()` wraps its body in `try { … } finally { $this->fileSystem->setDryRun($previousDryRun); }`.
- `src/ConfigSwitcher.php` (L837–L848): `switch_case_PROD_PROD()` adds `MESSAGE_USING_PROD_CONFIG`, then calls `reconcileCore(null, false)`.
- `src/ConfigSwitcher.php` (L870–L875): `reconcile(?string $direction = null, bool $dryRun = false) : SwitchOutcome` clears messages, then delegates to `reconcileCore()`.
- `src/ConfigSwitcher.php` (L890–L941): `reconcileCore()`:
  - validates the direction and sets the dry-run flag, **without try/finally**;
  - resolves `$mode = getStatus()->getMode() ?? self::MODE_PROD`, so an INITIAL call reports mode `prod`;
  - short-circuits DEV mode only (`MESSAGE_DEV_MODE_NOT_RECONCILABLE`), then calls `verify()`;
  - with an explicit `RECONCILE_TO_MAIN` it calls `$this->prodFile->copyTo($this->mainFile)` unguarded.
- `src/ConfigSwitcher.php` (L954–L975): `resolveReconcileDirection()` calls `requireModifiedDate()` on both config files.
- `src/ConfigSwitcher.php` (L1018–L1030): `finishReconcile()` builds the outcome, then restores the dry-run flag. It is the only restore path.
- `src/ConfigSwitcher.php` (L404–L432): `verify()` treats a missing prod file as `array()`, so every top-level key differs in INITIAL. For the same reason, `verify()` does not throw in PROD mode when `composer-prod.json` is missing (re-verified 2026-09-30 during design-review integration).
- `src/ConfigSwitcher.php` (L920–L925) and `src/Utils/BaseFile.php` (L91–L114): the `RECONCILE_TO_PROD` branch runs `mainFile->copyTo(prodFile)`, then `mainFile->getLockFile()->tryCopyTo(prodFile->getLockFile())`. `copyTo()` delegates to `FileSystem::copy()`, which writes the target with `file_put_contents()` (`src/Utils/FileSystem.php` L168–L189), and `tryCopyTo()` requires only the source to exist. A missing `composer-prod.json` is therefore created by this branch, not rejected (re-verified 2026-09-30).
- `src/ConfigSwitcher.php` (L1109–L1125): `switch_initProductionFiles()` creates `composer-prod.json` on the first switch only.
- `src/Utils/BaseFile.php` (L60–L78): `requireModifiedDate()` throws `ERROR_CANNOT_GET_MODIFIED_DATE` (182109) with `KEY_FILE_PATH` context when the file is missing.
- `tests/TestSuites/TestReconcile.php`: 8 tests (L25–L180). There is no INITIAL-state, missing-prod or dry-run-throw test.
- `tests/TestSuites/TestDryRun.php` (L136–L155): `test_dryRunFlagRestoredAfterException()` covers `switchTo()` only.
- `tests/TestSuites/TestSwitching.php` (L20–L36): `test_initialState()` asserts status/flag state, but never calls `switchUpdate()`.
- `tests/IntegrationSuites/TestEntryPoints.php` (L116): `test_switchUpdateIsNoOpInInitialState()` is the only INITIAL `switchUpdate()` coverage, and it runs at CLI level.

### Established Patterns
- A non-reconcilable state is a no-op with a coded message, never a throw: the DEV branch in `reconcileCore()` (`src/ConfigSwitcher.php` L903–L911).
- Dry-run flag restoration happens in `finally`: `switchTo()` (`src/ConfigSwitcher.php` L714–L718) and `previewSwitch()` (L737–L749).
- `reconcile()` and `switch_case_PROD_PROD()` share `reconcileCore()`, so both call paths behave identically (`src/ConfigSwitcher.php` L846).

### Structural Observations
- `src/ConfigSwitcher.php` `reconcileCore()`: the dry-run flag is restored only on the return paths, through `finishReconcile()`. A throw from `verify()` (malformed JSON), `requireModifiedDate()` or `copyTo()` leaves the shared facade stuck in dry-run mode, although `switchTo()` guarantees the opposite.
- `src/ConfigSwitcher.php` `reconcileCore()`: the `?? self::MODE_PROD` fallback mislabels the INITIAL state.
- `src/ConfigSwitcher.php` `reconcileCore()`: it owns the dry-run flag, operation list and `MESSAGE_DRY_RUN_ACTIVE` although it is also called nested, from inside `switchTo()`. Everywhere else the outermost entry point owns the flag (`switchTo()`, `previewSwitch()`).
- `src/ConfigSwitcher.php` `reconcileCore()`: "not reconcilable" is decided inline, by one DEV-only `if`. Adding INITIAL and missing-prod as further inline branches would give three copies of the same shape.
- `src/ConfigSwitcher.php` `verify()`: in INITIAL it reports every key as differing, since the prod file does not exist yet. That is misleading, but it is the public `VerificationResult` contract, and Tier 2 `composer switch-verify-config` tests assert on it.
- The long-form `array()` syntax remains in `reconcileCore()`/`requireValidReconcileDirection()` (32 occurrences file-wide).

### Constraints
- Error/message numbering: switcher messages use `1822xx` (`AGENTS.md` §4).
- [added by: Plan Auditor; verified by Planner 2026-09-30] `src/ConfigSwitcher.php` (L846, L893–L900): `reconcileCore()` calls `setDryRun($dryRun)` and `clearOperations()` unconditionally, and `switch_case_PROD_PROD()` passes `false`. So `previewSwitch(MODE_PROD)` in PROD mode (via `switchTo($mode, true)`, L674–L719, dispatched through `switch_copyLockFiles()` L797–L827) has the flag reset to `false` for the reconcile step. A drifted PROD project gets a real `composer.json` ↔ `composer-prod.json` copy during a preview. `finishReconcile()` then restores `true`, so the status and flag files stay dry-run; only the copy escapes. The nested `clearOperations()` is harmless today, because no operation is recorded before the PROD→PROD dispatch, but it would silently drop any that were.
- [verified by Planner 2026-09-30] `docs/agents/project-manifest/switching-decision-table.md` (L41) names the call chain `reconcileCore(null, false)`; `docs/agents/project-manifest/api-surface.md` (L100) describes `previewSwitch()` as writing nothing to disk.
- [verified by Planner 2026-09-30] `tests/TestSuites/TestDryRun.php` (L35–L50): `test_previewLeavesDiskUntouched()` previews `MODE_PROD` from the PROD state only when the two configs are in sync, so the reconcile step takes the `MESSAGE_ALREADY_IN_SYNC` return and the clobber is never exercised.
- [added by: Plan Auditor, unverified] `src/Utils/BaseFile.php` (L51): `exists()`; `src/Utils/StatusFile.php` (L50, L62, L67): `getMode()`, `isDEV()`, `isPROD()`.
- [added by: Plan Auditor, unverified] `tests/IntegrationSuites/TestGitHooks.php` (L346–L360): `runHookScript()` uses `new Process([$hookPath], $this->testTarget)` with the inherited environment; a restricted-`PATH` hook test needs its own Process with an `env` override.
- `MODE_INITIAL` must stay rejected by `requireValidMode()`. It is an outcome mode, not a switch target (`docs/agents/project-manifest/api-surface.md` L35).

- [added by: Plan Auditor, unverified] `src/ConfigSwitcher.php` L674-L716 `switchTo()` saves the dry-run flag, sets it, clears operations, and restores in `finally`; L737-L749 `previewSwitch()` only delegates to `switchTo($mode, true)`. Only callers of `reconcileCore()` are `reconcile()` (L874) and `switch_case_PROD_PROD()` (L848).
- [added by: Plan Auditor, unverified] `docs/agents/project-manifest/constraints.md` L20 says `clearOperations()` is called at the start of every `switchTo()`/`reconcileCore()` call.

## Area: File-system facade error handling

### Verified References
- [added by: Plan Architect Reviewer, verified by Planner 2026-09-30] `src/ComposerSwitcherException.php` (L9, L70–L72): `extends Exception`. The `setContext()` docblock notes that it does not affect the inherited `Exception` constructor signature (message, code, previous), so a `previous` throwable can be chained.
- `src/Utils/FileSystem.php` (L76–L106): `read()` calls `file_get_contents($path)` bare. On a missing file PHP emits `E_WARNING` before the facade throws `ERROR_CANNOT_READ_FILE`.
- `src/Utils/FileSystem.php` (L108–L129): `modifiedTime()` guards with `file_exists()` before `filemtime()`.
- `src/Utils/FileSystem.php` (L138–L156): `write()` calls `file_put_contents()` bare, so a warning is emitted on failure.
- `src/Utils/FileSystem.php` (L168–L189): `copy()` calls `file_put_contents($target, …)` bare, so a warning is emitted on failure.
- `src/Utils/FileSystem.php` (L200–L222): `delete()` calls `unlink()` bare, so a warning is emitted on failure.
- `src/Utils/FileSystem.php`: 9 `array()` long-form occurrences.
- `src/ComposerSwitcherException.php` (L25–L64): `KEY_DIRECTION`, `KEY_FILE_PATH`, `KEY_TARGET_PATH`, `KEY_MODE`, `KEY_EXPECTED`, `KEY_ACTUAL`, `KEY_PACKAGE_NAME`, plus `setContext()`, `getContext()` and `getContextValue()`.
- `tests/TestSuites/TestExceptionContext.php` (L94–L128) `test_copyFailureContext()`, (L132–L145) `test_readFailureCarriesErrorCode()`, and `tests/TestSuites/TestFileSystem.php` (L114–L122) `test_realMode_readMissingFileThrows()`: these are the three synthesis-named sources of unsuppressed warnings.
- `tests/TestSuites/TestDryRun.php` (L165–L201): `test_noFilesystemCallsOutsideFacade()` forbids bare filesystem calls anywhere in `src/` except `FileSystem.php` and `installGitHooks()`.

### External fact (verified 2026-09-30)
- Composer's `Composer\Util\ErrorHandler::handle()` (upstream `src/Composer/Util/ErrorHandler.php`, `main`) throws `\ErrorException` for every non-deprecation error that `error_reporting()` includes, `E_WARNING` among them. It returns early when the level is suppressed, so it respects `@`. Consequence: any library call running inside a Composer script (all `composerSwitch*()` entry points) that emits a native warning surfaces as a bare `ErrorException`. The library's typed `ComposerSwitcherException`, with its code and context, is never thrown. The "3 unsuppressed test warnings" in the synthesis are therefore a consumer-facing defect in `src/`, not test hygiene.

### Established Patterns
- Every throw site attaches structured context via `->setContext([...KEY_* => …])`, for example `src/Utils/FileSystem.php` L99–L105 and `src/Utils/BaseFile.php` L75–L77.
- The test harness suppresses native warnings with `@` where failure is expected, for example `tests/TestClasses/LocalPackageClone.php` L139 `@rename`.

### Structural Observations
- `src/Utils/FileSystem.php`: four native calls (`file_get_contents`, `file_put_contents` twice, `unlink`) can each emit a warning, and each is handled separately. A single scoped capture helper would give one place that owns "no native warning escapes the facade".
- The exceptions thrown from these sites discard the native error message (e.g. "Permission denied"), which is the most useful diagnostic.

### Constraints
- The fix must stay inside `src/Utils/FileSystem.php`, so the choke-point guard test keeps passing.

## Area: Test-runner strictness

### Verified References
- `phpunit.xml` (L1–L19): no `failOn*` attributes. There are two testsuites, `Test suites` (`tests/TestSuites`) and `Integration` (`tests/IntegrationSuites`).

### Structural Observations
- Nothing structural stops a new warning-emitting path from landing, because a green run with "OK, but there were issues!" is indistinguishable in CI from a clean run. The synthesis carried these three warnings across WP-002, WP-008 and WP-010 without anyone acting on them.

### Constraints
- PHPUnit `>=13.0` (`composer.json`). `failOnWarning`/`failOnNotice`/`failOnDeprecation` are valid PHPUnit ≥10 XML attributes.
- Deprecations can originate in vendor code (`symfony/process`, PHPUnit itself) on newer PHP minors, so failing on them would couple the suite to upstream release timing.

## Area: Tier 1 coverage gaps

### Verified References
- `src/State/SwitchMessage.php` (L44–L47): `hasCode()` returns `$this->code > 0`, so a negative code counts as "no code". This is untested.
- `tests/TestSuites/TestStateValueObjects.php` (L23–L47): `test_switchMessage_gettersAndArray`, `test_switchMessage_hasCode`. The negative case is absent.
- `tests/TestSuites/TestStateValueObjects.php` (L176–L183): `test_switchOutcome_noOperations()` asserts `hasOperations()` and `getMessageTexts()`, but not `toArray()` for empty collections. `SwitchDescription`'s empty-collection `toArray()`/`toJSON()` round trip is also untested (L189–L256).
- `src/Utils/ConfigFile.php` (L24–L35): `setFileSystem()` override that propagates to the eagerly-constructed `LockFile` (L12–L19). No test asserts that the lock file shares the facade.
- `src/ConfigSwitcher.php` (L325–L344): the constructor propagates one facade to main/prod/dev/status files.
- `src/ConfigSwitcher.php` (L1138–L1165): `displayMessages()` prints nothing when the log is empty. `setDisplayMessages(false)` disables `autoDisplayMessages()` (L1128–L1133).
- `tests/TestSuites/TestMessages.php` (L116–L137): `test_displayMessagesOutputUnchanged()` covers only the enabled, non-empty case.

### Established Patterns
- Output assertions use `ob_start()`/`ob_get_clean()` with `setWriteToConsole(false)` (`tests/TestSuites/TestMessages.php` L118–L128).
- Tier 1 switcher tests extend `ComposerSwitcherTestCase` and use `createSwitcher()` (`tests/TestClasses/ComposerSwitcherTestCase.php` L73–L82).

### Structural Observations
- `src/Utils/ConfigFile.php`: facade propagation to the eager `LockFile` is procedural. It is one override with an explanatory comment, flagged "structurally fragile" by WP-002. A delegation reshape (the `LockFile` reads its parent's facade) would make it structural, but it needs `BaseFile`'s private `$fileSystem` accesses rerouted and leaves `LockFile::setFileSystem()` with no meaningful behaviour.

## Area: Tier 2 entry-point coverage

### Verified References
- `tests/assets/integration-project/composer.json` (L14–L18): defines only the five v2 scripts (`switch-dev`, `switch-prod`, `switch-update`, `switch-verify-config`, `switch-install-hooks`). The five v3 entry points (`switch-describe`, `switch-describe-json`, `switch-reconcile`, `switch-preview-dev`, `switch-preview-prod`, listed in `README.md` L137 and `data-flows.md` L189) are never driven through a real `composer` binary.
- `tests/IntegrationSuites/TestEntryPoints.php` (L11): the class docblock says "driving all five namespaced `composer switch-*` commands".

### Structural Observations
- `composerSwitchReconcile()` is the consumer-facing surface of the INITIAL-state defect, and it runs under Composer's `ErrorHandler`. It has no Tier 2 test.

### Constraints
- A Tier 2 fresh work copy is in the INITIAL state. `composer switch-prod` is required before the PROD-state assertions (`TestEntryPoints.php` L82–L101 precedent).

## Area: Pre-commit hook Guard 2

### Verified References
- [added by: Plan Architect Reviewer, verified by Planner 2026-09-30] `resources/git-hooks/pre-commit` (L41–L58): Guard 2 sits inside `if echo "$STAGED" | grep -qx "composer.json"`. Composer's schema allows non-array `repositories` entries (e.g. `"packagist.org": false`), which a PHP decode must skip.
- External fact: PHP exits with status `255` on a fatal error, including a parse error in `php -r` code. A missing binary under `env` or `command` gives `127`. Guard 2's PHP result codes must therefore avoid `0`, `1`, `127` and `255` as meaningful answers.
- `resources/git-hooks/pre-commit` (L41–L58): Guard 2 runs `git show :composer.json | grep -qE '"type"\s*:\s*"path"'`, a file-wide match. The header comment (L3–L6) claims it is scoped to "the repositories key".
- `tests/IntegrationSuites/TestGitHooks.php` (L160–L188): `test_guard2MatchesTypePathOutsideRepositories()` is a characterisation test pinning the file-wide match. Its docblock names the insight `2cd87f16-…` reshape as the expected change point.
- `tests/IntegrationSuites/TestGitHooks.php` (L92–L107) `test_guard2BlocksPathRepository()`, (L113–L122) `test_hookPassesInProdState()`.
- `tests/IntegrationSuites/TestGitHooks.php` (L346–L360): `runHookScript()` executes the hook directly, via its `#!/usr/bin/env bash` shebang, with the inherited environment.
- `tests/IntegrationSuites/TestGitHooks.php` (L205–L240, L284): `test_skipsWhenGitUnavailable()` builds a restricted `PATH` through `createPhpOnlyPathDirectory()` (a single-purpose helper).
- `README.md` (L606–L610): describes Guard 2 as blocking a `"type": "path"` repository entry.

### Established Patterns
- The restricted-`PATH` technique is used to simulate a missing binary (insight `12cc032f-…`, `TestGitHooks.php` L205–L240).
- Characterisation tests mark a deferred change point (insight `76d1cee5-…`).

### Structural Observations
- `resources/git-hooks/pre-commit`: Guard 2's header comment and README both describe repositories-key scoping that the code does not implement. The mismatch is user-facing: a legitimate `"type": "path"` under `extra` blocks commits.
- `tests/IntegrationSuites/TestGitHooks.php` `createPhpOnlyPathDirectory()`: hard-codes one binary. A second restricted-`PATH` test (hook without `php`) would otherwise duplicate it.

### Constraints
- The hook runs in consumer repositories, where PHP is almost always on `PATH` but is not guaranteed in GUI git clients. Any PHP-based check needs a fail-safe fallback that keeps blocking.

## Area: LocalPackageClone concurrent-winner branch

### Verified References
- [added by: Plan Architect Reviewer, verified by Planner 2026-09-30] `tests/TestClasses/LocalPackageClone.php` (L184, L201, L206, L216): `cloneInto(string $targetDir) : bool`, `allocatePartialPath()`, `cleanupPartial()` and `purgeStalePartialSiblings()` are all `private`. The constructor (L69–L76) takes `?string $cacheDirectory` and `string $repositoryUrl` via promoted readonly properties. `getCacheDirectory()` (L84) is `public`.
- [added by: Plan Architect Reviewer, verified by Planner 2026-09-30] `tests/TestClasses/LocalPackageClone.php` (L127–L150): `cloneInto($partialDir)` runs after the purge and the `is_dir($cacheDir)` short-circuit, and before the partial's `composer.json` check and the `@rename()`. A directory created at `$cacheDir` from inside `cloneInto()` is therefore seen only by the rename. A POSIX `rename()` of a directory onto an empty directory succeeds, so a real lost race needs a non-empty winner directory.
- `tests/TestClasses/LocalPackageClone.php` (L108–L156): `ensureAvailable()`. The concurrent-winner branch at L139–L150 (`!@rename(...)`, then adopt or `REASON_CLONE_FAILED`) is unreachable from tests without a seam.
- `tests/TestClasses/LocalPackageClone.php` (L31): `class LocalPackageClone` is not final, so a test subclass is possible.
- `tests/IntegrationSuites/TestLocalPackageClone.php` (L73–L86, L106–L130): `test_successfulCloneIsAtomic()` and `createLocalGitRepository()`, a throwaway local repo usable as an injected URL.
- `tests/IntegrationSuites/TestVersionOverride.php` (L17, L38–L53): depends on Composer inferring `dev-master` from the clone's branch.

### Established Patterns
- A protected, overridable seam for otherwise-unreachable failure branches, such as `ComposerSwitcherTestCase::getFixtureSourceDir()` (`tests/TestClasses/ComposerSwitcherTestCase.php` L47–L59) and insight `f5a35b57-…`.

### Structural Observations
- `tests/TestClasses/LocalPackageClone.php`: `rename()` is inlined in `ensureAvailable()`, so no seam exists.
- Clone pinning (rework-1 deferred item) would need commit verification of an already-present shared cache. It would also need a non-atomic migration path for existing caches cloned at `HEAD`, which works against the atomic-acquisition guarantee (insight `dda3ef6c-…`).

## Area: Documentation

### Verified References
- `docs/agents/project-manifest/switching-decision-table.md` (L21): the INITIAL "Reconcile" row documents the throw as a known gotcha. The PROD section (L41–L48) has no missing-prod-config row.
- `docs/agents/project-manifest/api-surface.md` (L35) message-constant notes, (L102) `reconcile()` behaviour, (L133–L139) `KEY_*` constants.
- `docs/agents/project-manifest/data-flows.md` (L69–L85): PROD-to-PROD reconciliation flow.
- `docs/agents/project-manifest/constraints.md` (L18): FileSystem choke-point rule, (L86) pre-commit hook.
- `docs/agents/project-manifest/file-tree.md` (L43–L44, L60, L68): test-suite and hook annotations.
- `docs/agents/project-manifest/README.md` (L4): `Version: 3.0.0`.
- `README.md` (L90) reconcile note, (L306–L340) "Reconciling PROD configuration drift", (L515–L538) "Handling exceptions", (L606–L610) pre-commit hook.
- `changelog.md` (L3–L25): the `v3.0.0` entry.
- `AGENTS.md` §2: maintenance rules. New error/message constant → `api-surface.md` + `constraints.md`. Test structure changed → `file-tree.md` + `constraints.md`. New switching behaviour → `data-flows.md`.

### Constraints
- `git tag` lists nothing after `1.0.4`. `v2.0.0` and `v3.0.0` are both untagged, and the v3.0.0 work is still uncommitted in the working tree, so no consumer has seen v3.0.0 behaviour.

## Strategic Context

- The repository has no declared strategic vision (all horizons `null`).
- Prior projects: `2026-09-30-switcher-ergonomics` (v3.0.0, COMPLETE, 10/10 WPs; the source of this rework), `2026-09-29-self-sufficient-test-surface-rework-1`, `2026-09-28-self-sufficient-test-surface`.
- Insights applied:
  - `5ceb852c-…`: a WP with authoring deliverables needs an active authoring stage.
  - `cdaf1471-…`: verify stage configuration right after WP creation.
  - `f5a35b57-…`: protected seam for unreachable failure branches.
  - `76d1cee5-…`: characterisation tests as change markers.
  - `12cc032f-…`: restricted `PATH` for simulating missing binaries.
  - `dda3ef6c-…`: atomic cache acquisition.
  - `5d0fa468-…`/`1eea7425-…`: no per-instance caching across a dry-run boundary.
- Insights found stale during research:
  - `d215102a-…` claims `switch-prod` silently no-ops without a lock file. v3.0.0 WP-007 made the switch complete with a warning.
  - `a35e83b2-…` claims `ensureAvailable()` misreports `REASON_INVALID_DIRECTORY` after a partial clone. Rework-1's atomic acquisition removed that path.
- Maintainer policies (planner memory): consumer repos are never a test surface, and there is no PHP 7 compatibility requirement. Touched files are modernised in the same pass.
