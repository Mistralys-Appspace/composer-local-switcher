# Plan

## Plan Audit Cycles
- Audits: 3 (Sonnet 5.5 ×3) — Plan Auditor v1.11.0
- Architectural Reviews: 1 (Sonnet 5.5 ×1) — Plan Architect Reviewer v2.3.4

## Prior Project Context
- `2026-10-06-switching-model-v3` (COMPLETE, released as `3.0.0`) set the user requirement *"every change to the config must always be shown and confirmed — transparency at all times"*. It also made the `version` override optional, with the alias derived from the PROD lock instead. This plan builds on both. A change set that nobody can read does not deliver transparency, and an explicit pin that silently beats the derived version is exactly the kind of failure v3 set out to remove.
- Insight `9fb62549` (classifier defects in `DevConfigTransformer`) was explicitly earmarked for "a single follow-up WP against `DevConfigTransformer.php` / `SwitchCommandRunner.php`". This plan touches both files, so the fix is funded here.
- Insight `63ca78c4`: README has repeatedly drifted from the implementation. The Documentation stage must diff README independently of code review.
- Insight `7ba3d224`: Composer's errors go to stderr, so Tier 2 assertions use `ProcessResult::containsOutput()`.
- Insight `76d1cee5`: characterisation tests are updated on purpose when the behavior they pin changes.

## Knowledge Base Reconciliation

| Insight ID | Title | What the plan overtakes | Executed by |
|------------|-------|-------------------------|-------------|
| 9fb62549-ecec-4266-83c0-49ecbfc94bc1 | DevConfigTransformer's managed/non-managed classifier has two known, deferred edge-case bugs | Step 9 fixes both defects: VCS pruning classified as `CarriedBack`, and the `require`/`require-dev` cross-section duplication. The insight's claim that they are "deliberately deferred (not fixed)" and pinned in `TestConfirmation.php` will no longer hold. Retire it or rewrite it as resolved. | Ledger Knowledge Curator v1.4.1 (Targeted Reconciliation) |

## Summary
In Mail Forge, `composer switch-dev` produced output nobody could read, and then failed. The output mixed raw escaped JSON, absolute paths, internal file operations, numeric message codes and a duplicated message block, with nothing marking it as the switcher's. The failure came from a stale `"version": "1.0.4"` pin in `composer/local-repositories.json`. The pin overrode the correctly derived production version (`3.0.0`) and did not satisfy the root constraint `^3.0.0`. Nothing checked this, so the switch wrote the DEV config and then left the project half-switched when `composer update` failed. This plan does three things. First, it gives the switcher a clearly framed, readable console presentation: a header, shape-aware change descriptions, and details only at `-v`. Second, it validates every version alias against the root constraint before writing anything, and blocks with an actionable message when the alias would be unresolvable. Third, it ends expected failures with recovery guidance instead of a PHP stack box. Along the way it fixes the two known change-origin classifier defects, because those make the newly prominent `[permanent]` marker lie on an ordinary first DEV switch.

## Architectural Context
- **Core vs. edge.** `src/ConfigSwitcher.php` plans and performs switches (`switchTo()`, `previewSwitch()`) and returns a `SwitchOutcome` (`src/State/SwitchOutcome.php`) holding the messages (`SwitchMessage`), the operations (`FileOperation`), a `ComposerCommand` and a `ConfigChangeSet`. `src/Utils/SwitchCommandRunner.php` is the only component that prompts or runs Composer. `src/Utils/EventContext.php` is the only component that duck-types Composer's event/IO. `src/Utils/OutcomeRenderer.php` renders outcomes and descriptions through `EventContext::write()`.
- **Version aliasing.** `src/Utils/DevConfigTransformer.php::apply()` builds the DEV config. `deriveAlias()` picks the override (`SOURCE_OVERRIDE`) or the PROD-locked version (`SOURCE_LOCKED`) and records it in `DevTransformResult::getVersionDerivations()`.
- **Second output channel.** `ConfigSwitcher::displayMessages()` echoes messages after every non-preview `switchTo()` (`displayMessages` defaults to `true`). The legacy `src/Utils/ConsoleWriter.php` echoes headers when `setWriteToConsole(true)` is set. Both bypass `EventContext`.
- **Entry points.** `ConfigSwitcher::composerSwitchDev/Prod/Update()` build a runner and let its exceptions escape to Composer.
- **Tests.** Tier 1 (`composer test`, offline) uses `tests/TestClasses/ScriptedEventContext.php`. Tier 2 (`composer test-integration`) runs real Composer against `tests/assets/integration-project`.

## Approach / Architecture

### A. Version alias guard (fixes issue 2)
1. **New** `src/Utils/VersionConstraintChecker.php`. It checks whether a version satisfies a constraint and returns a **new** `src/State/ConstraintCheck.php` enum: `Satisfied`, `Violated`, `Invalid` (the version or constraint cannot be parsed) or `Unverifiable` (no semver engine is loaded). It uses `Composer\Semver\Semver::satisfies()` and `Composer\Semver\VersionParser` when `class_exists()` finds them. They are always loaded inside a Composer script process, so the zero-runtime-dependency policy holds. The semver class name is a constructor argument, which lets tests exercise the `Unverifiable` path.
   - **Evaluation order.** The checker instantiates `$parserClass` and calls its `normalize(string $version)` on the version first, then `parseConstraints(string $constraint)` on the constraint, then `Semver::satisfies()`. `normalize()` is the only parser method the `dev-*` rule depends on, so it is the contract the `ThrowingVersionParser` stub implements.
   - **`dev-*` alias rule.** A version is a *dev alias* when, case-insensitively, it starts with `dev-` or ends with `-dev` (e.g. `dev-main`, `3.0.x-dev`). If `normalize()` throws `UnexpectedValueException` for a dev alias, the result is `Unverifiable` (no block), because Composer accepts alias forms the checker cannot prove malformed, and a false block would be worse than today's behavior. If `normalize()` throws for any other version (e.g. `not-a-version`), or `parseConstraints()` throws for the constraint, the result is `Invalid`, which blocks per AC-02. A dev alias that normalises successfully is evaluated normally and can be `Satisfied` or `Violated`.
2. `DevConfigTransformer::apply()` gains a checker parameter. For every derivation with `SOURCE_OVERRIDE`, it looks up the package's constraint in the snapshot's `require`, then `require-dev`. It records any non-`Satisfied`/non-`Unverifiable` result as a conflict on `DevTransformResult` (**new** `getVersionConflicts()`, each entry holding `{packageName, version, constraint, section, check, lockedVersion}`). A `SOURCE_LOCKED` alias is not checked: a fresh lock satisfies its own constraints by construction, and a stale lock is already blocked.
3. In `ConfigSwitcher::switch_planProdToDev()` and `switch_planDevRefresh()`, `apply()` runs **before** any snapshot copy or write. If there are conflicts, the switch blocks through `switch_blockedPlan()` with a **new** `MESSAGE_VERSION_OVERRIDE_CONFLICT = 182230` message (level `Warning`). The message names the package, the pinned version, the constraint, the locked version, the file (`composer/local-repositories.json`) and the fix ("remove the `version` key to use the locked version, or set one matching `<constraint>`"). Nothing is written.
4. `describe()` reports the same conflict in its `warnings`, so `switch-describe` shows it before anyone switches.

### B. Readable, identifiable console output (fixes issue 1)
Target output for the transcript's scenario once the stale pin is removed:

```text
Composer Local Switcher — switch PROD → DEV
  composer.json changes (1):
    + repositories: local path ../composer-local-switcher for mistralys/composer-local-switcher (symlinked, as version 3.0.0 from the production lock)
  Next: composer update mistralys/composer-local-switcher
Apply these changes and run the command? [Y/n]

Composer Local Switcher — running composer update mistralys/composer-local-switcher
  …Composer's own output…
Composer Local Switcher — done: DEV mode active.
```

And for the stale-pin scenario:

```text
Composer Local Switcher — switch PROD → DEV blocked, nothing was changed
  ! mistralys/composer-local-switcher is pinned to 1.0.4 in composer/local-repositories.json,
    but composer.json requires ^3.0.0 (production lock: 3.0.0).
    Remove the "version" key to use the locked version, or set a version matching ^3.0.0.
Script Mistralys\ComposerSwitcher\ConfigSwitcher::composerSwitchDev handling the switch-dev event returned with error code 1
```

The exact wording is the implementer's choice. The structure is the contract: a header line naming the switcher and the transition, indented content, a footer framing the Composer run, no message codes, no absolute paths inside the project, no escaped JSON, and no duplicated block.

Components:
1. **New** `src/State/MessageLevel.php` enum: `Detail` (shown only at `-v`), `Info` and `Warning`. `SwitchMessage` gains a third constructor argument `level`, defaulting to `Info` so existing callers keep working. `getLevel()` and `toArray()['level']` are additive. `ConfigSwitcher::addMessage()` is variadic (`...$args` feed `sprintf`), so the level cannot be appended. It becomes a required **leading** parameter: `addMessage(MessageLevel $level, int $code, string $message, ...$args)`. A required leading level forces every emission site to state its level, which is what AC-13 asks for. All 20 existing call sites in `src/ConfigSwitcher.php` are touched. Bookkeeping messages ("Using Composer DEV configuration.", "Rebuilt a fresh DEV `composer.json`.", "Dry run: …", the version-derived line) become `Detail`. Messages that currently start with `WARNING:` become `Warning`. Their text prefix is dropped from the human rendering only, and the `getText()` value is left unchanged for API compatibility.
2. **New** `src/Utils/ConfigChangeFormatter.php`. It is built with the project root and turns a `ConfigChange` into one human line using shape-specific describers:
   - A path `repositories` entry → "local path `<relative url>` for `<package>` (symlinked, as version `<alias>` from `<source>`)".
   - A VCS/other repository entry → "`<type>` repository `<url>`".
   - A `require`/`require-dev` constraint → "`<section>` › `<package>`: `<before>` → `<after>`".
   - A scalar → "`<path>`: `<before>` → `<after>`".
   - A generic fallback → compact `json_encode(..., JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`, truncated past a fixed `MAX_VALUE_LENGTH`.

   It also exposes `displayPath(string $path) : string`, which gives a project-relative path when the path is under or adjacent to the project root and the absolute path otherwise. `OutcomeRenderer` is its consumer, for both change sets and verbose file operations. The describer list grows as new change shapes appear (scripts, autoload). Describers stay **private methods of this one class**, selected by a `match` on the change shape. No describer interface or registry is introduced: at five shapes a single class is proportionate, and an extension point would have no second consumer.
3. `OutcomeRenderer` is reshaped around a header, sections and a footer:
   - `renderPlan(SwitchOutcome, bool $isPreview)` writes a header ("switch X → Y" or "preview of switch X → Y (nothing will be changed)"), the change sets (count + formatted lines, with the `[permanent]`/`[discarded]` markers kept and the `[version]` marker folded into the description), warnings, "Next: composer …" via `ComposerCommand::toDisplayString()`, and — only when `EventContext::isVerbose()` — the file operations (relative paths) and `Detail` messages, each with its `[code]`.
   - `renderBlocked()`, `renderCommandStart()`, `renderDone()` and `renderFailure()` cover the remaining moments. `renderDescription()` gets the same header.
   - Dynamic values go through `EventContext::escape()`.
   - **Constructor.** `public function __construct(private readonly EventContext $context, private readonly ConfigChangeFormatter $formatter)`. Both arguments are required, with no defaults, and the class stays `final`. The project root reaches the renderer only through the formatter (`new ConfigChangeFormatter(string $projectRoot)`). Every existing construction site is updated the same way: the 2 production sites in `src/ConfigSwitcher.php` (`buildRunner()` L282, `composerSwitchDescribe()` L308) and the 7 test sites (`TestOutcomeRenderer.php` L42/62/68/76/98, `TestEntryPointEdge.php` L318, `TestConfirmation.php` L344).
   - **The renderer stays a stateless layout component.** Its only collaborators are the `EventContext` and the `ConfigChangeFormatter` passed to its constructor. Every lifecycle moment receives what it prints as parameters (e.g. `renderCommandStart(ComposerCommand $command)`, `renderDone(string $modeLabel)`, `renderFailure(ComposerCommand $command, int $exitCode)`); the renderer never stores the mode, the command or any run state, and never decides *when* a moment happens — the runner does. The "Composer Local Switcher —" prefix and the header/indent conventions live in one place inside the renderer (a private constant plus a private `writeHeader()`), which is what keeps AC-05 consistent.
   - **Size budget.** With formatting extracted to `ConfigChangeFormatter`, the reshaped renderer must stay at or below roughly 400 lines. If the implementation exceeds that, the frame lines (`renderCommandStart()`, `renderDone()`, `renderFailure()` and the shared header writer) move into a **new** `src/Utils/OutputFrame.php` that the renderer delegates to, within the same step. Code review checks the budget.
4. `EventContext` changes:
   - **new** `isVerbose()` (duck-types `$io->isVerbose()`, `false` when absent);
   - **new** `escape(string)` (escapes `<` for Composer's formatter when an IO is present, identity otherwise);
   - **new** `writeStyled(string $line)`, which keeps the `<info>`/`<comment>`/`<warning>` tags for Composer's IO and strips exactly those tags in the `echo` fallback;
   - `writeMessage(int $code, string $text, MessageLevel $level = MessageLevel::Info)` only formats and prints: the `[code]` prefix appears only when verbose. No recording seam is added to production code — `src/` gains no test-only method.
   - The test double `tests/TestClasses/ScriptedEventContext.php` overrides `writeMessage()` itself: it appends `(code, text, level)` to its own list and then calls `parent::writeMessage()`, whose output reaches the double's existing `write()` capture. It exposes **new** `hasWrittenMessageCode(int)` and `getWrittenMessages()`, so tests assert on codes as data instead of on line prefixes. This works because `writeMessage()` is public and non-final and the double already extends `EventContext` for exactly this purpose.
5. `ComposerCommand` **new** `toDisplayString()`: arguments joined by spaces, each quoted only when it contains whitespace or shell metacharacters. `toShellString()` is unchanged and is still the one used for execution and exception context.
6. `SwitchCommandRunner`:
   - suppresses the switcher's legacy auto-display **only for the duration of its own real `switchTo()` call**: it reads the current setting through a **new** public getter `ConfigSwitcher::isDisplayMessagesEnabled() : bool`, calls `setDisplayMessages(false)`, runs `switchTo()`, and restores the previous value in a `finally` block. That removes the duplicated block wherever a runner is used (Composer entry points and directly constructed runners alike), the runner owns all edge output, and a switcher shared with the runner behaves exactly as before once the call returns or throws.
   - uses the renderer's new moments.
   - drops `MESSAGE_DRY_RUN_ACTIVE` from the plan rendering, because the renderer's `isPreview` flag carries that meaning in the header. The message stays in the outcome for API consumers.
   - on a non-zero exit, writes a **new** `MESSAGE_COMPOSER_RECOVERY = 182231` (level `Warning`) before throwing. It states the mode the config is now in and the two ways forward: fix the cause, then `composer switch-update`, or `composer switch-prod` to return.
7. Entry points `composerSwitchDev/Prod/Update()` return `bool`. They catch `ComposerSwitcherException` with the codes `ERROR_SWITCH_BLOCKED`, `ERROR_CONFIRMATION_REQUIRED`, `ERROR_INPUTS_CHANGED` and `ERROR_COMPOSER_COMMAND_FAILED`. For each, the runner has already written an explanation, so the entry point writes the exception message as a styled error line and returns `false`. Composer then reports "returned with error code 1" and exits non-zero, without the stack box and usage dump. Any other exception still propagates.

### C. Classifier defects (adjacent, insight `9fb62549`)
`makeOriginClassifier()` classifies a removed or replaced repository entry whose URL matches a managed package's repository (the same match `mergeRepositoryEntry()` uses for pruning) as `LocalSwitch`. `revertSection()` checks managed-package membership across both `require` and `require-dev`, so a package moved between sections is never duplicated.

## Rationale
- **Block instead of silently falling back on a conflicting pin.** The `version` key is an explicit user statement. Silently ignoring it would break the v3 transparency rule just as surely as the current failure does. Blocking before any write keeps the project untouched and says exactly which line to change. The cost is one extra edit for the user, which is the same edit that fixes the problem.
- **Check against the root constraint only.** That constraint is the one the transcript failed on, and the one the switcher can see cheaply. Transitive constraints from other packages need Composer's full solver, and the real `composer update` already reports them. Re-implementing resolution would duplicate Composer.
- **Use Composer's own semver engine opportunistically** (`Unverifiable` → no block) instead of adding a runtime dependency. Every real invocation runs inside Composer, where the engine exists. Direct library users without it lose nothing compared with today.
- **Levels as data, not text prefixes.** The renderer needs to filter (`Detail` at `-v` only) and style (`Warning`). Parsing `WARNING:` out of strings would break the first time a message is reworded. `MessageLevel` has a current consumer (the renderer) and a clear trajectory (JSON consumers of `describe-json`/outcome `toArray()` filtering by severity).
- **A dedicated `ConfigChangeFormatter`.** Change descriptions are the part of the output users must read before confirming, and the shapes will grow. One class per concern keeps `OutcomeRenderer` a layout component. The describers stay private methods rather than a pluggable interface: there is no second consumer to justify an extension point.
- **Lifecycle moments in the renderer, but stateless and budgeted.** Centralising the header/footer wording in `OutcomeRenderer` is what keeps the "Composer Local Switcher —" frame consistent (AC-05). The risk is the renderer accumulating run-state knowledge and becoming a monolith, so every moment takes its inputs as parameters, the runner alone decides sequencing, and a ~400-line budget triggers extraction of the frame into `OutputFrame` if crossed.
- **No test-only seams in `src/`.** Recording written message codes is a test concern. `ScriptedEventContext` already extends `EventContext` and can override the public `writeMessage()`, so production gains nothing it does not use.
- **Scoped suppression of the legacy echo channel.** The runner needs the switcher's auto-display off during its own `switchTo()`, but the switcher is an injected collaborator the caller owns. Saving and restoring the flag in `finally` keeps the side effect local to the call and the single-output guarantee independent of how the runner was built. The cost is one additive public getter.
- **Recovery guidance, not automatic rollback.** Rolling back after a failed Composer run would be a second, unconfirmed state transition, which contradicts the confirm-every-change principle. The DEV refresh path (`switch_planRefreshCommand()`) already recovers cleanly once the cause is fixed.
- **Entry points return `false` for explained failures.** The user has already been told what went wrong and what to do, so a PHP exception box and a usage dump only add noise. Library and runner callers still get the typed exception.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| Conflicting `version` override | Block before writing, with an actionable message | (a) Fall back to the locked version with a warning in the change set; (b) let Composer fail (status quo); (c) prompt to drop the pin | (a) silently overrides an explicit setting; (b) leaves the project half-switched; (c) would need to write the user's `local-repositories.json`, an extra file mutation outside the confirmed change set. Blocking is transparent and touches nothing. |
| Semver evaluation | Duck-typed `Composer\Semver` via `VersionConstraintChecker`, tri-state with `Unverifiable` | (a) Add `composer/semver` as a runtime dependency; (b) hand-rolled constraint parser | (a) breaks the zero-runtime-dependency policy for a library that always runs inside Composer anyway; (b) is a large, bug-prone re-implementation of Composer. |
| Verbosity control | Compact by default, details at Composer's `-v` | (a) New `--verbose`/`--details` script flag; (b) always print everything, just formatted better | (a) duplicates a standard Composer flag users already know; (b) still buries the change set under internal bookkeeping. |
| Code assertions in tests | `ScriptedEventContext` overrides `writeMessage()`, records `(code, text, level)` and calls the parent | (a) Keep `[code]` in human output; (b) a protected `recordMessage()` hook in production `EventContext` that the double overrides; (c) assert only on `SwitchOutcome` messages | (a) machine codes in human lines were a test convenience that leaked into the UX; (b) adds a production method with no production consumer, when the double can already override the public, non-final `writeMessage()`; (c) misses the codes the runner writes itself (182224, 182231). |
| Where the legacy auto-display is disabled | Runner saves the flag, disables it around its real `switchTo()`, restores it in `finally` (new getter `isDisplayMessagesEnabled()`) | (a) Runner calls `setDisplayMessages(false)` and leaves it; (b) `ConfigSwitcher::buildRunner()` disables it on the switcher it constructs | (a) permanently mutates a collaborator the caller owns, surprising library users who share a switcher; (b) keeps the runner mutation-free but makes single output depend on every construction site remembering it, including the test factories. Scoped restore removes both problems for one additive getter. |
| Run-lifecycle output (header, command start, done, failure) | Stateless moments on `OutcomeRenderer`, inputs passed as parameters, ~400-line budget with extraction to `OutputFrame` if crossed | (a) Runner writes its own frame lines via `writeStyled()`; (b) a separate `OutputFrame` class from the start | (a) splits the frame wording across two classes, risking AC-05 drift; (b) over-splits at the current size (renderer 238 lines before the formatter extraction). The budget makes the split a measured decision rather than a guess. |
| Failed Composer command | Recovery message + typed exception | Automatic restore of `composer.json` from the snapshot | Auto-restore is an unconfirmed second transition, and Composer may already have written a new lock. Explicit guidance is safer and keeps the user in control. |
| Removing the legacy `echo` channel | Runner disables it; API kept | Delete `displayMessages()`/`ConsoleWriter`/`setWriteToConsole()` | Deletion is a breaking change days after `3.0.0`. Disabling it at the edge fixes the duplication with no API break. |

## Pattern Alignment
- Follows "plan in the core, execute at the edge" (`src/Utils/SwitchCommandRunner.php`): the guard lives in the core planner (`switch_plan*`), and all presentation lives in the runner and renderer.
- Follows the single duck-typing site (`src/Utils/EventContext.php`): `isVerbose()`, `escape()` and styled writes are probed there and nowhere else. `VersionConstraintChecker` duck-types *Composer's semver library*, not the event/IO. That is a deliberate second, narrowly scoped probe site, documented in its docblock and in `tech-stack.md`.
- Follows the blocked-precondition pattern (`switch_planProdToDev()` stale-lock block → `switch_blockedPlan()`).
- Follows the immutable value-object pattern of `src/State/*` (`ConstraintCheck`, `MessageLevel` as backed enums like `ConfigChangeOrigin`, `LockStatus`).
- Follows the `1822xx` message-code scheme (`AGENTS.md` §4): `182230`, `182231`.
- Follows the characterisation-test convention (insight `76d1cee5`) when the classifier pins change.
- **Departure:** static entry points change their return type from `void` to `bool`. This is justified because Composer treats a `false` return from a script callback as a failed script, which is the only clean non-exception failure signal Composer offers. Non-Composer callers of these static methods do not exist in the documented API (they are Composer script targets).

## Structural Improvements

| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `src/Utils/OutcomeRenderer.php::formatChange()` | Generic formatting dumps raw, slash-escaped JSON for structured values | Promoted to step 4 | This is the core of issue 1, and change shapes will keep growing. |
| `src/ConfigSwitcher.php::displayMessages()` / `src/Utils/ConsoleWriter.php` | A second `echo` channel duplicates the runner's output; `setDisplayMessages()` has no matching getter | Promoted to step 6 (runner disables it for the duration of its `switchTo()` and restores it; new getter `isDisplayMessagesEnabled()`) / Rejected (removal) | Scoped disabling fixes the bug now without leaving the shared switcher mutated. Removal is a breaking API change right after `3.0.0`, with no functional gain once the runner disables it. |
| `src/State/SwitchMessage.php` | Severity lives in text prefixes | Promoted to step 2 | The renderer needs levels to filter and style. Additive, so not breaking. |
| `src/Utils/EventContext.php::writeMessage()` | Machine code embedded in human line; tests depend on it | Promoted to step 3 | Removes the code noise. Tests get a typed channel from the `ScriptedEventContext` override, with no recording seam added to `src/`. |
| `src/State/ComposerCommand.php` | Only a shell-escaped string form | Promoted to step 3 | `toDisplayString()` for humans. Execution keeps `toShellString()`. |
| `src/Utils/DevConfigTransformer.php::deriveAlias()` | Override never validated | Promoted to step 1 | Root cause of issue 2. |
| `src/ConfigSwitcher.php::switch_planProdToDev()` | Snapshot copy happens before `apply()` | Promoted to step 5 | Validation must precede the first write operation, so a block records no operations. |
| `src/ConfigSwitcher.php::composerSwitch*()` | Explained failures escape as exceptions, producing a stack box and usage dump | Promoted to step 7 | Same output-readability goal, small and contained. |
| `src/Utils/DevConfigTransformer.php::makeOriginClassifier()` / `revertSection()` | Known misclassification + cross-section duplication (insight `9fb62549`) | Promoted to step 9 | The file is already being changed, and the defect makes the new prominent `[permanent]` marker wrong on a first DEV switch. Funded follow-up per the insight. |
| `src/Utils/SwitchCommandRunner.php::runCommand()` | No recovery guidance after a failed command | Promoted to step 6 | The transcript left the user half-switched without guidance. |

## Detailed Steps
1. **Version constraint checking (new code).**
   - Add `src/State/ConstraintCheck.php` (backed enum: `Satisfied`, `Violated`, `Invalid`, `Unverifiable`).
   - Add `src/Utils/VersionConstraintChecker.php` with `__construct(string $semverClass = 'Composer\\Semver\\Semver', string $parserClass = 'Composer\\Semver\\VersionParser')` and `check(string $version, string $constraint) : ConstraintCheck`. It returns `Unverifiable` when the classes are missing. Otherwise it calls `$parserClass::normalize()` on the version, then `parseConstraints()` on the constraint, then `Semver::satisfies()` (Approach A.1, evaluation order). An `UnexpectedValueException` from `normalize()` yields `Unverifiable` when the version is a dev alias (case-insensitively starts with `dev-` or ends with `-dev`), and `Invalid` otherwise (e.g. `not-a-version`). An `UnexpectedValueException` from `parseConstraints()` yields `Invalid`. Put the dev-alias detection in one private method (`isDevAlias(string $version) : bool`) so the rule has a single definition.
   - Add `composer/semver` (`^3.4`) to the library's `require-dev` only, so Tier 1 can exercise the real engine.
2. **Message levels.** Add `src/State/MessageLevel.php` (`Detail`, `Info`, `Warning`). Extend `SwitchMessage` with a `level` constructor argument (default `Info`), `getLevel()`, and `toArray()['level']`. Change `ConfigSwitcher::addMessage()` (L1521) to `addMessage(MessageLevel $level, int $code, string $message, ...$args)`, with the level as a required leading parameter, because the method is variadic. Update all 20 call sites with an intentional level for each (bookkeeping → `Detail`, `WARNING:`-prefixed → `Warning`, everything else → `Info`).
3. **Edge primitives.**
   - `EventContext`: add `isVerbose()`, `escape()` and `writeStyled()`. Change `writeMessage()` to print `[code]` only when verbose, and add its `MessageLevel` parameter. Add no recording hook or other test-only member.
   - `ComposerCommand`: add `toDisplayString()`.
   - `tests/TestClasses/ScriptedEventContext.php`: add a `verbose` constructor flag (overriding `isVerbose()`), override `writeMessage()` to record `(code, text, level)` and then call `parent::writeMessage()`, and add `hasWrittenMessageCode()` / `getWrittenMessages()`.
4. **Formatter and renderer.**
   - Add `src/Utils/ConfigChangeFormatter.php` (describers as above, `displayPath()`, `MAX_VALUE_LENGTH` constant).
   - Reshape `OutcomeRenderer` into `renderPlan()`, `renderBlocked()`, `renderCommandStart()`, `renderDone()`, `renderFailure()` and `renderDescription()`, all with the header/indent/footer layout from Approach B. Each moment takes its inputs as parameters and the renderer holds no run state (Approach B.3). Keep the header prefix in one private constant and one private header writer. If the finished class exceeds roughly 400 lines, extract the frame moments into a new `src/Utils/OutputFrame.php` that the renderer delegates to, in this same step. Keep `render()` as a thin wrapper over `renderPlan($outcome, $outcome->isDryRun())` for compatibility, and keep `formatOperation()` but have it use `displayPath()`.
   - Give `OutcomeRenderer` the constructor from Approach B.3 (`EventContext $context, ConfigChangeFormatter $formatter`, both required). Update both production construction sites. `buildRunner()` (L282) and `composerSwitchDescribe()` (L308) build `new ConfigChangeFormatter(<project root>)`, where the root is `dirname($switcher->getMainFile()->getPath())`, as `ConfigSwitcher` L1451 derives it. Update the 7 test construction sites in this step too, so the suite stays compilable. Each passes a formatter rooted at its fixture directory.
5. **Alias guard in the core.**
   - Extend `DevConfigTransformer::apply()` with an optional `?VersionConstraintChecker` (default: a new instance) and populate `DevTransformResult::getVersionConflicts()`.
   - Add `MESSAGE_VERSION_OVERRIDE_CONFLICT = 182230`.
   - In `switch_planProdToDev()` and `switch_planDevRefresh()`, compute `apply()` before any `copyTo`/`putData`, and block through `switch_blockedPlan()` on conflicts. The message has level `Warning` and the content described in Approach A.3.
   - In `describe()`, add the same conflict as a warning (reusing the transformer's check against `composer.json` in PROD/INITIAL, or against the snapshot in DEV).
6. **Runner.**
   - Add the public getter `ConfigSwitcher::isDisplayMessagesEnabled() : bool`.
   - In `SwitchCommandRunner::runSwitch()`, read the switcher's current display setting, call `setDisplayMessages(false)` immediately before the real `switchTo()`, and restore the previous value in a `finally` block wrapping only that call.
   - Render through the new renderer moments, including the "done" footer after a successful command, and after a successful switch with no command.
   - Add `MESSAGE_COMPOSER_RECOVERY = 182231` and write it from `runCommand()` before throwing `ERROR_COMPOSER_COMMAND_FAILED`.
   - Use `toDisplayString()` in every human line.
7. **Entry points.** Change `composerSwitchDev()`, `composerSwitchProd()` and `composerSwitchUpdate()` to return `bool`. Catch the four explained exception codes, write the message through `EventContext::writeStyled()` as an error line, and return `false`. Return `true` on success. Other exceptions propagate unchanged.
8. **Docblocks and constants.** Update the docblocks on `ConfigSwitcher`, `SwitchCommandRunner`, `OutcomeRenderer`, `EventContext` and `DevConfigTransformer` so they describe the new behavior (no stale "`[<code>]` prefix" wording).
9. **Classifier defects (insight `9fb62549`).**
   - In `DevConfigTransformer::makeOriginClassifier()`, classify a repository entry pruned or replaced for a managed package as `LocalSwitch`.
   - In `revertSection()`, check managed membership across both `require` sections so a moved package is never duplicated.
   - Update the pinning docblock and assertions in `tests/TestSuites/TestConfirmation.php` deliberately.
10. **Tests.** Implement every entry in the Test Plan, and update every existing string assertion listed in the research brief (`TestOutcomeRenderer.php`, `TestConfirmation.php`, `TestEntryPointEdge.php`, `tests/IntegrationSuites/TestNestedRun.php`) to the new layout and the recorded-code channel. Tier 2 tests cannot use `ScriptedEventContext`, so in `TestNestedRun.php` the code assertions move to the message wording that stays visible at default verbosity (see Test Plan). The 182224 line is printed by a child Composer process, and a `-v` on the outer command is not guaranteed to reach it. Two existing tests pin the "malformed override is written, then Composer fails" behavior that the guard removes. They are changed on purpose (insight `76d1cee5`), as the Test Plan specifies:
    - `tests/TestSuites/TestSwitching.php::test_malformedVersionIsWrittenVerbatim()` (L572) is **converted** into `test_malformedOverrideBlocks()`: same `not-a-version` setup, but the assertions now expect a blocked outcome with nothing written. Its docblock is rewritten. The old name is removed, not kept alongside.
    - `tests/IntegrationSuites/TestVersionOverride.php::test_switchProdRecoversAfterRejectedVersion()` (L201) is **rewritten** as `test_switchDevSucceedsAfterRemovingRejectedPin()`. A blocked switch creates no `composer-prod.json`/`.lock` snapshot and leaves `composer.json` untouched, so there is no half-switched state to recover from. The recovery the user actually performs is to remove the pin and switch again, and that is what the test now covers. The docblocks of this test and of `test_malformedVersionBlocksSwitch()` (the replacement for `test_malformedVersionFailsComposerUpdate()`) drop every cross-reference to the old write-then-fail behavior, including the `{@see}` links to `test_malformedVersionIsWrittenVerbatim()` and `test_malformedVersionFailsComposerUpdate()`.
11. **Documentation.** Apply every entry in Documentation Updates.
12. **Verification gate.** `composer test`, `composer test-integration` and `composer analyze` all clean, with zero unexpected skips.

## Dependencies
- Step 1 → step 5 (the checker is used by the transformer).
- Step 2 → steps 3, 4, 5, 6 (levels are consumed everywhere).
- Step 3 → steps 4, 6, 7.
- Step 4 → steps 6, 7.
- Steps 5 and 9 both touch `DevConfigTransformer.php`. Sequence step 9 after step 5 to avoid conflicting edits.
- Step 10 follows its subject steps. Step 11 follows steps 1–9. Step 12 runs last.

## Required Components
- **New:** `src/State/ConstraintCheck.php`, `src/State/MessageLevel.php`, `src/Utils/VersionConstraintChecker.php`, `src/Utils/ConfigChangeFormatter.php`
- **New, conditional:** `src/Utils/OutputFrame.php`, only if the reshaped `OutcomeRenderer` exceeds its ~400-line budget (step 4)
- **New tests:** `tests/TestSuites/TestVersionConstraintChecker.php`, `tests/TestSuites/TestConfigChangeFormatter.php`
- **New test class:** `tests/TestClasses/ThrowingVersionParser.php`. It is a stub parser whose `normalize()` always throws `UnexpectedValueException`, and it is passed as `$parserClass` to `VersionConstraintChecker` so the `dev-*` normalisation-failure → `Unverifiable` path is deterministic.
- **Modified:** `src/ConfigSwitcher.php`, `src/Utils/DevConfigTransformer.php`, `src/State/DevTransformResult.php`, `src/State/SwitchMessage.php`, `src/State/ComposerCommand.php`, `src/Utils/EventContext.php`, `src/Utils/OutcomeRenderer.php`, `src/Utils/SwitchCommandRunner.php`, `composer.json` (require-dev)
- **Modified tests:** `tests/TestClasses/ScriptedEventContext.php`, `tests/TestSuites/TestOutcomeRenderer.php`, `TestConfirmation.php`, `TestEntryPointEdge.php`, `TestEventContext.php`, `TestMessages.php`, `TestDevConfigTransformer.php`, `TestDescribe.php`, `TestStateValueObjects.php`, `TestSwitching.php`, `tests/IntegrationSuites/TestVersionOverride.php`, `TestEntryPoints.php`, `TestNestedRun.php`

## Assumptions
- Composer 2.x is the runtime for every Composer-script invocation. It ships `Composer\Semver\Semver`/`VersionParser`, and it treats a `false` return from a PHP script callback as a script failure (non-zero exit, "returned with error code 1"). Step 10's Tier 2 test confirms the second point empirically. If it does not hold, step 7 falls back to the current throw and keeps every other change.
- Composer's IO implements `isVerbose()` and renders `<info>`/`<comment>`/`<warning>`/`<error>` tags.
- The work releases as a minor version (`3.1.0`). Human-readable output is not a contract, and every API change is additive except the entry-point return type, which only Composer consumes.

## Constraints
- No new runtime dependency (`require` stays `php` only).
- PHP 8.4 idioms (enums, readonly, constructor promotion) per the PHP 8 modernisation policy. Touched files are modernised in the same pass.
- `switchTo()`/`previewSwitch()` must still never prompt or execute.
- A blocked switch records no file operations and writes nothing.
- Consumer repos (Mail Forge, HCP Editor) are not modified or used as a test surface.

## Out of Scope
- Checking alias versions against *transitive* constraints from other packages (Composer's solver reports these).
- Removing `ConsoleWriter`, `displayMessages()` or `setWriteToConsole()` (breaking; see Structural Improvements).
- Editing Mail Forge's `composer/local-repositories.json` or its `.dist` template (consumer repo; see the handoff for the immediate fix).
- Automatic rollback after a failed Composer command.
- Colour themes or localisation of output.

## Human Actions

| # | Action | When | Why an agent cannot do it |
|---|--------|------|---------------------------|
| 1 | Review the changelog entry, then tag and publish `3.1.0` | After the run | Releases are the user's decision and need repository publishing rights. |
| 2 | Raise Mail Forge's / HCP Editor's constraint to `^3.1.0` once released, and drop stale `version` pins from their `local-repositories.json(.dist)` | After the run | Consumer repos are outside this plan, and the release must exist first. |

## Acceptance Criteria
- AC-01: A `version` override that does not satisfy the package's root `require`/`require-dev` constraint blocks `switch-dev`/`switch-update` before any file is written or any operation recorded, with `MESSAGE_VERSION_OVERRIDE_CONFLICT` (182230). The message names the package, the pinned version, the constraint, the locked version and the fix.
- AC-02: A malformed `version` override (`ConstraintCheck::Invalid`) blocks the same way, instead of failing inside `composer update`.
- AC-03: With no semver engine available, the check yields `Unverifiable` and never blocks, which preserves current behavior for non-Composer callers.
- AC-04: `switch-describe` lists a conflicting override as a warning.
- AC-05: Every switcher console block starts with a header line naming "Composer Local Switcher" and the transition (or "preview"). A Composer run executed by the switcher is framed by a start line and a done/failure line.
- AC-06: In default verbosity, output contains no `[1822xx]` codes, no file-operation lines, no `Detail` messages, no slash-escaped JSON, and no absolute paths for files inside or adjacent to the project root. At `-v`, file operations and coded `Detail` messages appear.
- AC-07: A path-repository change is described in words (package, relative path, symlink, alias version and its source), not as a JSON object.
- AC-08: Messages from a real switch run by `SwitchCommandRunner` are printed exactly once. The switcher's own `displayMessages` setting is the same after `runSwitch()` returns or throws as it was before the call.
- AC-09: The first-preview "Dry run" message is not shown during `switch-dev`/`switch-prod`/`switch-update`. `switch-preview-*` states in its header that nothing will change.
- AC-10: Composer commands are displayed without unnecessary quoting (`composer update vendor/pkg`). Execution still uses the escaped form.
- AC-11: A failed Composer command prints `MESSAGE_COMPOSER_RECOVERY` (182231), naming the current mode, `composer switch-update` and `composer switch-prod`.
- AC-12: Explained failures (blocked, confirmation required, inputs changed, command failed) exit non-zero through Composer without a PHP exception box or usage dump. Unexpected exceptions still propagate.
- AC-13: `SwitchMessage` exposes a `MessageLevel`, every emitted message has an intentional level, and `toArray()` includes it.
- AC-14: Dynamic values containing `<` (e.g. `<2.0`) render literally through Composer's IO.
- AC-15: A first PROD→DEV switch that prunes a managed package's VCS repository classifies that change as `LocalSwitch` (no `[permanent]` marker, prompt defaults to yes). A package moved between `require` and `require-dev` during DEV is not duplicated on `switch-prod`.
- AC-16: `composer test`, `composer test-integration` and `composer analyze` pass with zero errors and no unexpected skips, and all documentation listed below is updated.
- AC-17: `src/` contains no test-only members: message-code recording lives entirely in `tests/TestClasses/ScriptedEventContext.php`, and `OutcomeRenderer` holds no run state and stays within its ~400-line budget (or its frame moments are extracted to `OutputFrame`).

## Testing Strategy
- Tier 1 (offline) covers the pure pieces: `VersionConstraintChecker` with the real `composer/semver` dev dependency and with an absent class name; `ConfigChangeFormatter` per change shape; `DevConfigTransformer` conflict detection and the classifier fixes; and `OutcomeRenderer`/`SwitchCommandRunner` layout through `ScriptedEventContext` in default and verbose modes.
- Tier 2 (real Composer) proves the end-to-end behavior: the guard blocks with an unchanged working copy, the explained failure exits without a stack box, the header appears, and messages are not duplicated.
- Existing string assertions migrate to the recorded-code channel, so tests stop depending on human wording where they only care about codes.

## Test Plan
- `tests/TestSuites/TestVersionConstraintChecker.php` (new):
  - `test_satisfiedVersion` — `3.0.0` against `^3.0.0` → `Satisfied`. Covers AC-01.
  - `test_violatedVersion` — `1.0.4` against `^3.0.0` → `Violated`. Covers AC-01.
  - `test_invalidVersion` — malformed non-dev version `not-a-version` against `^3.0.0` → `Invalid`, and with `ThrowingVersionParser` a non-dev version (`3.0.0`) whose normalisation throws → `Invalid`, so the dev-alias exception path is proven not to widen to every version. Covers AC-02.
  - `test_missingEngineIsUnverifiable` — a nonexistent class name → `Unverifiable`. Covers AC-03.
  - `test_devAliasIsNotInvalid` — with the real engine, `dev-main` and `3.0.x-dev` checked against `^3.0.0` never return `Invalid`. Then, with `tests/TestClasses/ThrowingVersionParser.php` as the parser class, a `dev-main` alias whose normalisation throws returns `Unverifiable`, not `Invalid`. Covers AC-02 (no false block) and AC-03, and backs Risks row 2.
- `tests/TestSuites/TestDevConfigTransformer.php`:
  - `test_conflictingOverrideIsReported` — a conflict in `getVersionConflicts()` with constraint/section/locked version. Covers AC-01.
  - `test_lockedAliasIsNotChecked` — a `SOURCE_LOCKED` alias produces no conflict. Covers AC-01.
  - `test_requireDevConstraintIsUsed` — the constraint is taken from `require-dev`. Covers AC-01.
  - `test_unverifiableCheckYieldsNoConflict` — `apply()` receives a `VersionConstraintChecker` built with a nonexistent semver class name, with a violating override (`1.0.4` against `^3.0.0`). `getVersionConflicts()` is empty, and the override alias is still applied with `SOURCE_OVERRIDE`. Because the switch only blocks on conflicts, nothing is blocked. Covers AC-03.
  - `test_prunedVcsEntryIsLocalSwitch` — the pruned VCS entry's origin is `LocalSwitch`. Covers AC-15.
  - `test_movedPackageNotDuplicatedOnRevert` — a package moved between sections is not duplicated. Covers AC-15.
- `tests/TestSuites/TestSwitching.php` (updated):
  - `test_conflictingOverrideBlocksProdToDev` (new) — blocked outcome, zero operations, `composer.json` byte-identical, code 182230. Covers AC-01.
  - `test_conflictingOverrideBlocksDevRefresh` (new) — same for DEV→DEV. Covers AC-01.
  - `test_malformedOverrideBlocks` — **converted from** `test_malformedVersionIsWrittenVerbatim` (L572), which is removed. It keeps the existing setup (override `not-a-version` for `mistralys/application-utils` in `composer/local-repositories.json`, then `switchToDevelopment()`). It now asserts that the returned outcome `isBlocked()`, records zero file operations and carries code 182230, that `composer.json` is byte-identical to its pre-switch content, that no `options.versions` entry for the package exists, and that `composer/composer-prod.json` was not created. The docblock describing verbatim writing is replaced with one describing the block. Covers AC-01, AC-02.
- `tests/TestSuites/TestDescribe.php` `test_conflictingOverrideWarning` — a warning names the package and the constraint. Covers AC-04.
- `tests/TestSuites/TestConfigChangeFormatter.php` (new):
  - `test_pathRepositoryEntryInWords` — relative path, symlink, version, source; no `{`, no `\/`. Covers AC-07, AC-06.
  - `test_vcsRepositoryEntry`, `test_requireConstraintChange`, `test_scalarChange`.
  - `test_genericValueUnescapedAndTruncated`. Covers AC-06.
  - `test_displayPathRelativeForProjectAndSibling`. Covers AC-06.
- `tests/TestSuites/TestOutcomeRenderer.php` (updated):
  - `test_planHasHeader` — first line contains "Composer Local Switcher" and the transition. Covers AC-05.
  - `test_previewHeaderSaysNothingChanges` — covers AC-09.
  - `test_defaultVerbosityHidesDetails` — no `[1822`, no `would `, no Detail texts. Covers AC-06.
  - `test_verboseShowsOperationsAndCodes` — covers AC-06.
  - `test_valuesAreEscapedForIo` — a `<2.0` constraint is escaped when an IO is present. Covers AC-14.
  - `test_describeHasHeader` — covers AC-05.
  - `test_frameMomentsUseSharedPrefix` — `renderBlocked()`, `renderCommandStart()`, `renderDone()` and `renderFailure()` each write a first line starting with the same "Composer Local Switcher —" prefix, using only the values passed in. Covers AC-05, AC-17.
- `tests/TestSuites/TestMessages.php` `test_displayMessagesGetterReflectsSetter` — `isDisplayMessagesEnabled()` defaults to `true` and follows `setDisplayMessages()`. Covers AC-08.
- Code review checkpoint (not an automated test): `OutcomeRenderer` line count against the ~400-line budget, and no stored run state. Covers AC-17.
- `tests/TestSuites/TestEventContext.php`:
  - `test_isVerboseDuckTyped`, `test_writeMessageHidesCodeUnlessVerbose`. Covers AC-06.
  - `test_scriptedContextRecordsCodeAndLevel` — `ScriptedEventContext::writeMessage()` records `(code, text, level)` *and* still captures the formatted line through the parent (no `[code]` prefix at default verbosity, prefix present when the double is built verbose). Covers AC-13, AC-17.
  - `test_eventContextHasNoRecordingMembers` — reflection asserts `EventContext` declares no `recordMessage` (or other recording) method, guarding against a test seam reappearing in `src/`. Covers AC-17.
  - `test_styledFallbackStripsOnlyKnownTags`, `test_escape`. Covers AC-14.
- `tests/TestSuites/TestStateValueObjects.php`:
  - `test_switchMessageLevelDefaultsToInfo`, `test_switchMessageToArrayIncludesLevel`. Covers AC-13.
  - `test_composerCommandDisplayString` — no quotes for plain args, quotes for args with spaces. Covers AC-10.
- `tests/TestSuites/TestMessages.php` `test_everyMessageHasIntentionalLevel` — bookkeeping messages are `Detail`, former `WARNING:` messages are `Warning`. Covers AC-13.
- `tests/TestSuites/TestConfirmation.php` (updated):
  - Existing assertions move to `hasWrittenMessageCode()` and the new layout.
  - `test_realSwitchMessagesPrintedOnce` — with the switcher's auto-display left at its default `true`, run a real switch through the runner inside `ob_start()`/`ob_get_clean()` (the legacy channel `echo`es, bypassing the context) and assert the buffered stdout contains none of the outcome's message texts, while each appears exactly once in the captured context lines. Covers AC-08.
  - `test_runSwitchRestoresDisplayMessagesSetting` — `isDisplayMessagesEnabled()` is `true` after a successful `runSwitch()`, and also after a `runSwitch()` that throws (e.g. a failing scripted Composer process); a switcher pre-set to `false` stays `false`. Covers AC-08.
  - `test_runnerHidesDryRunMessage` — covers AC-09.
  - The pinning docblock is updated for the classifier fix. Covers AC-15.
- `tests/TestSuites/TestEntryPointEdge.php` (updated):
  - `[182224]`-style assertions move to `hasWrittenMessageCode()`.
  - `test_failedCommandPrintsRecovery` — code 182231 with `switch-update`/`switch-prod`. Covers AC-11.
  - `test_commandShownWithoutQuotes` — covers AC-10.
  - `test_doneFooterAfterCommand` — covers AC-05.
  - `test_entryPointReturnsFalseOnExplainedFailure` and `test_entryPointPropagatesUnexpectedException` — cover AC-12.
- `tests/IntegrationSuites/TestVersionOverride.php`:
  - `test_malformedVersionFailsComposerUpdate` is replaced by `test_malformedVersionBlocksSwitch` — non-zero exit, output names the version, `composer.json` unchanged. Covers AC-02.
  - New `test_conflictingVersionBlocksSwitch` — override `1.0.0` against `^2.0`: non-zero exit, the output (via `containsOutput()`) names `^2.0` and `local-repositories.json`, mode still PROD, files byte-identical, no "In SwitchCommandRunner.php". Covers AC-01, AC-12.
  - `test_switchProdRecoversAfterRejectedVersion` (L201) is **rewritten** as `test_switchDevSucceedsAfterRemovingRejectedPin`. The old test read `composer/composer-prod.json`/`.lock` produced by a DEV switch that wrote before failing, and a blocked switch produces neither, so it cannot pass as written. The new test proceeds as follows:
    - It sets override `not-a-version`, runs `bootstrapProd()`, and records `composer.json` and `composer.lock` content.
    - It runs `runSwitch('switch-dev')` and asserts a non-zero exit, `composer.json` and `composer.lock` byte-identical, and `composer/composer-prod.json` absent.
    - It removes the pin with `setLocalRepositoryVersionOverride(null)` and runs `runSwitch('switch-dev')` again. It asserts exit 0, that DEV mode is active, and that the package alias is the locked version (`SOURCE_LOCKED`, `2.0.0`).
    - It runs `runSwitch('switch-prod')`, asserts exit 0, and asserts `composer.json` equals the recorded PROD baseline (`assertEquals`, as before) and `composer.lock` is byte-identical.

    Both steps now run through real Composer, because a blocked switch no longer leaves an unparseable constraint in `composer.json`. That removes the reason the old docblock gave for driving recovery through `ConfigSwitcher` directly. The docblock is rewritten accordingly. Covers AC-01, AC-02 (a block leaves the project fully recoverable by editing the pin alone).
- `tests/IntegrationSuites/TestEntryPoints.php`:
  - `test_switchOutputIsFramedAndNotDuplicated` — header present, done footer present, "Rebuilt a fresh DEV" absent at default verbosity. Covers AC-05, AC-06, AC-08.
  - `test_verboseSwitchShowsDetails` (`-v`) — covers AC-06.
- `tests/IntegrationSuites/TestNestedRun.php` (updated):
  - `test_postUpdateHookSwitchUpdateIsHarmless` — the `assertStringContainsString('182224', …)` at L59–L64 is re-pointed at the nested-run skip wording, which is visible at default verbosity. The assertion uses a stable substring, `COMPOSER_SWITCHER_NESTED`, through `containsOutput()`. `MESSAGE_NESTED_RUN_SKIPPED` must therefore be written at a level shown by default (`Info`). Covers AC-06, because no `[182224]` code appears at default verbosity.
  - `test_userRunRequireWithHookRetainedIsHarmless` — the `containsOutput('182228')` absence check at L95–L98 becomes vacuous once codes are hidden. It is replaced by an absence check on the confirmation-required wording (`Confirmation required`), which `MESSAGE_CONFIRMATION_REQUIRED` would print at default verbosity. The rest of the test is unchanged. Covers AC-06 and keeps the original guarantee.
- Verification gate: `composer test`, `composer test-integration`, `composer analyze`. Covers AC-16.

## Documentation Updates
- `README.md` — replace the output samples (L48–L70) with the new framed layout, mention `-v` for details, and describe the version-pin guard. Diff every README claim against the implementation independently (insight `63ca78c4`).
- `docs/usage.md` — rewrite "What you will be asked to confirm" and the preview section (L36–L90): new layout, marker semantics, `-v`, blocked-switch example, recovery after a failed command.
- `docs/setup.md` — "pinned package versions": the pin must satisfy the `composer.json` constraint or the switch is blocked; prefer no pin.
- `docs/migrating-from-2x.md` — step 5: a pin left over from 2.x is now detected and blocks the switch with instructions.
- `docs/api.md` — `MessageLevel`, `SwitchMessage::getLevel()`, `DevTransformResult::getVersionConflicts()`, `ComposerCommand::toDisplayString()`, entry-point return values.
- `changelog.md` — new `v3.1.0` entry, in the existing style: readable framed output, details at `-v`, version-pin guard, recovery hint, no duplicate messages, cleaner failures, classifier fixes.
- `docs/agents/project-manifest/api-surface.md`:
  - new classes/enums and methods (including `ConfigSwitcher::isDisplayMessagesEnabled()`, and `OutputFrame` if extracted);
  - changed `SwitchMessage` constructor, `OutcomeRenderer` constructor (now `EventContext`, `ConfigChangeFormatter`), `EventContext::writeMessage()`, `DevConfigTransformer::apply()` and entry-point signatures;
  - new constants `MESSAGE_VERSION_OVERRIDE_CONFLICT`, `MESSAGE_COMPOSER_RECOVERY`.
- `docs/agents/project-manifest/constraints.md`:
  - new message codes;
  - the rule that messages carry a `MessageLevel` and machine codes appear only at `-v`;
  - the tests' use of `hasWrittenMessageCode()` instead of line prefixes, with recording done by the `ScriptedEventContext::writeMessage()` override (no test-only members in `src/`);
  - the runner's scoped suppression of the switcher's auto-display (saved and restored around its `switchTo()`);
  - the escape rule for dynamic values.
- `docs/agents/project-manifest/file-tree.md` — the four new `src/` files (five if `OutputFrame` is extracted), two new test suites, and the new test class `tests/TestClasses/ThrowingVersionParser.php`.
- `docs/agents/project-manifest/tech-stack.md` — `composer/semver` (require-dev), and the opportunistic use of Composer's semver at runtime as a second, scoped duck-typing site.
- `docs/agents/project-manifest/data-flows.md` — the alias validation step before snapshotting, the runner's output moments, and failure/recovery flow.
- `docs/agents/project-manifest/switching-decision-table.md` — new blocked rows (override conflict/invalid) for PROD/INITIAL→DEV and DEV→DEV, with code 182230, and the 182231 recovery on command failure.
- `AGENTS.md` §4 — the agent-run row: mention that blocked switches exit non-zero with the reason printed, and that `-v` shows file operations.

## Risks & Mitigations
| Risk | Mitigation |
|------|------------|
| **Composer does not treat a `false` callback return as failure in some version** | A Tier 2 test asserts a non-zero exit. If it fails, step 7 reverts to throwing and every other improvement still ships (see Assumptions). |
| **Semver normalisation differs from what Composer applies to path-repo `versions` aliases** (e.g. `dev-main`, `3.0.x-dev`) | Use `VersionParser::normalize()` before `satisfies()`, exactly as Composer does. `test_devAliasIsNotInvalid` tests the `dev-*` and branch-alias cases, and `test_unverifiableCheckYieldsNoConflict` confirms that `Unverifiable` never becomes a conflict. An `Invalid` result on an alias form Composer accepts would be a false block, so any `UnexpectedValueException` on *normalisation* of a dev alias (starts with `dev-` or ends with `-dev`) maps to `Unverifiable`, not `Invalid` — the rule is specified in Approach A.1 and step 1. |
| **Escaping misses a formatter edge case**, garbling output | Centralise escaping in `EventContext::escape()` and test with `<`, `>`, `\`. Values are only escaped when an IO is present. |
| **Many existing string assertions break** | Step 10 enumerates them, and the recorded-code channel removes most wording dependencies. |
| **Classifier fix changes prompt defaults unexpectedly** | It is limited to managed-package repository matches, with dedicated Tier 1 tests plus the updated characterisation test. |
| **`OutcomeRenderer` grows into a monolith once it owns the run-lifecycle moments** | Moments are stateless and parameter-driven, the runner owns sequencing, and a ~400-line budget triggers extraction to `OutputFrame` within step 4 (checked in code review, AC-17). |
| **A runner exception leaves the shared switcher with auto-display off** | The flag is restored in `finally`; `test_runSwitchRestoresDisplayMessagesSetting` covers the throwing path. |
| **README drifts again** | The Documentation stage diffs README independently (insight `63ca78c4`). |

## Recommended Workflow
- **Workflow:** ledger
- **Rationale:** Cross-cutting changes span the core planner, transformer, value objects, edge runner, renderer and entry points, plus a new validation behavior with a public-API surface and a dozen documentation artefacts. That benefits from separate QA, review and documentation stages.
