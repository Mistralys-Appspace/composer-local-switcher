# Plan

## Plan Audit Cycles
- Audits: 3 (Sonnet 5.5 ×3) — Plan Auditor v1.12.0
- Architectural Reviews: 1 (Sonnet 5.5 ×1) — Plan Architect Reviewer v2.3.4

## Prior Project Context
This is a rework of `2026-10-08-switch-output-and-version-guard` (released as v3.1.0, all stages passed). Its synthesis (`docs/agents/plans/2026-10-08-switch-output-and-version-guard/synthesis.md`) records one functional gap and a set of small cleanups.

- **Functional gap.** Real (non-preview) switches still print the dry-run preview's header and "Would run:", and `OutcomeRenderer::renderBlocked()` has no caller in `src/`. The plan's target layout ("switch PROD → DEV", "Next:", a "blocked, nothing was changed" header) was never reached. Insight `33a6df5a` records why no stage caught it: no acceptance criterion ran a real switch against the planned sample. This plan adds that criterion at both test tiers.
- **Cleanups.** Test double `writeStyled()` capture, a `ConfigSwitcher::getProjectRoot()` helper, a shared `@phpstan-type` alias, a stale docblock, the duplicated legacy "Running `composer …`" line, README/data-flows notes, and five coverage gaps.
- No strategic vision is configured for the repository.

## Knowledge Base Reconciliation

| Insight ID | Title | What the plan overtakes | Executed by |
|------------|-------|-------------------------|-------------|
| 33a6df5a-e93d-41a3-b64d-766d33e0fe2e | Real-switch output reuses the preview outcome (preview header, "Would run:"); renderBlocked() is unused | Steps 1–2 fix the defect: real switches render the plan with `isPreview=false` and `renderBlocked()` has callers. The insight's claim that both are still broken no longer holds. Retire it or rewrite it as resolved, keeping the "real-switch AC" lesson. | Ledger Knowledge Curator v1.4.2 (Targeted Reconciliation) |
| 9876bcd9-f654-4f73-ac27-a33a7deba560 | Assert on message codes (hasWrittenMessageCode), not output substrings; ScriptedEventContext::writeStyled() bypasses capture | Step 6 adds the `writeStyled()` override, so the claim that the test double bypasses capture no longer holds. Keep the `hasWrittenMessageCode()` and `<warning>`-tag parts. | Ledger Knowledge Curator v1.4.2 (Targeted Reconciliation) |

## Summary
Make the console output of a real `switch-dev` / `switch-prod` / `switch-update` match the design the v3.1.0 plan promised, and close the low-priority loose ends recorded in that project's synthesis. A real switch will open with `Composer Local Switcher — switch PROD → DEV` (no preview wording), label the planned command `Next:`, and a blocked switch will render through `renderBlocked()` (`… blocked, nothing was changed`). The legacy duplicate "Running `composer …`" line goes away, a switch that needs no Composer command still closes with a "done" footer, and the housekeeping items (test-double capture, `getProjectRoot()`, a shared `@phpstan-type`, stale docblock, coverage gaps, doc notes) are done in the same pass. Output tests now include a real-switch transcript check at Tier 1 and Tier 2. Source: the synthesis of `2026-10-08-switch-output-and-version-guard`; this plan addresses its actionable items.

## Architectural Context
- `src/Utils/SwitchCommandRunner.php` is the only component that prompts and executes Composer. `runSwitch()` (L91–L139) previews twice (`previewSwitch()`), renders the **first** preview with `OutcomeRenderer::render()` (L101), confirms, then runs the real `switchTo()` and `runCommand()`. `runPreview()` (L161–L166) also uses `render()`.
- `src/Utils/OutcomeRenderer.php` is a stateless renderer. `render()` is a wrapper that passes `$outcome->isDryRun()` as the preview flag. Because every outcome the runner pre-renders is a dry run, a real switch always shows the preview wording. `renderPlan($outcome, bool $isPreview)` and `renderBlocked()` already implement the right layouts.
- `src/State/SwitchOutcome.php` carries the target mode but not the mode being left; `ConfigSwitcher::switchTo()` (`src/ConfigSwitcher.php` L931) builds it at two sites and saves the new state before the second.
- `tests/TestClasses/ScriptedEventContext.php` is the Tier 1 double; Tier 2 runs real Composer against `tests/assets/integration-project` (`tests/IntegrationSuites/TestEntryPoints.php`).
- The project manifest (`docs/agents/project-manifest/`) must be updated alongside code (`AGENTS.md` §2).

## Approach / Architecture
1. **Render the right moment.** The runner renders the first (planning) outcome with `renderPlan($first, false)` for a real switch and `renderBlocked($first)` when it is blocked. `runPreview()` uses `renderPlan($outcome, true)` and `renderBlocked()` likewise. A small private runner method (`renderPlanned(SwitchOutcome $outcome, bool $isPreview)`) holds that branch once, so the two entry points cannot drift.
2. **Name the transition.** `SwitchOutcome` gains an optional trailing `?string $previousMode = null` and `getPreviousMode()`. `switchTo()` reads `statusFile->getMode() ?? MODE_INITIAL` before planning and passes it to both constructions. The renderer builds one transition label from it (`PROD → DEV`; `initial` displays as `PROD`, mapped via the `ConfigSwitcher::MODE_INITIAL`/`MODE_PROD` constants rather than string literals, since the first switch always starts from the PROD state; same mode displays as `refresh DEV`; unknown previous mode falls back to today's `to DEV` wording) and uses it in the plan, preview and blocked headers. The `done` footer keeps its wording.
3. **Complete the frame.** One footer rule for every real switch that applied its changes without opening a `running` frame: `runCommand()` calls `renderDone()` both when the outcome needs no Composer command and after the `--no-install` skipped notice, so those switches still end framed. The nested-run skip (`emitNestedRunSkipped()`) applied nothing and prints no footer. The legacy `MESSAGE_COMPOSER_COMMAND` line is dropped, since `renderCommandStart()` already prints the command and reason.
4. **Housekeeping inside the touched files**: `ConfigSwitcher::getProjectRoot()`, a shared message-shape `@phpstan-type`, the stale docblock, the test double's `writeStyled()` override.
5. **Verify against the real output**: a Tier 1 transcript test and a Tier 2 transcript test, both built from the sample in this plan (see AC-01, AC-02).

Target output after the change (the structure is the contract; exact wording is the implementer's choice):

```text
Composer Local Switcher — switch PROD → DEV
composer.json changes (1):
  local path `../package-name` for `vendor/package-name` (symlinked, as version `1.4.2` from `options.versions`)
Next: composer update vendor/package-name (Installing the switched local packages at their aliased versions.)
Apply these changes and run `composer update vendor/package-name`? [yes]
Composer Local Switcher — running `composer update vendor/package-name`
  Installing the switched local packages at their aliased versions.
...Composer's own output...
Composer Local Switcher — switch to DEV done
```

Blocked:

```text
Composer Local Switcher — switch PROD → DEV blocked, nothing was changed
  ! WARNING: The version override [1.0.4] for [vendor/package-name] does not satisfy ...
ERROR: The switch is blocked by a precondition (see the messages above).
```

## Rationale
The defect is one wrong flag at one call site, but the cause is a wrapper (`render()`) that equates "dry-run outcome" with "preview". Fixing the call site while leaving the wrapper as the runner's rendering path would let the same bug return, so the runner uses the explicit moment methods the renderer already has. The previous-mode field is the only way to show the promised `PROD → DEV` without having the renderer read the status file, which would break its statelessness. The legacy "Running" line is removed now because its sole reason to exist was one test assertion on the preview wording, and that assertion is rewritten here anyway. Housekeeping items are included because each touches a file this plan already changes.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| How the runner renders a real switch | Runner calls `renderPlan($first, false)` / `renderBlocked()` explicitly | (a) Change `render()` to infer "real" from context; (b) re-render the outcome from the real `switchTo()` after confirmation | (a) hides the same dry-run/preview conflation; (b) prints the plan after the prompt, which defeats showing the change set before confirmation. Explicit moment methods match the renderer's stateless design. |
| Source mode in the header | Optional `previousMode` on `SwitchOutcome` | (a) Renderer reads `StatusFile`; (b) runner passes a label string to the renderer; (c) drop the `PROD →` part | (a) makes the renderer stateful and needs file access; (b) spreads header wording into the runner; (c) leaves the planned target unmet. The additive nullable field keeps old constructions valid. |
| `OutcomeRenderer::render()` | Kept, marked `@deprecated` (not used by the runner; the explicit moment methods are preferred) | Remove it | Removing a public method right after a minor release is a breaking change with little gain once the runner stops using it. `@deprecated` over prose-only documentation because the wrapper is the documented trap behind this defect. |
| Legacy `MESSAGE_COMPOSER_COMMAND` line | Stop emitting; keep the constant, marked as no longer emitted | Keep the line; delete the constant | Keeping duplicates the command and reason on two adjacent lines. Deleting a public constant in a minor release breaks consumers that referenced it. |

## Pattern Alignment
- Stateless renderer with the caller choosing the moment — follows `src/Utils/OutcomeRenderer.php`'s own docblock contract.
- Tests assert via `ScriptedEventContext::hasWrittenMessageCode()` for codes and `hasWrittenLineContaining()` for layout — `tests/TestClasses/ScriptedEventContext.php`.
- Additive, defaulted constructor argument on a value object — follows `SwitchOutcome`'s existing optional `$composerCommand`/`$blocked`/`$configChanges` arguments (`src/State/SwitchOutcome.php` L33–L43).
- Manifest updates in the same change — `AGENTS.md` §2.
- **Departure:** first use of `@phpstan-type`/`@phpstan-import-type` in `src/` (none exists today). Justified: two array-shape PHPDocs describe the same message shape, and an alias gives one definition; PHPStan already runs over `src/`.

## Structural Improvements

| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `src/Utils/SwitchCommandRunner.php::runSwitch()`/`runPreview()` | Both call the dry-run-equating `render()`; blocked outcomes are rendered as plans | Promoted to step 1 | This is the defect itself. |
| `src/Utils/OutcomeRenderer.php::render()` | Wrapper conflates dry run with preview | Rejected (removal) | Public method of a released class; kept and marked `@deprecated` in step 1. |
| `src/State/SwitchOutcome.php` | No record of the mode being left | Promoted to step 2 | Needed for the promised transition header without a stateful renderer. |
| `src/Utils/SwitchCommandRunner.php::runCommand()` | Legacy "Running" line duplicates `renderCommandStart()`; no frame when no command is needed | Promoted to step 3 | Same output goal; inside the method already being edited. |
| `src/ConfigSwitcher.php` L445, L483; `SwitchCommandRunner::getWorkingDir()` L383 | Three copies of the project-root derivation | Promoted to step 5 | All three are in files this plan edits. |
| `src/State/SwitchMessage.php` / `SwitchOutcome.php` `toArray()` PHPDoc | Message shape declared twice | Promoted to step 5 | `SwitchOutcome.php` is edited by step 2. |
| `tests/TestClasses/ScriptedEventContext.php` | `writeStyled()` bypasses capture | Promoted to step 6 | The new real-switch transcript tests need styled lines captured. |
| `src/Utils/SwitchCommandRunner.php` L337–L350 docblock | References removed `OutcomeRenderer::formatChange()` | Promoted to step 3 | Same method area. |
| Warning messages' literal `WARNING:` prefix combined with the renderer's `!` marker | Renders as `! WARNING: …` | Rejected | The prefix is still relied on by the legacy echo channel and by `describe()` warnings; removing it spans seven call sites in `ConfigSwitcher.php` and their tests, outside this plan's blast radius. Recorded under Deferred Items. |
| `DevConfigTransformer::revertSection()` moved-package double override | May report a moved package as an override in both sections | Rejected (behaviour change) | Pinned with a characterisation test and documented (steps 7, 8); a behavioural fix belongs in its own plan. |

## Detailed Steps
1. **Render the correct moment in the runner** (`src/Utils/SwitchCommandRunner.php`).
   - Add private `renderPlanned(SwitchOutcome $outcome, bool $isPreview) : void`: `renderBlocked($outcome)` when `$outcome->isBlocked()`, otherwise `renderPlan($outcome, $isPreview)`.
   - `runSwitch()` replaces `$this->renderer->render($first)` with `renderPlanned($first, false)`; the blocked throw stays.
   - `runPreview()` uses `renderPlanned($outcome, true)`.
   - Update the class and `runSwitch()` docblocks to state that step 1 renders the planning outcome as a real switch.
   - Mark `OutcomeRenderer::render()` `@deprecated` in its docblock: it equates dry run with preview, the runner does not use it, and consumers should call `renderPlan()`/`renderBlocked()` with an explicit moment.
2. **Transition header.**
   - `src/State/SwitchOutcome.php`: append `private readonly ?string $previousMode = null`, add `getPreviousMode() : ?string`, include `previousMode` in `toArray()` (update its shape doc). `hasSameEffectsAs()` is unchanged.
   - `src/ConfigSwitcher.php::switchTo()`: read `$previousMode = $this->statusFile->getMode() ?? self::MODE_INITIAL` before `switch_plan()` and pass it to both `new SwitchOutcome(…)` constructions (L954, L971).
   - `src/Utils/OutcomeRenderer.php`: add one private `describeTransition(SwitchOutcome $outcome) : string` (`PROD → DEV`; `initial` displays as `PROD`, via `ConfigSwitcher::MODE_INITIAL`/`MODE_PROD`, with a docblock stating that the first switch starts from the PROD state; equal modes → `refresh DEV`; null previous mode → `to DEV`). Use it in `renderPlan()` (real: `switch <transition>`; preview: `preview of switch <transition> (nothing will be changed)`) and `renderBlocked()` (`switch <transition> blocked, nothing was changed`).
   - Operation lines at `-v`: `renderPlan()` lists operations through `formatOperation()` (L293–L305), which hardcodes `would <type>: …`. Pass the `$isPreview` flag into `formatOperation()` so a real switch prints `will <type>: …` and a preview keeps `would <type>: …`, so a real switch never reads as a dry run. `formatOperation()` is public on a released class, so the parameter is added as a trailing defaulted `bool $isPreview = true`, which keeps existing callers and their `would` wording unchanged; `renderPlan()` passes its own flag explicitly.
3. **Complete the frame and drop the duplicate** (`src/Utils/SwitchCommandRunner.php::runCommand()`).
   - Footer rule (single, for all "no `running` frame" paths): when `getComposerCommand()` is `null`, and after the `--no-install` skipped notice, call `renderDone(strtoupper($outcome->getMode()))` before returning. `emitNestedRunSkipped()` is unchanged and prints no footer. Document the rule in the `runCommand()` docblock.
   - Remove the `MESSAGE_COMPOSER_COMMAND` `writeMessage()` call and its docblock paragraph. In `src/ConfigSwitcher.php` L184 mark `MESSAGE_COMPOSER_COMMAND` as no longer emitted (kept so consumers referencing it still compile).
   - Fix the `hasPermanentOrDiscardedChanges()` docblock: reference `OutcomeRenderer`'s `[permanent]`/`[discarded]` markers instead of the removed `formatChange()`.
4. **Re-point existing tests to the new wording.**
   - `tests/TestSuites/TestConfirmation.php::test_yesStillPrintsChanges()`: assert `Next: composer` and the framed `running \`composer` line instead of `Would run:` and `Running \`composer`; rewrite its comment.
   - `tests/TestSuites/TestOutcomeRenderer.php` (L46, L56–L57, L152/L157): headers now carry the transition when `previousMode` is set; keep the null-previous-mode fallback assertions.
   - `tests/IntegrationSuites/TestEntryPoints.php` L231–L245: the header substring becomes `Composer Local Switcher — switch PROD → DEV`; the occurrence-count assertion counts that header (the `… done` footer no longer shares the substring).
   - `tests/TestSuites/TestEntryPointEdge.php` L177 (`… switch to DEV done`) is unchanged by design.
   - `tests/TestSuites/TestEntryPointEdge.php::test_commandShownWithoutQuotes` (L145–L162): it finds a line containing "Running `composer" and asserts it is non-null, which the removed legacy line satisfied. Re-point it at the framed `running \`composer` header or the `Next:` line; keep its no-quotes and `composer update` assertions.
   - `tests/TestSuites/TestOutcomeRenderer.php::test_renderPlanShowsFileOperationsAndCodedDetailMessagesWhenVerbose` (L130–L138): it calls `renderPlan($outcome, false)` and asserts `would write`; change the assertion to `will write` (the preview variant is covered by the new `test_verboseOperationLinesUseWillForRealSwitch`).
   - `tests/IntegrationSuites/TestEntryPoints.php` L272–L275: the real `switch-dev -v` assertion `containsOutput('would ')` becomes `will `.
   - Grep `tests/` for any other `[<code>]` substring assertions (insight `9876bcd9`), for `MESSAGE_COMPOSER_COMMAND`, for the literal "Running `composer" substring, and for `would ` (real-switch renders that asserted dry-run wording), and repoint them to `hasWrittenMessageCode()` or the new wording.
5. **Housekeeping in touched files.**
   - Add `public function getProjectRoot() : string` to `ConfigSwitcher` (`dirname($this->getMainFile()->getPath())`), and replace the three copies (`ConfigSwitcher.php` L445, L483; `SwitchCommandRunner::getWorkingDir()`).
   - Declare `@phpstan-type SwitchMessageShape array{code:int,text:string,level:string}` on `SwitchMessage`, use it in `SwitchMessage::toArray()`, and `@phpstan-import-type` it in `SwitchOutcome::toArray()`.
6. **Test double.** `tests/TestClasses/ScriptedEventContext.php`: override `writeStyled(string $line)` to record the tag-stripped line into the same captured-lines list as `write()` (reusing the base class's tag-stripping; if it is private, widen it to `protected` in `src/Utils/EventContext.php` rather than duplicating the regex). Update the class docblock.
7. **New tests** (see Test Plan): real-switch transcript, blocked transcript, no-command footer, `--no-install` render, preview unchanged, `writeStyled()` capture, `getProjectRoot()`, previous-mode plumbing, and the coverage gaps from the synthesis.
8. **Documentation** (see Documentation Updates): README and `docs/usage.md` samples replaced with the real output (remove the sentence explaining that the first block is a dry-run preview), manifest, data-flows notes, changelog v3.2.0.
9. **Final gate.** `composer test`, `composer test-integration` and `composer analyze` all green; diff the README/usage samples against a captured real Tier 2 transcript before finishing (AC-02).

## Dependencies
- Step 2 before steps 1's header assertions (step 4) can pass; steps 1–3 before step 4 and step 7.
- Step 6 before the transcript tests in step 7.
- Work packages should order the output fix (steps 1–4 and their tests) ahead of the housekeeping (steps 5–6), so it forms a cheap rollback point if the housekeeping stalls.
- Steps 1–7 before step 8 (docs describe shipped output); step 9 last.

## Required Components
- Existing: `src/Utils/SwitchCommandRunner.php`, `src/Utils/OutcomeRenderer.php`, `src/Utils/EventContext.php` (only if the tag-stripper needs widening), `src/State/SwitchOutcome.php`, `src/State/SwitchMessage.php`, `src/ConfigSwitcher.php`, `tests/TestClasses/ScriptedEventContext.php`, `tests/TestSuites/TestConfirmation.php`, `tests/TestSuites/TestOutcomeRenderer.php`, `tests/TestSuites/TestEntryPointEdge.php`, `tests/TestSuites/TestEventContext.php`, `tests/TestSuites/TestDevConfigTransformer.php`, `tests/IntegrationSuites/TestEntryPoints.php`.
- No new source files.

## Assumptions
- `OutcomeRenderer::render()` has no consumers outside this repository that depend on the preview wording for real switches (the library's documented entry points are Composer script targets).
- Composer's IO prints `→` and `—` correctly (the existing header already uses `—`).
- The release (tag/publish) of v3.1.0 and the new minor version is the user's, see Human Actions.

## Constraints
- PHP >=8.4, `declare(strict_types=1)`, PHPStan 0 errors over `src/` and `tests/`.
- Entry-point bodies remain one-line delegations (source-scan test).
- Message codes stay in the `1822xx` scheme; no new code is added.

## Out of Scope
- Removing (as opposed to deprecating) `OutcomeRenderer::render()` or the `MESSAGE_COMPOSER_COMMAND` constant.
- Changing blocked-message wording (including the `WARNING:` prefix) or the `1822xx` codes.
- Fixing the moved-package double-override behaviour (characterised and documented only).
- Rendering the derivation source of a path-repository alias instead of the literal `options.versions` key (needs plumbing from `DevConfigTransformer`).
- Any release action.

## Human Actions

| # | Action | When | Why an agent cannot do it |
|---|--------|------|---------------------------|
| 1 | Tag and publish the release (v3.1.0 if still untagged, then the v3.2.0 minor version this plan prepares in the changelog), per the project's release process | After the run | Releases, tags and publishing are the user's; the run only prepares the changelog entry and version fields. |

## Acceptance Criteria

- AC-01: A real `switch-dev`/`switch-prod`/`switch-update` prints a header naming the transition (`switch PROD → DEV`; `refresh DEV` for a refresh), never contains "preview" or "nothing will be changed", labels the planned command `Next:`, and (when a command runs) closes with a framed `done` footer. A switch needing no Composer command, and a `--no-install` switch, also end with the footer; the nested-run skip prints none.
- AC-02: A real `switch-dev` against `tests/assets/integration-project` (Tier 2) produces output matching this plan's target layout structurally, and the README/`docs/usage.md` samples are copied from (or diffed against) that real output.
- AC-03: A blocked real switch renders through `renderBlocked()` (header `… blocked, nothing was changed`, the explaining message), still throws `ERROR_SWITCH_BLOCKED`, and exits non-zero; no preview wording appears.
- AC-04: `switch-preview-dev`/`-prod` still print the preview header (`preview of switch … (nothing will be changed)`) and `Would run:`.
- AC-05: The runner no longer writes the legacy `MESSAGE_COMPOSER_COMMAND` line; the command and its reason appear once, in the `running` frame.
- AC-06: A `SwitchOutcome` built without a previous mode renders the old `switch to DEV` wording (no regression for library consumers); one built by `ConfigSwitcher::switchTo()` always carries the previous mode (`initial` when no status exists).
- AC-07: `ScriptedEventContext::writeStyled()` lines are captured and visible to `hasWrittenLineContaining()`.
- AC-08: `ConfigSwitcher::getProjectRoot()` exists and replaces all three copies of the derivation; `SwitchMessage`'s array shape is defined once as a `@phpstan-type` and imported where reused.
- AC-09: The stale `formatChange()` docblock reference is gone.
- AC-10: Each coverage gap in the synthesis has a test: path repository with missing/empty `options.versions`; a verbose Detail message with code 0; the no-command runner path; false-return for `composerSwitchProd()` and `composerSwitchUpdate()`; the moved-package double-override pinned.
- AC-11: README, `docs/usage.md`, `docs/api.md`, the project manifest and `changelog.md` reflect the shipped behaviour (see Documentation Updates); `composer test`, `composer test-integration` and `composer analyze` pass.

## Testing Strategy
Tier 1 (offline, `ScriptedEventContext`) pins the renderer and runner behaviour, including full-transcript checks for a real switch, a blocked switch and a preview. Tier 2 runs the real `switch-dev` through Composer and compares the structure of the printed transcript with the planned sample, which is the check the original project lacked (insight `33a6df5a`). Existing assertions that encoded the preview wording for real switches are rewritten, not deleted.

## Test Plan
- `tests/TestSuites/TestConfirmation.php::test_realSwitchRendersPlanWithoutPreviewWording` (new) — `runSwitch(DEV)` with `--yes`: header `switch PROD → DEV`, `Next: composer`, no `preview`, no `nothing will be changed`, no `Would run` — AC-01, AC-05.
- `tests/TestSuites/TestConfirmation.php::test_yesStillPrintsChanges` (modified) — asserts `Next: composer` and the framed `running \`composer` line — AC-01, AC-05.
- `tests/TestSuites/TestConfirmation.php::test_commandAndReasonAppearOnce` (new) — the planned command's reason text appears in exactly one `running` frame, and `hasWrittenMessageCode(MESSAGE_COMPOSER_COMMAND)` is false — AC-05.
- `tests/TestSuites/TestEntryPointEdge.php::test_blockedRealSwitchRendersBlockedHeader` (new; placed beside `test_blockedSwitchThrowsSwitchBlocked` and reusing its blocked-switch fixture, the corrupted main lock content hash) — output has `blocked, nothing was changed`, no preview wording, and the call throws `ERROR_SWITCH_BLOCKED` — AC-03.
- `tests/TestSuites/TestEntryPointEdge.php::test_commandShownWithoutQuotes` (modified) — asserts against the framed `running \`composer` header or `Next:` line instead of the removed legacy line; no-quotes and `composer update` assertions kept — AC-05.
- `tests/TestSuites/TestOutcomeRenderer.php::test_renderPlanShowsFileOperationsAndCodedDetailMessagesWhenVerbose` (modified) — real-plan assertion `would write` becomes `will write` — AC-01.
- `tests/IntegrationSuites/TestEntryPoints.php` real `switch-dev -v` test (L272–L275, modified) — asserts `will ` instead of `would ` — AC-01.
- `tests/TestSuites/TestOutcomeRenderer.php::test_verboseOperationLinesUseWillForRealSwitch` (new) — at `-v`, a real plan lists `will <type>:` and a preview lists `would <type>:` — AC-01, AC-04.
- `tests/TestSuites/TestEntryPointEdge.php::test_nestedRunSkipPrintsNoFooter` (new) — the nested-run skip notice appears with no `done` footer — AC-01.
- `tests/TestSuites/TestConfirmation.php::test_switchWithNoComposerCommandEndsWithDoneFooter` (new) — covers `runCommand()`'s `null`-command path — AC-01, AC-10.
- `tests/TestSuites/TestConfirmation.php::test_noInstallStillRendersNextLine` (new) — `--no-install` prints `Next:`, the skipped notice and the `done` footer, no `running` frame — AC-01.
- `tests/TestSuites/TestEntryPointEdge.php::test_previewEntryPointsKeepPreviewWording` (new) — preview via the runner prints the preview header and `Would run:` — AC-04.
- `tests/TestSuites/TestEntryPointEdge.php::test_composerSwitchProdAndUpdateReturnFalseOnExplainedFailure` (new) — false-return path for `composerSwitchProd()` and `composerSwitchUpdate()` — AC-10.
- `tests/TestSuites/TestOutcomeRenderer.php` — modified/new cases: header carries the transition for a real, preview and blocked outcome; `initial` displays as `PROD`; same mode displays `refresh DEV`; null previous mode keeps `switch to DEV` — AC-01, AC-03, AC-04, AC-06.
- `tests/TestSuites/TestOutcomeRenderer.php::test_detailMessageWithCodeZeroAtVerbose` (new) — a `Detail` message with code 0 renders without a `[0]` prefix at `-v` — AC-10.
- `tests/TestSuites/TestStateValueObjects.php` — `SwitchOutcome::getPreviousMode()` default `null`, round-trips through `toArray()` — AC-06.
- `tests/TestSuites/TestSwitching.php::test_switchToRecordsPreviousMode` (new) — `switchTo()` outcome carries `initial` first, then `prod`/`dev` on later switches — AC-06.
- `tests/TestSuites/TestSwitching.php::test_getProjectRootMatchesMainFileDirectory` (new) — AC-08.
- `tests/TestSuites/TestEventContext.php` or `TestOutcomeRenderer.php::test_scriptedContextCapturesStyledLines` (new) — `writeStyled('<warning>x</warning>')` is captured — AC-07.
- `tests/TestSuites/TestOutcomeRenderer.php` or `TestConfigChangeFormatter.php::test_pathRepositoryWithMissingOptionsVersions` (new) — a path repository with missing/empty `options.versions` renders without error — AC-10.
- `tests/TestSuites/TestDevConfigTransformer.php::test_movedPackageOnRevertIsReportedInBothSections` (new, characterisation) — pins the current override list for a package moved between `require`/`require-dev` — AC-10.
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchOutputIsFramedAndNotDuplicated` (modified) — transition header exactly once, no `preview`/`Would run`, `Next:` present, footer present — AC-01, AC-02.
- `tests/IntegrationSuites/TestEntryPoints.php::test_realSwitchTranscriptMatchesPlannedLayout` (new) — real `switch-dev` output lines appear in the planned order (header, change set, `Next:`, running frame, footer) — AC-02.
- `tests/IntegrationSuites/TestEntryPoints.php::test_blockedStalePinTranscript` (new) — stale-pin switch exits non-zero, prints the blocked header, and no preview wording — AC-03.
- Static analysis: `composer analyze` clean, including the `@phpstan-type` alias — AC-08, AC-11.

## Documentation Updates
- `README.md` — replace the real-switch sample and the blocked sample (L~64–L~95) with the real transcripts from step 9; delete the "first block is the dry-run preview" explanation; add one sentence that an explained failure exits non-zero with the reason printed (no stack trace).
- `docs/usage.md` — same samples (L43–L161); keep the preview section's `Would run:` description accurate; add the non-zero-exit note.
- `docs/api.md` — `SwitchOutcome::getPreviousMode()`, `ConfigSwitcher::getProjectRoot()`, the `render()` `@deprecated` note, `MESSAGE_COMPOSER_COMMAND` no longer emitted, `render()` deprecated. Also `OutcomeRenderer::formatOperation()`: new signature `formatOperation(FileOperation $operation, bool $isPreview = true): string`, and the wording change (`will <type>: …` for a real switch, `would <type>: …` for a preview, which remains the default).
- `docs/agents/project-manifest/api-surface.md` — new/changed signatures (`SwitchOutcome` constructor + `getPreviousMode()`, `getProjectRoot()`, `MESSAGE_COMPOSER_COMMAND` note at L29 and L40; and the `formatOperation()` entry at L557 and L560: update the signature to `formatOperation(FileOperation $operation, bool $isPreview = true): string` and the description from "renders a single operation as `would <type>: ...`" to the `will`/`would` wording by `$isPreview`).
- `docs/agents/project-manifest/data-flows.md` — L~291 (runner output moments: real switch renders the planning outcome as a real plan or `renderBlocked()`; the `MESSAGE_COMPOSER_COMMAND` mention removed; no-command footer); a note on the moved-package double-override on revert.
- `docs/agents/project-manifest/constraints.md` — the `MESSAGE_COMPOSER_COMMAND` mention in the message-code paragraph (L11); the test-double rule that `writeStyled()` is captured.
- `docs/agents/project-manifest/switching-decision-table.md` — only if its output column cites the preview header or `Would run:` for real switches (verify, then adjust).
- `docs/agents/project-manifest/file-tree.md` — no new files; update only if test files are added.
- `changelog.md` — new `v3.2.0 - Switch output matches the plan (Minor)` entry in the existing style: real switches no longer print preview wording, header names the transition, blocked switches use the blocked header, duplicate "Running" line removed, `getPreviousMode()`/`getProjectRoot()` added, `render()` deprecated.
- Manifest/`composer.json` version fields — bump to the new minor version (v3.2.0: public API is added, so semver calls for a minor) wherever v3.1.0 is currently recorded (manifest `README.md`; the release itself is a Human Action).

## Deferred Items

| # | Deferred Item | Origin | Reason Deferred | Notes |
|---|---------------|--------|-----------------|-------|
| 1 | Path-repository alias source rendered as the literal `options.versions` instead of the derivation source | Synthesis, WP-005 (Out-of-scope, low) | Needs source plumbing from `DevConfigTransformer::deriveAlias()` through the formatter; outside this plan's blast radius. | Revisit if the sample text confuses users. |
| 2 | `! WARNING:` double marker on Warning-level messages | Observed in the shipped blocked sample | The `WARNING:` text prefix is shared with the legacy echo channel and `describe()` warnings across seven call sites. | Fix together with any future change to the legacy echo channel. |
| 3 | Behavioural fix for the moved-package double override on revert | Synthesis, WP-008 (Developer, medium) | Only characterised and documented here. | Own plan against `DevConfigTransformer::revertSection()`. |
| 4 | Removal of `OutcomeRenderer::render()` and the `MESSAGE_COMPOSER_COMMAND` constant | This plan's decisions | Breaking API removal; wait for a major version. | Reconsider at the next breaking release. |

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **Header change breaks assertions elsewhere that match the `switch to DEV` substring** | Step 4 names the known breakages (including the two `would` assertions) and greps `tests/` for the substring, `would `, and `[<code>]`/`MESSAGE_COMPOSER_COMMAND` assertions; the null-previous-mode fallback keeps old wording for direct renderer tests. |
| **Real-switch render of a dry-run outcome shows dry-run-only text at `-v`** (the Detail message "Dry run: no files will actually be changed." and the per-operation `would <type>:` lines from `formatOperation()`) | Operation lines are reworded to `will <type>:` for a real switch (step 2, `test_verboseOperationLinesUseWillForRealSwitch`). The Detail message is accurate for the planning phase; the Tier 2 transcript test records the `-v` output once to confirm both the Detail message and the operation lines are not misleading, otherwise the message is filtered in `renderPlanned()`. |
| **`→` renders badly in some terminals** | The existing header already relies on `—`; the transition label lives in one private method, so a fallback is a one-line change. |
| **Samples in the docs drift again** | AC-02 and step 9 derive the README/usage samples from a captured real transcript, and the Tier 2 transcript test pins the structure. |

## Recommended Workflow
- **Workflow:** ledger
- **Rationale:** Eleven acceptance criteria across the runner, renderer, value object, test doubles, two test tiers and the manifest benefit from separate implementation, QA and review stages, which would also have caught the original gap.
