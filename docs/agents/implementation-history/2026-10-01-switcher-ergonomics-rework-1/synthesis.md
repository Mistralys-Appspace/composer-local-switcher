# Synthesis Report — Switcher Ergonomics Rework 1

### Outcome Summary

This rework closed every actionable item from the switcher-ergonomics v3.0.0 synthesis plus the deferrals that fit a rework cycle. `FileSystem`'s real-mode I/O now captures native PHP warnings behind a `runNative()` seam and surfaces only typed `ComposerSwitcherException`s with a chained `KEY_NATIVE_ERROR`, `reconcile()` no longer throws in the INITIAL state or when `composer-prod.json` is missing and now owns dry-run-flag/operation-list restoration via `try/finally` (fixing a real bug where a drifted-PROD preview could perform a live write), the pre-commit hook's Guard 2 is now scoped to the `repositories` key with a fail-safe grep fallback, PHPUnit runs with `failOnWarning`/`failOnNotice` enabled, and Tier 1/Tier 2 coverage gaps (state value objects, facade propagation, clone-concurrency race, and five previously-undriven `switch-*` CLI entry points) were closed. All nine work packages passed every pipeline stage (implementation, QA, a dedicated security audit for the pre-commit hook change, code review, and documentation), the full verification gate (`composer test`, `composer test-integration`, `composer analyze`) ran clean with zero Tier 2 skips, and every change folded into the still-unreleased, unheaded `v3.0.0` changelog entry since no release tag exists yet.

### Metrics

- **Tier 1 (`composer test`):** 103 → 124 tests passing by the end of the plan (530 assertions), 0 failures, 0 PHPStan errors throughout.
- **Tier 2 (`composer test-integration`):** 62 → 67 tests passing (346–355 assertions across runs), 0 failures, 0 unexpected skips (only the plan-tolerated named-reason capability skips were possible, and none were triggered on this host).
- **Static analysis:** `composer analyze` (PHPStan) reported 0 errors across every pipeline run (47/47 files clean at the final gate).
- **Security audit (WP-004, Guard 2 rewrite):** 0 Critical/High/Medium findings across all 14 audit areas (OWASP A01–A10 plus input validation, data handling, dependency audit, auth/authz); 2 Low observations recorded.
- **Code review:** 9/9 work packages passed with 0 blocking issues; 1 reviewer-applied fix-forward (WP-007, restored the project's `AC-NN` docblock convention); 1 documentation-forward item raised and later resolved (WP-003, `displayMessages()` docblock clarification).
- **Pipeline health:** 9/9 work packages passed all active pipeline stages; 0 stages missing.

### Strategic Recommendations

- **Outermost-entry-point-owns-the-flag is now a load-bearing pattern.** WP-002 moved dry-run-flag and operation-list ownership from `reconcileCore()` to the public `reconcile()`/`switchTo()`, fixing a real bug (a PROD-mode preview could previously perform a live write on drift). Future nested-call additions to `ConfigSwitcher` should audit against this same pattern before introducing a new entry point, rather than threading a dry-run parameter through the core.
- **`set_error_handler()` + `finally` is the project's house pattern for native-call safety**, now established in `FileSystem::runNative()`. Any future native PHP call added to the codebase (not just filesystem calls) should route through this or an equivalent seam rather than reintroducing a bare native call.
- **Fail-safe-over-fail-open is the explicit design contract for the pre-commit hook.** Guard 2's rewrite demonstrates the pattern clearly: a confident "none found" result is the only way to pass; every other outcome (undecodable JSON, missing/crashing `php`) falls back to the pre-existing blocking grep. This is a good template for any future guard added to the hook.
- **Structural guarantees beat procedural reminders.** WP-006's rationale — enabling `failOnWarning`/`failOnNotice` because three known warnings survived three prior work packages unaddressed — is a reusable lesson: where a class of regression can be caught by a test-runner setting, prefer the setting over a review checklist item.

### Code Insights

**Developer:**
- `src/Utils/FileSystem.php` — `modifiedTime()`'s captured `\ErrorException` (from a TOCTOU `filemtime()` race) is intentionally discarded since that method has no failure-path exception to attach it to; accepted as an out-of-scope gap for this plan (WP-001).
- `src/ConfigSwitcher.php` — `reconcile_detectBlocker()`'s DEV → INITIAL → missing-prod ordering is documented as mutually-exclusive-in-practice; the ordering guarantee itself has no dedicated unit test, only inferred correctness (WP-002).
- `tests/assets/integration-project/composer/composer-prod.json` must mirror `composer.json`'s `scripts` block exactly, since the verify-config/reconcile comparison treats any key drift as real divergence — discovered via a failing test before the fix (WP-007).

**QA:**
- `FileSystem::runNative()` only captures the *last* of multiple warnings raised within one native call (matches `error_get_last()` semantics, not a defect, but worth a docblock note) (WP-001).
- `displayMessages()` always prints when called manually, regardless of `setDisplayMessages(false)` — only the automatic internal call is gated; correct but the method names alone could mislead a future reader (WP-003, later resolved via docblock clarification in documentation stage).
- `switch-describe`/`switch-preview-prod` remain undriven by dedicated Tier 2 tests, accepted per WP-007's own documented rationale (they share code paths with commands that are driven).

**Security Auditor:**
- Guard 2's `php -r` invocation receives staged content exclusively via STDIN/`json_decode()` — never string-interpolated into PHP source or a shell command — closing off injection paths (WP-004).
- Hardening note: stderr is suppressed on the `php -r` crash path, reducing operator diagnosability of fallback triggers without weakening the fail-safe guarantee (WP-004, Low, non-blocking).

**Reviewer:**
- `ConfigSwitcher::displayMessages()`/`setDisplayMessages()` docblocks should clarify that the flag only gates the automatic internal display call, not a direct manual call — flagged as documentation-forward and resolved in WP-003's documentation stage.
- WP-007's three new test docblocks contained an unresolved template placeholder (`"AC-{WP-007}"`); reviewer applied a fix-forward restoring the project's established `AC-NN` convention.

**Documentation:**
- README.md lagged `api-surface.md` on multiple occasions across this plan (WP-001's native-error guarantee, WP-002's reconcile no-op states, WP-008's constraints.md/api-surface.md/switching-decision-table.md gaps) even when code review reported zero documentation-forward items — confirms the project's existing guidance that README and the project manifest must be independently cross-checked against implementation, not assumed to track code-review's own findings.

### Deferred & Follow-Up Items

- **Source:** WP-001 (QA) — **Originating agent:** QA — **Description:** `modifiedTime()`'s captured native error (TOCTOU `filemtime()` race) is discarded since the method has no failure-path exception to attach it to. **Status:** out-of-scope — explicitly accepted as a non-regressing gap, not carried forward as an action item.
- **Source:** WP-002 (QA) — **Originating agent:** QA — **Description:** `reconcile_detectBlocker()`'s DEV/INITIAL/missing-prod ordering has no dedicated unit test proving the order itself (only the mutual exclusivity of the states makes it currently safe). **Priority/rationale:** Low — behavior is correct and documented, but a future refactor that makes the states non-exclusive would have no test to catch an ordering regression.
- **Source:** WP-003 / code-review — **Originating agent:** Reviewer — **Description:** Documentation-forward item requesting `displayMessages()`/`setDisplayMessages()` docblock clarification. **Status:** resolved within this plan (WP-003's documentation stage) — included here only for completeness of the audit trail, not as an open item.
- **Source:** WP-004 (Security Auditor) — **Originating agent:** Security Auditor — **Description:** stderr is suppressed on the Guard 2 `php -r` crash path, reducing operator diagnosability of which fallback trigger fired. **Priority/rationale:** Low, non-blocking hardening suggestion — does not weaken the fail-safe guarantee.
- **Source:** WP-007 (QA) — **Originating agent:** QA — **Description:** `switch-describe` and `switch-preview-prod` remain wired into the Tier 2 fixture but are not driven by dedicated tests. **Status:** out-of-scope — explicitly accepted per the WP's own rationale (both share code paths with commands that are already driven).

### Next Steps

- No functional gaps remain from this rework; the project's v3.0.0 line is feature- and test-complete per this cycle's scope. The Planner's next cycle should treat the items above as optional hardening (ordering test for `reconcile_detectBlocker()`, stderr visibility on the Guard 2 crash path) rather than required follow-up.
- Since `v3.0.0` is still unreleased (no git tag), the next natural milestone for the Release Engineer is cutting the actual `v3.0.0` tag once the maintainers are ready — this plan deliberately avoided bumping to `v3.0.1` because no consumer has observed v3.0.0 behavior yet.
- If a future plan touches `ConfigSwitcher`'s nested-call structure again, re-verify the outermost-entry-point-owns-the-flag pattern holds for any new entry point before merging.
