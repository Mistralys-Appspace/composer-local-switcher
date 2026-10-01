# Synthesis Report — Self-Sufficient Test Surface Rework

### Outcome Summary

This plan reshaped the composer-local-switcher test harness into a self-sufficient, single-owner surface without touching `src/` or `resources/`: it migrated PHPUnit to the v13 attribute set and raised the dev-toolchain floor accordingly, brought all three test directories under PHPStan, and introduced `WorkCopy`/`FixtureFileSystem` as the single owner of work-copy lifecycle with a bootstrap-driven 24-hour stale purge. It also choked every git invocation through a new `GitRunner`, rewrote `LocalPackageClone` to acquire its cache atomically (clone-into-temp-then-rename), fixing a live bug where Tier 1 runs deleted the shared Tier 2 clone cache, hoisted six duplicated Tier 2 helpers into `IntegrationTestCase`, and closed nine named coverage gaps across Tier 1 and Tier 2. All 13 work packages passed every pipeline stage (implementation, QA, security audit where applicable, code review, release engineering/documentation) with zero blocking issues and zero security findings; the final verification gate (WP-013) independently reconfirmed a clean `composer test`, `composer test-integration`, `composer analyze`, and shell-invocation/`sleep()` grep gate.

### Metrics

- **Work packages:** 13/13 COMPLETE, all pipeline stages passed (`pipeline_health.wps_with_all_stages_pass: 13`, `total_stages_missing: 0`).
- **Final regression (WP-013 gate):** `composer test` 38/38 (0 deprecations), `composer test-integration` 52/52, `composer analyze` 0 errors across `src/`, `tests/TestClasses`, `tests/TestSuites`, `tests/IntegrationSuites`.
- **Security audits:** WP-006 (GitRunner/ProcessResult), WP-007 (LocalPackageClone), WP-011 (hook/Guard characterisation tests) — all 3 audits PASS, 0 Critical/High/Medium findings, `security_issues: 0` on every audit.
- **Code review:** 0 blocking issues across all 13 WPs; 2 reviewer-applied non-behavioral fix-forwards (WP-004 method rename, WP-006 constructor-promotion on `ProcessResult`).
- **Scope confinement:** `git diff --stat` confirmed by WP-013 — no changes reached `src/` or `resources/`; all touched files fall within the plan's Required Components.
- **Hygiene:** `tests/assets/local-clones/simple_html_dom` byte-identical (by `.git/HEAD` and entry count) before/after the full suite run; `tests/assets/work-projects/` empty at gate time (33 legacy orphans removed, no stale or legacy-named entries remain).

### Strategic Recommendations

- **Atomic acquisition as the general pattern for cache-like resources.** WP-007's clone-into-temp-sibling-then-`rename()` algorithm made an incomplete `LocalPackageClone` cache unrepresentable by construction, closing both a misreport bug and a live Tier-1-deletes-Tier-2-cache defect. This pattern (verify-then-atomically-publish, with concurrent-winner discard-and-adopt handling) is worth reapplying anywhere else in the codebase that acquires a shared, disk-backed resource.
- **Constructor injection over shared mutable state unlocks testability.** Both `LocalPackageClone` (cache directory + repository URL) and the new `GitRunner`/`WorkCopy` classes are designed so tests inject throwaway paths instead of mutating a shared fixture — this is what let WP-007's Tier 1 suite stop touching the real clone cache at all. Continuing to prefer injected paths over shared globals in future harness work will keep tests parallel-safe.
- **Single choke-point per external binary.** `GitRunner` (WP-006) mirrors the existing `ComposerRunner` pattern — one class per external binary, always using Symfony Process's array-argument form, resolved via PATH/`ExecutableFinder`. This closed every shell-invocation vector in the touched files (confirmed structurally by all three security audits) and is the reason the WP-013 grep gate for `exec(`/`shell_exec`/`proc_open`/`passthru`/`system(` came back empty.
- **`runComposerChecked()` as the house idiom for fail-fast subprocess assertions.** WP-008 generalized the best of five divergent "run Composer, fail with both streams" implementations into a single hoisted helper; the WP-011 implementation note about `ProcessResult::containsOutput()` (stdout+stderr) vs. `getOutput()` (stdout only) is a concrete trap other test authors are likely to hit again with Composer's stderr-only error output — worth calling out in onboarding/test-writing guidance.
- **Characterisation tests as a deliberate boundary marker for known limitations.** WP-011's `test_guard2MatchesTypePathOutsideRepositories()` pins a known Guard 2 limitation with a docblock naming the future guard-registry reshape that will change it — this makes an eventual behavioural change a visible, intentional test update instead of a silent regression. This is a reusable technique whenever a fix is deliberately deferred to a separate plan.

### Code Insights

**Developer**
- WP-001: `phpunit.xml` config migration dropped PHPUnit-9-only `convert*ToExceptions` attributes rather than retaining them, since PHPUnit 13 no longer recognizes them (would itself trigger an unknown-attribute warning).
- WP-002: The three `assertTrue(true)` placeholder patterns across harness subclasses were replaced with `expectNotToPerformAssertions()`, the correct PHPUnit idiom for intentionally-non-asserting test methods.
- WP-003: `FixtureFileSystem::copyDirectory()` treats an existing file/symlink at the destination as a collision too, not just an existing directory, to avoid a silent partial-copy-into-file failure mode. `WorkCopy::purgeStale()` reads symlink mtime via `lstat()` rather than a link-following call, so age decisions for symlinks are based on the symlink itself, not a possibly-broken target.
- WP-004: `ComposerSwitcherTestCase` is now a thin consumer of `WorkCopy`'s lifecycle API rather than re-inlining fixture-copy orchestration.
- WP-006: `runHookScript()`'s `ProcessResult` intentionally preserves the original array shape's semantics (`getOutput()` = combined stdout+stderr) because an existing assertion checks the combined stream, which could appear on either.
- WP-007: A `rename()` failure whose destination still lacks `composer.json` is reported as `REASON_CLONE_FAILED`, not `REASON_INVALID_DIRECTORY` — that reason is now reserved exclusively for a cache directory that pre-existed before `ensureAvailable()` ever attempted to clone into it.
- WP-011: `test_malformedVersionFailsComposerUpdate()` initially asserted against `getOutput()` (stdout only) and failed because Composer's version-constraint parse error is written to stderr; fixed via the existing `ProcessResult::containsOutput()` helper (stdout+stderr combined) — flagged as a reusable lesson for future Composer-output assertions in this suite.

**QA**
- WP-003: Suite lacks direct tests for `WorkCopy::allocate()` collision-throw and `FixtureFileSystem::copyDirectory()` direct collision-throw; both verified correct manually via a scratch script, not a defect.
- WP-006: `ProcessResult` (renamed from `ComposerResult`) initially lacked the constructor-promoted readonly properties the WP's own Deliverables specified — non-blocking, cosmetic, fixed by the code-review stage.
- WP-006: `GitRunner::run()` has no test for a zero-argument call or a non-existent/unwritable working directory — low priority since current call sites always pass valid inputs.
- WP-007: No test exercises the "concurrent-winner" rename-failure/adopt branch (`LocalPackageClone.php`) — a genuine race condition that would need an injectable rename hook to test deterministically; flagged as a candidate for a future hardening WP, not a defect.

**Security Auditor**
- WP-007: Three Low/Info hardening observations recorded, none blocking: the upstream clone URL is unpinned (branch-following clone, deliberate per WP-007's Notes to keep `dev-master` inference intact for `TestVersionOverride`); `rename()` uses error suppression that could mask non-race failures; `glob()` in the stale-partial purge is sensitive to filesystem metacharacters in path segments.

**Reviewer**
- WP-004: Renamed `TestGitHooks::callRemoveDirectory()` → `removeWorkCopyOf()` with a docblock — the old name was a leftover from when the method invoked cleanup via reflection; after the refactor it is a direct `FixtureFileSystem::removeDirectory()` call, so the "call" framing was stale.
- WP-006: Applied the constructor-promotion fix-forward on `ProcessResult.php` flagged non-blocking by QA (non-behavioral, verified with full regression + static analysis afterward).

### Deferred & Follow-Up Items

- **[Deferred, WP-007, Developer]** Pinning `LocalPackageClone`'s upstream clone to a specific tag/commit was explicitly deferred, not rejected — a detached checkout would change Composer's inferred `dev-master` version, which `TestVersionOverride.php` currently depends on. Revisit once/if that dependency is addressed.
- **[Deferred, WP-007, QA/Reviewer]** No test exercises `LocalPackageClone`'s concurrent-winner rename-failure/adopt branch; a future hardening WP could add an injectable rename hook to test this race deterministically.
- **[Deferred, WP-003, QA]** Add direct collision-throw tests for `WorkCopy::allocate()` and `FixtureFileSystem::copyDirectory()` (currently only verified manually via scratch script) — low priority, worth picking up when this suite is next touched.
- **[Deferred, WP-006, QA]** Add `GitRunner::run()` coverage for a zero-argument call and a non-existent/unwritable working directory — low priority, no current call site needs it.
- **[Out-of-scope, WP-011, Developer]** `resources/git-hooks/pre-commit`'s Guard 2 is a file-wide grep match rather than scoped to the `repositories` key; fixing it is an out-of-scope guard-registry reshape tracked by insight `2cd87f16-2556-4523-af27-0b88a6adbf0b`. WP-011's `test_guard2MatchesTypePathOutsideRepositories()` deliberately pins the current (limited) behaviour as a characterisation test so the reshape shows up as a visible, intentional test change.
- **[Out-of-scope, WP-011, Developer]** `test_malformedVersionFailsComposerUpdate()` deliberately stops asserting after the failed `composer update` — what `switch-prod` does next on a malformed version is being redesigned by the separate `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md` (its AC-13); asserting further here would create a cross-plan conflict.
- **[Out-of-scope, plan-level]** `tests/TestSuites/TestSwitching.php` lines 214–252 were explicitly reserved and left untouched throughout this plan (WP-010's Notes) for the pending `2026-09-25-switcher-ergonomics` plan, which also plans five new Tier 1 suites extending `ComposerSwitcherTestCase` — that plan's protected-surface contract (`$assetsFolder`, `$testSource`, `$testTarget`, `getFixtureSourceDir()`, `setKeepWorkFiles()`, `createSwitcher()`) was preserved unchanged by WP-004 specifically to not block it.

### Next Steps

1. **Resume or kick off the switcher-ergonomics plan** (`docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md`), which depends on the protected surface and reserved test regions this plan deliberately preserved.
2. **Consider a small follow-up WP** to close the two low-priority coverage gaps (WorkCopy/FixtureFileSystem direct collision-throw tests; GitRunner edge-case coverage) the next time either file is touched, rather than as a standalone effort.
3. **Track the Guard 2 registry reshape** as a named future change — WP-011's characterisation test is the trigger point; when that reshape lands, the pinned test's assertion should be updated deliberately rather than silently.
4. **Revisit clone pinning** for `LocalPackageClone` once `TestVersionOverride`'s `dev-master`-inference dependency is resolved or redesigned.
