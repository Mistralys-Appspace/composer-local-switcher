# Plan

## Plan Audit Cycles
- Audits: 2 (Sonnet 5.5 ×2) — Plan Auditor v1.11.0
- Architectural Reviews: 1 (Sonnet 5.5 ×1) — Plan Architect Reviewer v2.3.4

## Prior Project Context

- **Source synthesis:** `docs/agents/implementation-history/2026-09-30-switcher-ergonomics/synthesis.md` (v3.0.0, 10/10 WPs COMPLETE). It lists these deferred or follow-up items:
  - `reconcile()` throws in the INITIAL state;
  - three unsuppressed PHP warnings;
  - coverage gaps from WP-001, WP-002 and WP-004;
  - `switchUpdate()` INITIAL-state unit coverage;
  - a WP pipeline-configuration process note.

  Every one of them is promoted into this plan.
- **Carried-forward deferrals:** the predecessor plan's `Out of Scope` held three items deferred from `2026-09-29-self-sufficient-test-surface-rework-1`: `LocalPackageClone` clone pinning, the concurrent-winner test, and Guard 2 scoping. The user asked for every deferred item that fits a rework. The concurrent-winner test and Guard 2 scoping are promoted. Clone pinning stays deferred, with its reason in `Deferred Items`.
- **Release state:** no git tag exists after `1.0.4`, and the v3.0.0 work is still uncommitted, so no consumer has observed v3.0.0 behaviour. This plan's fixes therefore fold into the unreleased `v3.0.0` changelog entry rather than opening a `v3.0.1`.
- **Insights applied:**
  - `5ceb852c-…` (authoring WPs need an authoring stage) and `cdaf1471-…` (check stage configuration right after WP creation) shape `Constraints` and `Recommended Workflow`.
  - `f5a35b57-…` (protected seam for unreachable failure branches) shapes step 9.
  - `76d1cee5-…` (characterisation tests as change markers) and `12cc032f-…` (restricted `PATH` to simulate a missing binary) shape step 8.
  - `dda3ef6c-…` (atomic cache acquisition) is the reason clone pinning stays deferred.
- **Maintainer policies:** consumer repositories are never a test surface. There is no PHP 7 compatibility requirement, and touched files are modernised in the same pass.

## Knowledge Base Reconciliation

| Insight ID | Title | What the plan overtakes | Executed by |
|------------|-------|-------------------------|-------------|
| d215102a-9dc8-4377-b6af-7652f99305d1 | switch-dev then switch-prod needs an intervening `composer update`, or switch-prod silently no-ops | Already untrue since v3.0.0 WP-007: `switchTo()` completes in full and records `MESSAGE_NO_LOCK_FILE_FOUND`/`MESSAGE_PROD_LOCK_MISSING` instead of no-opping. This plan's documentation sweep (step 10) restates the current behaviour, so the entry would contradict both the code and the docs. | Ledger Knowledge Curator v1.4.1 (Targeted Reconciliation) |
| a35e83b2-5ab6-4608-bb0a-2d20dcc30b41 | LocalPackageClone::ensureAvailable() misreports the failure reason after a partial clone is left on disk | Fixed by rework-1's atomic `.partial-*` acquisition: a partial clone can no longer occupy the cache path. Step 9 adds tests for the last untested acquisition branch, and the entry still describes an open defect. | Ledger Knowledge Curator v1.4.1 (Targeted Reconciliation) |
| 76d1cee5-c759-44ae-8e66-ece748505b0d | Characterisation tests as a deliberate boundary marker for deferred fixes | The entry's worked example, `test_guard2MatchesTypePathOutsideRepositories()`, is intentionally flipped by step 8, which renames it and inverts its assertion as the marker predicted. The principle stays valid; the example should be updated to say the marker fired, and how. | Ledger Knowledge Curator v1.4.1 (Targeted Reconciliation) |


## Summary

This rework addresses every actionable item in the switcher-ergonomics synthesis
(`docs/agents/implementation-history/2026-09-30-switcher-ergonomics/synthesis.md`), and every carried-forward
deferral that fits a rework. The main work:

- **Reconciliation that never throws in an unreconcilable state.** `reconcile()`,
  `composerSwitchReconcile()` and the PROD→PROD switch path degrade to coded no-op outcomes in the
  INITIAL state and when `composer-prod.json` is missing, instead of throwing. The one exception
  is an explicit `reconcile(RECONCILE_TO_PROD)` in PROD mode, which is honoured and recreates the
  missing baseline from `composer.json`. `reconcile()` also gains the same dry-run-flag `finally`
  guarantee `switchTo()` already has, and the nested PROD→PROD reconcile stops overwriting the
  enclosing switch's dry-run flag, so `previewSwitch(MODE_PROD)` in a drifted PROD project no
  longer performs a real copy.
- **No native PHP warnings escape `FileSystem`.** They currently do, and they are not a
  test-hygiene issue: Composer's `ErrorHandler` turns them into bare `ErrorException`s, so consumers
  running a `composer switch-*` script never see the library's typed exception.
- **A test runner that fails on warnings and notices,** so this cannot regress silently.
- **Tests for the gaps the synthesis names:** the accumulated value-object, message, facade and
  `switchUpdate()` gaps, and Tier 2 coverage for the five v3 entry points.

It also scopes the pre-commit hook's Guard 2 to the `repositories` key, as its own comment and
the README already claim, and makes `LocalPackageClone`'s concurrent-winner branch testable.
Every change folds into the still-unreleased v3.0.0.

## Architectural Context

- **`src/ConfigSwitcher.php`** is the single orchestrator:
  - `reconcile()` (L870–L875) and `switch_case_PROD_PROD()` (L837–L848) share the private
    `reconcileCore()` (L890–L941), which today short-circuits only DEV mode (L903–L911).
  - Direction resolution (`resolveReconcileDirection()`, L954–L975) calls
    `BaseFile::requireModifiedDate()` (`src/Utils/BaseFile.php` L60–L78), which throws
    `ERROR_CANNOT_GET_MODIFIED_DATE` when `composer-prod.json` does not exist, as in the INITIAL
    state before `switch_initProductionFiles()` (L1109–L1125) has ever run.
  - The dry-run flag is restored only on `reconcileCore()`'s return paths, via `finishReconcile()`
    (L1018–L1030). `switchTo()` (L674–L719) uses `try/finally` for the same purpose.
  - `reconcileCore()` also sets the flag to its own `$dryRun` argument and clears the operation
    list unconditionally (L893–L900), although `switch_case_PROD_PROD()` calls it nested inside
    `switchTo()` with a hard-coded `false` (L846). A PROD-mode `previewSwitch(MODE_PROD)` therefore
    runs its reconcile copy for real when the two configs have drifted. The existing PROD preview
    test (`tests/TestSuites/TestDryRun.php` L35–L50) only covers the in-sync case.
  - Messages are `SwitchMessage` value objects with `1822xx` codes (L39–L52).
- **`src/Utils/FileSystem.php`** is the single I/O choke-point. `test_noFilesystemCallsOutsideFacade()`
  (`tests/TestSuites/TestDryRun.php` L165–L201) enforces this for all of `src/`. Its real-mode
  `read()`, `write()`, `copy()` and `delete()` call `file_get_contents`, `file_put_contents` and
  `unlink` bare (L94, L147, L179, L211), so a failure emits `E_WARNING` before the typed
  `ComposerSwitcherException` is thrown.
- **`src/ComposerSwitcherException.php`** carries structured context under `KEY_*` constants (L25–L64).
- **The test harness** has two PHPUnit suites (`phpunit.xml`):
  - Tier 1 `tests/TestSuites/`, offline;
  - Tier 2 `tests/IntegrationSuites/`, which runs real `git` and `composer` binaries against
    `tests/assets/integration-project/`, whose `composer.json` (L14–L18) wires only the five v2
    scripts.
- **`resources/git-hooks/pre-commit`** is the shipped bash hook. Guard 2 (L41–L58) greps the whole
  staged `composer.json`. `tests/IntegrationSuites/TestGitHooks.php` L160–L188 pins that behaviour
  as a characterisation test.
- **`tests/TestClasses/LocalPackageClone.php`** acquires the shared Tier 2 clone atomically. Its
  concurrent-winner branch (L139–L150) is unreachable without a seam.

## Approach / Architecture

1. **Reconcile blockers as one decision point.** `reconcileCore()` currently decides "not
   reconcilable" with an inline DEV check. Replace that with a private
   `reconcile_detectBlocker(?string $direction) : ?SwitchMessage`, which returns the blocking
   message for three states:
   - DEV mode: the existing `MESSAGE_DEV_MODE_NOT_RECONCILABLE`, whatever the direction;
   - INITIAL state: new `MESSAGE_INITIAL_NOT_RECONCILABLE = 182215`, whatever the direction;
   - PROD mode with `composer-prod.json` missing, when `$direction` is `null` or
     `RECONCILE_TO_MAIN`: new `MESSAGE_PROD_CONFIG_MISSING = 182216`.

   The check runs after direction validation and before `verify()`. The DEV and INITIAL blockers
   are direction-agnostic, because no direction can act in those states. The missing-prod blocker
   is not. `RECONCILE_TO_MAIN` must stay blocked, because its source is missing. The automatic
   (`null`) direction must stay blocked, because it would otherwise pick a direction by mtime
   against a file that does not exist. An explicit `RECONCILE_TO_PROD` is the caller's consent to
   recreate the baseline from `composer.json`. It is not blocked, and it runs the existing
   `RECONCILE_TO_PROD` copy, which already creates a missing target. A blocked call records the
   message and returns a no-op `SwitchOutcome`. The outcome mode becomes `MODE_INITIAL` in the
   INITIAL state, replacing the `?? MODE_PROD` mislabel. Because `switch_case_PROD_PROD()` shares
   the core with a `null` direction, `switch-prod` and `switch-update` in a PROD project with a
   deleted `composer-prod.json` also stop throwing, and they stay blocked.
2. **The outermost entry point owns the dry-run flag.** Flag ownership moves out of
   `reconcileCore()` into the public `reconcile()`. `reconcile()` validates the direction, saves the
   flag, sets it, clears operations, adds `MESSAGE_DRY_RUN_ACTIVE` when dry-running, and calls the
   core inside `try { … } finally { setDryRun($previousDryRun); }`, mirroring `switchTo()`.
   `reconcileCore(?string $direction)` loses its `$dryRun` parameter and never touches the flag or
   the operation list. It runs under whatever flag its caller set. The nested PROD→PROD call from
   `switchTo()` therefore inherits the switch's flag, so a preview stays a preview, and its
   operations join the switch's own list. `finishReconcile()` shrinks to building the outcome.
3. **Keep native warnings inside the facade.** `FileSystem` gains one private helper that runs a
   native filesystem call under a scoped `set_error_handler()`. The handler captures the native
   error message instead of letting it propagate, and the previous handler is restored in
   `finally`. Every real-mode `file_get_contents`, `file_put_contents` and `unlink` goes through
   the helper. Each throw site adds the captured text under a new
   `ComposerSwitcherException::KEY_NATIVE_ERROR = 'nativeError'` context key. It also chains the
   captured error as the exception's `$previous`, as an `\ErrorException`, so the native file and
   line survive. The context key stays the primary, serialisable diagnostic channel; the chained
   exception is supplementary.
4. **A strict test runner.** `phpunit.xml` gains `failOnWarning="true"` and `failOnNotice="true"`,
   so a warning or notice fails the run instead of printing "OK, but there were issues!".
   Deprecations are excluded (see `Considered Alternatives`).
5. **Tier 2 entry-point parity.** The integration fixture wires all ten `switch-*` scripts.
   `TestEntryPoints` drives `switch-reconcile` (INITIAL and PROD), `switch-describe-json` and
   `switch-preview-dev` through the real `composer` binary, with its real `ErrorHandler` active.
6. **Guard 2 scoped by parsing, with a fail-safe fallback.** The hook pipes the staged
   `composer.json` into `php -r` to decode it. It blocks only when an entry of `repositories`
   (list or keyed-object form) is an array with `"type": "path"`. Non-array entries, such as
   `"packagist.org": false`, are skipped. The PHP check answers with dedicated exit codes that
   cannot collide with PHP's own (`255` on a fatal error) or the shell's (`127` for a missing
   binary). Only the dedicated "none found" code lets the commit through. Every other status takes
   today's file-wide grep: PHP absent from `PATH`, undecodable input, and a PHP crash alike. The
   hook keeps blocking and never fails open.
7. **A `LocalPackageClone` clone seam.** `cloneInto(string $targetDir) : bool` changes from
   `private` to `protected`, and the inlined `@rename()` stays where it is. A test subclass calls
   the parent clone, then materialises a non-empty competing directory at `getCacheDirectory()`.
   The real `rename()` then loses the race exactly as it would against a concurrent process, and
   no rename outcome is stubbed.
8. **Test gaps closed** in the existing suites, next to the tests they extend.

## Rationale

- **Why a no-op outcome instead of an auto-initialising reconcile in INITIAL:** there is no
  baseline to reconcile against before the first switch, and any write would be a guess.
  `switchUpdate()` already treats INITIAL as an explicit no-op (L617–L640), and DEV mode already
  treats "not reconcilable" as a coded no-op. Following both keeps one consistent answer to the
  question "what does the switcher do when it cannot act?".
- **Why a missing prod config in PROD mode is blocked too:** it is the same defect reached from a
  different state. `requireModifiedDate()` on a missing file throws, and so does an explicit
  `RECONCILE_TO_MAIN`. Guarding INITIAL alone would leave `composer switch-update` throwing for a
  user who deleted `composer-prod.json`. For the automatic direction, a no-op with guidance is the
  most restrictive interpretation (`AGENTS.md` §4). Silently backing up `composer.json` would
  recreate the baseline from a file that may carry uncommitted edits, without anyone having asked.
- **Why an explicit `RECONCILE_TO_PROD` is still honoured in that state:** that objection does
  not hold once the caller names the direction. Explicit input is treated as consent elsewhere in
  the reconcile API: once `requireValidReconcileDirection()` has accepted it, an explicit direction
  replaces the mtime resolution outright (`$direction ?? resolveReconcileDirection()` in
  `reconcileCore()`) and is never second-guessed. The CLI recovery (delete the status file, then `composer switch-prod`)
  ends with `switch_initProductionFiles()` building `composer-prod.json` from the same
  `composer.json`, so refusing the explicit request protects nothing and only lengthens the path.
  The existing `RECONCILE_TO_PROD` branch already creates a missing target
  (`BaseFile::copyTo()`/`tryCopyTo()`), so honouring it costs one condition and no new code path.
  The DEV state is a true dead end and INITIAL has no mode to reconcile, so those two blockers stay
  direction-agnostic.
- **Why `reconcile_detectBlocker()`:** it has three current consumers (DEV, INITIAL, missing prod).
  One ordered decision point is easier to read and extend than three inline branches. It takes
  `$direction` because the missing-prod rule depends on it; the other two rules ignore it.
- **Why flag ownership moves to `reconcile()` instead of passing `isDryRun()` down:** the dry-run
  flag belongs to whichever public entry point the caller invoked. `switchTo()` and
  `previewSwitch()` already follow that rule; the nested `reconcileCore()` is the one place that
  breaks it. Having `switch_case_PROD_PROD()` pass `$this->fileSystem->isDryRun()` would also stop
  the real copy. It would still leave the core clearing the enclosing switch's operation list and
  recording `MESSAGE_DRY_RUN_ACTIVE` a second time, and every future nested caller would have to
  remember the same workaround. With the core flag-agnostic, a nested caller cannot get it wrong.
  This is a correctness fix to a dry-run guarantee the plan already rewrites (AC-03), not a
  deferrable nicety: a preview that writes to disk is the exact failure dry-run exists to prevent.
- **Why the warning fix belongs in `src/`, not in tests:** Composer's `ErrorHandler::handle()`
  throws `\ErrorException` for any non-suppressed warning (verified against upstream on
  2026-09-30). Suppressing the warnings in the three tests would hide a defect consumers hit on
  every I/O failure inside a Composer script.
- **Why a scoped error handler instead of `@` plus `error_get_last()`:** the two are close.
  Calling `error_clear_last()` before the wrapped call removes the risk of reading a stale error
  from unrelated earlier code, so staleness alone is not decisive. The handler wins on
  explicitness: it captures exactly the error raised by the wrapped call as a structured value
  (level, message, file, line) that can become the chained `\ErrorException`. It does not depend on
  how `error_reporting` masking interacts with `@`, and it restores the caller's handler
  (Composer's, PHPUnit's) deterministically in `finally`.
- **Why fail on warnings and notices in `phpunit.xml`:** the synthesis carried these three
  warnings through three WPs without anyone acting on them. A structural guarantee beats a
  procedural reminder, the same lesson the synthesis draws from the `FileSystem` choke-point.
- **Why Guard 2 is scoped now, without the guard-registry reshape:** the hook's own header and
  `README.md` L606–L610 already promise repositories-key scoping. The false positive is
  user-facing, and the fix is local to one guard. The registry reshape described by insight
  `2cd87f16-…` is a Node.js cross-platform pattern for many guards. For a two-guard bash hook it
  would be structure with no named consumer.
- **Why the `LocalPackageClone` seam is a protected `cloneInto()`:** a protected method is the
  narrowest change that makes the branch reachable. `ComposerSwitcherTestCase::getFixtureSourceDir()`
  sets the local precedent, and it leaves the constructor contract untouched. Of the two protected
  seams, opening `cloneInto()` is more faithful than extracting a stubbable `promotePartial()`. A
  stubbed rename returning `false` can encode states the real call never produces: on POSIX,
  renaming a directory onto an empty directory succeeds. With `cloneInto()` open, the test only
  arranges the filesystem race, and the real `rename()` decides the outcome. It is also a
  visibility change with no new method.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| INITIAL-state reconcile | Coded no-op, `MESSAGE_INITIAL_NOT_RECONCILABLE`, mode `MODE_INITIAL` | (a) Throw a dedicated, documented exception. (b) Implicitly run `switch_initProductionFiles()` and report in-sync. | (a) keeps `composer switch-reconcile` crashing in a fresh project. (b) writes files from a read-shaped command and blurs the INITIAL/PROD boundary `describe()` reports. The no-op matches `switchUpdate()`'s INITIAL contract. |
| PROD mode, `composer-prod.json` missing | Coded no-op, `MESSAGE_PROD_CONFIG_MISSING`, for the `null` and `RECONCILE_TO_MAIN` directions; an explicit `RECONCILE_TO_PROD` is honoured and recreates the baseline | (a) Force `RECONCILE_TO_PROD` for every direction (recreate the baseline from `composer.json` automatically). (b) Block every direction, explicit `RECONCILE_TO_PROD` included. (c) Leave it throwing. | (a) silently promotes possibly-unintended edits to the production baseline when nobody asked. (b) refuses the one request that repairs the state, although the CLI recovery builds the same baseline from the same file (design review, Decision 1). (c) leaves `switch-update` crashing. Blocking only the unconsented directions is the most restrictive option that still leaves a direct repair. |
| Blocker detection | One private `reconcile_detectBlocker(?string $direction) : ?SwitchMessage` | (a) Inline `if` branches per state in `reconcileCore()`. (b) A strategy or guard-object list. | With three states, one ordered method reads as one policy. Returning the existing `SwitchMessage` avoids a new type. (b) adds types for three rules with no named fourth. |
| Dry-run flag in the nested PROD→PROD reconcile | Public `reconcile()` owns flag, operation reset and `MESSAGE_DRY_RUN_ACTIVE`; `reconcileCore(?string $direction)` is flag-agnostic | (a) `switch_case_PROD_PROD()` passes `$this->fileSystem->isDryRun()` into the unchanged core. (b) Defer the fix to a later plan. | (a) stops the real copy but keeps the core clearing the caller's operations and duplicating the dry-run message, and every nested caller must repeat the workaround. (b) leaves `composer switch-preview-prod` writing to disk in a drifted PROD project, although this plan rewrites the same block and promises dry-run safety. |
| Native-warning handling | Scoped `set_error_handler()` helper in `FileSystem`; captured text in `KEY_NATIVE_ERROR`, captured error chained as `$previous` | (a) `@` + `error_clear_last()`/`error_get_last()`. (b) Pre-checks (`is_writable()` etc.) before each call. (c) Suppress in tests only. | (a) is a near-tie: with `error_clear_last()` it has no staleness problem, but the handler is more explicit, yields a structured error for chaining, and does not depend on `error_reporting` masking. (b) is racy and misses causes such as a full disk. (c) leaves consumers receiving `ErrorException` under Composer. |
| Runner strictness | `failOnWarning` + `failOnNotice` | Also `failOnDeprecation`; or no config change | Vendor deprecations on new PHP minors would fail the suite for reasons outside this repo. Leaving the config unchanged relies on someone noticing "issues". |
| Guard 2 scoping | `php -r` JSON decode with grep fallback | (a) A pure-bash/`sed` structural parse. (b) Require `jq`. (c) Full guard-registry reshape. | (a) cannot parse JSON reliably. (b) adds a dependency consumer machines often lack. (c) is speculative for two guards. PHP is near-universal where Composer runs, and the fallback keeps it fail-safe. |
| Concurrent-winner seam | `cloneInto()` made protected; the subclass arranges a non-empty winner and the real `rename()` fails | (a) A new protected `promotePartial()` wrapping the rename, stubbed to return `false`. (b) Constructor-injected `Closure $renamer`. | (a) lets a test encode rename results the OS never produces, such as losing to an empty directory (design review, Decision 8), and adds a method. (b) widens the constructor for a test-only concern. Opening `cloneInto()` matches the harness's protected-seam style and exercises the genuine failure path. |
| Changelog placement | Fold into the unreleased `v3.0.0` entry | New `v3.0.1` entry | No v3.0.0 tag exists, so a patch entry would describe fixes to behaviour no consumer ever received. |

## Pattern Alignment

- **Follows** the coded no-op pattern for unreconcilable states: the DEV branch of `reconcileCore()` (`src/ConfigSwitcher.php` L903–L911).
- **Follows** "an explicit direction is consent": once validated by `requireValidReconcileDirection()`, an explicit direction overrides mtime resolution in `reconcileCore()` (`src/ConfigSwitcher.php` L890–L941). The missing-prod blocker honours an explicit `RECONCILE_TO_PROD` on the same basis.
- **Follows** `try/finally` dry-run restoration: `switchTo()` (`src/ConfigSwitcher.php` L714–L718).
- **Follows** "the outermost entry point owns the dry-run flag": `switchTo()` sets it and `previewSwitch()` delegates to `switchTo()` rather than setting it again (`src/ConfigSwitcher.php` L674–L749). `reconcile()` becomes the owner on the reconcile path, and the shared `reconcileCore()` stops reassigning it.
- **Follows** `1822xx` message numbering (`AGENTS.md` §4, `src/ConfigSwitcher.php` L39–L52). The new codes are `182215` and `182216`.
- **Follows** structured exception context via `KEY_*` constants and `setContext()` (`src/ComposerSwitcherException.php` L25–L75).
- **Follows** the single I/O choke-point: all native calls stay inside `src/Utils/FileSystem.php`, keeping `test_noFilesystemCallsOutsideFacade()` green.
- **Follows** the protected test seam style of `tests/TestClasses/ComposerSwitcherTestCase.php` L47–L59.
- **Follows** the restricted-`PATH` technique of `tests/IntegrationSuites/TestGitHooks.php` L205–L240.
- **Follows** the characterisation-marker convention (insight `76d1cee5-…`): the marker test is deliberately flipped and renamed, and its docblock records why.
- **Deliberate departure:** `resources/git-hooks/pre-commit` gains a PHP subprocess where it was pure bash plus git and grep. This is justified by the need for real JSON parsing. The fallback preserves today's behaviour wherever PHP is absent.
- **Deliberate departure (PHP 8 policy):** touched code uses PHP 8 constructs and short `[]` syntax. `src/Utils/FileSystem.php` and `tests/TestClasses/LocalPackageClone.php` are small enough to modernise whole. In `src/ConfigSwitcher.php`, only the reconcile block (L855–L1030) is modernised, per the predecessor plan's per-method boundary.

## Structural Improvements

| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `src/ConfigSwitcher.php` `reconcileCore()` dry-run restore | Restored only on return paths through `finishReconcile()`, so a throw leaves the facade stuck in dry-run mode. | Promoted to step 3 | A correctness hole directly beside the INITIAL fix. `switchTo()` already sets the standard. |
| `src/ConfigSwitcher.php` `reconcileCore()` flag and operation ownership (L893–L900, called nested from `switch_case_PROD_PROD()` L846) | The core sets the flag to its own argument and clears operations even when nested inside `switchTo()`, so a PROD-mode `previewSwitch(MODE_PROD)` performs a real reconcile copy on drift (Plan Auditor, Major 1). | Promoted to step 3 | A dry-run guarantee broken in the very block step 3 rewrites. Moving ownership to `reconcile()` fixes it structurally, at no extra surface. |
| `src/ConfigSwitcher.php` dry-run `finally` idiom, repeated in `switchTo()`, `previewSwitch()` (L737–L749) and, after step 3, `reconcile()` | Three copies of save-flag / set / `finally` restore. A scoped `withDryRun(callable)` helper would own the idiom once. | Rejected | Consolidating means rewriting `switchTo()` and `previewSwitch()`, which this plan otherwise leaves untouched. That is outside the blast radius, and each copy is three lines. Worth reconsidering in any plan that touches those two methods. |
| `src/ConfigSwitcher.php` `reconcileCore()` inline DEV check | "Not reconcilable" is decided inline, and this plan adds two more states. | Promoted to step 3 | With three consumers, `reconcile_detectBlocker()` keeps the policy in one place. |
| `src/ConfigSwitcher.php` `reconcileCore()` `?? MODE_PROD` | Mislabels INITIAL as `prod` in the outcome. | Promoted to step 3 | The outcome must agree with `describe()` and `switchUpdate()`, which both report `MODE_INITIAL`. |
| `src/Utils/FileSystem.php` native calls | Four call sites each leak warnings independently and discard the native error text. | Promoted to step 1 | One capture helper owns the guarantee, and the native text is the most useful diagnostic. |
| `src/Utils/FileSystem.php` long-form `array()` (9×) | Legacy syntax in a file this plan rewrites. | Promoted to step 1 | PHP 8 modernisation policy: touched files are modernised in the same pass. |
| `src/ConfigSwitcher.php` reconcile block `array()` | Legacy syntax in methods this plan rewrites. | Promoted to step 3 | Same policy, limited to the methods touched. A whole-file sweep of the 1366-line orchestrator exceeds the blast radius. |
| `phpunit.xml` | No `failOn*` attributes, so warnings pass silently. | Promoted to step 2 | A structural guarantee against recurrence. |
| `src/Utils/ConfigFile.php` eager `LockFile` facade propagation | Propagation is procedural (one override). WP-002 called it structurally fragile. | Rejected (regression test added in step 5 instead) | A delegation reshape means rerouting every private `$fileSystem` access in `src/Utils/BaseFile.php` and leaves `LockFile::setFileSystem()` with no behaviour. That is a larger, more surprising API than one override plus a test that fails the moment propagation breaks. |
| `src/ConfigSwitcher.php` `verify()` in INITIAL | Reports every key as differing, because `composer-prod.json` does not exist yet. | Rejected (recorded in `Deferred Items`) | Changing it alters the public `VerificationResult` contract and the Tier 2 `switch-verify-config` output. The reconcile guard runs before `verify()`, so the fix does not depend on it. |
| `tests/assets/integration-project/composer.json` scripts | Wires only the five v2 scripts, so the v3 entry points are never exercised under a real Composer. | Promoted to step 7 | `composerSwitchReconcile()` is the consumer-facing surface of the defect this plan fixes. |
| `tests/IntegrationSuites/TestEntryPoints.php` class docblock | Says "all five namespaced commands". | Promoted to step 7 | Becomes false once the fixture wires ten. |
| `resources/git-hooks/pre-commit` Guard 2 | File-wide grep contradicts its own header and the README. | Promoted to step 8 | User-facing false positive, and a local fix. |
| `resources/git-hooks/pre-commit` guard-registry reshape (insight `2cd87f16-…`) | Two inline guards, no registry. | Rejected | A registry for two bash guards has no current consumer or named growth trajectory. The insight's cross-platform motivation targets Node hooks. |
| `tests/IntegrationSuites/TestGitHooks.php` `createPhpOnlyPathDirectory()` | Single-purpose helper, and step 8 needs a second restricted `PATH` (hook without `php`). | Promoted to step 8 | Generalising to `createRestrictedPathDirectory(string ...$binaries)` avoids a near-duplicate helper. |
| `tests/TestClasses/LocalPackageClone.php` inlined `@rename()` / private `cloneInto()` | Concurrent-winner branch unreachable. | Promoted to step 9 | Opening `cloneInto()` as a protected seam makes both branches testable through a real rename, with no change to the public contract and no new method. |
| `tests/TestClasses/LocalPackageClone.php` clone pinning | Clones upstream `HEAD`. | Rejected (recorded in `Deferred Items`) | Existing shared caches were cloned at `HEAD`, so pinning needs commit verification plus a migration path that cannot be atomic. That works against the atomic-acquisition guarantee, and no drift failure has been observed. |

## Detailed Steps

1. **`FileSystem`: keep native warnings inside the facade.**
   - 1.1. Add `public const KEY_NATIVE_ERROR = 'nativeError';` to `src/ComposerSwitcherException.php`, with a docblock stating that it holds the native PHP error message captured when a filesystem call failed.
   - 1.2. In `src/Utils/FileSystem.php`, add a private helper that runs a callable under a temporary `set_error_handler()`:
     - the handler records the error as an `\ErrorException` (message, severity, file, line) and returns `true`, so it does not propagate;
     - the previous handler is restored in `finally`;
     - the helper returns both the callable's result and the captured error (`?\ErrorException`).
   - 1.3. Route the real-mode `file_get_contents()` in `read()`, `file_put_contents()` in `write()` and `copy()`, and `unlink()` in `delete()` through the helper. When an error was captured, every exception those methods throw on failure adds `KEY_NATIVE_ERROR => $error->getMessage()` to its existing context, and passes `$error` as the constructor's `$previous`. `modifiedTime()` wraps `filemtime()` the same way, and still returns `null` on failure without throwing.
   - 1.4. Modernise `src/Utils/FileSystem.php`: replace every `array()` with `[]`. Update the class docblock to state the "no native warning escapes the facade" guarantee.
2. **Make the test runner strict.** In `phpunit.xml`, add `failOnWarning="true"` and `failOnNotice="true"` to the `<phpunit>` element. Run `composer test` and `composer test-integration`. Any warning or notice still surfacing is fixed at its source in whichever file emits it: `src/` through step 1's helper, the harness through `@` or an explicit guard. Test-level suppression is never the fix.
3. **`ConfigSwitcher`: reconcile blockers and dry-run safety.**
   - 3.1. Add `public const MESSAGE_INITIAL_NOT_RECONCILABLE = 182215;` and `public const MESSAGE_PROD_CONFIG_MISSING = 182216;` to `src/ConfigSwitcher.php`, after `MESSAGE_PROD_LOCK_MISSING`. Extend the `MODE_INITIAL` docblock (L32–L36) to say that `reconcile()` also reports it.
   - 3.2. Add a private `reconcile_detectBlocker(?string $direction) : ?SwitchMessage` that checks, in this order:
     - DEV mode → `MESSAGE_DEV_MODE_NOT_RECONCILABLE`, existing text unchanged, whatever `$direction`;
     - INITIAL, where the status file has no mode → `MESSAGE_INITIAL_NOT_RECONCILABLE`, whatever `$direction`, with text telling the user no switch has been run yet and to run `composer switch-prod` or `composer switch-dev` first;
     - PROD mode with `$this->prodFile->exists()` false and `$direction !== self::RECONCILE_TO_PROD` → `MESSAGE_PROD_CONFIG_MISSING`. The text names the missing `composer-prod.json` path and lists three recoveries: restore the file; call `reconcile(ConfigSwitcher::RECONCILE_TO_PROD)` to recreate it from `composer.json`; or, from the command line (where `composer switch-reconcile` passes no direction), delete the status file and run `composer switch-prod`.

     It returns `null` when reconciliation can proceed, including PROD mode with the prod config missing and `$direction === self::RECONCILE_TO_PROD`. That call then runs the existing `RECONCILE_TO_PROD` branch unchanged: `MESSAGE_BACKED_UP_MAIN_TO_PROD`, `mainFile->copyTo(prodFile)` and the lock `tryCopyTo()`, which create the missing files. `verify()` reports "not in sync" in this state rather than throwing, because it treats a missing prod file as empty, so the branch is reached. The docblock records why the missing-prod rule, alone of the three, depends on the direction.
   - 3.3. Split flag ownership between `reconcile()` and `reconcileCore()`:
     - (a) `reconcile(?string $direction = null, bool $dryRun = false)` clears messages, then validates the direction. An invalid direction still throws before any state change;
     - (b) `reconcile()` saves the dry-run flag, sets it to `$dryRun`, clears operations, and opens `try`;
     - (c) inside `try`, `reconcile()` adds `MESSAGE_DRY_RUN_ACTIVE` when dry-running, and returns `reconcileCore($direction)`;
     - (d) `reconcile()` restores the flag in `finally`;
     - (e) `reconcileCore(?string $direction) : SwitchOutcome` drops its `$dryRun` parameter. It never calls `setDryRun()` or `clearOperations()`, and never adds `MESSAGE_DRY_RUN_ACTIVE`; it runs under the caller's flag. It calls `reconcile_detectBlocker($direction)`. When that returns a message, the core records it and returns the outcome. The DEV and INITIAL blockers apply whatever the explicit `$direction`; the missing-prod blocker applies to `null` and `RECONCILE_TO_MAIN` only;
     - (f) otherwise the core runs the existing verify, resolve-direction and copy logic unchanged.

     `switch_case_PROD_PROD()` calls `reconcileCore(null)`, so the nested call inherits `switchTo()`'s flag: a `previewSwitch(MODE_PROD)` in a drifted PROD project plans the reconcile copy instead of performing it, and the copy appears in the preview's operation list. The outcome mode is `getStatus()->getMode() ?? self::MODE_INITIAL`, and the outcome's dry-run value is the facade's current `isDryRun()`. `finishReconcile()` becomes a pure outcome builder with no flag handling or `$previousDryRun` parameter, or is inlined; its docblock is updated to match.
   - 3.4. Update the docblocks of `reconcile()` (L850–L869), `reconcileCore()`, `switch_case_PROD_PROD()` and `composerSwitchReconcile()` (L246–L251) to list the INITIAL and missing-prod-config outcomes. The `reconcileCore()` docblock states that it is flag-agnostic and that the dry-run flag belongs to its caller (`reconcile()` or `switchTo()`); the `switch_case_PROD_PROD()` comment (L840–L845) states that the nested reconcile inherits the switch's dry-run flag. The `reconcile()` docblock also states that an explicit `RECONCILE_TO_PROD` with the prod config missing recreates it, and the `composerSwitchReconcile()` docblock states that the CLI entry point always uses the automatic direction, so it reports the missing-prod no-op. Modernise `array()` to `[]` in the reconcile block (L855–L1030).
4. **Tier 1 reconcile and INITIAL-state tests.** Add the `TestReconcile`, `TestSwitching` and `TestDryRun` tests enumerated in the Test Plan (reconcile guards, dry-run restore on throw, missing-prod PROD→PROD switch, preview of PROD in a drifted PROD state, `switchUpdate()` INITIAL outcome).
5. **Tier 1 coverage gaps from the synthesis.** Add the `TestStateValueObjects`, `TestFileSystem` (facade propagation) and `TestMessages` tests enumerated in the Test Plan.
6. **Tier 1 `FileSystem` failure tests.** Add the Composer-style error-handler tests and the native-error context assertions enumerated in the Test Plan, in `tests/TestSuites/TestFileSystem.php` and `tests/TestSuites/TestExceptionContext.php`. Unwritable-directory tests reuse the existing `chmod` + skip-when-root probe pattern from `test_copyFailureContext()` (L94–L128).
7. **Tier 2 entry-point parity.**
   - 7.1. Add `switch-describe`, `switch-describe-json`, `switch-reconcile`, `switch-preview-dev` and `switch-preview-prod` to `tests/assets/integration-project/composer.json` `scripts`, wired to the matching `ConfigSwitcher::composer*` entry points exactly as `README.md` L130–L145 documents.
   - 7.2. Add the `TestEntryPoints` tests enumerated in the Test Plan. Update the class docblock (L11) to describe all ten commands. It must also state that `switch-describe` and `switch-preview-prod` are wired but deliberately not driven. They share their code paths with `switch-describe-json` and `switch-preview-dev`, and neither is part of the defect surface this plan fixes.
8. **Guard 2 scoped to `repositories`.**
   - 8.1. In `resources/git-hooks/pre-commit`, replace Guard 2's grep with a check that pipes `git show :composer.json` into `php -r`. The PHP code:
     - decodes the JSON;
     - iterates `repositories` whether it is a list or a keyed object, and skips any entry that is not an array (e.g. `"packagist.org": false`) instead of reading a key from it;
     - treats a `repositories` value that is absent or not an array/object as "none found";
     - exits with three dedicated statuses, defined as named variables at the top of the guard: "path repository found", "none found", and "undecodable input". None of them may be `0`, `1`, `2`, `127` or `255`, so neither a PHP fatal error (`255`), a shell "command not found" (`127`), nor an accidental normal exit (`0`) can be mistaken for an answer. The recommended values are `10`, `11` and `12`.

     The hook blocks on "found" and passes on "none found". Every other outcome takes the existing file-wide `grep -qE '"type"\s*:\s*"path"'`: `command -v php` failing, "undecodable input", and any unexpected status. The blocking message, and the header comment (L3–L6), state the scoped behaviour and the fallback.
   - 8.2. In `tests/IntegrationSuites/TestGitHooks.php`, generalise `createPhpOnlyPathDirectory()` (L284) into `createRestrictedPathDirectory(string ...$binaries)`. It symlinks each named binary resolved from the current `PATH`. `test_skipsWhenGitUnavailable()` calls it with `'php'`.
   - 8.3. Rename `test_guard2MatchesTypePathOutsideRepositories()` to `test_guard2IgnoresTypePathOutsideRepositories()` and invert its assertion to exit code `0`. Rewrite its docblock to record that the characterisation marker fired as intended, and why. Add the remaining Guard 2 tests enumerated in the Test Plan.
9. **`LocalPackageClone` clone seam.**
   - 9.1. In `tests/TestClasses/LocalPackageClone.php`, change `cloneInto(string $targetDir) : bool` (L184) from `private` to `protected`. Its docblock names it a test seam: an override may call `parent::cloneInto()` and then arrange a concurrent winner at `getCacheDirectory()`, because `cloneInto()` runs after the stale-partial purge and the `is_dir($cacheDir)` short-circuit, and before the `@rename()`. The `@rename($partialDir, $cacheDir)` call (L139) stays inline and unchanged. Modernise the file's remaining `array()` to `[]`.
   - 9.2. Add the two concurrent-winner tests enumerated in the Test Plan to `tests/IntegrationSuites/TestLocalPackageClone.php`. Each uses an anonymous subclass whose `cloneInto()` calls the parent against a throwaway local repository (`createLocalGitRepository()`), then creates a **non-empty** directory at the cache path, and returns the parent's result. The real `rename()` then fails because its target is a non-empty directory. Each test first asserts, or skips with a named reason, that a `rename()` of a directory onto a non-empty directory fails on the host, so a platform that behaves differently skips rather than passing vacuously.
10. **Documentation sweep.** Apply every entry in `Documentation Updates`.
11. **Final verification gate.**
    - Run `composer test`, `composer test-integration` and `composer analyze` from a clean state.
    - All three must pass with zero PHPStan errors, and the PHPUnit summary must read `OK` with no "there were issues" line.
    - Tier 2 must report no skips other than capability skips with a named reason. Only a skip for missing `git`, `composer` or network access counts against the gate, and those three are assumed available. The tolerated capability skips are the ones the new tests define: the concurrent-winner tests' `rename()`-onto-non-empty-directory probe, the restricted-`PATH` Guard 2 tests when `bash`, `git` or `grep` cannot be resolved, and the existing permission probes when running as root. Each tolerated skip is listed, with its reason, in the gate WP's pipeline summary.
    - Confirm by `grep` that no file under `src/`, `tests/` or `docs/agents/project-manifest/` still describes the INITIAL-state reconcile throw as current behaviour.

## Dependencies

- Step 2 depends on step 1. Enabling `failOnWarning` first would fail the three known tests before the fix lands.
- Step 3 must land before steps 4 and 7.2's reconcile tests.
- Step 6 depends on step 1.
- Step 5 is independent of steps 1–3, but runs under step 2's strict runner.
- Step 8.3 depends on steps 8.1 and 8.2.
- Step 9.2 depends on step 9.1.
- Step 10 depends on steps 1, 3, 7, 8 and 9, because it documents their final shape.
- Step 11 depends on every other step.

## Required Components

- `src/ComposerSwitcherException.php`: modified (new `KEY_NATIVE_ERROR`).
- `src/Utils/FileSystem.php`: modified (native-error capture helper, modernisation).
- `src/ConfigSwitcher.php`: modified (two new `MESSAGE_*` constants, new private `reconcile_detectBlocker()`, restructured `reconcile()`/`reconcileCore()`/`finishReconcile()` with dry-run flag ownership moved to `reconcile()`, docblocks).
- `phpunit.xml`: modified.
- `resources/git-hooks/pre-commit`: modified (Guard 2).
- `tests/TestClasses/LocalPackageClone.php`: modified (`cloneInto()` widened from `private` to `protected` as a test seam, modernisation).
- `tests/assets/integration-project/composer.json`: modified (five new scripts).
- `tests/TestSuites/TestReconcile.php`, `tests/TestSuites/TestSwitching.php`, `tests/TestSuites/TestDryRun.php`, `tests/TestSuites/TestStateValueObjects.php`, `tests/TestSuites/TestFileSystem.php`, `tests/TestSuites/TestExceptionContext.php`, `tests/TestSuites/TestMessages.php`: modified (new tests).
- `tests/IntegrationSuites/TestEntryPoints.php`, `tests/IntegrationSuites/TestGitHooks.php`, `tests/IntegrationSuites/TestLocalPackageClone.php`: modified (new tests, helper generalisation).
- `README.md`, `changelog.md`, `docs/agents/project-manifest/api-surface.md`, `data-flows.md`, `constraints.md`, `file-tree.md`, `switching-decision-table.md`: modified.
- No new files and no new dependencies.

## Assumptions

- The v3.0.0 work in the working tree is still untagged and unreleased when the run starts (`git tag` shows nothing after `1.0.4`). The changelog step folds into the existing `v3.0.0` entry on that basis.
- `php` is on `PATH` in the environment running Tier 2, as it must be for Composer itself. The restricted-`PATH` Guard 2 test deliberately removes it for one subprocess only.
- Composer's `ErrorHandler` behaviour is as verified upstream on 2026-09-30: it throws `\ErrorException` for non-suppressed warnings and returns early for suppressed levels.

## Constraints

- PHP `>=8.4`, `declare(strict_types=1)`, PHPStan level per `phpstan.neon` over `src/` and `tests/` (`AGENTS.md` §5).
- Message codes stay in the switcher `1822xx` range, and exception codes in `182101`–`1821xx` (`AGENTS.md` §4). `KEY_NATIVE_ERROR` is a context key, not a code.
- No native filesystem call may appear in `src/` outside `src/Utils/FileSystem.php` and `ConfigSwitcher::installGitHooks()` (`test_noFilesystemCallsOutsideFacade()`).
- No step places work, tests or scaffolding in `../hcp-editor/` or `../mailforge/`.
- **Pipeline configuration (synthesis process note, insight `5ceb852c-…`):** every work package whose deliverables include authored content (code, tests, docs, changelog, manifest) keeps an active authoring stage, `implementation` or `documentation`. The documentation-sweep WP (step 10) and the final-gate WP (step 11) in particular must not be verification-only, and the final gate needs `implementation` active so a QA FAIL routes to an agent that can author fixes. The TPM verifies each WP's `active_pipeline_stages` immediately after creation (insight `cdaf1471-…`).
- The Guard 2 WP (step 8) includes a `security-audit` stage: the hook runs PHP over staged repository content, and failing open would let local path repositories be committed.

## Out of Scope

- Changing `verify()`'s INITIAL-state output or `VerificationResult`'s comparability semantics (see `Deferred Items`).
- A guard-registry reshape of `resources/git-hooks/pre-commit`, or Windows-native hook support.
- Pinning `LocalPackageClone`'s upstream clone (see `Deferred Items`).
- Routing `installGitHooks()` through `FileSystem`, which remains the documented exception.
- A wholesale PHP 8 modernisation of `src/ConfigSwitcher.php` beyond the reconcile block.
- Any change to `../hcp-editor/` or `../mailforge/`.
- `failOnDeprecation` in `phpunit.xml`.

## Human Actions

| # | Action | When | Why an agent cannot do it |
|---|--------|------|---------------------------|
| 1 | Review the updated `v3.0.0` changelog entry, then commit and tag/publish `v3.0.0` (git tag + Packagist). This covers the predecessor plan's still-pending release action, which now includes this rework. | After the run | Git write operations and Packagist publishing belong to the maintainer, and the agents are barred from both. |
| 2 | Adopt the release in `../hcp-editor/` and `../mailforge/` by raising their version constraints to `^3.0`, or deliberately pinning them below it. | After the run | Those repositories are outside this plan, and choosing a consumer's major version is the maintainer's decision. |

## Acceptance Criteria

- AC-01: `reconcile()` called in the INITIAL state (no status file), with `$direction` `null`, `RECONCILE_TO_MAIN` or `RECONCILE_TO_PROD`:
  - does not throw;
  - returns a `SwitchOutcome` with mode `ConfigSwitcher::MODE_INITIAL` and no operations;
  - carries `MESSAGE_INITIAL_NOT_RECONCILABLE` (182215);
  - leaves `composer-prod.json`, its lock, and the status file uncreated.
- AC-02: In PROD mode with `composer-prod.json` deleted:
  - `reconcile()`, `reconcile(RECONCILE_TO_MAIN)`, `switchToProduction()` and `switchUpdate()` do not throw, record `MESSAGE_PROD_CONFIG_MISSING` (182216), and leave `composer.json` byte-identical and `composer-prod.json` uncreated;
  - `reconcile(RECONCILE_TO_PROD)` does not throw, does not record 182216, and records `MESSAGE_BACKED_UP_MAIN_TO_PROD`. It recreates `composer-prod.json` byte-identical to `composer.json` (and its lock when the main lock exists), and leaves `composer.json` byte-identical;
  - `reconcile(RECONCILE_TO_PROD, true)` in the same state reports the copy as a planned operation and creates no file on disk.
- AC-03: When `reconcile(null, true)` throws mid-call (e.g. a malformed `composer.json`), the shared `FileSystem` facade's `isDryRun()` is `false` afterwards, and a subsequent real reconcile writes to disk.
- AC-04: An invalid reconcile direction still throws `ERROR_INVALID_RECONCILE_DIRECTION` in every state, INITIAL included, with its existing context.
- AC-05: `composer switch-reconcile` in a fresh (INITIAL) Tier 2 work copy exits `0` and prints the `MESSAGE_INITIAL_NOT_RECONCILABLE` text.
- AC-06: No real-mode `FileSystem` failure (`read`, `write`, `copy`, `delete`) emits a native PHP warning. With a throwing, Composer-style error handler installed, each failure surfaces as a `ComposerSwitcherException` with its documented code, never as an `\ErrorException`.
- AC-07: Every exception thrown from a failed native filesystem call carries the captured native message under `ComposerSwitcherException::KEY_NATIVE_ERROR`, and chains the captured error as an `\ErrorException` via `getPrevious()`, with the same message.
- AC-08: `phpunit.xml` sets `failOnWarning="true"` and `failOnNotice="true"`, and `composer test` reports a plain `OK` with no "there were issues" line.
- AC-09: New Tier 1 tests cover:
  - `SwitchMessage::hasCode()` for a negative code;
  - `SwitchOutcome::toArray()` with no messages and no operations;
  - `SwitchDescription::toArray()`/`toJSON()` round-tripping with empty files, repositories and warnings.
- AC-10: A Tier 1 test asserts that `ConfigFile::getLockFile()->getFileSystem()` is the same instance as the `FileSystem` passed to `ConfigFile::setFileSystem()`. The same holds for all three config files' lock files after `ConfigSwitcher` construction.
- AC-11: Tier 1 tests assert that `setDisplayMessages(false)` suppresses all automatic output, and that `displayMessages()` with an empty message log prints nothing.
- AC-12: A Tier 1 test asserts that `switchUpdate()` in the INITIAL state returns mode `MODE_INITIAL`, no operations, no messages and `isDryRun()` `false`, and creates no files.
- AC-13: `tests/assets/integration-project/composer.json` wires all ten `switch-*` scripts. Tier 2 tests drive `switch-reconcile` (INITIAL and in-sync PROD), `switch-describe-json` (valid JSON, mode `initial`) and `switch-preview-dev` (no file changed on disk).
- AC-14: Guard 2:
  - passes a staged `composer.json` whose only `"type": "path"` sits outside `repositories`;
  - blocks one with a path repository in list form or keyed-object form;
  - evaluates a `repositories` object containing a non-array entry (e.g. `"packagist.org": false`) without a PHP error: it passes when no other entry is a path repository, and blocks when one is;
  - uses dedicated PHP exit codes outside `0`, `1`, `2`, `127` and `255`, and passes only on the "none found" code;
  - blocks via the grep fallback when the staged file is not valid JSON;
  - blocks via the grep fallback when `php` is not on `PATH`.
- AC-15: Both branches of `LocalPackageClone`'s lost-rename path are tested through a real, failing `rename()` against a non-empty winner directory, with no stubbed rename result. Adopting a valid winner returns the cache path with `REASON_NONE`. An invalid winner (non-empty, no `composer.json`) returns `null` with `REASON_CLONE_FAILED`. Neither leaves a `.partial-*` sibling.
- AC-16: `test_noFilesystemCallsOutsideFacade()` still passes.
- AC-17: Every documentation artefact in `Documentation Updates` is updated. The decision table no longer describes any throw in the INITIAL "Reconcile" row, and it gains a PROD-state "Reconcile — prod config missing" row.
- AC-18: The `changelog.md` `v3.0.0` entry lists this rework's changes, and `docs/agents/project-manifest/README.md` still reads `Version: 3.0.0`.
- AC-19: `composer test`, `composer test-integration` and `composer analyze` all pass, with zero PHPStan errors. When `git`, `composer` and network are available, Tier 2 reports no skips except named-reason capability skips on hosts lacking the capability: the `rename()`-onto-non-empty-directory probe, unresolvable `bash`/`git`/`grep` in the restricted-`PATH` tests, and the root permission probes. A skip for missing `git`, `composer` or network fails the gate.
- AC-20: In PROD mode with `composer.json` drifted from `composer-prod.json` (content differs, `composer.json` newer), `previewSwitch(MODE_PROD)`:
  - leaves every file on disk byte-identical, with unchanged modification times;
  - returns a dry-run `SwitchOutcome` whose operations include the planned `composer.json` → `composer-prod.json` copy, with `MESSAGE_BACKED_UP_MAIN_TO_PROD` and exactly one `MESSAGE_DRY_RUN_ACTIVE`;
  - leaves `getFileSystem()->isDryRun()` `false` afterwards.

  A real `switchToProduction()` from an identically-seeded work copy performs the same copy on disk.

## Testing Strategy

- **Tier 1** proves the behaviour of each changed unit in isolation, against per-test work copies:
  reconcile blockers, dry-run restoration, `FileSystem` warning capture, and the coverage gaps. For
  AC-06, the `FileSystem` warning tests install their own throwing error handler, modelled on
  Composer's `ErrorHandler`, so consumer-visible behaviour is proven offline.
- **Tier 2** proves the consumer-facing surface through real binaries: the v3 Composer entry
  points under Composer's own `ErrorHandler`, the installed pre-commit hook against real `git`
  state, and `LocalPackageClone` against a throwaway local repository.
- **The strict runner** (step 2) turns any residual warning or notice into a failure, so the "no
  warnings" criteria are enforced for every test, not only the new ones.
- **PHPStan** covers `src/` and `tests/`. The final gate (step 11) runs all three commands from a
  clean state.

## Test Plan

- `tests/TestSuites/TestReconcile.php::test_initialStateIsNotReconcilable` — fresh switcher, `reconcile()` → no throw, mode `MODE_INITIAL`, no operations, code 182215, prod config, prod lock and status file absent — AC-01
- `tests/TestSuites/TestReconcile.php::test_initialStateIgnoresExplicitDirection` — `reconcile(RECONCILE_TO_MAIN)` and `reconcile(RECONCILE_TO_PROD)` in INITIAL → same no-op outcome, no files created — AC-01
- `tests/TestSuites/TestReconcile.php::test_initialStateInvalidDirectionStillThrows` — `reconcile('sideways')` in INITIAL → `ERROR_INVALID_RECONCILE_DIRECTION` with `KEY_DIRECTION` context — AC-04
- `tests/TestSuites/TestReconcile.php::test_missingProdConfigIsNotReconcilable` — `switchToProduction()`, delete `composer-prod.json`, then `reconcile()` and `reconcile(RECONCILE_TO_MAIN)` → no throw, code 182216, no operations, `composer.json` bytes unchanged, `composer-prod.json` still absent — AC-02
- `tests/TestSuites/TestReconcile.php::test_missingProdConfigExplicitToProdRecreatesBaseline` — `switchToProduction()`, delete `composer-prod.json` (and its lock), then `reconcile(RECONCILE_TO_PROD)` → no throw, no 182216, `MESSAGE_BACKED_UP_MAIN_TO_PROD` present, `composer-prod.json` exists and is byte-identical to `composer.json`, the prod lock is recreated from the main lock, and `composer.json` bytes are unchanged; a following `reconcile()` reports `MESSAGE_ALREADY_IN_SYNC` — AC-02
- `tests/TestSuites/TestReconcile.php::test_missingProdConfigExplicitToProdDryRunWritesNothing` — same state, `reconcile(RECONCILE_TO_PROD, true)` → the outcome is dry-run with a copy operation targeting `composer-prod.json`, the file is still absent on disk, and `getFileSystem()->isDryRun()` is `false` afterwards — AC-02, AC-03
- `tests/TestSuites/TestReconcile.php::test_prodToProdSwitchWithMissingProdConfig` — PROD, delete `composer-prod.json`, then `switchToProduction()` and `switchUpdate()` → no throw, messages contain 182216, `composer.json` bytes unchanged — AC-02
- `tests/TestSuites/TestReconcile.php::test_dryRunFlagRestoredWhenReconcileThrows` — PROD, overwrite `composer.json` with invalid JSON, `reconcile(null, true)` → `ERROR_CANNOT_DECODE_JSON`, then `getFileSystem()->isDryRun()` is `false`; restore valid JSON, make main newer, `reconcile()` → an applied operation on disk — AC-03
- `tests/TestSuites/TestDryRun.php::test_previewProdInDriftedProdStateLeavesDiskUntouched` — independent work copy, `switchToProduction()`, then modify `composer.json` content and `touch()` it newer than `composer-prod.json`; snapshot with the existing `snapshotDirectory()` helper, `previewSwitch(MODE_PROD)` → identical snapshot afterwards, outcome `isDryRun()` true, operations contain a copy targeting `composer-prod.json`, messages contain `MESSAGE_BACKED_UP_MAIN_TO_PROD` and exactly one `MESSAGE_DRY_RUN_ACTIVE`, and `getFileSystem()->isDryRun()` is `false` afterwards — AC-20
- `tests/TestSuites/TestDryRun.php::test_previewProdMatchesRealSwitchInDriftedProdState` — two identically-seeded, identically-drifted PROD work copies: the preview's operation list (type/source/target, in order) equals the real `switchToProduction()`'s, and the real switch leaves `composer-prod.json` byte-identical to the drifted `composer.json` — AC-20
- `tests/TestSuites/TestSwitching.php::test_switchUpdateInInitialStateReturnsInitialOutcome` — fresh switcher, `switchUpdate()` → mode `MODE_INITIAL`, `hasOperations()` false, `getMessages()` empty, `isDryRun()` false, status/prod/flag files absent — AC-12
- `tests/TestSuites/TestStateValueObjects.php::test_switchMessage_negativeCodeHasNoCode` — `new SwitchMessage(-1, 'x')` → `hasCode()` false, `getCode()` −1, `toArray()` `['code' => -1, 'text' => 'x']` — AC-09
- `tests/TestSuites/TestStateValueObjects.php::test_switchOutcome_emptyCollectionsToArray` — outcome with no messages and no operations → `toArray()` has empty `messages` and `operations` arrays and the correct mode/dry-run values — AC-09
- `tests/TestSuites/TestStateValueObjects.php::test_switchDescription_emptyCollectionsRoundTrip` — description with empty files, repositories and warnings → `json_decode(toJSON(), true)` equals `toArray()`, with empty arrays preserved as arrays — AC-09
- `tests/TestSuites/TestFileSystem.php::test_configFileLockFileSharesFacade` — `ConfigFile::setFileSystem($fs)` → `getLockFile()->getFileSystem()` is identical to `$fs` — AC-10
- `tests/TestSuites/TestFileSystem.php::test_switcherPropagatesFacadeToAllLockFiles` — after `ConfigSwitcher` construction, the main, prod and dev lock files' facades are identical to `getFileSystem()` — AC-10
- `tests/TestSuites/TestMessages.php::test_setDisplayMessagesFalseSuppressesOutput` — `setDisplayMessages(false)`, run a DEV→PROD switch under output buffering → captured output is `''`, and `getMessages()` is still non-empty — AC-11
- `tests/TestSuites/TestMessages.php::test_displayMessagesWithEmptyLogPrintsNothing` — fresh switcher, `displayMessages()` under output buffering → `''` — AC-11
- `tests/TestSuites/TestFileSystem.php::test_realMode_failuresRaiseNoNativeWarning` — install a handler that throws `\ErrorException` on any error (Composer-style), then exercise `read()` of a missing file, plus `write()`, `copy()` into and `delete()` inside an unwritable directory (the latter three skipped when running as root) → each throws `ComposerSwitcherException` with the documented code, never `\ErrorException`; restore the handler in `finally` — AC-06
- `tests/TestSuites/TestFileSystem.php::test_realMode_readMissingFileThrows` (modified) — also asserts that `getContextValue(KEY_NATIVE_ERROR)` is a non-empty string, and that `getPrevious()` is an `\ErrorException` whose message equals it — AC-07
- `tests/TestSuites/TestFileSystem.php::test_realMode_errorHandlerRestoredAfterFailure` — install a sentinel handler, trigger a failing `read()`, then `set_error_handler(null)` returns the sentinel, proving the previous handler was restored — AC-06
- `tests/TestSuites/TestExceptionContext.php::test_copyFailureContext` (modified) — also asserts that a `KEY_NATIVE_ERROR` string is present — AC-07
- `tests/TestSuites/TestExceptionContext.php::test_readFailureCarriesErrorCode` (modified) — also asserts `KEY_NATIVE_ERROR` — AC-07
- `tests/TestSuites/TestExceptionContext.php::test_writeAndDeleteFailureContext` — unwritable directory (skipped as root) → `ERROR_CANNOT_WRITE_FILE` and `ERROR_CANNOT_DELETE_FILE`, each with `KEY_FILE_PATH`, `KEY_NATIVE_ERROR` and an `\ErrorException` as `getPrevious()` — AC-07
- `tests/TestSuites/TestDryRun.php::test_noFilesystemCallsOutsideFacade` (unchanged) — still green after steps 1 and 3 — AC-16
- Full `composer test` run under the step 2 `phpunit.xml` — the summary is a plain `OK` — AC-08
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchReconcileIsNoOpInInitialState` — fresh work copy, `composer switch-reconcile` → exit `0`, output contains the 182215 message text, no `composer-prod.json` created — AC-05, AC-13
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchReconcileReportsInSyncInProdState` — `switch-prod`, then `switch-reconcile` → exit `0`, output contains the already-in-sync text — AC-13
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchDescribeJsonReportsInitialMode` — fresh copy, `composer switch-describe-json` → exit `0`, stdout decodes as JSON with `mode` = `initial` — AC-13
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchPreviewDevLeavesDiskUntouched` — fresh copy, snapshot the file list and hashes, `composer switch-preview-dev` → exit `0`, identical snapshot afterwards — AC-13
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2IgnoresTypePathOutsideRepositories` (renamed from `test_guard2MatchesTypePathOutsideRepositories`, assertion inverted) — `"type": "path"` under `extra` only → exit `0` — AC-14
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2BlocksPathRepository` (unchanged) — list-form path repository → blocked — AC-14
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2BlocksPathRepositoryInObjectForm` — `repositories` as a keyed object containing a `path` entry → blocked — AC-14
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2SkipsNonArrayRepositoryEntries` — `repositories` as a keyed object with `"packagist.org": false` and a VCS entry → exit `0` and no PHP error text in the hook output; the same object plus a `path` entry → blocked — AC-14
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2FallsBackWhenPhpCheckCrashes` — `PATH` from `createRestrictedPathDirectory('bash', 'git', 'grep')` plus a stub `php` script, with `"type": "path"` under `extra` only. Run once with a stub exiting `255` (fatal) and once with a stub exiting `0` → blocked both times by the file-wide fallback, proving only the dedicated "none found" code passes. Skipped with a named reason when a required binary cannot be resolved — AC-14
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2FallsBackOnInvalidJson` — staged `composer.json` that is not valid JSON but contains `"type": "path"` → blocked — AC-14
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2FallsBackWhenPhpUnavailable` — run the hook with `PATH` from `createRestrictedPathDirectory('bash', 'git', 'grep')`, with `"type": "path"` under `extra` only → blocked by the file-wide fallback. Skipped with a named reason when a required binary cannot be resolved — AC-14
- `tests/IntegrationSuites/TestGitHooks.php::test_skipsWhenGitUnavailable` (modified to use `createRestrictedPathDirectory('php')`) — behaviour unchanged — AC-14
- `tests/IntegrationSuites/TestLocalPackageClone.php::test_lostRenameAdoptsValidWinner` — subclass whose `cloneInto()` calls the parent against a throwaway local repository, then creates the cache directory containing a `composer.json` → the real `rename()` fails, and the result is the cache path with `REASON_NONE`, the winner's `composer.json` untouched, and no `.partial-*` siblings — AC-15
- `tests/IntegrationSuites/TestLocalPackageClone.php::test_lostRenameWithInvalidWinnerFails` — subclass whose `cloneInto()` calls the parent, then creates a **non-empty** cache directory holding a stray file and no `composer.json` → the real `rename()` fails, and the result is `null` with `REASON_CLONE_FAILED` and no `.partial-*` siblings. Both tests first probe that renaming a directory onto a non-empty directory fails on the host, and skip with a named reason otherwise — AC-15
- Documentation review during QA of step 10 — each `Documentation Updates` entry is present, the decision table's INITIAL "Reconcile" row describes the no-op, and `grep -rn "ERROR_CANNOT_GET_MODIFIED_DATE" docs/agents/project-manifest/switching-decision-table.md` returns no INITIAL-row match — AC-17, AC-18
- Final gate: `composer test`, `composer test-integration`, `composer analyze` — all green, zero PHPStan errors, and no Tier 2 skips other than the named-reason capability skips AC-19 tolerates, each listed with its reason — AC-19

## Documentation Updates

- `docs/agents/project-manifest/switching-decision-table.md`:
  - rewrite the INITIAL "Reconcile" row (L21) to the coded no-op (mode `initial`, `MESSAGE_INITIAL_NOT_RECONCILABLE`, no file effects, any direction);
  - add a PROD-state row, "Reconcile — prod config missing", with `MESSAGE_PROD_CONFIG_MISSING` for the automatic direction and `RECONCILE_TO_MAIN`, and no file effects;
  - add a PROD-state row, "Reconcile to prod — prod config missing", for an explicit `reconcile(RECONCILE_TO_PROD)`: `MESSAGE_BACKED_UP_MAIN_TO_PROD`, with `composer-prod.json` (and its lock, when the main lock exists) recreated from `composer.json`. It notes that `composer switch-reconcile` cannot reach this row, because the CLI always uses the automatic direction;
  - note under "Switch to PROD (reconcile in place)" that it inherits both no-ops;
  - add a cross-cutting note that `reconcile()` restores the dry-run flag even on exceptions;
  - in the "Switch to PROD (reconcile in place)" row (L41), change the PHP call chain from `reconcileCore(null, false)` to `reconcileCore(null)`, and note that a preview (`previewSwitch(MODE_PROD)` / `composer switch-preview-prod`) plans the reconcile copy without performing it.
- `docs/agents/project-manifest/api-surface.md`:
  - add `MESSAGE_INITIAL_NOT_RECONCILABLE` and `MESSAGE_PROD_CONFIG_MISSING` to the constants list, and to the emitter note (L35);
  - update the `reconcile()` behaviour paragraph (L102) with both new outcomes, the explicit-`RECONCILE_TO_PROD` exception to the missing-prod no-op, and the `finally` restoration;
  - update the `previewSwitch()` behaviour paragraph (L100) to state that a PROD-mode preview of `MODE_PROD` covers the reconcile step too, and writes nothing;
  - add `KEY_NATIVE_ERROR` to the `ComposerSwitcherException` keys (L133–L139), and state that filesystem failures also chain the native error as an `\ErrorException` via `getPrevious()`;
  - state the `FileSystem` "no native warning escapes" guarantee.
- `docs/agents/project-manifest/data-flows.md`: add the blocker check (DEV → INITIAL → prod-missing, the last skipped for an explicit `RECONCILE_TO_PROD`) to §4 "PROD-to-PROD Reconciliation" (L69–L85), and to the `reconcile()` flow. State in §4 that the dry-run flag belongs to the entry point (`reconcile()` or `switchTo()`), and that the nested PROD→PROD reconcile inherits it.
- `docs/agents/project-manifest/constraints.md`:
  - extend the `FileSystem` choke-point rule (L18) with the native-warning capture guarantee and `KEY_NATIVE_ERROR`;
  - reword the `getOperations()` rule (L20), which says `clearOperations()` runs at the start of every `switchTo()`/`reconcileCore()` call. After step 3.3 that is false. It should say that `reconcile()` and `switchTo()` clear operations at their start, and that `reconcileCore()` never clears them and inherits the caller's list, so each returned outcome still reflects only that one entry-point call;
  - add the testing rule that `phpunit.xml` fails on warnings and notices, and that warnings are fixed at their source, never suppressed in tests;
  - record the two new `1822xx` message codes;
  - describe Guard 2's scoped check and grep fallback next to the pre-commit hook entry (L86).
- `docs/agents/project-manifest/file-tree.md`:
  - update the annotations for `TestReconcile.php`, `TestDryRun.php`, `TestFileSystem.php`, `TestExceptionContext.php`, `TestMessages.php`, `TestStateValueObjects.php`, `TestSwitching.php`, `TestEntryPoints.php`, `TestGitHooks.php` (L60) and `TestLocalPackageClone.php`;
  - update the `pre-commit` annotation (L68) to "DEV-mode guard and repositories-scoped path-repo guard".
- `README.md`:
  - reconcile note (L90) and "Reconciling PROD configuration drift" (L306–L340): document the INITIAL and missing-prod no-ops alongside the DEV no-op (L336). Give both missing-prod recoveries: `reconcile(ConfigSwitcher::RECONCILE_TO_PROD)` from PHP, and deleting the status file then running `composer switch-prod` from the command line;
  - "Handling exceptions" (L515–L538): add `nativeError` to the listed context keys, mention the chained `getPrevious()` native error, and state that filesystem failures surface as typed exceptions even inside Composer scripts;
  - pre-commit hook section (L606–L610): state that Guard 2 inspects the `repositories` key when PHP is available, and falls back to a whole-file match otherwise, or whenever the PHP check does not return a clean answer.
- `changelog.md`: extend the `v3.0.0` entry (L3–L25) under **Added**/**Non-breaking** with:
  - the reconcile INITIAL and missing-prod no-ops and their two message codes, and the explicit `RECONCILE_TO_PROD` recovery for a missing prod config;
  - dry-run restoration on reconcile exceptions;
  - `previewSwitch(MODE_PROD)` / `composer switch-preview-prod` in a drifted PROD project no longer performs the reconcile copy for real;
  - `FileSystem` native-warning capture and `KEY_NATIVE_ERROR`;
  - Guard 2 scoping and its fallback;
  - the strict PHPUnit configuration and the new Tier 1/Tier 2 coverage.

  Do not add a new version heading.
- `docs/agents/project-manifest/README.md`: version stays `3.0.0`. No other change is required unless a section index entry changes.
- `AGENTS.md` (repository): no change. None of its maintenance-rule triggers (new file, directory restructure, dependency, PHP version) apply.

## Deferred Items

| # | Deferred Item | Origin | Reason Deferred | Notes |
|---|---------------|--------|-----------------|-------|
| 1 | Pin `LocalPackageClone`'s upstream clone to a fixed commit | `2026-09-29-self-sufficient-test-surface-rework-1` synthesis (WP-007), carried through the `2026-09-30-switcher-ergonomics` plan's `Out of Scope` (archived at `docs/agents/implementation-history/2026-09-30-switcher-ergonomics/plan.md`) | Existing shared caches were cloned at `HEAD`, so pinning needs commit verification of a present cache and a migration path for caches at the wrong commit. That migration cannot be atomic, which works against the acquisition guarantee rework-1 established (insight `dda3ef6c-…`). The upstream repository is maintainer-controlled, and no drift failure has been observed. | Reconsider if upstream `mistralys/simple_html_dom` changes break Tier 2. A viable shape is a shallow fetch of the pinned commit onto a local `master` branch, so Composer still infers `dev-master` for `TestVersionOverride`, with the commit hash embedded in the cache directory name so migration becomes a fresh atomic acquisition. |
| 2 | Make `verify()` report "not comparable" in the INITIAL state instead of listing every key as differing | This plan's research (brief, "Reconciliation guards" structural observations) | It changes the public `VerificationResult` contract (`isComparable()` would need a reason, not just DEV mode) and the Tier 2 `switch-verify-config` output. This plan's reconcile guard does not depend on it. | Pair with any future plan that touches `VerificationResult` or `composerVerifyConfig()`. It is a breaking change, so it belongs in a major version. |

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **`failOnWarning` surfaces warnings in Tier 2 or harness code beyond the three known ones, and stalls step 2.** | Step 2 includes fixing each at its source. Every Tier 2 suite already passes, and harness failure paths already use `@` (e.g. `LocalPackageClone.php` L139). |
| **The scoped error handler swallows a warning from a call that nonetheless succeeds, hiding a real problem.** | The helper is used only around the four native calls whose return value is already checked. Failures still throw, with the captured text attached, and a success-with-warning has no consumer-visible failure to report. |
| **The Guard 2 PHP check fails open (e.g. PHP present but erroring), so a local path repository gets committed.** | The PHP check answers with dedicated exit codes that avoid `0`, `1`, `2`, `127` and `255`, and only the "none found" code passes. Every other status, including a PHP fatal error, takes the fail-safe grep fallback. Non-array `repositories` entries are skipped, so valid Composer files cannot crash the check. The Guard 2 WP carries a `security-audit` stage. `test_guard2FallsBackOnInvalidJson`, `test_guard2FallsBackWhenPhpUnavailable`, `test_guard2FallsBackWhenPhpCheckCrashes` and `test_guard2SkipsNonArrayRepositoryEntries` pin the behaviour. |
| **The restricted-`PATH` hook test cannot resolve `bash`, `git` or `grep` on some machines.** | The test skips with a reason naming the missing binary, mirroring `test_skipsWhenGitUnavailable()`, and never fails spuriously. AC-19 and the step 11 gate tolerate this named-reason skip explicitly, so it neither fails the gate nor needs an ad-hoc waiver. |
| **Moving flag ownership out of `reconcileCore()` changes the real (non-preview) PROD→PROD switch.** | It does not: `switchTo($mode, false)` already sets the flag to `false` before dispatching, so the nested core runs under the same value it used to force. The existing `TestReconcile` and `TestSwitching` PROD→PROD tests stay unchanged and must stay green, and `test_previewProdMatchesRealSwitchInDriftedProdState` pins the real switch's copy. |
| **The new missing-prod no-op masks a genuinely corrupted project.** | The message names the missing path and the recovery actions, and `describe()` still reports the file as absent, so the state stays observable. |
| **An explicit `RECONCILE_TO_PROD` in the missing-prod state promotes uncommitted `composer.json` edits to the new baseline.** | It runs only on an explicit request, which is the same consent the direction already expresses when the prod config exists. The automatic direction, `switch-prod`, `switch-update` and `composer switch-reconcile` all stay blocked. `reconcile(RECONCILE_TO_PROD, true)` previews the copy first, and `test_missingProdConfigExplicitToProdDryRunWritesNothing` pins that. |
| **`rename()` of a directory onto a non-empty directory behaves differently on some host, so the concurrent-winner tests pass vacuously.** | Both tests probe the host behaviour first and skip with a named reason when it differs. POSIX specifies failure (`ENOTEMPTY`/`EEXIST`) for this case. |
| **Permission-based failure tests are ineffective when run as root.** | Reuse the existing probe-and-skip pattern from `test_copyFailureContext()`. The missing-file `read()` case needs no permissions and always runs. |
| **The user tags v3.0.0 before this run, making "fold into v3.0.0" wrong.** | Recorded as an Assumption. The documentation WP checks `git tag` first; if `v3.0.0` exists, it writes a `v3.0.1` entry and bumps the manifest version instead, and reports the deviation. |

## Recommended Workflow
- **Workflow:** ledger
- **Rationale:** Eleven steps across distinct concerns: `src/` behaviour change, a facade error-handling guarantee, test-runner policy, a shipped shell hook with a security surface, harness seams, Tier 1 and Tier 2 tests, and a documentation sweep. That needs formal QA, a security audit on the hook, and review. The synthesis's own process lesson applies: every WP keeps an authoring stage.
