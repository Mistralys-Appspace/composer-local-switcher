# Synthesis Report — Self-Sufficient Test Surface

### Outcome Summary

This project built a genuine, in-repository Composer test project as a second ("Tier 2") test tier for composer-local-switcher, proving the library's core promises — symlinked vendor paths, version overrides, lock-file round-tripping, entry-point dispatch, and git-hook guards — against a real, cloned dependency rather than fixture-only assertions, while keeping the existing offline Tier 1 suite fast and network-free. It also renamed the Tier 1 fixture to match the library's own path convention, fixed a symlink-teardown bug and a removed-PHPUnit-API regression uncovered along the way, and recorded the already-applied PHP 8.4 floor bump and script-key rename as a documented v2.0.0 release. All 16 work packages completed; the final full-suite gate (WP-016) confirmed 22 Tier 1 tests and 41 Tier 2 tests green, PHPStan level 6 clean, and no file touched outside the plan's declared scope.

### Metrics

- **Final full-suite verification (WP-016):** `composer test` 22/22 passed (Tier 1, offline, no Composer/network invocation); `composer test-integration` 41/41 passed (Tier 2, real git clone + real Composer binary); `composer analyze` (PHPStan level 6) — zero errors.
- **Security audits:** WP-005 (LocalPackageClone) — PASS, 0 issues across all 14 audit areas. WP-013 (Git Hooks suite) — PASS, 0 issues across all 14 audit areas.
- **Code review:** 16/16 work packages passed code-review with 0 blocking issues; several documentation-forward items raised and all resolved by the Documentation stage.
- **Rework:** WP-003 required one rework cycle (implementation + QA) after a cross-WP interaction was found — a Tier 1 test in WP-006 was invoking the real Composer binary, violating WP-003's "no network in default run" acceptance criterion. Resolved by relocating four binary-invoking tests to Tier 2; re-verified green.
- **Test count growth across the session:** Tier 2 suite grew from 0 (start) to 6 suites / 41 tests by WP-016, incrementally verified at each step (5, 17, 39, 39, 41, 46 cumulative counts as suites landed).

### Strategic Recommendations

- **Placeholder-exact assertions over path-pattern assertions:** WP-009 chose to assert that fixture fields decode to the literal placeholder string (e.g. `'__LIBRARY_SRC_PATH__'`) rather than checking for absence of a path pattern like `/Users` or `/home`. This is a more robust, environment-independent way to prove a machine-specific value never leaked into a committed fixture — worth adopting as the default pattern for any future placeholder-substitution fixture.
- **`runComposerChecked()` fail-fast wrapper (WP-014, tagged gold-nugget by Reviewer):** a reusable pattern for future Tier 2 suites that need a Composer invocation to fail loudly with captured stdout/stderr rather than silently continuing.
- **Reference-loop hygiene (WP-008 review):** `setLocalRepositoryVersion()`'s `foreach` by-reference with explicit `unset()` afterward was flagged as a clean, reusable pattern worth propagating to other array-mutation code.
- **Array-form process invocation as house style:** the security audit on WP-013 confirmed that using Symfony Process's array-argument constructor everywhere (never a shell string) is the project's de facto injection-safe convention — worth codifying explicitly in `constraints.md` for future contributors writing shell-invoking test code.
- **Single choke-point classes pay off:** concentrating Composer-binary invocation (`ComposerRunner`) and clone acquisition (`LocalPackageClone`) each behind one class meant that a systemic fix (e.g. removing the incompatible `--no-progress` flag in WP-011) only had to be made once and was automatically picked up by every dependent Tier 2 suite.

### Code Insights

**Developer:**
- WP-002: `tearDown()` called PHPUnit's removed `hasFailed()` method, blocking every test regardless of its own assertions — fixed by switching to `status()->isFailure() || status()->isError()` (PHPUnit 10+ equivalent). `phpunit.xml` validates against a deprecated configuration schema (recurring low-priority debt item across several WPs; `vendor/bin/phpunit --migrate-configuration` would resolve it).
- WP-005: chose `exec()`/`escapeshellarg()` over Symfony Process for the git clone probe since no dependency on `symfony/process` existed yet at that point in the plan.
- WP-010: `tests/assets/work-projects/` had 33+ orphaned directories from Tier 1 suites failing to clean up — pre-existing, out of scope, flagged for follow-up.
- WP-011: `ComposerRunner` unconditionally appended `--no-progress`, which `composer show` rejects — fixed by dropping the flag entirely (verified harmless since no invocation runs against an interactive TTY).
- WP-012: cross-command lock-file dependency discovered — `switch-dev` then `switch-prod` needs an intervening `composer update`, or `switch-prod` silently no-ops due to `switchTo()`'s no-lock-file guard.

**QA:**
- WP-002: `tests/assets/work-projects/` (gitignored) has 32+ accumulated stale run directories with leftover `dev-config.status` files predating the fixture rename — unbounded growth risk, not a regression.
- WP-008: flagged that a permanent acceptance-test file (`TestIntegrationTestCase.php`) was omitted from the implementation pipeline's declared `files_modified`, undercounting delivered scope — recommended Reviewer/Documentation account for it explicitly (they did).
- WP-012: `test_switchUpdatePropagatesProdEdit` uses a real `sleep(1)` to force an mtime difference for a PROD/PROD propagation check — timing-dependent, non-blocking flakiness risk; recommended a `touch()`-based mtime bump instead.
- WP-013: two low-priority coverage gaps in the shipped (pre-existing, unchanged) `resources/git-hooks/pre-commit` script — untested hook-overwrite of a pre-existing custom hook, and an untested Guard 2 regex false-positive on unrelated JSON containing the literal `type:path` substring.

**Reviewer:**
- WP-005: a failed git clone can leave a partial target directory on disk, causing `ensureAvailable()` to misreport `REASON_INVALID_DIRECTORY` instead of `REASON_CLONE_FAILED` on every subsequent call until manually cleaned up (medium-priority maintainability observation).
- WP-009: `readFile()`/`decodeJsonFile()` private helpers appeared for the first time in `TestProdBootstrap.php` — recommended hoisting to `IntegrationTestCase` once a second Tier 2 suite needs them (architecture, medium priority).
- WP-016: independently re-verified every one of QA's claimed checks (full test runs, PHPStan, git diff scope, residual grep) rather than trusting the report — found no discrepancies, including confirming a dirty `../hcp-editor` working tree predates this plan and is unrelated to it.

**Security Auditor:**
- WP-005: two low/info hardening observations — the cloned repo's default branch is untracked/unpinned; dev-only `composer.lock` version bumps with no advisories.
- WP-013: `putenv`-based `PATH`/`COMPOSER_BINARY` manipulation in `test_skipsWhenGitUnavailable` carries a theoretical leak-on-fatal-error risk (test is wrapped in try/finally, so this is a low-priority hardening note, not a finding).

### Deferred & Follow-Up Items

- **[Out-of-scope]** Reshaping `resources/git-hooks/pre-commit` into a testable, cross-platform guard registry (global insight `2cd87f16-2556-4523-af27-0b88a6adbf0b`) — explicitly rejected for this plan in WP-013's Rationale because it would change a shipped resource already installed in consumers' `.git/hooks/`, turning a test-infrastructure plan into a behavioral change with a migration story. This plan gives the hook its first test coverage as-is; the reshape is a prerequisite-satisfied follow-up for a future plan.
- **[Out-of-scope]** Reproducing the documented "has higher repository priority" resolver error (WP-011 Rejected Approaches) — needs a third package constraining `simple_html_dom`, which the zero-dependency fixture cannot supply without scope growth.
- **[Deferred, low priority]** `phpunit.xml` validates against a deprecated PHPUnit configuration schema, emitting a deprecation notice on every run (first flagged by Developer in WP-002, re-observed through WP-016). Fix: `vendor/bin/phpunit --migrate-configuration`.
- **[Deferred, low priority]** `tests/assets/work-projects/` accumulates orphaned run directories (33+ observed) from Tier 1 suites whose teardown only runs on success paths — flagged repeatedly by QA/Developer (WP-002, WP-010, WP-014). A cleanup pre-step in `composer test` was suggested.
- **[Deferred, medium priority]** `LocalPackageClone::ensureAvailable()` misreports `REASON_INVALID_DIRECTORY` instead of `REASON_CLONE_FAILED` after a partial clone failure leaves a directory behind (WP-005, Reviewer).
- **[Deferred, medium priority]** `test_switchUpdatePropagatesProdEdit`'s `sleep(1)`-based mtime forcing (WP-012) should be replaced with a `touch()`-based mtime bump to remove a timing-dependent flakiness risk.
- **[Deferred, medium priority]** `readFile()`/`decodeJsonFile()` helpers in `TestProdBootstrap.php` should be hoisted into `IntegrationTestCase` once reused by a second Tier 2 suite (WP-009, Reviewer) — by WP-016 several more Tier 2 suites exist and likely duplicate this need.
- **[Deferred, low priority]** Coverage gaps in the shipped (unchanged) pre-commit hook: untested hook-overwrite of a pre-existing custom hook, and an untested Guard 2 regex false positive on unrelated JSON containing `type:path` (WP-013, QA).
- **[Deferred, low priority]** No test asserts round-trip lock fidelity beyond one full PROD→DEV→PROD cycle (e.g., a third `switch-prod`) — WP-014, QA.
- **[Deferred, low priority]** No malformed-version negative test, and no negative case proving the hyphen alias is skipped for non-underscore package names — WP-011, QA.
- **[Note, resolved but worth orchestration review]** Multiple project comments (WP-005, WP-006, WP-008, WP-010) document concurrent Developer sessions independently implementing the same work package at the same time, converging on compatible designs with no data loss but representing a genuine concurrency hazard in the ledger/orchestration layer. Not investigated per protocol; flagged for awareness at the process level.

### Next Steps

1. Consider a small, standalone "test infrastructure hygiene" plan covering: the `phpunit.xml` schema migration, `tests/assets/work-projects/` cleanup, and the `LocalPackageClone` clone-failure/invalid-directory misreport fix — all low-effort, all currently deferred debt.
2. When a future plan reshapes `resources/git-hooks/pre-commit` into a cross-platform guard registry, this project's Tier 2 `TestGitHooks.php` suite is now the regression safety net to build against.
3. Hoist the `readFile()`/`decodeJsonFile()` JSON-fixture helpers out of `TestProdBootstrap.php` into `IntegrationTestCase` before adding further Tier 2 suites, to avoid continued duplication.
4. Investigate the concurrent-Developer-session pattern observed across WP-005/006/008/010 at the orchestration layer — while harmless this cycle, it is worth a root-cause pass before it causes a real conflict on a less independently-convergent piece of work.
5. The v2.0.0 release recorded in this plan (PHP 8.4 floor, script-key rename, Tier 2 suite) is ready for tagging/publishing by a future Release Engineer pass if not already done.

---

## AX Feedback
No friction encountered.
