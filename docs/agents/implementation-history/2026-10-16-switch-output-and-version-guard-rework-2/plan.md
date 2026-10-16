# Plan

## Plan Audit Cycles
- Audits: 1 (Sonnet 5.5 ×1) — Plan Auditor v1.12.0
- Architectural Reviews: 1 (Sonnet 5.5 ×1) — Plan Architect Reviewer v2.3.4

## Prior Project Context
This is the second rework of `switch-output-and-version-guard`. It follows `2026-10-15-switch-output-and-version-guard-rework-1`, which prepared v3.2.0: all 7 WPs passed every stage. Its synthesis (`docs/agents/plans/2026-10-15-switch-output-and-version-guard-rework-1/synthesis.md`) leaves three actionable follow-ups and one human action:

- **Cleanup.** One inline `dirname()` project-root derivation remains in `ConfigSwitcher::switch_installedPackages()`. WP-004's `getProjectRoot()` consolidation did not cover it.
- **Known limitation, now pinned.** `DevConfigTransformer::revertSection()` reports a package moved between `require` and `require-dev` during DEV as an override in **both** sections. `test_movedPackageOnRevertIsReportedInBothSections` pins this behaviour so the fix can land safely.
- **Process.** The Tier 2 real-transcript check (`TestEntryPoints::test_realSwitchTranscriptMatchesPlannedLayout()`) caught the class of defect that let v3.1.0 ship broken output. The synthesis recommends making that check a standing exit gate for any change to console output.
- **Release.** Tagging v3.2.0 belongs to the user.

The synthesis also gives two design lessons, and this plan applies both:
- **Conflated concepts.** When a bug comes from an abstraction that conflates two concepts, reshape the abstraction instead of patching the call site. The override bug is this case: one per-section loop both merges values and detects overrides.
- **Deprecate, don't remove.** Deprecate-and-redirect rather than remove (insight `f2d9cf92-5544-4c30-8071-9d831eb94d0c`).

No strategic vision is configured for the repository.

## Knowledge Base Reconciliation

| Insight ID | Title | What the plan overtakes | Executed by |
|------------|-------|-------------------------|-------------|
| 9fb62549-ecec-4266-83c0-49ecbfc94bc1 | DevConfigTransformer's managed/non-managed classifier has two known, deferred edge-case bugs | Bug (1), the VCS-pruning misclassification, was already fixed by `isPrunableRepositoryEntry()`. Bug (2), duplication into both sections, was already fixed by cross-section membership. Steps 4–6 remove the remaining symptom, the double-reported override. After this plan, neither claim holds and the "fix both together in a follow-up WP" instruction is complete. Retire the entry, or rewrite it as resolved. | Ledger Knowledge Curator v1.4.2 (Targeted Reconciliation) |

## Summary
This plan addresses the actionable items in `docs/agents/plans/2026-10-15-switch-output-and-version-guard-rework-1/synthesis.md`, in three parts:

1. **Revert fix.** When a managed package is moved between `require` and `require-dev` during DEV, revert reports it once, as a single override that names the move. The move is still discarded and the package restored to its snapshot section, which keeps the "managed entries always take the snapshot value" policy. Both sides of the move stay classified `[discarded]` in the change set.
   - Override detection moves out of the per-section merge loop into a per-package pass.
   - Overrides become a typed `State\ManagedEntryOverride` value object.
   - `RevertResult`'s constructor takes `ManagedEntryOverride[]` only. `RevertResult::getOverriddenManagedEntries()` is kept as a deprecated array adapter, the only legacy surface.
2. **Cleanup.** The last inline project-root derivation is routed through `ConfigSwitcher::getProjectRoot()`.
3. **Testing convention.** "Any change to console output ships with a Tier 2 real-transcript test" becomes a written convention in `constraints.md`. This plan applies it to its own new message wording.

Changelog and manifest version are prepared for v3.3.0. Releasing remains the user's.

## Architectural Context
- **`src/Utils/DevConfigTransformer.php`** — static transformer.
  - `apply()` builds the DEV config from a PROD snapshot.
  - `revert()` (L161–L259) is its three-way inverse: base = `apply(snapshot)`, current = live DEV config, target = snapshot.
  - For `require`/`require-dev`, `revert()` first computes `$managedInBase`: the managed names present in the base of *either* section. It then calls `revertSection()` (L478–L527) once per section.
  - `revertSection()` does two jobs in one loop: it merges the section (a managed package takes the snapshot value; everything else takes current), and it records an override whenever `current[$pkg] !== base[$pkg]` *in that section*.
  - Because override detection runs per section, one per-package event (a move) is evaluated twice and reported twice.
  - `makeOriginClassifier()` (L262–L326) turns the reported overrides into `"{section}|{package}"` keys. A managed `require`/`require-dev` diff path with a matching key is `ConfigChangeOrigin::Discarded`; without one it is `LocalSwitch`.
- **`src/State/RevertResult.php`** — immutable result holding `effectiveConfig` and an untyped override array shape. Constructed only by `revert()` (L258).
- **`src/ConfigSwitcher.php`**
  - `switch_planDevRefresh()` (L1279) and `switch_planDevToProd()` (L1426) both pass `getOverriddenManagedEntries()` to `switch_recordOverriddenEntries()` (L1738), which emits one `MESSAGE_MANAGED_ENTRY_OVERRIDDEN` (182221, `Info`) per entry.
  - `getProjectRoot()` (L584) derives the project root. `switch_installedPackages()` (L1751) still derives it inline.
- **`src/Utils/SwitchCommandRunner.php`** (L228, L374–L388) — any `Discarded` or `CarriedBack` change makes the confirmation prompt default to "no".
- **`src/Utils/OutcomeRenderer.php`** — renders `Info` messages at default verbosity (L367–L390), so the override text appears in a real transcript.
- **Tests**
  - Tier 1 (`tests/TestSuites/`): offline, over the `test-project` fixture.
  - Tier 2 (`tests/IntegrationSuites/`): real Composer, over `integration-project`, whose managed package is `mistralys/simple_html_dom`.

## Approach / Architecture

### 1. Typed overrides — new `src/State/ManagedEntryOverride.php`
A `final` immutable value object in the style of `src/State/SwitchMessage.php`:

- Constructor (`private readonly` promoted properties): `string $packageName`, `string $section` (the section the entry is restored to, i.e. where base has it), `mixed $snapshotValue`, `mixed $currentValue`, `?string $currentSection` (where the live DEV config has the package; `null` when DEV removed it entirely).
- `getPackageName()`, `getSection()`, `getSnapshotValue()`, `getCurrentValue()`, `getCurrentSection()`.
- `isMove() : bool` — `currentSection !== null && currentSection !== section`.
- `getAffectedSections() : string[]` — `[section]`, plus `currentSection` when it is a move. This is what the classifier consumes.
- `toArray()` — `array{section:string,packageName:string,snapshotValue:mixed,currentValue:mixed,currentSection:string|null}`, declared as `@phpstan-type ManagedEntryOverrideShape`.
- `public static function fromArray(array $data) : self` — the inverse of `toArray()`, typed with `@param ManagedEntryOverrideShape $data`. It follows the sibling factories `src/State/ComposerCommand.php` L60 and `src/State/LocalRepository.php` L90: no runtime validation beyond what the PHPStan shape and the `strict_types` constructor already enforce. As in `LocalRepository::fromArray()`'s `versionOverride` handling, the optional `currentSection` is read with `?? null` and kept only when it is a string. It does not accept or infer from the legacy four-key shape: that shape cannot express a move, and nothing in the plan needs to rebuild a value object from it.

### 2. One override per package — reshape `DevConfigTransformer::revert()`
- `revertSection()` keeps only its merge job and returns the merged section array. Its override-recording branch and its second return element are removed.
- New private static `detectManagedOverrides(array $current, array $base, array $snapshot, string[] $managedInBase) : ManagedEntryOverride[]` runs once per managed package across both sections. Each argument is a full config, with sections read through `sectionOf()`.
  - It locates the package in base: `require` first, then `require-dev`. That gives `section` and `baseValue`.
  - It locates the package in current the same way. That gives `currentSection` and `currentValue`.
  - It records an override when `currentSection !== section` or `!valuesEqual(currentValue, baseValue)`.
  - `snapshotValue` = `sectionOf($snapshot, section)[$pkg] ?? null`.
- `revert()` calls `revertSection()` for each section, then `detectManagedOverrides()` once, and builds `new RevertResult($effectiveConfig, $overrides)`.
- `makeOriginClassifier()` builds `$overriddenKeys` from `$revertResult->getOverrides()`, adding one key per `getAffectedSections()` entry. Both sides of a move stay `Discarded`, so the confirmation default ("no") does not change.

### 3. `RevertResult` API — typed constructor, legacy getter kept
- The constructor becomes `(array $effectiveConfig, array $overrides)` with `@param array<int,ManagedEntryOverride> $overrides`. It accepts value objects only. Its docblock states that `RevertResult` is produced by `DevConfigTransformer::revert()`, which is its only caller in the repository and in the tests. No legacy-array normalisation path exists, so there is no heuristic reconstruction of `currentSection`.
- New `getOverrides() : ManagedEntryOverride[]`.
- `getOverriddenManagedEntries()` is kept and marked `@deprecated` (use `getOverrides()`). It derives the legacy four-key arrays (`section`, `packageName`, `snapshotValue`, `currentValue`) from the value objects, so its documented shape is unchanged for consumers who read the result. A move now yields one legacy entry (`section` = restored section, `currentValue` = the moved value), not two.
- `hasOverriddenManagedEntries()` is unchanged in meaning.

### 4. Messages — `ConfigSwitcher::switch_recordOverriddenEntries()`
- The method takes `ManagedEntryOverride[]`. Both call sites pass `$revertResult->getOverrides()`.
- A non-move keeps today's wording exactly: `A manual edit to the managed [%s] entry for [%s] was discarded (restored to the production-aliased value).`
- A move uses a new wording under the same code 182221: `The managed entry for [%s] was moved from [%s] to [%s] during DEV; the move was discarded (restored to [%s] with the production-aliased value).` The arguments are package, section, currentSection, section.

### 5. Cleanup and convention
- `switch_installedPackages()` uses `$this->getProjectRoot()`.
- `constraints.md` §Test Conventions gains the real-transcript exit-gate rule. It names `TestEntryPoints::test_realSwitchTranscriptMatchesPlannedLayout()` as the template.

## Rationale
- **Fix the root cause.** The double report is not a de-duplication problem. Override detection is a per-package fact computed in a per-section loop. Moving detection to a per-package pass removes the cause, and it makes `revertSection()` a pure merge function, which is easier to reason about. Post-hoc merging of two half-entries would leave the conflation in place, and every future per-package attribute would hit the same trap.
- **Why a value object now.** The override record is gaining a fifth field (`currentSection`) and behaviour that two consumers need: `isMove()` for message wording, and `getAffectedSections()` for the classifier. Kept as an array, both consumers would re-derive that behaviour separately, which is the duplication rework-1 just cleaned up elsewhere. Current consumers: `ConfigSwitcher::switch_recordOverriddenEntries()` and `DevConfigTransformer::makeOriginClassifier()`.
- **Keep the move-discarding policy.** Managed entries always take the snapshot value (`constraints.md` L28). Carrying a section move back would change the carry-back policy, which is a behavioural decision outside this follow-up. The user is still told the move was discarded, now once and accurately.
- **Back-compat where consumers actually are.** Consumers *read* a `RevertResult`. They do not build one, because it is the output of `revert()`. So the deprecate-and-redirect precedent (`OutcomeRenderer::render()`) is applied to the read side: `getOverriddenManagedEntries()` stays, deprecated. The constructor is narrowed to `ManagedEntryOverride[]`. Its only caller is `revert()`, and no test constructs it (research brief). Keeping a legacy-array constructor path would need a lossy heuristic to invent `currentSection`, since the four-key shape cannot express a move. It would also need runtime validation and a new exception code for input only that path could supply. That is a fabricated-default path inside a pure value object, protecting a caller that does not plausibly exist. The constructor narrowing is called out in the changelog. The release stays Minor because the public read API is unchanged.
- **No runtime validation in `fromArray()`.** The sibling factories (`ComposerCommand::fromArray()`, `LocalRepository::fromArray()`) trust their `@phpstan-type` shape, and malformed scalars already fail as a `TypeError` in the `strict_types` constructor. With the legacy constructor path gone, `fromArray()` only ever receives `toArray()` output, so a dedicated exception code would guard input no code path produces.
- **Convention in `constraints.md`.** `constraints.md` is the document every agent reads before implementing (`AGENTS.md` Quick Start step 3), so a rule written there applies to every future plan instead of depending on a planner remembering it.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| Where to fix the double report | Separate per-package override detection from the per-section merge | (a) Post-process `revert()`'s merged list, pairing entries with `currentValue: null` / `snapshotValue: null` for the same package; (b) pass a "seen" set into `revertSection()` | Both alternatives keep override detection inside a per-section loop and patch its output. (a) relies on null-pattern heuristics that also match legitimate add/remove edits. (b) makes the second section's result depend on call order. A per-package pass states the rule directly. |
| What a pure move reports | One override, flagged as a move, with its own wording | (a) Report nothing for a pure move (value unchanged); (b) carry the move back into PROD | (a) hides that the user's edit was discarded, and the confirmation still defaults to "no" without an explanation. (b) changes the managed-entry policy and belongs in its own plan. |
| Override representation | New `State\ManagedEntryOverride` value object; legacy array adapter kept | (a) Add a fifth `currentSection` key to the array shape; (b) replace the array return type outright | (a) leaves `isMove()` and affected-section logic duplicated across two consumers. (b) breaks a public method in a minor release, contrary to the deprecate-and-redirect precedent. |
| `RevertResult` constructor compatibility | Constructor takes `ManagedEntryOverride[]` only; the deprecated getter is the sole legacy surface | (a) Accept both value objects and legacy arrays, normalised via a legacy `fromArray()`; (b) keep the old constructor shape and add an optional second argument for the value objects | (a) can only rebuild a move through a lossy heuristic ("non-null `currentValue` implies same section"), and it pulls in validation and a new exception code. All of that protects a constructor that no in-repo code or test calls, on a type that exists to be returned by `revert()`. (b) leaves two parallel sources of truth in one object. Narrowing is the cleaner typed API. The getter, which consumers do use, keeps its shape. |
| `ManagedEntryOverride::fromArray()` input handling | Unvalidated inverse of `toArray()`, trusting the `@phpstan-type` shape | (a) Validate types and throw a new `ComposerSwitcherException` code (182117); (b) validate only on a legacy-array path | Sibling factories (`ComposerCommand`, `LocalRepository`) do not validate, and the `strict_types` constructor already rejects wrong scalar types. (a) adds a public error code, two manifest entries and a test for input nothing produces. (b) has no path to attach to once the constructor is narrowed. |
| Exit-gate rule location | `constraints.md` §Test Conventions | (a) A planner-persona memory only; (b) a CI check | (a) is invisible to the Developer, QA and Reviewer roles. (b) cannot judge whether a change touches console output. A written convention reaches every role. |

## Pattern Alignment
- **Follows** immutable `final` value objects with `private readonly` promoted properties and `toArray()`: `src/State/SwitchMessage.php`.
- **Follows** the unvalidated `fromArray()` factory that trusts its PHPStan shape: `src/State/ComposerCommand.php` L60, `src/State/LocalRepository.php` L90 (optional key read with `?? null` and an `is_string()` guard).
- **Follows** the `@phpstan-type` / `@phpstan-import-type` shape alias: `src/State/SwitchMessage.php` L21, `src/State/SwitchOutcome.php` L23.
- **Follows** deprecate-and-redirect for an ambiguous public API: `src/Utils/OutcomeRenderer.php` L83 (`render()`), insight `f2d9cf92…`.
- **Follows** the message-code scheme. No new message code; 182221 is reused for the move wording, since the event ("a managed entry edit was discarded") is the same. No new exception code either.
- **Follows** deprecate-and-redirect on the read side only. The `RevertResult` constructor is narrowed rather than kept dual-shaped, because the precedent protects consumers of a public API, and `RevertResult` is consumed by reading, not by construction (see Rationale).
- **Follows** the two-tier testing split (`docs/agents/project-manifest/constraints.md` §Test Tiers). Transformer, value-object and message-code coverage go in Tier 1. Real-transcript coverage goes in Tier 2.
- **Follows** the characterization-test boundary marker (insight `76d1cee5…`). The pinned test is deliberately rewritten into the fixed expectation.
- No departures.

## Structural Improvements

| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `src/ConfigSwitcher.php` L1753 (`switch_installedPackages()`) | Last inline `dirname()` project-root derivation | Promoted to step 2 | One-line, behaviour-neutral, inside a file this plan already edits. |
| `src/Utils/DevConfigTransformer.php` `revertSection()` | Conflates section merging with override detection, which is the root cause of the double report | Promoted to step 4 | This is the fix itself, not adjacent cleanup. |
| `src/State/RevertResult.php` override array shape | Untyped shape whose consumers re-derive behaviour | Promoted to steps 3 and 5 | The shape gains a field and two behaviours in this plan; a class now avoids duplicating them. |
| `docs/agents/project-manifest/api-surface.md` L280 | Stale "Not yet called anywhere in `ConfigSwitcher`" sentence | Promoted to step 11 | Same paragraph this plan rewrites. |
| `docs/agents/project-manifest/data-flows.md` L139–L145 | "never double-overridden" is only true for `effectiveConfig` | Promoted to step 11 | Same passage this plan rewrites. |
| `WARNING:`-prefixed message texts (`ConfigSwitcher.php` ×8, `SwitchCommandRunner.php` ×1) | Render as `! WARNING:` (double marker) | Rejected | None of these texts is in this plan's blast radius. The fix changes the legacy echo channel and `describe()` output, which deserves its own plan (Deferred Items #2). |
| `src/Utils/ConfigChangeFormatter.php` L165 alias source | Renders the literal `options.versions` instead of the alias source | Rejected | Needs plumbing from `DevConfigTransformer::deriveAlias()` through the formatter. This plan touches `revert()`, not `apply()`'s alias derivation (Deferred Items #1). |

## Detailed Steps
1. **Capture the pre-plan baseline.** Run `composer test`, `composer test-integration` and `composer analyze` on the current working tree. Record pass/fail/skip counts and any pre-existing failures in `docs/agents/plans/2026-10-16-switch-output-and-version-guard-rework-2/baseline.md` (new file). This step has no dependencies and must run before any source change; step 12 compares against it.
2. **Route `switch_installedPackages()` through `getProjectRoot()`.** In `src/ConfigSwitcher.php`, replace `$projectRoot = dirname($this->mainFile->getPath());` with `$projectRoot = $this->getProjectRoot();`. Then grep `src/` for `dirname(` to confirm that no project-root derivation remains outside `getProjectRoot()`. The `ConfigChangeFormatter.php` L114 parent-directory call is expected to remain.
3. **Add `src/State/ManagedEntryOverride.php` (new).** Implement it as specified in Approach §1, with the class docblock, `@package Composer Switcher` / `@subpackage State`, and a `@phpstan-type ManagedEntryOverrideShape` alias on the class.
   - `fromArray()` is the unvalidated inverse of `toArray()`, in the style of `LocalRepository::fromArray()`. No exception code is added.
   - Run `composer dump-autoload` (classmap autoloading).
4. **Reshape override detection in `src/Utils/DevConfigTransformer.php`.**
   - Strip override recording out of `revertSection()`. Its return type becomes `array<string,mixed>` (the merged section), and its docblock is updated.
   - Add `detectManagedOverrides()` as in Approach §2.
   - Update `revert()` to call it once after the section loop and to construct `RevertResult` with `ManagedEntryOverride[]`.
   - Update the `revert()` docblock: a package moved between sections is reported once, as a move.
   - The existing `$managedInBase` computation is reused as the detector's package list.
5. **Extend `src/State/RevertResult.php`.** As in Approach §3:
   - The constructor's override parameter is typed `array<int,ManagedEntryOverride>`. Its docblock names `DevConfigTransformer::revert()` as the producer.
   - Add `getOverrides()`.
   - `getOverriddenManagedEntries()` gets `@deprecated 3.3.0 Use getOverrides()` and maps the value objects to the legacy four-key arrays. Its `@return` shape is unchanged.
   - `hasOverriddenManagedEntries()` counts the value objects.
6. **Update `DevConfigTransformer::makeOriginClassifier()`.** Build `$overriddenKeys` from `getOverrides()`, adding one key per `getAffectedSections()` entry. Update its docblock to say both sides of a moved managed package are `Discarded`.
7. **Update `src/ConfigSwitcher.php` messages.**
   - `switch_recordOverriddenEntries()` takes `ManagedEntryOverride[]` and chooses the move or non-move wording (Approach §4).
   - Both call sites (in `switch_planDevRefresh()` and `switch_planDevToProd()`) pass `$revertResult->getOverrides()`.
   - Update the `MESSAGE_MANAGED_ENTRY_OVERRIDDEN` docblock to reference `RevertResult::getOverrides()` and the move wording.
   - Grep `src/` for any remaining `getOverriddenManagedEntries()` call. None is allowed outside `RevertResult` itself.
8. **Tier 1 tests: transformer and value objects.**
   - Rewrite the characterization test as the fix, and update its docblock to state the limitation is resolved.
   - Add classifier, removal, edit-and-move and legacy-adapter tests.
   - Add a `ManagedEntryOverride` region to `tests/TestSuites/TestStateValueObjects.php`.
   - See the Test Plan for exact names and assertions.
9. **Tier 1 test: switcher message.** In `tests/TestSuites/TestSwitching.php`, add a DEV→PROD test over the `test-project` fixture that moves the managed package in the live DEV `composer.json` before switching. Assert that exactly one 182221 message is written, with the move wording and both section names. Assert through the suite's existing harness, not `ScriptedEventContext` (which `TestSwitching` does not use): count codes with the suite's private `getMessageCodes(ConfigSwitcher $switcher)` helper (`tests/TestSuites/TestSwitching.php` L896), and check the wording by filtering `ConfigSwitcher::getMessages()` (`src/ConfigSwitcher.php` L1847) for code 182221 and reading `SwitchMessage::getText()` (`src/State/SwitchMessage.php`). Where the three new tests share that filter, add a sibling private helper next to `getMessageCodes()` that returns the `SwitchMessage` objects for a given code.
10. **Tier 2 real-transcript test.** In `tests/IntegrationSuites/TestCarryBack.php`, add `test_movedManagedPackageIsRestoredWithSingleNotice()`:
    - `switchToDev()`.
    - Use `decodeJsonFile()`/`writeJsonFile()` to move `mistralys/simple_html_dom` from `require` to `require-dev` in the work copy's `composer.json`.
    - `runSwitch('switch-prod')`.
    - Assert that the switch succeeds, that the restored `composer.json` has the package in `require` and not in `require-dev`, and that the moved-wording text (`was moved from [require] to [require-dev] during DEV`) occurs exactly once in `getOutput()` (`substr_count`).
    - Assert that the change set marks the package lines `[discarded]`.
11. **Documentation and conventions.** Apply every entry in `## Documentation Updates`, including the new exit-gate convention in `constraints.md`, the changelog v3.3.0 entry, and the manifest version bump.
12. **Exit gate.**
    - Run `composer test`, `composer test-integration` and `composer analyze`, and compare against `baseline.md` from step 1. No new failures are allowed. The test counts must increase by the obligations in the Test Plan.
    - Run the Tier 2 transcript tests in `TestEntryPoints.php` and the new `TestCarryBack` test as part of `composer test-integration`. This is the real-transcript gate step 11 codifies.
    - Grep `docs/` (excluding `docs/agents/plans/` and `docs/agents/implementation-history/`) for "reported as an override in both sections" and `test_movedPackageOnRevertIsReportedInBothSections`. Both must return nothing.

## Dependencies
- Step 1 has no dependencies and runs first. Step 12 depends on step 1.
- Step 2 is independent of steps 3–10.
- Step 3 → step 5 → steps 4 and 6. `revert()` constructs `RevertResult` with the value objects, and the classifier reads `getOverrides()`.
- Step 7 depends on steps 3 and 5.
- Step 8 depends on steps 3–6. Step 9 depends on step 7. Step 10 depends on step 7.
- Step 11 depends on steps 2–10 (documents the final shapes and wording).
- Step 12 depends on all others.
- This plan builds on the uncommitted v3.2.0 working tree from rework-1 (see Assumptions).

## Required Components
- `src/State/ManagedEntryOverride.php` — **new**
- `src/State/RevertResult.php` — modified
- `src/Utils/DevConfigTransformer.php` — modified
- `src/ConfigSwitcher.php` — modified
- `tests/TestSuites/TestDevConfigTransformer.php` — modified
- `tests/TestSuites/TestStateValueObjects.php` — modified
- `tests/TestSuites/TestSwitching.php` — modified
- `tests/IntegrationSuites/TestCarryBack.php` — modified
- `docs/agents/plans/2026-10-16-switch-output-and-version-guard-rework-2/baseline.md` — **new** (step 1)
- Documentation files listed in `## Documentation Updates`

## Assumptions
- Rework-1's v3.2.0 changes are still uncommitted in the working tree (24 modified files per git status). This plan builds on that state and does not require it to be committed or tagged first.
- The Tier 2 environment (git, network, Composer binary, local-clone cache) is available. Where it is not, Tier 2 tests skip gracefully per `constraints.md` §Test Tiers, and the skip is recorded in the exit-gate result rather than treated as a pass.
- Editing the DEV `composer.json` directly to move a package is a faithful stand-in for any tool that moves it, such as `composer require --dev`. `revert()` only sees the resulting file.
- Composer resolves `mistralys/simple_html_dom` from `require-dev` during the DEV session just as well as from `require`. The test switches straight to PROD without an intermediate install, following the existing `TestCarryBack` pattern.

## Constraints
- PHP >=8.4, `declare(strict_types=1)`, classmap autoloading (`composer dump-autoload` after adding a class).
- `composer analyze` must stay clean.
- No new `1822xx` message code. 182221 is reused. No new `1821xx` exception code.
- `revert(apply(P), P, repos, lock)->getEffectiveConfig() == P` must keep holding (existing round-trip data provider).
- Tier 1 stays offline.
- Never touch `../hcp-editor` or `../mailforge`.

## Out of Scope
- Carrying a DEV-time section move of a managed package back into PROD. This is a policy change.
- Removing `RevertResult::getOverriddenManagedEntries()`, `OutcomeRenderer::render()` or `MESSAGE_COMPOSER_COMMAND`. Each is a breaking removal of a read-side API.
- The `! WARNING:` double marker and the alias-source rendering (Deferred Items).
- Any release action.

## Human Actions

| # | Action | When | Why an agent cannot do it |
|---|--------|------|---------------------------|
| 1 | Commit, tag and publish v3.2.0 (rework-1) and then v3.3.0 (this plan), or fold both changelog entries into one release if v3.2.0 was never tagged | After the run | Releases, tags and publishing are the user's decision; the run only prepares the changelog entry and the manifest version. |

## Acceptance Criteria
- AC-01: `src/ConfigSwitcher.php` contains no inline project-root derivation outside `getProjectRoot()`. `switch_installedPackages()` calls `$this->getProjectRoot()`.
- AC-02: `DevConfigTransformer::revert()` reports exactly one override for a managed package moved between `require` and `require-dev` during DEV, with `isMove() === true`, `getSection()` = the snapshot section and `getCurrentSection()` = the section it was moved to. `effectiveConfig` still restores it once, in its snapshot section.
- AC-03: An in-place edit of a managed package and a DEV-time removal of a managed package each still report exactly one override, with `isMove() === false`. An unedited DEV config reports none.
- AC-04: `makeOriginClassifier()` classifies both diff paths of a moved managed package (`require › pkg` and `require-dev › pkg`) as `ConfigChangeOrigin::Discarded`.
- AC-05: `State\ManagedEntryOverride` exists with the getters, `isMove()`, `getAffectedSections()`, `toArray()` and `fromArray()` described in the plan. `fromArray(toArray())` round-trips for an in-place edit, a removal and a move. No new `ComposerSwitcherException` code is introduced.
- AC-06: `RevertResult`'s constructor takes `ManagedEntryOverride[]`. `RevertResult::getOverrides()` returns those value objects. `getOverriddenManagedEntries()` is `@deprecated` and still returns the legacy four-key arrays.
- AC-07: A DEV→PROD (and DEV→DEV refresh) switch with a moved managed package emits exactly one `MESSAGE_MANAGED_ENTRY_OVERRIDDEN` message, worded as a move and naming both sections. Non-move overrides keep their existing wording verbatim.
- AC-08: A real `composer switch-prod -- --yes` run (Tier 2) after moving the managed package in DEV succeeds, restores the package to `require` only, and prints the move notice exactly once.
- AC-09: `docs/agents/project-manifest/constraints.md` states the real-transcript exit-gate convention for console-output changes, naming `TestEntryPoints::test_realSwitchTranscriptMatchesPlannedLayout()` as the template.
- AC-10: Every documentation artefact in `## Documentation Updates` is updated. No doc outside plan/history folders still describes the double report or names the removed characterization test.
- AC-11: `composer test`, `composer test-integration` and `composer analyze` pass with no new failures against `baseline.md`.

## Testing Strategy
- **Tier 1** covers the transformer's per-package override detection, the classifier, the value object, the `RevertResult` adapter, and the switcher-level message, all offline over in-memory configs and the `test-project` fixture.
- **Tier 2** proves the end-to-end promise against a real Composer run: the moved package is restored, and the transcript shows one move notice.
- The existing round-trip invariant data provider and the carry-back suites guard against regressions in `effectiveConfig`.
- The exit gate compares the full run against the step 1 baseline.

## Test Plan
- `tests/TestSuites/TestDevConfigTransformer.php::test_movedPackageOnRevertIsReportedOnceAsMove` (replaces `test_movedPackageOnRevertIsReportedInBothSections`) — a pure `require`→`require-dev` move yields `getOverrides()` count 1, `isMove()` true, section `require`, currentSection `require-dev`, snapshotValue `^2.0`. The docblock records that the former limitation is fixed. — AC-02
- `tests/TestSuites/TestDevConfigTransformer.php::test_movedAndEditedPackageIsReportedOnce` — move plus value change yields one override carrying both the moved section and the new value. — AC-02
- `tests/TestSuites/TestDevConfigTransformer.php::test_removedManagedPackageIsReportedOnce` — deleting the managed package from DEV yields one override with `getCurrentSection() === null`, `isMove()` false, and the snapshot value restored. — AC-03
- `tests/TestSuites/TestDevConfigTransformer.php::test_revertRestoresManagedEntriesAndReportsOverrides` (modified) — asserts through `getOverrides()` (section, package, snapshot/current values, `isMove()` false). — AC-03
- `tests/TestSuites/TestDevConfigTransformer.php::test_revertResultCarriesEffectiveConfigAndOverridesOnly` (modified) — unedited config gives empty `getOverrides()`; one in-place edit gives a count of 1. — AC-03
- `tests/TestSuites/TestDevConfigTransformer.php::test_movedPackageNotDuplicatedOnRevert` (unchanged) — `effectiveConfig` still restores the package once. — AC-02
- `tests/TestSuites/TestDevConfigTransformer.php::test_movedPackageBothPathsClassifiedDiscarded` — `ConfigDiff::between($current, $effective, makeOriginClassifier($repos, $result))` for a move yields `Discarded` for both `require › acme/local-one` and `require-dev › acme/local-one`. — AC-04
- `tests/TestSuites/TestDevConfigTransformer.php::test_legacyOverriddenManagedEntriesAdapter` — `getOverriddenManagedEntries()` returns exactly the four legacy keys, one entry for a move. — AC-06
- Existing round-trip data provider in `TestDevConfigTransformer.php` (unchanged, must pass) — `revert(apply(P)) == P` with no overrides. — AC-03
- `tests/TestSuites/TestStateValueObjects.php::test_managedEntryOverride_gettersAndArray` — getters and `toArray()` (five keys) for an in-place edit. — AC-05
- `tests/TestSuites/TestStateValueObjects.php::test_managedEntryOverride_moveAffectsBothSections` — `isMove()` true, and `getAffectedSections()` returns both sections for a move and one section otherwise, including `currentSection === null`. — AC-05
- `tests/TestSuites/TestStateValueObjects.php::test_managedEntryOverride_fromArrayRoundTrips` — `fromArray(toArray())` reproduces an equal object for an in-place edit, a removal (`currentSection` null) and a move. — AC-05
- `tests/TestSuites/TestStateValueObjects.php::test_revertResult_exposesValueObjectsAndLegacyShape` — a `RevertResult` constructed with `ManagedEntryOverride` instances returns the same instances from `getOverrides()`, reports `hasOverriddenManagedEntries()` correctly for empty and non-empty lists, and maps them to the four legacy keys in `getOverriddenManagedEntries()`. — AC-06
- `tests/TestSuites/TestSwitching.php::test_movedManagedPackageEmitsSingleMoveMessage` — DEV→PROD with a moved managed package writes exactly one 182221 message whose text contains "moved from [require] to [require-dev]". — AC-07
- `tests/TestSuites/TestSwitching.php::test_editedManagedPackageKeepsLegacyWording` — an in-place edit writes one 182221 message with the unchanged "A manual edit to the managed [require] entry" wording. — AC-07
- `tests/TestSuites/TestSwitching.php::test_refreshWithMovedManagedPackageEmitsSingleMoveMessage` — DEV→DEV refresh (`switch_planDevRefresh()` path) with a moved managed package writes exactly one 182221 message. — AC-07
- `tests/IntegrationSuites/TestCarryBack.php::test_movedManagedPackageIsRestoredWithSingleNotice` — real `switch-prod` succeeds, the package is back in `require` only, the move notice occurs once in the output, and the change set shows `[discarded]`. — AC-08
- `tests/IntegrationSuites/TestEntryPoints.php` transcript tests (unchanged, must pass) — real-transcript gate. — AC-09, AC-11
- Exit gate: full `composer test`, `composer test-integration` and `composer analyze` compared against `baseline.md`; `grep -rn "dirname(" src/ConfigSwitcher.php` shows only `getProjectRoot()`. — AC-01, AC-10, AC-11

## Documentation Updates
- `docs/agents/project-manifest/api-surface.md`:
  - Add a `ManagedEntryOverride` section (constructor, getters, `isMove()`, `getAffectedSections()`, `toArray()` shape, `fromArray()` as its inverse).
  - Rewrite the `RevertResult` section: the `ManagedEntryOverride[]` constructor, `getOverrides()`, the deprecated `getOverriddenManagedEntries()` adapter. Remove the stale "Not yet called anywhere in `ConfigSwitcher`" sentence.
  - In the `revert()` paragraph, replace "may be reported as an override in both sections" with the once-as-a-move behaviour.
  - In the `makeOriginClassifier()` paragraph, note that both sides of a move are `Discarded`.
- `docs/agents/project-manifest/constraints.md`:
  - Add the real-transcript exit-gate convention under §Test Conventions: any change to console/CLI output ships with a Tier 2 test that runs the real `composer switch-*` command and asserts the transcript structure, with `TestEntryPoints::test_realSwitchTranscriptMatchesPlannedLayout()` as the template.
  - Clarify at L28 that a section move of a managed package is discarded and reported once.
- `docs/agents/project-manifest/data-flows.md`: in the DEV→PROD carry-back passage (L136–L145) and the DEV→DEV refresh passage (`switch_planDevRefresh()`, from L54), state that a moved managed package is restored once *and* reported once, with the move wording.
- `docs/agents/project-manifest/switching-decision-table.md`: rows at L75 and L84 should say that `MESSAGE_MANAGED_ENTRY_OVERRIDDEN` is emitted once per managed package, a section move included, with the move wording.
- `docs/agents/project-manifest/file-tree.md`:
  - Add `src/State/ManagedEntryOverride.php`.
  - Update the `RevertResult.php` line.
  - Rewrite the `TestDevConfigTransformer.php` description, dropping the characterization-test wording.
  - Extend the `TestStateValueObjects.php`, `TestSwitching.php` and `TestCarryBack.php` descriptions.
- `docs/agents/project-manifest/README.md`: `**Version:** 3.3.0`.
- `changelog.md`: new `## v3.3.0 - Moved packages reported once (Minor)` entry with these bullets:
  - Switching: a moved managed package is reported once, as a move.
  - API: added `State\ManagedEntryOverride` and `RevertResult::getOverrides()`; deprecated `getOverriddenManagedEntries()`.
  - API: `RevertResult`'s constructor now takes `ManagedEntryOverride[]`. It is only produced by `DevConfigTransformer::revert()`; code that reads a `RevertResult` is unaffected.
  - Internal: project-root derivation consolidated.
  - Tests: Tier 1 and Tier 2 coverage.

## Deferred Items

| # | Deferred Item | Origin | Reason Deferred | Notes |
|---|---------------|--------|-----------------|-------|
| 1 | Path-repository alias source rendered as the literal `options.versions` instead of the derivation source | Prior plan Deferred Items #1 (rework-1 synthesis WP-005) | Needs source plumbing from `DevConfigTransformer::deriveAlias()` through `ConfigChangeFormatter`; this plan touches `revert()`, not alias derivation. | Revisit with any change to `apply()`'s alias derivation or the change-set formatter. |
| 2 | `! WARNING:` double marker on Warning-level messages | Prior plan Deferred Items #2 | Nine `WARNING:`-prefixed texts are shared with the legacy echo channel and `describe()` warnings; none is touched here. | Own plan: strip the prefix from texts and let the legacy channel add it from `MessageLevel::Warning`. The new exit-gate convention applies. |
| 3 | Removal of `OutcomeRenderer::render()`, `MESSAGE_COMPOSER_COMMAND` and `RevertResult::getOverriddenManagedEntries()` | Prior plan Deferred Items #4; this plan's deprecations | Breaking API removals. | Bundle into the next major release. |

## Risks & Mitigations
| Risk | Mitigation |
|------|------------|
| **Per-package detection changes which overrides are reported for non-move edits** | Existing in-place-edit and unedited tests are kept (modified only to read `getOverrides()`); a removal test is added; the round-trip invariant provider must pass unchanged. |
| **Classifier stops marking one side of a move `Discarded`, flipping the confirmation default to "yes"** | `getAffectedSections()` feeds the classifier; dedicated `test_movedPackageBothPathsClassifiedDiscarded` and the Tier 2 `[discarded]` assertion. |
| **Silent break for an external reader of `getOverriddenManagedEntries()`** | The getter keeps its shape and is tested (`test_legacyOverriddenManagedEntriesAdapter`, `test_revertResult_exposesValueObjectsAndLegacyShape`); removal is deferred to a major release. |
| **An external caller constructs `RevertResult` with legacy arrays** | Judged implausible: the type is `revert()`'s output and has no other caller in the repository or tests. The narrowed constructor fails loudly (PHPStan at analysis time, and an `Error` on the first method call on an array element at runtime) rather than silently, and the changelog names the change. |
| **Tier 2 test cannot run in a constrained environment** | `IntegrationTestCase` skips gracefully; the exit gate records skips explicitly, and Tier 1 covers the same logic offline. |
| **Base has a managed package in both sections (malformed but possible input)** | The detector resolves `require` first, then `require-dev`, matching `sectionOf()` lookups; behaviour is documented in the `detectManagedOverrides()` docblock. |

## Recommended Workflow
- **Workflow:** ledger
- **Rationale:** The fix reshapes the three-way revert, the most data-sensitive path in the library, and adds a public value object with a deprecation, so independent QA and code review are worth the overhead even though the change is contained.
