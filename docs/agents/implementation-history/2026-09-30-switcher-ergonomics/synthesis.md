# Synthesis Report — Switcher Ergonomics (v3.0.0)

### Outcome Summary

The Composer Local Switcher's switching mechanism was rebuilt to be observable and programmatically consumable, replacing prose-only reporting with typed value objects, a single file-system write choke-point, unified content-first reconciliation, a `describe()` state snapshot, a true dry-run preview, and structured exception context, released as a major version bump to v3.0.0. All 10 work packages across value objects, the FileSystem facade, test-harness hardening, structured messages, `verify()`, `reconcile()`, missing-lock handling, exception context, `describe()`/dry-run, and the final documentation/verification sweep completed and passed their full pipelines (implementation/QA/code-review/documentation, plus release-engineering for the closing WP). One pre-existing, out-of-scope defect was surfaced but deliberately deferred: `reconcile()` throws an unguarded exception when called from the INITIAL state, recommended as a follow-up work package.

### Metrics

- **Work packages:** 10/10 COMPLETE, 40/40 pipeline stages PASS (`pipeline_health.wps_with_all_stages_pass: 10`, 0 missing).
- **Rework:** Only WP-010's QA stage required one rework cycle (documentation/manifest deliverables were initially missing); all other WPs passed every stage on the first attempt.
- **Tests:** Final regression sweep (WP-010) — `composer test` 100/100 unit tests (156 including WP-009's additions, 704 assertions) green; `composer test-integration` (Tier 2, real git + composer binaries) 56/56 green, no skips; `composer analyze` (PHPStan) 0 errors across 46/46 files.
- **Blocking issues found in code review:** 0 across all 10 WPs.
- **Documentation:** 10 files updated in the final sweep (README.md, changelog.md, AGENTS.md, and 5 project-manifest docs, plus the new `switching-decision-table.md`); manifest version line correctly bumped to 3.0.0; changelog carries an explicit BREAKING block for the three changed return types and the `tryCopyTo()` semantics change.

### Failed Metrics / Blockers / Concerns (Aggregated)

- **WP-010 QA rework (resolved):** First QA pass FAILed — the decision table, changelog v3.0.0 entry, manifest version bump, and several README sections did not exist yet. This was unwritten work, not a code defect. A process gap was also surfaced: WP-010's pipeline had no active `implementation` stage, so the FAIL self-routed back to QA, which is a verification-only role, not a content-authoring one. The Documentation agent authored the missing content directly (outside a formal pipeline stage, per Ledger Doctor's guidance), and the QA re-run then PASSed. No ledger corruption occurred; this was a WP-pipeline-configuration lesson, not a data-integrity issue.
- **Known, deliberately deferred defect (WP-006/WP-009):** `reconcile()` (and its `composerSwitchReconcile()` entry point) throws an unguarded `ERROR_CANNOT_GET_MODIFIED_DATE` when invoked from the INITIAL state, because only DEV mode is special-cased in the modified-date lookup. Confirmed independently by the Developer, QA, Reviewer, and Documentation agents across WP-006 and WP-009. Not introduced by this plan — pre-existing behavior exposed by the new `reconcile()` surface. Documented as a known gotcha in `switching-decision-table.md`; a defensive guard is recommended as a future implementation WP.
- **Non-blocking test hygiene (WP-010, carried since WP-002/WP-008):** 3 unsuppressed native PHP warnings surface in `composer test` output (from `TestExceptionContext::test_copyFailureContext`, `test_readFailureCarriesErrorCode`, and `TestFileSystem::test_realMode_readMissingFileThrows`), all from intentional real-I/O-failure test paths. Tests pass but produce "OK, but there were issues!" instead of a clean OK. Recommended cleanup (suppress via `@` or assert via `expectWarning()`) before future release sign-off.

### Strategic Recommendations

- **FileSystem choke-point as a structural (not procedural) guarantee (WP-002/WP-009):** Routing every file mutation through one facade with an in-memory dry-run overlay made "no disk writes during preview" a property of the architecture — enforced by a static guard test scanning all of `src/` — rather than a rule every call site has to remember. This pattern is worth reusing anywhere a preview/dry-run capability is added to file- or state-mutating code.
- **Preview built by reusing the real code path (WP-009):** `previewSwitch()` runs the actual `switchTo()` logic under the dry-run overlay instead of maintaining a parallel preview implementation, structurally guaranteeing preview/real parity (verified by `test_previewOperationsMatchRealSwitch`). This avoids the "two implementations that drift" trap the plan explicitly called out as a risk for reconciliation, and generalizes as a design principle beyond this plan.
- **Content-first reconciliation, decoupled from timing (WP-006):** Splitting "should we act" (content diff via `verify()`) from "which direction" (modification time) fixed a real bug class (identical-content copies, and silent no-ops when mtimes matched but content differed) while preserving existing behavior for the common case. The shared `reconcileCore()` between `reconcile()` and `switch_case_PROD_PROD()` is what makes their outputs provably identical rather than "supposed to be" identical.
- **Value objects centralize business rules instead of duplicating them at call sites (WP-001):** `VerificationResult::isInSync()` centralizes the "DEV mode forces not-in-sync" rule once, and `toArray()` reuses it rather than re-deriving the value — avoiding the classic array-vs-object-method divergence bug.

### Code Insights

**Developer:**
- WP-002: `ConfigFile`'s eager `LockFile` requires explicit facade propagation — flagged as structurally fragile and lacking a dedicated regression test (medium priority, non-blocking).
- WP-006: Added a minimal exception-context mechanism ahead of schedule (only `KEY_DIRECTION`) to satisfy WP-006's own acceptance criterion, explicitly handed off for WP-008 to complete with the fuller `KEY_*` set.
- WP-007: `switch_case_DEV_PROD()` and `switch_case_PROD_DEV()` now symmetrically guard their missing-lock cases; `switchUpdate()`'s INITIAL-state `SwitchOutcome` is only exercised via a CLI-level test, not a direct unit assertion (low priority, deferred).
- WP-009: Found and fixed a genuine correctness bug in `StatusFile`'s per-instance caching — a dry-run write could leave a stale, never-applied mode cached past the dry run's lifetime, corrupting a subsequent real read. Fixed by reading fresh from disk/overlay on every call.

**QA:**
- WP-001/WP-004: Minor coverage gaps — untested negative `SwitchMessage` codes, empty-collection `toArray()` round trips, and `setDisplayMessages(false)`/empty-log `displayMessages()` paths — all manually verified correct but lack automated coverage (low/medium priority, non-blocking).
- WP-002: Confirmed no dedicated regression test asserts `ConfigFile`'s eager `LockFile` shares the parent's `FileSystem` instance after `setFileSystem()` — a silent-breakage risk for a future refactor (medium priority, non-blocking).
- WP-010: Raised the process-level pipeline-configuration concern (see Blockers section) before proceeding with self-rework, rather than looping `RUN_QA` against unchanged content — a disciplined halt-and-escalate call.

**Reviewer:**
- WP-002/WP-009: Independently verified (via grep) that no other `BaseFile` subclass shares `StatusFile`'s caching hazard pattern, closing out QA's sanity-check request.
- WP-009: Cross-checked `switching-decision-table.md`'s and README's programmatic-usage code samples line-by-line against the actual implementation — found zero inaccuracies, including subtle branch-specific message emission behavior.

**Ledger Doctor:**
- Diagnosed the WP-010 "deadlock" as a process gap, not ledger corruption: QA self-rework is a verification role being asked to author content. Recommended (and this plan followed) having Documentation author the missing deliverables as direct file edits outside a formal pipeline stage, since code-review/release-engineering hadn't passed yet.

### Deferred & Follow-Up Items

- **[Deferred] `reconcile()` INITIAL-state guard** — Source: WP-006/WP-007/WP-009 (surfaced repeatedly by Developer, QA, Reviewer, Documentation). `composerSwitchReconcile()`/`reconcile()` throws an unguarded `ERROR_CANNOT_GET_MODIFIED_DATE` when called before any switch has ever occurred, because only DEV mode is special-cased in the modified-date lookup. Recommended as a dedicated follow-up implementation WP; documented as a known gotcha in `switching-decision-table.md` in the meantime.
- **[Deferred] Test-hygiene cleanup for 3 unsuppressed PHP warnings** — Source: WP-010 (QA, Release Engineering, Documentation all carried this forward). `TestExceptionContext::test_copyFailureContext`, `test_readFailureCarriesErrorCode`, and `TestFileSystem::test_realMode_readMissingFileThrows` produce native PHP warnings from intentional real-I/O-failure paths. Non-blocking for this release; recommended cleanup before the next release sign-off.
- **[Deferred] Coverage gaps in message/facade tests** — Source: WP-001, WP-002, WP-004 (QA/Reviewer). Untested: negative `SwitchMessage` codes, empty-collection `toArray()` round trips (WP-001); `ConfigFile`/`LockFile` facade-propagation wiring (WP-002); `setDisplayMessages(false)` and the empty-log `displayMessages()` path (WP-004). All manually verified correct; recommended for a future test-hardening pass.
- **[Out-of-scope] `switchUpdate()` INITIAL-state `SwitchOutcome` unit coverage** — Source: WP-007 (QA/Reviewer). Only exercised indirectly via a CLI-level test; a direct unit assertion was judged unnecessary for this plan's scope but flagged for awareness.
- **[Process note, not a code item] WP pipeline-stage configuration** — Source: WP-010 (QA, Ledger Doctor). A WP whose pipeline omits an `implementation` stage but still requires new content to be authored (not just verified) creates a routing ambiguity when QA FAILs, since QA self-rework is verification-only. Recommend the Project Manager/Dependency Sequencer ensure any WP with authoring deliverables retains an active authoring stage (documentation or implementation) in its `active_pipeline_stages`, even when most of its acceptance criteria are verification-oriented.

### Next Steps

1. Scope and schedule a small follow-up WP to add an INITIAL-state guard to `reconcile()`/`resolveReconcileDirection()` so `composerSwitchReconcile()` degrades gracefully (e.g., a new message or no-op) instead of throwing, before any consumer hits it in production.
2. Consider a lightweight test-hardening WP to close the accumulated low/medium-priority coverage gaps (message edge cases, facade-propagation wiring, `setDisplayMessages` paths) and suppress the 3 known PHP warnings in the exception/filesystem failure tests.
3. Perform the three out-of-plan Human Actions the release depends on: tag the v3.0.0 release, and update any downstream consumer version constraints — these were explicitly out of WP-010's scope and were not automated.
4. When decomposing future plans, apply the WP-010 lesson: any work package with authoring deliverables (docs, manifests, changelog) needs an active authoring pipeline stage, not just a verification stage, to avoid QA self-rework ambiguity.
