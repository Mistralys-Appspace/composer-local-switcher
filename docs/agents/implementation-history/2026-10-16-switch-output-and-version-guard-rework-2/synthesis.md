# Synthesis Report — Switch Output and Version Guard - Rework 2

### Outcome Summary

This project fixed a bug where moving a Composer-managed package between `require` and `require-dev` during local DEV work caused the revert process to report the change as an override twice instead of once. The fix introduced a new `State\ManagedEntryOverride` value object to replace the untyped four-key override array, separated override detection from section merging in `DevConfigTransformer`, updated the override message wording to describe moves explicitly, and consolidated the last inline project-root derivation into the existing `getProjectRoot()` method. The project also established a written testing convention requiring real-transcript verification for any future change to console output, and shipped these changes as v3.3.0 with full documentation and changelog updates. All six work packages completed with zero blocking issues and no regressions against the pre-plan baseline.

### Metrics

| Suite | Baseline (WP-001) | Final (WP-006 exit gate) |
|---|---|---|
| `composer test` (Tier 1 unit) | 332/332 passed | 343/343 passed (1199 assertions) |
| `composer test-integration` (Tier 2) | 82/82 passed | 83/83 passed (477 assertions) |
| `composer analyze` (PHPStan) | 0 errors (86 files) | 0 errors (87 files) |

- All 6 work packages: 100% stage pass rate (22/22 pipeline stages passed; 0 FAIL, 0 rework cycles).
- Code review: 0 blocking issues across all 3 reviewed WPs; 1 non-blocking fix-forward applied (import ordering in `src/ConfigSwitcher.php`).
- `TestEntryPoints::test_realSwitchTranscriptMatchesPlannedLayout()` (the real-transcript Tier 2 template test) continued to pass throughout, confirming no console-output regression.
- Version bump: 3.2.0 → 3.3.0 (Minor) — chosen because `RevertResult`'s narrowed constructor has no caller outside `DevConfigTransformer::revert()` in this repo, so the breaking signature change has nil external impact in practice.

### Strategic Recommendations

- **Separate fact detection from value merging when a bug is a "double-report," not a "duplicate."** WP-004's root-cause fix worked by moving override *detection* out of the per-section merge loop (`revertSection()`) into a single per-package pass (`detectManagedOverrides()`) run once across both sections — rather than post-processing or de-duplicating a list after the fact. Rejected approaches (post-processing, "seen" sets, null-pattern heuristics) were all documented as inferior because they treat the symptom, not the abstraction conflation causing it. This pattern — "the fix is to run the check once at the right granularity, not to suppress the extra runs" — generalizes well to similar double-reporting bugs.
- **Deprecate-and-redirect vs. narrow-the-constructor is a judgment call based on construction sites.** `RevertResult`'s constructor was safely narrowed to the typed `ManagedEntryOverride[]` shape (no legacy-array fallback) specifically because no code outside `revert()` constructs it — verified via search before deciding. `getOverriddenManagedEntries()` was kept but deprecated as a pure derived adapter (`array_map` over `getOverrides()`) so the legacy shape can never drift from the typed one. This is a reusable decision rule: narrow the write side when construction is centralized; deprecate-and-adapt on the read side when external consumers exist.
- **Writing exit-gate conventions into `constraints.md` (not persona memory or CI) reaches every future role.** The real-transcript Tier 2 convention was deliberately placed in `constraints.md` because `AGENTS.md`'s Quick Start step 3 makes every agent read it before implementing — a planner-only memory would be invisible to Developer/QA/Reviewer, and a CI check cannot judge whether a given change actually touches console output.

### Code Insights

**Developer:**
- `src/State/RevertResult.php`: narrowed constructor to `ManagedEntryOverride[]` only; `getOverriddenManagedEntries()` reimplemented as a pure `array_map` adapter over `getOverrides()` so the legacy shape can never drift from the typed one.
- `src/Utils/DevConfigTransformer.php`: `detectManagedOverrides()` resolves each managed package's base/current section via a shared `locateSection()` helper (require checked before require-dev), matching `sectionOf()`'s own lookup order — documented as covering the malformed-both-sections edge case.
- `src/ConfigSwitcher.php`: new `ManagedEntryOverride` use-import follows the file's existing alphabetical State-namespace import grouping.

**QA:**
- `src/Utils/DevConfigTransformer.php:detectManagedOverrides()`: a narrow, non-blocking data-loss gap exists when base already (malformedly) declares a managed package in *both* `require` and `require-dev` — an edit to the require-dev duplicate's value can be silently dropped. Only reachable via an already-malformed base/snapshot, never via `apply()`'s own output; not a regression, flagged as a potential coverage-gap test if this edge case is ever brought in-scope.
- Two low-priority, non-blocking coverage gaps noted for awareness: no test asserts wording for a managed package removed outright (falls to legacy wording per `isMove()`'s own semantics), and no test names the simultaneous move+value-edit combination as an explicit scenario (handled correctly by `isMove()`, which checks section only, but untested as a named case).

**Reviewer:**
- `detectManagedOverrides()`'s `baseSection` fallback (`?? 'require'`) is defensive-only and unreachable in practice, since `$packageName` is only ever iterated from `$managedInBase`, which by construction is already confirmed present in one of base's two sections. Not a bug — noted for future maintainers reading the fallback.
- `src/State/ManagedEntryOverride.php`'s class docblock was called out as a good documentation template for future value objects (gold-nugget observation, code-review pipeline).

**Documentation:**
- A concurrent Documentation agent instance found and fixed a real gap missed by the first pass: `file-tree.md`'s `TestStateValueObjects.php` entry did not mention the three `ManagedEntryOverride` tests added by WP-003. The edit is present and correct in the working tree, but WP-006 was already marked COMPLETE by the time this was found, so it could not be recorded as a formal pipeline artifact — logged here for traceability (see project comment, 2026-10-16T07:17:36Z).

### Deferred & Follow-Up Items

- **Source: WP-004 (QA) — out-of-scope / edge case.** Originating agent: QA. A narrow data-loss gap in `DevConfigTransformer::detectManagedOverrides()` when a base config already malformedly lists a managed package in both `require` and `require-dev`: an edit to the require-dev duplicate's value can be silently discarded without a reported override. Only reachable via an already-malformed base/snapshot (never produced by `apply()`'s own output). Rationale: low priority, not reachable through normal usage — worth a coverage-gap test only if this edge case is ever considered in-scope for a future plan.
- **Source: WP-005 (QA) — deferred, low priority.** Originating agent: QA. No test asserts the message wording for a managed package removed outright during DEV (currentSection === null falls to legacy non-move wording). Low priority; behavior is correct, just untested as a named scenario.
- **Source: WP-005 (QA) — deferred, low priority.** Originating agent: QA. No test names the simultaneous move+value-edit combination as an explicit scenario (handled correctly today by `isMove()`, which checks section only). Low priority; worth a named regression test in a future cycle.
- **Source: project-level — traceability gap, already resolved in the working tree.** Originating agent: Documentation. A concurrent Documentation agent fixed `file-tree.md`'s `TestStateValueObjects.php` entry (missing the three new `ManagedEntryOverride` test names) after WP-006 was already COMPLETE, so the fix could not be declared as a pipeline artifact. The file change itself is present and correct; no further action needed beyond awareness that this edit exists outside the formal pipeline record.

### Next Steps

- No code follow-up is required to ship v3.3.0 — the exit gate is clean and all acceptance criteria across all 6 WPs are met.
- If a future plan touches `DevConfigTransformer`'s override detection again, consider picking up the two QA-flagged coverage gaps (outright-removal wording, named move+edit test) and the malformed-both-sections edge case as a small, low-risk test-hardening WP.
- The release itself (tagging/publishing v3.3.0) is a Human Action outside this plan's scope — the Planner/Project Manager should confirm the release has been cut before starting the next cycle.
- The real-transcript exit-gate convention is now codified in `constraints.md` — the Planner should reference it explicitly in any future plan that touches console/transcript output, rather than re-deriving the rule.
