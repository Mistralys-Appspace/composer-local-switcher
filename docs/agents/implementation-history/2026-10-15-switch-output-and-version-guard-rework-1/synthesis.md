# Synthesis — Switch Output and Version Guard - Rework 1

### Outcome Summary

This project fixed the console output of real `switch-dev`/`switch-prod`/`switch-update` runs so they match the v3.1.0 plan's original design: real switches now render as real plans (not previews), name the mode transition (`PROD → DEV`, `refresh DEV`), end with a consistent framed `done` footer, and print the Composer command exactly once. Alongside the fix, the team closed four pre-existing coverage gaps, deduplicated project-root derivation and a shared message-shape PHPDoc, fixed a test-double gap in styled-line capture, and added Tier 2 integration transcript tests that run a real Composer switch and verify the output structurally matches the plan. All seven work packages passed implementation, QA, code review, and documentation with zero blocking findings; the plan was shipped as v3.2.0.

### Metrics

- **Work packages:** 7/7 COMPLETE, all 4 pipeline stages PASS on each (28/28 stage passes, 0 FAIL/rework cycles recorded).
- **Tests:** Full regression suite grew from 312 (pre-plan baseline) to 332 unit tests / 1136 assertions, plus 82 Tier 2 integration tests / 467 assertions (up from an 80-test baseline) — all passing, no regressions against the captured pre-plan baseline.
- **Static analysis:** `composer analyze` clean at 86/86 files, 0 errors, throughout every WP (including the new `@phpstan-type`/`@phpstan-import-type` alias introduced in WP-004).
- **Code review:** 0 Blocking, 0 Fix-Forward, 0 Documentation-Forward findings across all 7 WPs.
- **Release:** Version bumped 3.1.0 → 3.2.0 (Minor), changelog entry added, no breaking changes or migration guide needed.

### Strategic Recommendations

- **A wrapper that conflates two concepts will keep re-introducing the same bug.** WP-001's root cause was `OutcomeRenderer::render()` equating "dry-run outcome" with "preview" — the original defect was one wrong flag, but the Developer correctly diagnosed that patching the call site without removing the ambiguous wrapper would let the bug return. The wrapper was kept (marked `@deprecated`, not removed, to avoid a breaking change) but the runner was switched to explicit moment-named methods (`renderPlanned()`, `renderBlocked()`). Future call-site bugs in this codebase should be checked for a similar "the real cause is an abstraction that conflates two states" pattern before being patched locally.
- **End-to-end transcript verification against real tool output closes a gap unit tests cannot.** WP-006 added the first Tier 2 test that runs a real Composer switch and diffs the transcript structure against the plan's promised layout — the kind of check the prior v3.1.0 project never ran, which is how the original output defect shipped unnoticed. This pattern (real-tool transcript assertions, not just mocked unit assertions) is worth keeping as a standing exit-gate practice for any future plan that changes console output.
- **Stateless-renderer discipline paid off.** Rather than letting `OutcomeRenderer` read `StatusFile` directly to get the previous mode (which would have been the easiest fix), the team added a `previousMode` field to `SwitchOutcome` instead — preserving the renderer's documented statelessness. This is a reusable precedent: when a renderer needs new context, prefer threading it through the value object over giving the renderer file/state access.

### Code Insights

**Developer**
- `src/Utils/OutcomeRenderer.php`: `render()` kept public and marked `@deprecated` rather than removed — removing a public method right after a minor release was explicitly rejected as a breaking change with little gain (WP-001).
- `src/Utils/SwitchCommandRunner.php`: Added `renderPlanned()` as a single dispatch point for both `runSwitch()`'s pre-confirmation render and `runPreview()`'s render, making the preview/real distinction explicit at the call site rather than inferred from `SwitchOutcome::isDryRun()` (WP-001). Later extended with a single footer rule (`renderDone()`) covering all three `runCommand()` exit paths — no-command, `--no-install`, and successful run (WP-003).
- `src/ConfigSwitcher.php`: `$previousMode` is read once at the top of `switchTo()`'s try block (not immediately before `saveState()` as the WP scope note suggested), verified safe by grepping every `statusFile->` call site to confirm `switch_plan()` never mutates the mode in between (WP-001).
- `src/ConfigSwitcher.php` / `SwitchCommandRunner.php`: New `getProjectRoot()` helper consolidates three named inline `dirname()` derivations; a fourth pre-existing occurrence at `ConfigSwitcher.php:1753` (`switch_installedPackages()`) was deliberately left untouched as out of this WP's literal scope (WP-004, confirmed independently by QA and Reviewer).
- `src/Utils/EventContext.php`: `stripStyleTags()` widened from `private` to `protected` (not `public`) — minimally scoped since only the `ScriptedEventContext` test double needs it (WP-005).

**QA**
- Flagged the leftover `dirname()` call at `ConfigSwitcher.php:1753` as a coverage/dedup gap outside WP-004's declared scope — not a failure, but recorded for a future cleanup pass (WP-004).
- Manually verified `writeStyled()` edge cases beyond the new unit test (mixed tags, empty string, unrecognized tag) to confirm consistency with `write()`'s existing stripping behavior (WP-005).
- Independently re-ran the plan's full exit gate (`composer test`, `composer test-integration`, `composer analyze`) against the Developer's recorded pre-plan baseline and confirmed an exact match with no new failures (WP-006).

**Reviewer**
- Confirmed the `would`→`will` wording change in `test_verboseSwitchShowsDetails` is a genuine test-correctness fix (the test drives a real switch, so `will` is the correct assertion), not an unintentional behavior change (WP-006).
- Verified `setLocalRepositoryVersionOverride()` and related integration-test helpers were reused as-is with no duplication introduced in the new blocked-transcript test (WP-006).

**Documentation**
- Discovered and fixed a stale README Quick Start assumption: a fresh-install first switch always supplies `previousMode` (`initial`, displayed as `PROD`), so the bare "to DEV" fallback wording is only reachable via a hand-built `SwitchOutcome` with no previous mode — not via any real CLI entry point. All three README examples were corrected accordingly (WP-001).
- Found and closed a documentation gap in `docs/usage.md`: the running/done example never stated that the `done` footer closes every successful switch, including the no-command and `--no-install` paths this plan made framed (WP-003).
- Added an undocumented moved-package double-override clause to `data-flows.md` §4 (DEV→PROD Carry-Back), surfacing existing `DevConfigTransformer::revert()` cross-section dedup behavior that was covered in `api-surface.md`/changelog but missing at the manifest data-flow level (WP-007).

### Deferred & Follow-Up Items

- **Source:** WP-002 (implementation notes) / plan's own Deferred Items list — **Originating agent:** Developer — **Description:** `DevConfigTransformer::revertSection()` has a known moved-package double-override limitation (a package moved between `require`/`require-dev` is reported in both override sections on revert). WP-002 added a characterization test (`test_movedPackageOnRevertIsReportedInBothSections`) that pins the current, unfixed behavior rather than fixing it. **Status:** out-of-scope for this plan, explicitly deferred to a future plan. **Priority:** low (known limitation, documented and pinned, not a regression risk).
- **Source:** WP-004 (QA pipeline comment) — **Originating agent:** QA — **Description:** A fourth `dirname($this->mainFile->getPath())` derivation remains undeduplicated at `src/ConfigSwitcher.php:1753` (`switch_installedPackages()`); WP-004's `getProjectRoot()` consolidation only covered the three named call sites per its literal scope. **Status:** out-of-scope for this plan, flagged for a future cleanup pass. **Priority:** low (cosmetic duplication, no functional impact).

### Next Steps

- Consider a small follow-up cleanup WP to route the remaining `ConfigSwitcher::switch_installedPackages()` (L1753) `dirname()` call through `getProjectRoot()` for full consistency.
- Plan a dedicated fix for `DevConfigTransformer::revertSection()`'s moved-package double-override behavior now that it is pinned by a characterization test — the next plan can safely change the fix without losing track of the original behavior.
- The Tier 2 real-transcript verification pattern introduced in WP-006 (`tests/IntegrationSuites/TestEntryPoints.php`) is a reusable template — the Planner should consider requiring an equivalent real-transcript exit gate for any future plan that touches console/CLI output, so this class of defect (shipped without ever running the real tool and diffing output) cannot recur unnoticed.
- The release itself (tagging/publishing v3.2.0) remains a Human Action outside this plan's scope — confirm with the user before tagging.

---

## AX Feedback
No friction encountered.
