# Plan

## Plan Audit Cycles
- Audits: none — Plan Auditor v1.11.0
- Architectural Reviews: none — Plan Architect Reviewer v2.3.4

## Prior Project Context

Two archived projects shaped the current code. `2026-08-26-switching-gaps-and-sync-hardening`
delivered v1.1.0 — `verify()`, `installGitHooks()`, `fromProjectRoot()` and the five static
Composer entry points — and rewired both consumer projects to call those entry points directly,
deleting all wrapper boilerplate. `2026-08-27-...-rework-1` stabilised the result at v1.1.1
(16 tests, 84 assertions, PHPStan level 6 clean). Both projects added *capabilities*; neither
revisited the shape in which those capabilities report back to a caller, which is exactly the
gap this plan closes.

The repository has no declared strategic vision, and the knowledge base holds no
repository-scoped insight for `composer-local-switcher`. Two global insights informed the
design: `d8e7dbeb-1ac5-46ba-b659-af1bccc8320e` (funnel a cross-cutting concern through one
choke-point rather than scattering it across call sites) motivated the `FileSystem` facade over
a `$dryRun` flag threaded through the switch methods, and `f5a35b57-8d4b-49f3-94bc-563ba34e9eb2`
(extract a seam rather than mock a hard collaborator) informed how the dry-run path is tested.

Two predecessor plans in this repository have run to completion (refreshed 2026-09-30).
`docs/agents/plans/2026-09-28-self-sufficient-test-surface/plan.md` built the in-repository
two-tier test surface and is recorded in `changelog.md` as **v2.0.0**: PHP floor `>=8.4`, and the
consumer script-key rename `verify-config` → `switch-verify-config` / `install-hooks` →
`switch-install-hooks`. Its rework, `docs/agents/plans/2026-09-29-self-sufficient-test-surface-rework-1/`,
reshaped the harness without touching `src/`. It added `WorkCopy` / `FixtureFileSystem` as the
single owner of work-copy lifecycle, `GitRunner` / `ProcessResult` as the git choke-point, an
atomically acquired `LocalPackageClone` and shared Tier 2 helpers on `IntegrationTestCase`, and it
ended with `composer test` 38/38 and `composer test-integration` 52/52.

That rework deliberately left the protected surface of `ComposerSwitcherTestCase` unchanged for
this plan's five new suites. Its synthesis deferred four harness items and handed one to this plan.
The two cheap items (collision-throw tests, `GitRunner` edge cases) are absorbed as step 9.2. The
handoff is absorbed as step 9.1: `TestVersionOverride` stops at a failed `composer update` because
what `switch-prod` does next is this plan's AC-13 defect, a link this plan had never stated. The rest are recorded under `Out of Scope`. Both predecessors reached a major version, and
the README script block they edited is extended rather than reconciled here — see `Constraints`
and `Considered Alternatives`.

## Summary

Make the switching mechanism observable and programmatically consumable. Today a caller —
human or agent — must combine `getStatus()`, `verify()` and its own file-existence checks to
learn what state the project is in; message codes that are public API are silently discarded by
`sprintf()`; drift can be detected but not fixed; a switch cannot be previewed; and exceptions
carry their facts inside prose. This plan introduces a small set of value objects (message,
file operation, switch outcome, verification result, state description), routes every file
mutation through one `FileSystem` choke-point so a true dry run becomes possible without
duplicating the switch logic, unifies the two divergent reconciliation mechanisms into one
content-first `reconcile()`, adds a single `describe()` "state of the world" call with human and
JSON Composer entry points, gives `ComposerSwitcherException` a structured context payload, and
documents the whole surface in a new state × action decision table. Three latent defects found
inside the blast radius — a switch that silently aborts when `composer.lock` is missing, a
lock backup that never runs on first use, and a throw site missing its error code — are fixed
in the same pass. The result is released as **v3.0.0**, since `verify()`, `getMessages()` and
`switchTo()` change their return types. The predecessor plan takes v2.0.0 for the PHP floor bump,
so this plan's major lands one above it.

## Architectural Context

`src/ConfigSwitcher.php` is a single orchestrator (709 lines) over six utility classes in
`src/Utils/`. It holds one `ConfigFile` per role — main, prod, dev — plus a `StatusFile` whose
path is derived from the dev config path (L142), and a `ConsoleWriter` that is disabled by
default. State lives entirely on disk: a JSON status file (mode, date, three canonical paths)
and `composer.json.DEV` / `composer.json.PROD` flag files.

Switching is a state machine dispatched by `switch_copyLockFiles()` (L380–L410) across four
cases — `DEV_DEV`, `PROD_PROD`, `DEV_PROD`, `PROD_DEV` — preceded on the first ever switch by
`switch_initProductionFiles()` (L498–L515). Both PROD-bound cases copy `composer-prod.json`
outright; the DEV-bound cases rebuild `composer.json` from the prod baseline in
`switch_adjustConfigForDev()` (L561–L683), which is the only place `composer.json` is
synthesised rather than copied.

Two independent drift mechanisms coexist. `verify()` (L196–L230) normalises both configs with a
recursive `ksort()` and compares top-level keys by content; it is read-only. `switch_case_PROD_PROD()`
(L420–L440) compares `filemtime()` values and copies in whichever direction the newer file
points. The two disagree in both directions: identical content with different mtimes triggers a
pointless copy, and different content with equal mtimes is ignored entirely.

All file I/O is performed by direct PHP calls inside `src/Utils/BaseFile.php` (`copy`, `unlink`,
`file_exists`, `filemtime`) and `src/Utils/ConfigFile.php` (`file_get_contents`,
`file_put_contents`). There is no seam between the orchestrator and the disk, which is what
makes a dry run impossible today without duplicating logic.

Messages are collected as plain strings by `addMessage()` (L545–L551), which is `sprintf()`-style.
Two call sites (L320–L323, L484–L487) pass a `MESSAGE_*` constant as a variadic argument into a
format string containing no placeholder, so `sprintf()` discards it — the constants are public
API (`docs/agents/project-manifest/api-surface.md` L14–L15) yet unreachable from `getMessages()`.

Tests run in two tiers (`docs/agents/project-manifest/constraints.md` Test Tiers). Tier 1
(`tests/TestSuites/`, `composer test`) is hermetic: suites extend
`tests/TestClasses/ComposerSwitcherTestCase.php`, whose `setUp()` (L25–L40) allocates a
`WorkCopy` and fills it from the `test-project` fixture. Tier 2 (`tests/IntegrationSuites/`,
`composer test-integration`) runs the real `git` and `composer` binaries through
`GitRunner` / `ComposerRunner` and skips gracefully when either is unavailable. Work-copy
creation, removal and stale purging are owned by `tests/TestClasses/WorkCopy.php` and
`tests/TestClasses/FixtureFileSystem.php`.

Both consumer projects reference the library only through the five static Composer entry points;
a repository-wide grep found no PHP call site for any `ConfigSwitcher` instance method in either
(`../mailforge/composer.json` L110–L114, `../hcp-editor/composer.json` L182, L213–L216).

## Approach / Architecture

Five layers, built in dependency order:

1. **Value objects** (new `src/State/`). `SwitchMessage` (code + text), `FileOperation`
   (type, source, target, reason, applied flag), `SwitchOutcome` (mode, dry-run flag, messages,
   operations), `VerificationResult` (in-sync, differences, dev-mode) and `SwitchDescription`
   (the full state blob). Every one exposes typed getters plus a `toArray()` with a documented
   array shape, so the JSON entry point is a one-liner and PHPStan keeps the shapes honest.

2. **One write choke-point** (new `src/Utils/FileSystem.php`). A thin facade over
   `file_exists`, `file_get_contents`, `file_put_contents`, `copy`, `unlink` and `filemtime`.
   `BaseFile` and its subclasses delegate all I/O to it; `ConfigSwitcher` owns one instance and
   propagates it to its four files and to every `FlagFile` it creates. The facade records a
   `FileOperation` for every mutation — in real runs too, which is how `SwitchOutcome` can tell
   a caller what actually happened. In dry-run mode it records the operation, skips the disk,
   and keeps an in-memory overlay so later reads in the same run observe the pending writes.
   This is what lets `previewSwitch()` run the *real* switch code path rather than a parallel
   simulation.

3. **Structured messages.** `addMessage()` gains an explicit code as its first parameter and
   keeps `sprintf()` formatting for the remaining arguments. Every one of the eleven call
   sites gets a code from an extended `MESSAGE_*` block (`182203`–`182214`).
   `getMessages()` returns `SwitchMessage[]`; `getMessageTexts()` returns the rendered strings
   `displayMessages()` prints.

4. **Unified reconciliation.** `reconcile()` becomes the single implementation:
   content first (`verify()`), direction second (explicit argument, else `filemtime()`), with an
   explicit ambiguous outcome when content differs but mtimes are equal. `switch_case_PROD_PROD()`
   is reduced to a call into it, so the two mechanisms become one.

5. **State description and entry points.** `describe()` assembles a `SwitchDescription` from the
   status file, `verify()`, and file presence/mtime probes. Five new Composer entry points expose
   describe (human + JSON), reconcile, and preview for each mode.

Documentation follows in the same pass: a new manifest document holds the state × action
decision table, and the existing five manifest documents are updated per the `AGENTS.md` §2
maintenance rules.

## Rationale

The unifying defect is that the switcher tells a caller what it did only through prose — console
lines and message strings — while its structured facts (codes, diffs, file effects, error
context) are either discarded or never assembled. Every item in this plan replaces a prose
channel with a typed one, and the human-readable rendering is then derived from the typed data
rather than being the only representation.

The `FileSystem` facade is the load-bearing decision. A `$dryRun` boolean threaded through
`switchTo()` → `switch_copyLockFiles()` → four case methods → `switch_adjustConfigForDev()`
would have to be honoured at a dozen independent mutation sites, and any site that forgets it
writes to disk during a "preview" — the precise failure mode the choke-point insight
(`d8e7dbeb`) describes. Routing I/O through one object makes the guarantee structural: a dry run
cannot write because the writer refuses to, not because every caller remembered to ask.

Reconciliation is made content-first because content is the question the user actually has
("do these two files agree?") and mtime only answers "which was touched last". Keeping mtime as
the *direction* heuristic preserves the existing behaviour that works, while the new ambiguous
outcome surfaces the case the old code silently swallowed.

The value objects are not speculative: each has a named consumer in this plan —
`SwitchMessage` for `getMessages()`, `FileOperation` for `SwitchOutcome::getOperations()`,
`SwitchOutcome` for `switchTo()`/`reconcile()`/`previewSwitch()`, `VerificationResult` for
`verify()`, `SwitchDescription` for `describe()` and the JSON entry point. `SwitchOutcome`
deliberately serves four methods rather than growing a per-method result class.

The major version bump is the honest semver reading of three changed public return types. Both
consumers use only the static entry points, so the runtime blast radius is nil; the cost is two
constraint edits recorded under `Human Actions`.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| Message shape | `SwitchMessage` value object; `getMessages(): SwitchMessage[]`, `getMessageTexts(): string[]` | `array{code:int,text:string}` as the user sketched; keep `string[]` and add `getMessageLog()` | An array shape needs a PHPDoc contract restated at every consumer and cannot grow a `__toString()` or a severity later. A parallel `getMessageLog()` leaves the lossy method as the obvious one to call. The object is the same amount of code with a real type. |
| Dry run | `FileSystem` facade with an operation log and a dry-run overlay | `$dryRun` flag threaded through the switch methods; a separate `previewSwitch()` that recomputes the effects independently | A threaded flag must be honoured at ~12 mutation sites and silently writes if one is missed. An independent preview duplicates the state machine and will drift from it — the same two-implementations problem this plan is fixing for reconciliation. |
| Dry-run reads | In-memory overlay inside `FileSystem`: reads see pending writes | No overlay (reads always hit disk); copy the project to a temp directory and run for real | Without an overlay, a preview of the first-ever switch crashes: `switch_adjustConfigForDev()` reads `composer-prod.json`, which `switch_initProductionFiles()` would only just have created. A temp-directory sandbox would have to rewrite every absolute path in the status file and is far heavier than an overlay keyed by path. |
| Reconciliation | One `reconcile()`: content decides *whether*, mtime decides *which way*, ambiguity reported | Keep mtime-only (status quo); content-only with no automatic direction; always prefer prod → main | Mtime-only copies identical files and ignores real drift with equal mtimes. Content-only cannot pick a direction unattended, which breaks `switch-update`. Always-prefer-prod would silently destroy a direct `composer.json` edit the current code preserves. |
| State description | `describe(): SwitchDescription` with `toArray()`, plus two entry points (`switch-describe`, `switch-describe-json`) | A single entry point switching on an `--json` argument | Reading Composer's argument vector requires either the `Composer\Script\Event` type hint — which would add a `composer-plugin-api` dependency to a deliberately zero-dependency library — or `$_SERVER['argv']` parsing. Two entry points cost three lines and keep the dependency count at zero. |
| Exception context | `setContext(array)` / `getContext()` plus `KEY_*` constants; constructor signature untouched | Add a fourth constructor parameter; typed per-case exception subclasses | A fourth parameter breaks every existing `new ComposerSwitcherException(...)` call and any consumer subclass. Per-case subclasses multiply ten error codes into ten classes for no gain, since the code already discriminates. |
| Versioning | v3.0.0 | v2.1.0 with additive-only API, keeping `getMessages(): string[]` | Three public return types change; calling that a minor release would be wrong, and any unbounded consumer constraint would pull it in as if it were compatible either way. A major tag makes the boundary explicit and is cheap here, because neither consumer calls an instance method. v2.0.0 is taken by the predecessor plan's PHP floor bump. |
| Value-object location | New `src/State/` subdirectory and `Mistralys\ComposerSwitcher\State` namespace | Put them in `src/Utils/`; put them in the root namespace | `Utils/` holds file-system abstractions exclusively; mixing result types in would blur a boundary the manifest documents. Classmap autoloading over `src/` picks up a new subdirectory with no configuration change. |
| Missing-lock switch | Guard the lock restore, then let the switch proceed with a warning | Keep the early return; throw an exception instead | The early return contradicts the message it prints ("run `composer update` **after switching**") and leaves a freshly cloned project unable to switch at all. Throwing would turn a recoverable situation into a hard failure for the same clone case. |

## Pattern Alignment

- **Deliberate departure:** new code uses short `[]` array syntax rather than the `array()` long form of `src/ConfigSwitcher.php` (L199, L342, L607), under the same PHP 8 modernisation policy. Touched methods are converted in the same pass; untouched ones keep the long form until a later plan reaches them.
- **Deliberate departure:** all new code uses PHP 8 constructs — typed properties, constructor promotion and `readonly` where the value is immutable — rather than the `@var`-docblock style of `src/Utils/LockFile.php` (L11–L21). That style is legacy, not a convention: the predecessor plan raises the floor to `>=8.4` and codifies that new code uses PHP 8 constructs while touched files may be modernised in the same pass (maintainer decision, 2026-09-28).
- Per that same policy, the existing methods this plan already rewrites — `verify()`, `switchTo()`, `getMessages()`, `reconcile()` and the `Utils/` call sites routed through `FileSystem` — are modernised in the same pass, since they fall inside this plan's blast radius. Untouched `src/` files are left alone.
- Follows the fluent `self`-returning setter pattern of `setFlagFileEnabled()` (L150) for the new `setDryRun()`.
- Follows the `1821xx` error-code scheme (`docs/agents/project-manifest/constraints.md` L11): the new exception code takes `182111`, and the new message codes take `182203`–`182214` in the switcher's `1822xx` block.
- Follows the `str_replace('.json', ...)` path-derivation convention (`constraints.md` L16–L19); no new derivation rules are introduced.
- Follows the `// region:` test-file structure of `tests/TestSuites/TestSwitching.php` (L14, L427) in all new suites.
- Follows the existing fixture workflow: each Tier 1 test gets its work copy from `ComposerSwitcherTestCase::setUp()` (`tests/TestClasses/ComposerSwitcherTestCase.php` L25–L40), which delegates to `WorkCopy::allocate()` / `createFromFixture()`. A test that needs a second, independent copy (`test_previewOperationsMatchRealSwitch`) allocates it through the same `WorkCopy` API and removes it in a `finally` block. It never copies the fixture by hand.
- Follows the harness-test pattern of `tests/TestSuites/TestWorkCopy.php` (direct `TestCase`, throwaway root under `sys_get_temp_dir()`) for the new `tests/TestSuites/TestFixtureFileSystem.php`, and the isolated-runner pattern of `tests/IntegrationSuites/TestGitRunner.php` for the new `GitRunner` edge-case tests.
- Follows the Tier rule in `docs/agents/project-manifest/constraints.md` (Test Conventions): the new `GitRunner` and `TestVersionOverride` tests spawn `git` / Composer and so live in Tier 2; everything else stays in Tier 1.
- **Departure — new namespace.** `Mistralys\ComposerSwitcher\State` is a third namespace where `constraints.md` (L7) declares two. Justified: the value objects are results, not file abstractions, and `src/Utils/` is documented as the file-abstraction home. `constraints.md` and `AGENTS.md` §5 are updated in step 11 rather than left contradicting the code.
- **Departure — a facade between the file classes and PHP's filesystem functions.** The established pattern is direct calls inside `BaseFile`/`ConfigFile`. Justified by the dry-run requirement, which has no correct implementation without a single interception point; `tech-stack.md` records the new pattern in step 11.

## Structural Improvements

| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `src/ConfigSwitcher.php` (L545–L551) `addMessage()` | `sprintf()`-style with no code channel; the two public `MESSAGE_*` constants are discarded at L320–L323 and L484–L487, and eleven further calls carry no code at all. | Promoted to step 3 | This is the plan's originating defect; a code parameter plus codes for every call site is the whole fix. |
| `src/ConfigSwitcher.php` (L420–L440) vs `verify()` (L196–L230) | Two independent drift mechanisms — `filemtime()` comparison and `ksort()`+content diff — that disagree in both directions. | Promoted to step 5 | Leaving two implementations is the exact divergence the request asks to remove; `switch_case_PROD_PROD()` becomes a call into the unified `reconcile()`. |
| `src/ConfigSwitcher.php` (L318–L327) missing-lock early return | Aborts the entire switch — no config rewrite, no status file, no flag file — while printing a message that says the switch happened. A freshly cloned project without `composer.lock` cannot switch at all. | Promoted to step 6 | A latent defect directly in the blast radius of the switch-flow rework, and the message text is evidence of the intended behaviour. |
| `src/Utils/BaseFile.php` (L98–L103) `tryCopyTo()` | Requires **both** source and target to exist, so the first lock backup at L432/L438 silently never happens — the case where a backup matters most. | Promoted to step 2 | Both call sites want "copy if the source exists"; the file-abstraction layer is already being reworked for the `FileSystem` facade in the same step. |
| `src/Utils/ConfigFile.php` (L38–L40) | `throw new ComposerSwitcherException('Failed to read file: ' . $path);` passes no error code, so `getCode()` returns `0`, while the equivalent failure in `src/Utils/LockFile.php` (L31–L34) uses `ERROR_CANNOT_READ_FILE`. | Promoted to step 7 | Step 7 revisits every throw site to attach context; fixing the missing code there costs one argument. |
| `src/ConfigSwitcher.php` (L540–L543) `$messages` property | Declared mid-class, after `displayMessages()`, away from the property block at L30–L63. | Promoted to step 3 | The property's type changes in step 3 anyway; relocating it while editing it costs nothing. |
| `src/ConfigSwitcher.php` (L58) `$displayMessages` | Initialised `true` and never written — there is no setter, so `autoDisplayMessages()` is effectively unconditional. | Promoted to step 3 (add `setDisplayMessages(bool)`) | A dry run that prints its messages as if they were real actions is misleading; the preview entry points need to control rendering, which requires the missing setter. |
| `src/ConfigSwitcher.php` (L254–L269) `installGitHooks()` | Uses bare `copy()` + `chmod()` on `.git/hooks/pre-commit`, bypassing the file abstractions and therefore the new `FileSystem` facade. | Rejected | It sits outside the switch flow, its target is not a `BaseFile`, and `chmod` has no meaningful dry-run representation. Routing it through the facade would widen the facade's contract for one caller that never needs previewing. |
| `docs/agents/project-manifest/README.md` (L4) | Carries the version line the predecessor plan set to `2.0.0`; this plan ships `3.0.0`, so it goes stale again the moment this work lands. | Promoted to step 11 | The file is edited in this step regardless, and a stale version line in the manifest index misleads every agent that reads it first. |
| `README.md` (L87–L88) | The "only edit `composer-prod.json`" WARNING says nothing about PROD-mode reconciliation being best-effort. | Promoted to step 10 | Explicitly requested, and the reconciliation semantics change in step 5, so the note documents new behaviour rather than merely annotating old. |
| `tests/TestSuites/TestSwitching.php` (L273–L312) | Three tests assert `verify()`'s raw array shape. | Promoted to step 9.1 | The return type changes in step 4; the tests must move to the value object's accessors. |
| `tests/TestClasses/WorkCopy.php` (L60, L80), `tests/TestClasses/FixtureFileSystem.php` (L78) | The path-existence predicate `is_dir \|\| is_file \|\| is_link` is written out three times, once per collision guard. | Promoted to step 9.2 | Step 9.2 adds tests for exactly these guards; one `FixtureFileSystem::pathExists()` predicate gives the three guards one definition to test, instead of three copies that can drift. |
| `tests/TestSuites/TestWorkCopy.php` (L24–L27) | `$workRoot` is an untyped property with an `@var` docblock. | Promoted to step 9.2 | The file is edited in step 9.2, and the PHP 8 policy (`constraints.md` L6) modernises touched files in the same pass. |
| `tests/TestClasses/GitRunner.php` (L34–L46) | `run()` throws Symfony's `RuntimeException` for a missing working directory (`vendor/symfony/process/Process.php` L372–L373), but its docblock declares no `@throws`. | Promoted to step 9.2 | The new edge-case test pins that behaviour; the docblock must state the contract the test asserts. |
| `tests/IntegrationSuites/TestVersionOverride.php` (L108–L111) | The `test_malformedVersionFailsComposerUpdate()` docblock defers the post-failure `switch-prod` behaviour to this plan's AC-13. The link is real but was never stated here. A PROD→DEV switch with no DEV lock deletes `composer.lock` (`src/ConfigSwitcher.php` L480–L488), and a failed `composer update` writes none, so the next `switch-prod` hits the missing-lock early return (L318–L327) and aborts silently, leaving the user stuck in DEV. No test proves the recovery end to end. | Promoted to step 9.1 | Step 6 removes exactly that early return. A Tier 2 recovery test is the only end-to-end proof that a user who hits a rejected version can get back to PROD. |
| `tests/TestClasses/LocalPackageClone.php` | The upstream clone follows the branch head instead of a pinned tag or commit. | Rejected | Pinning changes Composer's inferred `dev-master` version, which `TestVersionOverride` depends on; this plan does not rework that dependency. Recorded in `Out of Scope`. |
| `tests/TestClasses/LocalPackageClone.php` (L139) | The concurrent-winner rename-failure/adopt branch is untested. | Rejected | Testing it deterministically needs a new injectable rename hook in a class this plan does not otherwise touch. Recorded in `Out of Scope`. |

## Detailed Steps

1. **Value objects** — create `src/State/` with five classes in namespace `Mistralys\ComposerSwitcher\State`. All use PHP 8 constructs: `readonly` promoted constructor properties with real type declarations, and getters. Typed properties replace `@var` docblocks; `[]` replaces `array()` syntax in all new code (see `Pattern Alignment`).
   - `src/State/SwitchMessage.php` — `__construct(int $code, string $text)`; `getCode(): int`, `getText(): string`, `hasCode(): bool` (code `> 0`), `__toString(): string` (returns the text), `toArray(): array{code:int,text:string}`.
   - `src/State/FileOperation.php` — constants `TYPE_COPY = 'copy'`, `TYPE_WRITE = 'write'`, `TYPE_DELETE = 'delete'`; `__construct(string $type, string $targetPath, ?string $sourcePath, string $reason, bool $applied)`; getters for each; `toArray(): array{type:string,target:string,source:string|null,reason:string,applied:bool}`.
   - `src/State/VerificationResult.php` — `__construct(bool $devMode, bool $inSync, string[] $differences)`; `isDevMode(): bool`, `isComparable(): bool` (`!devMode`), `isInSync(): bool` (always `false` when not comparable, preserving today's DEV-mode value), `getDifferences(): string[]`, `toArray(): array{inSync:bool,differences:string[],devMode:bool}`. The `devMode` key is now always present, where the old array omitted it outside DEV.
   - `src/State/SwitchOutcome.php` — `__construct(string $mode, bool $dryRun, SwitchMessage[] $messages, FileOperation[] $operations)`; `getMode(): string`, `isDryRun(): bool`, `getMessages(): SwitchMessage[]`, `getMessageTexts(): string[]`, `getOperations(): FileOperation[]`, `hasOperations(): bool`, `toArray()`. Returned by `switchTo()`, `switchUpdate()`, `reconcile()` and `previewSwitch()`.
   - `src/State/SwitchDescription.php` — see step 8 for its payload; `toArray()` plus typed getters.

2. **`FileSystem` choke-point** — create `src/Utils/FileSystem.php` and route all I/O through it.
   - Methods: `exists(string $path): bool`, `read(string $path): ?string`, `modifiedTime(string $path): ?int`, `write(string $path, string $content, string $reason): void`, `copy(string $from, string $to, string $reason): void`, `delete(string $path, string $reason): void`.
   - State: `$dryRun` (bool, default `false`), `$overlay` (`array<string, string|null>` — path → pending content, `null` meaning pending deletion), `$operations` (`FileOperation[]`).
   - `setDryRun(bool $dryRun): void`, `isDryRun(): bool`, `getOperations(): FileOperation[]`, `clearOperations(): void`.
   - Real mode: perform the I/O, then record an applied `FileOperation`. Throw the same `ComposerSwitcherException` codes the current call sites throw (`ERROR_CANNOT_WRITE_FILE`, `ERROR_CANNOT_DELETE_FILE`, `ERROR_CANNOT_COPY_FILE`, `ERROR_CANNOT_READ_FILE`).
   - Dry-run mode: record an unapplied `FileOperation`, write to `$overlay` instead of disk, and never call a mutating PHP function. `exists()` and `read()` consult `$overlay` before the disk (an overlay value of `null` means "deleted", so `exists()` returns `false`). `copy()` resolves the source through `read()` so a copy of a pending write works. `modifiedTime()` returns the current timestamp for any path present in `$overlay`, so mtime comparisons behave as if the write had landed.
   - `src/Utils/BaseFile.php`: add a private `$fileSystem` property, a `getFileSystem(): FileSystem` accessor that lazily creates a private default instance, and `setFileSystem(FileSystem $fs): void`. Rewrite `exists()`, `getModifiedDate()`, `delete()` and `copyTo()` to delegate. `copyTo()` and `delete()` gain an optional `string $reason = ''` parameter passed through to the facade.
   - `src/Utils/BaseFile.php` `tryCopyTo()`: change the condition from `$this->exists() && $target->exists()` to `$this->exists()` only, and update the docblock at L92–L97 to "copies only if the source file exists".
   - `src/Utils/ConfigFile.php`: `getData()` and `putData()` delegate to the facade; override `setFileSystem()` to also set it on the `LockFile` created at L21. Keep the `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` encoding and the trailing `PHP_EOL`.
   - `src/Utils/LockFile.php` `getContent()` and `src/Utils/FlagFile.php` `create()`: delegate to the facade.
   - `src/ConfigSwitcher.php`: create one `FileSystem` in the constructor, store it, and call `setFileSystem()` on `$mainFile`, `$prodFile`, `$devFile` and `$statusFile`; `getFlagFile()` (L355–L360) sets it on every `FlagFile` it returns. Add `getFileSystem(): FileSystem`.

3. **Structured messages** — in `src/ConfigSwitcher.php`:
   - Extend the message-code block after L26 with `MESSAGE_USING_DEV_CONFIG = 182203`, `MESSAGE_USING_PROD_CONFIG = 182204`, `MESSAGE_BACKED_UP_MAIN_TO_PROD = 182205`, `MESSAGE_RESTORED_PROD_TO_MAIN = 182206`, `MESSAGE_RUN_INSTALL_PROD = 182207`, `MESSAGE_RUN_INSTALL_DEV = 182208`, `MESSAGE_REBUILT_DEV_CONFIG = 182209`, `MESSAGE_ALREADY_IN_SYNC = 182210`, `MESSAGE_RECONCILE_AMBIGUOUS = 182211`, `MESSAGE_DEV_MODE_NOT_RECONCILABLE = 182212`, `MESSAGE_DRY_RUN_ACTIVE = 182213`, `MESSAGE_PROD_LOCK_MISSING = 182214`.
   - Change `addMessage()` (L545–L551) to `private function addMessage(int $code, string $message, ...$args) : void`, appending `new SwitchMessage($code, sprintf($message, ...$args))`.
   - Update all eleven call sites to pass a code first: L320 → `MESSAGE_NO_LOCK_FILE_FOUND`, L415 → `MESSAGE_USING_DEV_CONFIG`, L423 → `MESSAGE_USING_PROD_CONFIG`, L430 → `MESSAGE_BACKED_UP_MAIN_TO_PROD`, L436 → `MESSAGE_RESTORED_PROD_TO_MAIN`, L445 → `MESSAGE_USING_PROD_CONFIG`, L458 → `MESSAGE_RUN_INSTALL_PROD`, L464 → `MESSAGE_USING_DEV_CONFIG`, L477 → `MESSAGE_RUN_INSTALL_DEV`, L484 → `MESSAGE_CREATE_NEW_LOCK_FILE`, L682 → `MESSAGE_REBUILT_DEV_CONFIG`.
   - Move the `$messages` property from L540–L543 into the property block after L63, retyped `@var SwitchMessage[]`.
   - `getMessages(): array` now returns `SwitchMessage[]` (`@return SwitchMessage[]`); add `getMessageTexts(): array` (`@return string[]`) mapping over `getText()`.
   - `displayMessages()` (L527–L538) renders `getMessageTexts()`; output is byte-identical to today's.
   - Add `setDisplayMessages(bool $display): self` beside the other fluent setters (after L164) writing the existing `$displayMessages` property.
   - Add `clearMessages(): void` (private) called at the start of `switchTo()` and `reconcile()` so each operation returns only its own messages.

4. **`verify()` returns a value object** — in `src/ConfigSwitcher.php` change `verify()` (L196–L230) to return `VerificationResult`. The DEV short-circuit returns `new VerificationResult(true, false, array())`; the normal path returns `new VerificationResult(false, empty($differences), $differences)`. The `recursiveKsort()` comparison logic is unchanged. Update `composerVerifyConfig()` (L98–L117) to use `isDevMode()` / `isInSync()` / `getDifferences()`, keeping its printed output identical.

5. **Unified reconciliation** — in `src/ConfigSwitcher.php`:
   - Add `public const RECONCILE_TO_MAIN = 'to-main'` and `RECONCILE_TO_PROD = 'to-prod'`.
   - Add `reconcile(?string $direction = null, bool $dryRun = false): SwitchOutcome`:
     1. Clear messages, set the facade's dry-run flag, clear its operation log; add `MESSAGE_DRY_RUN_ACTIVE` when previewing.
     2. If `$direction` is non-null and is neither constant, throw `ComposerSwitcherException` with the new `ERROR_INVALID_RECONCILE_DIRECTION` (`182111`) and context keys for the supplied value.
     3. If the status is DEV: add `MESSAGE_DEV_MODE_NOT_RECONCILABLE` ("`composer.json` is generated from `composer-prod.json` in DEV mode — run `switch-dev` to rebuild it") and return an outcome with no operations.
     4. Run `verify()`. If in sync, add `MESSAGE_ALREADY_IN_SYNC` and return with no operations — this is the behavioural change that stops identical-content copies.
     5. Resolve the direction: the explicit argument wins; otherwise compare `getModifiedDate()` of main and prod — main newer → `RECONCILE_TO_PROD`, prod newer → `RECONCILE_TO_MAIN`. If the timestamps are equal, add `MESSAGE_RECONCILE_AMBIGUOUS` naming both files and the differing keys, perform no write, and return.
     6. Apply: `RECONCILE_TO_PROD` copies main → prod plus `tryCopyTo()` on the lock files and adds `MESSAGE_BACKED_UP_MAIN_TO_PROD`; `RECONCILE_TO_MAIN` copies prod → main plus the lock files and adds `MESSAGE_RESTORED_PROD_TO_MAIN`.
     7. Restore the facade's previous dry-run flag and return the outcome built from the messages and the facade's operation log.
   - Replace the body of `switch_case_PROD_PROD()` (L420–L440) with the console line, `MESSAGE_USING_PROD_CONFIG`, and a delegation to the reconciliation core, so exactly one implementation remains. Its messages merge into the enclosing switch's message list.

6. **Missing-lock handling** — in `src/ConfigSwitcher.php`:
   - In `switch_case_DEV_PROD()` (L442–L459) replace the unconditional `$this->prodFile->getLockFile()->copyTo($this->mainFile->getLockFile())` at L456 with: copy when the prod lock exists; otherwise delete the main lock file (if any) and add `MESSAGE_PROD_LOCK_MISSING` ("No production lock file found — run `composer update` after switching.").
   - Remove the early `return` at L318–L327: keep the `MESSAGE_NO_LOCK_FILE_FOUND` warning, then continue into `switch_copyLockFiles()`, `saveState()` and `writeFlagFiles()` so the switch completes.
   - Change `switchTo()` to return `SwitchOutcome`, and `switchToDevelopment()`, `switchToProduction()`, `switchUpdate()` accordingly. `switchUpdate()` returns an outcome with mode `initial` and no operations when the status is INITIAL, replacing today's silent no-op.

7. **Exception context** — in `src/ComposerSwitcherException.php`:
   - Add `ERROR_INVALID_RECONCILE_DIRECTION = 182111`.
   - Add context key constants `KEY_FILE_PATH = 'filePath'`, `KEY_TARGET_PATH = 'targetPath'`, `KEY_MODE = 'mode'`, `KEY_DIRECTION = 'direction'`, `KEY_EXPECTED = 'expected'`, `KEY_ACTUAL = 'actual'`, `KEY_PACKAGE_NAME = 'packageName'`.
   - Add a private `$context` array (default `array()`), `setContext(array $context): self` (fluent, returns `$this`), `getContext(): array` and `getContextValue(string $key)`. The constructor signature is unchanged.
   - Attach context at every throw site listed in the research brief: `src/ConfigSwitcher.php` L346–L352 (mode, expected list), L566–L569 (dev file path), L577–L583 (dev file path, expected key), L589–L592 (dev file path, package name where known); `src/Utils/BaseFile.php` L54–L60, L70–L73, L85–L88 (file path, target path); `src/Utils/ConfigFile.php` L45–L49, L53–L56, L76–L80, L84–L87; `src/Utils/LockFile.php` L31–L34; `src/Utils/FlagFile.php` L30–L36; plus every throw introduced by `FileSystem` in step 2.
   - Fix `src/Utils/ConfigFile.php` L38–L40: pass `ComposerSwitcherException::ERROR_CANNOT_READ_FILE` as the error code.

8. **`describe()` and new entry points** — in `src/ConfigSwitcher.php`:
   - Add `describe(): SwitchDescription`, assembling: `mode` (`dev` / `prod` / `initial`), `lastSwitchDate` (`StatusFile::getDate()`), a per-file record for main, prod and dev configs, their three lock files and the status file (path, exists, modified date as `Y-m-d H:i:s` or `null`), flag-file presence for both modes, the `VerificationResult` from `verify()`, and the `local-repositories` entries (package name + path + version) read from the dev file when it exists and parses. A dev file that is missing or malformed yields an empty list plus a `warnings` entry rather than an exception, so `describe()` is safe to call in any state.
   - `SwitchDescription::toArray()` returns the whole blob as a nested array with documented shapes; `SwitchDescription::toJSON(): string` encodes it with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.
   - Add `previewSwitch(string $mode): SwitchOutcome` — validates the mode via `requireValidMode()`, then runs `switchTo($mode, true)` with message display suppressed.
   - Add `switchTo(string $mode, bool $dryRun = false)`: set the facade's dry-run flag and `MESSAGE_DRY_RUN_ACTIVE` for the duration, restoring the previous value in a `finally` block so an exception mid-preview cannot leave the switcher stuck in dry-run mode.
   - Add five static entry points beside the existing ones (L83–L130): `composerSwitchDescribe()` (renders the human table), `composerSwitchDescribeJson()` (`echo describe()->toJSON()`), `composerSwitchReconcile()`, `composerSwitchPreviewDev()`, `composerSwitchPreviewProd()`. The preview entry points print each `FileOperation` as `would <type>: <source> -> <target> (<reason>)` followed by the messages.

9. **Tests** — enumerated in full in the Test Plan.
   1. **Switcher tests.** Update `tests/TestSuites/TestSwitching.php` and add five suites under `tests/TestSuites/`, each extending `ComposerSwitcherTestCase`. `TestDryRun::test_previewOperationsMatchRealSwitch` obtains its second work copy via `WorkCopy::allocate($this->assetsFolder . '/work-projects')` + `createFromFixture($this->testSource)` and removes it in a `finally` block. Add `test_switchProdRecoversAfterRejectedVersion` to `tests/IntegrationSuites/TestVersionOverride.php` (Tier 2): after the malformed-version `composer update` fails, `switch-prod` via `runComposerChecked()` succeeds and restores the PROD `composer.json` and `composer.lock`. This fails today because of the missing-lock early return (`src/ConfigSwitcher.php` L318–L327) and passes once step 6 lands. Rewrite the `test_malformedVersionFailsComposerUpdate()` docblock (L108–L111) to name this test as the continuation instead of the plan path.
   2. **Test-harness hardening** (deferred from `docs/agents/plans/2026-09-29-self-sufficient-test-surface-rework-1/synthesis.md`):
      - `tests/TestClasses/FixtureFileSystem.php`: add `public static function pathExists(string $path): bool` (`is_dir || is_file || is_link` — true for a dangling symlink too). Use it in `copyDirectory()` (L78) and in `WorkCopy::allocate()` (L60) and `createFromFixture()` (L80). Messages and exception types stay unchanged.
      - New `tests/TestSuites/TestFixtureFileSystem.php` (Tier 1, direct `TestCase`, throwaway root under `sys_get_temp_dir()`, modelled on `TestWorkCopy`): `pathExists()` for a directory, a file, a dangling symlink and a missing path; `copyDirectory()` throws `RuntimeException` onto an existing directory, an existing file and an existing symlink, and in each case leaves the destination untouched.
      - `tests/TestSuites/TestWorkCopy.php`: add `test_allocate_throwsOnCollision`. It reads the private static `WorkCopy::$counter` via `ReflectionProperty`, pre-creates the predicted next path `<root>/<YmdHis>-<pid>-<counter+1>` for both the current second and the next one (so a second boundary cannot make the test pass vacuously), and asserts `allocate()` throws. Convert `$workRoot` to a typed `private string $workRoot` property.
      - `tests/TestClasses/GitRunner.php`: add `@throws \Symfony\Component\Process\Exception\RuntimeException` to `run()`'s docblock for a working directory that does not exist. Behaviour is unchanged.
      - `tests/IntegrationSuites/TestGitRunner.php` (Tier 2): `test_runWithoutArgumentsReturnsNonZeroWithUsage` (bare `git` exits non-zero and prints usage, via `ProcessResult::containsOutput()` because the usage stream is platform-dependent); `test_runInMissingWorkingDirectoryThrows`; `test_runInReadOnlyDirectoryReportsFailure` (`git init` in a `chmod 0555` directory returns a non-zero `ProcessResult` with non-empty error output; skipped via `markTestSkipped()` when the directory is still writable after `chmod`, e.g. when running as root; permissions restored before cleanup).

10. **README** — in `README.md`:
    - After the WARNING at L87–L88, add a note that PROD-mode reconciliation is best-effort: it resolves drift by content comparison and picks a direction by modification time, and is not a substitute for editing `composer/composer-prod.json` directly. Name the ambiguous case (equal timestamps, differing content) and the DEV case where `composer.json` is generated and cannot be reconciled.
    - Add the five new script keys to the wiring example at L99–L106: `switch-describe`, `switch-describe-json`, `switch-reconcile`, `switch-preview-dev`, `switch-preview-prod`.
    - Update the "Verifying configuration sync" section (L216–L229) for `VerificationResult`.
    - Add a "Inspecting the current state" section documenting `describe()` and both describe commands, with a full example JSON payload.
    - Add a "Previewing a switch" section documenting `previewSwitch()` and the two preview commands.
    - Add a "Reconciling drift" section documenting `reconcile()`, its direction constants and the ambiguous outcome.
    - Add a "Programmatic and agent usage" section covering `SwitchOutcome`, `SwitchMessage` codes, `FileOperation`, and `ComposerSwitcherException::getContext()`.
    - Add a "Migrating from 2.x" section listing the three changed return types and their replacements.

11. **Manifest and project documentation**:
    - New `docs/agents/project-manifest/switching-decision-table.md` — a table whose rows are the current state (INITIAL / DEV / PROD, with and without `composer.lock`, in sync / drifted / ambiguous) and whose columns are the intended action, the exact command, the PHP call, the resulting file effects (which of `composer.json`, `composer.lock`, `composer-prod.json`, `composer-prod.lock`, `local-repositories.lock`, `.status`, flag files are written) and the message codes emitted. Every row must be traceable to a code path in `src/ConfigSwitcher.php`.
    - `docs/agents/project-manifest/README.md` — add the new document to the Sections table (L10–L18) and set the version line (L4) to `3.0.0`.
    - `docs/agents/project-manifest/api-surface.md` — add the `State` namespace section with all five value objects, the `FileSystem` class, the twelve new `MESSAGE_*` constants, `ERROR_INVALID_RECONCILE_DIRECTION`, the exception context API and `KEY_*` constants, the five new entry points, `describe()`, `reconcile()`, `previewSwitch()`, `setDisplayMessages()`, `getMessageTexts()`, `getFileSystem()`, the changed return types of `verify()`, `switchTo()`, `switchToDevelopment()`, `switchToProduction()`, `switchUpdate()` and `getMessages()`, and the changed `copyTo()` / `delete()` / `tryCopyTo()` signatures and semantics.
    - `docs/agents/project-manifest/file-tree.md` — add `src/State/` with its five files, `src/Utils/FileSystem.php`, the six new Tier 1 suites (five switcher suites plus `TestFixtureFileSystem.php`) and the new manifest document.
    - `docs/agents/project-manifest/data-flows.md` — rewrite flow 4 for the unified reconciliation, add flows for `describe()`, dry-run preview and the message/operation log, and extend the entry-point list at L110.
    - `docs/agents/project-manifest/constraints.md` — record the third namespace (L7), the extended code ranges (L11: `182101`–`182111`, `182201`–`182214`), the `FileSystem` write-choke-point rule ("no PHP filesystem function may be called outside `FileSystem`, except `installGitHooks()`"), the new `tryCopyTo()` semantics (File Conventions, L15–L19), and the new test suites plus the `FixtureFileSystem::pathExists()` guard predicate (Testing section, L38–L70).
    - `docs/agents/project-manifest/tech-stack.md` — add the value-object and write-choke-point patterns to "Architectural Patterns" (L40 onwards).
    - `AGENTS.md` — add the decision table to the §1 index (L12–L19) and to the §3 efficiency rules (L51–L56); add a §4 row pointing state/action questions at it; update the §5 Namespace row (L92).
    - `changelog.md` — add a `## v3.0.0` entry at the top, above the predecessor's `## v2.0.0`, covering every change above, with an explicit BREAKING block for the three return types and the `tryCopyTo()` semantics. Test-harness changes (step 9.2) get a short non-breaking bullet.

12. **Verification sweep** — run `composer analyze` (PHPStan level 6), `composer test` and `composer test-integration`; all three must be clean before handoff, and the Tier 2 run must show no skip in `TestGitRunner` or `TestVersionOverride`. The predecessor plan splits the suites, so `composer test` alone no longer covers everything.

## Dependencies

- Step 1 (value objects) precedes steps 3, 4, 5, 6 and 8 — every one returns or collects one.
- Step 2 (`FileSystem`) precedes steps 5, 6 and 8: reconciliation, the guarded lock restore and dry run all record operations through it.
- Step 3 (messages) precedes step 5 and step 8 — both add coded messages.
- Step 4 (`VerificationResult`) precedes step 5 (`reconcile()` consumes it) and step 8 (`describe()` embeds it).
- Step 5 precedes step 6: `switch_case_PROD_PROD()` must already delegate before `switchTo()`'s control flow changes.
- Step 7 (exception context) is independent of steps 3–6 but must follow step 2, which introduces new throw sites.
- Step 9.1 (switcher tests) depends on steps 1–8. Its `TestVersionOverride` recovery test depends on step 6 specifically. Step 9.2 (harness hardening) depends on nothing in `src/` and may run at any point before step 12; it should land first, so that step 9.1's second-`WorkCopy` test runs against the refactored guard.
- Steps 10 and 11 (documentation) depend on steps 1–8 for accuracy and may run in parallel with step 9.
- Step 12 depends on everything.

## Required Components

**New:**
- `src/State/SwitchMessage.php`
- `src/State/FileOperation.php`
- `src/State/VerificationResult.php`
- `src/State/SwitchOutcome.php`
- `src/State/SwitchDescription.php`
- `src/Utils/FileSystem.php`
- `tests/TestSuites/TestMessages.php`
- `tests/TestSuites/TestDescribe.php`
- `tests/TestSuites/TestReconcile.php`
- `tests/TestSuites/TestDryRun.php`
- `tests/TestSuites/TestExceptionContext.php`
- `tests/TestSuites/TestFixtureFileSystem.php`
- `docs/agents/project-manifest/switching-decision-table.md`

**Modified:**
- `src/ConfigSwitcher.php`
- `src/ComposerSwitcherException.php`
- `src/Utils/BaseFile.php`
- `src/Utils/ConfigFile.php`
- `src/Utils/LockFile.php`
- `src/Utils/FlagFile.php`
- `tests/TestSuites/TestSwitching.php`
- `tests/TestSuites/TestWorkCopy.php`
- `tests/TestClasses/FixtureFileSystem.php`
- `tests/TestClasses/WorkCopy.php`
- `tests/TestClasses/GitRunner.php`
- `tests/IntegrationSuites/TestGitRunner.php`
- `tests/IntegrationSuites/TestVersionOverride.php`
- `README.md`
- `changelog.md`
- `AGENTS.md`
- `docs/agents/project-manifest/README.md`
- `docs/agents/project-manifest/api-surface.md`
- `docs/agents/project-manifest/file-tree.md`
- `docs/agents/project-manifest/data-flows.md`
- `docs/agents/project-manifest/constraints.md`
- `docs/agents/project-manifest/tech-stack.md`

No new external dependencies. `src/State/` is picked up by the existing classmap autoload entry
for `src/` (`composer.json` L18–L22), so no autoload configuration changes.

## Assumptions

- The library stays at zero runtime dependencies; no Composer API class (`Composer\Script\Event`) may be referenced, which is why the JSON output is a separate entry point rather than a flag.
- `getMessages()`, `verify()` and `switchTo()` have no external callers beyond this repository. Verified for both known consumers: neither has a PHP call site for any `ConfigSwitcher` instance method, and both use only the static entry points.
- The console output produced by `displayMessages()`, `composerVerifyConfig()` and `ConsoleWriter` stays byte-identical for existing flows; only new flows add new output.
- The `tests/assets/test-project/` fixture remains the single source for test projects; new scenarios are produced by mutating the ephemeral copy, as the existing suite already does.
- Fixture lock files hold sentinel strings (`PROD` / `DEV`) rather than real Composer lock JSON; `FileSystem` must not assume lock files are parseable.

## Constraints

- PHP >= 8.4 (`docs/agents/project-manifest/constraints.md` L6): new code uses PHP 8 constructs — typed properties, constructor promotion, `readonly` — and there is no PHP 7 compatibility requirement. Touched files may be modernised in the same pass; untouched files are left alone.
- PHPStan level 6 over `src/` must stay at zero errors; every `toArray()` needs a documented array shape. The `phpVersion` pin was removed from `phpstan.neon` with the PHP 8.4 floor, so analysis follows the host PHP version.
- New codes follow `1821xx`: the exception class takes `182111`, the switcher takes `182203`–`182214`. No code is renumbered.
- After step 2, no PHP filesystem function may be called outside `src/Utils/FileSystem.php`, with `installGitHooks()` as the single documented exception.
- Dry-run mode must never reach the disk. The flag is restored in a `finally` block so an exception cannot leave the switcher in dry-run state.
- Do not change the switching semantics outside the cases named in steps 5 and 6: the DEV rebuild in `switch_adjustConfigForDev()`, VCS pruning, `require-dev` placement and path-repository generation stay exactly as they are.
- Do not edit `composer.json` in either consumer project, and do not touch `../hcp-editor/` or `../mailforge/` at all — this plan is library-only.
- `README.md` L99–L106 already carries the `switch-` prefixed keys when this plan starts, established by the v2.0.0 predecessor. The five new script keys introduced here follow the same `switch-` convention, so the wiring block is extended rather than re-namespaced.
- Do not run `composer update` in this repository; the lock file is committed and unrelated to this work.
- No test may touch the shared clone cache `tests/assets/local-clones/`, and every test that spawns `git` or Composer lives in Tier 2 (`constraints.md` Test Conventions).
- Mtime ordering between files is forced with `touch()`, never `sleep()` (`constraints.md` Test Conventions). This binds every reconcile and dry-run test.

## Out of Scope

- Renaming `verify-config` / `install-hooks` in the README wiring example — already delivered in v2.0.0 by `docs/agents/plans/2026-09-28-self-sufficient-test-surface/plan.md`.
- Any change to `../hcp-editor/` or `../mailforge/`, including their version constraints on the library (see `Human Actions`).
- Routing `installGitHooks()` through `FileSystem` — see `Structural Improvements`.
- Changing the DEV config generation algorithm: path-repository shape, VCS pruning, `require-dev` placement, underscore/hyphen matching.
- A `--json` variant for the other entry points; only `describe` gets one, because it is the state-inspection call agents need.
- A **wholesale** PHP 8 modernisation sweep of `src/`. Files this plan already touches are modernised in the same pass per the project policy; files it does not touch are left for the plans that next reach them.
- Deprecation shims or a 2.x compatibility branch.
- Pinning `LocalPackageClone`'s upstream clone to a tag or commit (rework-1 synthesis, WP-007). It would change Composer's inferred `dev-master` version, which `tests/IntegrationSuites/TestVersionOverride.php` depends on. Reconsider when a plan reworks that dependency.
- A deterministic test for `LocalPackageClone`'s concurrent-winner rename-failure/adopt branch (rework-1 synthesis, WP-007). It needs an injectable rename hook in a class this plan does not touch. Reconsider if Tier 2 suites start running in parallel.
- Scoping `resources/git-hooks/pre-commit` Guard 2 to the `repositories` key (rework-1 synthesis, WP-011). It belongs to the guard-registry reshape tracked by insight `2cd87f16-2556-4523-af27-0b88a6adbf0b`, and `test_guard2MatchesTypePathOutsideRepositories()` marks the change point.

## Human Actions

| # | Action | When | Why an agent cannot do it |
|---|--------|------|---------------------------|
| 1 | Tag and publish the `v3.0.0` release (git tag + Packagist), after reviewing the changelog entry produced in step 11. | After the run | Version control and package publishing are the user's; the persona is barred from git write operations, and Packagist publishing needs the maintainer's credentials. |
| 2 | Raise `../hcp-editor/composer.json` (L145) to `^3.0` when adopting the new release. | After the run | Its caret constraint will not resolve 3.0.0, so the consumer stays on whatever major it is pinned to until a person decides to adopt. The file is outside this plan's repository. |
| 3 | Give `../mailforge/composer.json` (L74) an explicit `^3.0` (or a lower major to stay behind). | After the run | If its constraint is still unbounded — it reads `>=1.0.4` unless tightened when v2.0.0 was adopted — it would pull 3.0.0 in silently on the next `composer update`. Choosing which major to pin to is the maintainer's call, in a repository this plan does not touch. |

## Acceptance Criteria

- AC-01: `ConfigSwitcher::getMessages()` returns `SwitchMessage[]`, and the messages produced for a missing lock file and for a forced DEV lock recreation carry the codes `MESSAGE_NO_LOCK_FILE_FOUND` (182201) and `MESSAGE_CREATE_NEW_LOCK_FILE` (182202) respectively.
- AC-02: Every message emitted by any switch, reconcile or preview operation carries a non-zero code; no `addMessage()` call site passes a code of `0` or omits one.
- AC-03: `displayMessages()` and `composerVerifyConfig()` produce byte-identical console output to v2.0.0 for the same inputs.
- AC-04: `describe()` returns a `SwitchDescription` reporting mode, last switch date, per-file existence and modification dates for all four configs and three lock files, flag-file presence per mode, the verification result, and the parsed `local-repositories` list — in INITIAL, DEV and PROD states, without throwing.
- AC-05: `describe()->toJSON()` returns valid JSON that round-trips through `json_decode()` into the same structure as `toArray()`.
- AC-06: `reconcile()` performs no file operation when `verify()` reports the configs in sync, even when their modification times differ.
- AC-07: `reconcile()` copies prod → main when `composer-prod.json` is newer and content differs, and main → prod when `composer.json` is newer and content differs; the corresponding lock file follows in each direction.
- AC-08: `reconcile()` performs no write and emits `MESSAGE_RECONCILE_AMBIGUOUS` when content differs but modification times are equal; supplying an explicit direction resolves the same case with a write.
- AC-09: `reconcile()` in DEV mode performs no write and emits `MESSAGE_DEV_MODE_NOT_RECONCILABLE`.
- AC-10: `switch_case_PROD_PROD()` contains no `filemtime` comparison of its own — a PROD→PROD switch and a direct `reconcile()` call produce the same file effects from the same starting state.
- AC-11: `previewSwitch()` for either mode leaves every file on disk byte-identical and every file modification time unchanged, while returning a `SwitchOutcome` whose operation list matches the operations a real switch from the same state performs.
- AC-12: A real `switchTo()` returns a `SwitchOutcome` whose operations are all marked applied and match the files actually written.
- AC-13: Switching with no `composer.lock` present completes the switch — status file written, flag file written, `composer.json` updated — and emits `MESSAGE_NO_LOCK_FILE_FOUND`, instead of aborting.
- AC-14: `BaseFile::tryCopyTo()` copies when the source exists and the target does not, and the first PROD→PROD lock backup therefore produces `composer-prod.lock`.
- AC-15: Every `ComposerSwitcherException` thrown by the library carries a non-zero error code and a context array containing at least the file path (or, for `ERROR_INVALID_SWITCH_MODE` / `ERROR_INVALID_RECONCILE_DIRECTION`, the offending value and the expected set).
- AC-16: `ConfigFile::getData()`'s read failure throws with `ERROR_CANNOT_READ_FILE` rather than code `0`.
- AC-17: No PHP filesystem function (`file_get_contents`, `file_put_contents`, `copy`, `unlink`, `file_exists`, `filemtime`) appears in `src/` outside `src/Utils/FileSystem.php`, except within `ConfigSwitcher::installGitHooks()`.
- AC-18: `composer analyze` reports zero PHPStan errors across `src/` and all three test directories, and both `composer test` and `composer test-integration` pass with every suite green.
- AC-19: `docs/agents/project-manifest/switching-decision-table.md` exists, is linked from `AGENTS.md` §1 and from the manifest `README.md` Sections table, and every command it names is registered in `README.md`'s wiring example.
- AC-20: The five manifest documents, `AGENTS.md` and `changelog.md` reflect the new classes, namespace, constants, code ranges, entry points, changed signatures and test suites, per the `AGENTS.md` §2 maintenance rules; the manifest `README.md` version line reads `3.0.0`.
- AC-21: `README.md` documents the best-effort nature of PROD-mode reconciliation next to the existing "only edit `composer-prod.json`" warning, and carries sections for state inspection, preview, reconciliation, programmatic/agent usage, and 2.x migration.
- AC-22: `FixtureFileSystem::pathExists()` is the only path-existence predicate in `tests/TestClasses/WorkCopy.php` and `tests/TestClasses/FixtureFileSystem.php`, and automated tests trigger the collision throw of `WorkCopy::allocate()` and of `FixtureFileSystem::copyDirectory()` (onto an existing directory, file and symlink) directly.
- AC-23: `GitRunner::run()` has automated coverage for a zero-argument call, a missing working directory (throws, as its docblock declares) and a read-only working directory (non-zero `ProcessResult`).
- AC-24: After a malformed `local-repositories` version makes `composer update` fail in DEV, `switch-prod` completes and restores the PROD `composer.json` and `composer.lock`. The `test_malformedVersionFailsComposerUpdate()` docblock names the recovery test instead of pointing at a plan document.

## Testing Strategy

PHPUnit against the existing fixture-copy harness: every test copies `tests/assets/test-project/`
into an ephemeral work directory and drives a real `ConfigSwitcher` over real files, which is how
the current suite works and the only way to assert file effects credibly. The new suites are
organised by concern rather than appended to `TestSwitching.php`, which already holds eighteen
tests. Dry-run coverage asserts *absence* of effects: file contents and modification times are
captured before the preview and compared after, and the operation list from the preview is
compared against the operation list from an equivalent real switch — this is the assertion that
catches a mutation site that bypassed the facade. A static guard test enforces the choke-point
rule by scanning `src/` for forbidden filesystem calls, so the rule survives future edits.
Harness hardening (step 9.2) follows the same two tiers: `TestFixtureFileSystem` and the new
`TestWorkCopy` case run in Tier 1, while the `GitRunner` edge cases and the
`TestVersionOverride` recovery test need real binaries and run in Tier 2, where they skip
gracefully if a binary is missing. The verification sweep therefore runs both
`composer test` and `composer test-integration`.
PHPStan level 6 guards the documented array shapes; the `phpVersion` pin was removed from
`phpstan.neon` when the floor rose to PHP 8.4, so the analysis runs against the host PHP version.
Documentation is verified by reading the changed files against the `AGENTS.md` §2 table.

## Test Plan

- `tests/TestSuites/TestMessages.php::test_missingLockFileMessageCarriesCode` — a switch with `composer.lock` deleted yields a `SwitchMessage` with code `182201` — AC-01.
- `tests/TestSuites/TestMessages.php::test_devLockRecreationMessageCarriesCode` — a PROD→DEV switch with no DEV lock yields code `182202` — AC-01.
- `tests/TestSuites/TestMessages.php::test_allMessagesCarryNonZeroCodes` — across initial, DEV→PROD, PROD→DEV and PROD→PROD switches plus a reconcile, every returned `SwitchMessage::getCode()` is non-zero — AC-02.
- `tests/TestSuites/TestMessages.php::test_messageTextsMatchLegacyStrings` — `getMessageTexts()` returns exactly the strings v2.0.0 produced for a DEV→PROD switch — AC-03.
- `tests/TestSuites/TestMessages.php::test_displayMessagesOutputUnchanged` — output buffering around `displayMessages()` asserts the rendered block (leading blank line, one line per message, trailing blank line) — AC-03.
- `tests/TestSuites/TestDescribe.php::test_describeInInitialState` — mode `initial`, null last-switch date, prod config absent, main config present, no flag files, three parsed local repositories — AC-04.
- `tests/TestSuites/TestDescribe.php::test_describeInDevState` — after a DEV switch: mode `dev`, DEV flag present, PROD flag absent, verification reports dev mode — AC-04.
- `tests/TestSuites/TestDescribe.php::test_describeInProdState` — after a PROD switch: mode `prod`, PROD flag present, verification in sync, prod lock present — AC-04.
- `tests/TestSuites/TestDescribe.php::test_describeToleratesMissingDevFile` — with the dev config deleted, `describe()` returns an empty repository list and a warning instead of throwing — AC-04.
- `tests/TestSuites/TestDescribe.php::test_describeJsonRoundTrips` — `json_decode(describe()->toJSON(), true)` equals `describe()->toArray()` — AC-05.
- `tests/TestSuites/TestReconcile.php::test_noOperationsWhenInSync` — after a PROD switch, `touch()` the prod config to make it newer, then `reconcile()`: zero operations, `MESSAGE_ALREADY_IN_SYNC` — AC-06.
- `tests/TestSuites/TestReconcile.php::test_restoresProdToMainWhenProdNewer` — edit the prod config, ensure it is newer, `reconcile()`: main config matches prod, `MESSAGE_RESTORED_PROD_TO_MAIN`, lock copied — AC-07.
- `tests/TestSuites/TestReconcile.php::test_backsUpMainToProdWhenMainNewer` — edit the main config, ensure it is newer, `reconcile()`: prod matches main, `MESSAGE_BACKED_UP_MAIN_TO_PROD` — AC-07.
- `tests/TestSuites/TestReconcile.php::test_ambiguousWhenTimestampsEqual` — edit both files to differ and force identical `filemtime` values via `touch()`: no operations, `MESSAGE_RECONCILE_AMBIGUOUS` — AC-08.
- `tests/TestSuites/TestReconcile.php::test_explicitDirectionResolvesAmbiguity` — the same fixture with `RECONCILE_TO_MAIN`: prod content lands in the main config — AC-08.
- `tests/TestSuites/TestReconcile.php::test_invalidDirectionThrows` — an unknown direction throws with code `182111` and a context containing the offending value — AC-08, AC-15.
- `tests/TestSuites/TestReconcile.php::test_devModeIsNotReconcilable` — after a DEV switch, `reconcile()` performs no operation and emits `MESSAGE_DEV_MODE_NOT_RECONCILABLE` — AC-09.
- `tests/TestSuites/TestReconcile.php::test_prodToProdSwitchMatchesReconcile` — from one drifted starting state, a second `switchToProduction()` and a direct `reconcile()` produce identical file contents and identical operation lists — AC-10.
- `tests/TestSuites/TestDryRun.php::test_previewLeavesDiskUntouched` — snapshot every file's content and `filemtime` in the work directory, run `previewSwitch(MODE_DEV)` and `previewSwitch(MODE_PROD)` from both INITIAL and PROD states, and assert the snapshot is unchanged — AC-11.
- `tests/TestSuites/TestDryRun.php::test_previewOperationsMatchRealSwitch` — preview a DEV switch on one work copy, perform the real switch on a second identical copy, and assert the operation type/source/target triples match in order — AC-11, AC-12.
- `tests/TestSuites/TestDryRun.php::test_previewOfInitialSwitchSucceeds` — previewing a DEV switch from INITIAL (where the prod config does not yet exist) completes without throwing, proving the overlay serves the pending write to `switch_adjustConfigForDev()` — AC-11.
- `tests/TestSuites/TestDryRun.php::test_realSwitchOperationsAreApplied` — a real `switchToDevelopment()` returns operations all flagged applied, and each named target exists on disk — AC-12.
- `tests/TestSuites/TestDryRun.php::test_dryRunFlagRestoredAfterException` — force a failure mid-preview (delete the dev config) and assert `getFileSystem()->isDryRun()` is `false` afterwards and a subsequent real switch writes to disk — AC-11.
- `tests/TestSuites/TestDryRun.php::test_noFilesystemCallsOutsideFacade` — scan every `.php` file under `src/` for `file_get_contents`, `file_put_contents`, `copy(`, `unlink(`, `file_exists(` and `filemtime(`, allowing only `src/Utils/FileSystem.php` and the `installGitHooks()` body — AC-17.
- `tests/TestSuites/TestSwitching.php::test_switchWithoutLockFileCompletes` (new) — delete `composer.lock`, switch to DEV, and assert the status file, flag file and rewritten main config all exist and `MESSAGE_NO_LOCK_FILE_FOUND` was emitted — AC-13.
- `tests/TestSuites/TestSwitching.php::test_prodSwitchWithoutProdLockDeletesMainLock` (new) — a DEV→PROD switch with no `composer-prod.lock` removes `composer.lock` and emits `MESSAGE_PROD_LOCK_MISSING` instead of throwing — AC-13.
- `tests/TestSuites/TestSwitching.php::test_tryCopyToCreatesMissingTarget` (new) — `tryCopyTo()` onto a non-existent target creates it; the first PROD→PROD reconcile produces `composer-prod.lock` — AC-14.
- `tests/TestSuites/TestSwitching.php` (L273–L312, updated) — the three `verify()` tests assert `isInSync()`, `getDifferences()`, `isDevMode()` and `isComparable()` on `VerificationResult` — AC-04.
- `tests/TestSuites/TestSwitching.php` (remaining tests, updated) — `switchToDevelopment()` / `switchToProduction()` return values are ignored where irrelevant; existing assertions about files, status and flags are unchanged and must still pass — AC-03.
- `tests/TestSuites/TestExceptionContext.php::test_invalidModeContext` — `switchTo('bogus')` throws code `182110` with a context containing the offending mode and the expected set — AC-15.
- `tests/TestSuites/TestExceptionContext.php::test_missingDevFileContext` — deleting the dev config and switching to DEV throws code `182101` with the dev file path in the context — AC-15.
- `tests/TestSuites/TestExceptionContext.php::test_invalidJsonStructureContext` — a dev config without `local-repositories` throws code `182103` with the file path and expected key — AC-15.
- `tests/TestSuites/TestExceptionContext.php::test_copyFailureContext` — a copy to an unwritable target throws code `182107` with both source and target paths — AC-15.
- `tests/TestSuites/TestExceptionContext.php::test_readFailureCarriesErrorCode` — `ConfigFile::getData()` on an unreadable file throws code `182108`, not `0` — AC-16.
- `tests/TestSuites/TestFixtureFileSystem.php::test_pathExistsDetectsDirectoryFileAndDanglingSymlink` — `true` for a directory, a file and a symlink whose target is gone; `false` for a missing path — AC-22.
- `tests/TestSuites/TestFixtureFileSystem.php::test_copyDirectoryThrowsOnExistingDirectory` / `test_copyDirectoryThrowsOnExistingFile` / `test_copyDirectoryThrowsOnExistingSymlink` — each throws `RuntimeException` and leaves the pre-existing destination byte-identical — AC-22.
- `tests/TestSuites/TestFixtureFileSystem.php::test_copyDirectoryCopiesNestedTree` — a nested source tree is reproduced at a fresh destination, so the refactored guard does not block the normal path — AC-22.
- `tests/TestSuites/TestWorkCopy.php::test_allocate_throwsOnCollision` — with the predicted next path pre-created for the current and next second, `allocate()` throws `RuntimeException` — AC-22.
- `tests/TestSuites/TestWorkCopy.php` (existing nine tests, unchanged) — still green after the `pathExists()` refactor and the typed-property conversion — AC-22.
- `tests/IntegrationSuites/TestGitRunner.php::test_runWithoutArgumentsReturnsNonZeroWithUsage` — bare `git` returns a non-zero exit and output containing `usage` — AC-23.
- `tests/IntegrationSuites/TestGitRunner.php::test_runInMissingWorkingDirectoryThrows` — `(new GitRunner('<missing path>'))->run('status')` throws `Symfony\Component\Process\Exception\RuntimeException` — AC-23.
- `tests/IntegrationSuites/TestGitRunner.php::test_runInReadOnlyDirectoryReportsFailure` — `git init` in a `0555` directory returns a non-zero `ProcessResult` with non-empty error output; skipped when `chmod` does not take effect — AC-23.
- `tests/IntegrationSuites/TestVersionOverride.php::test_switchProdRecoversAfterRejectedVersion` — malformed version, `switch-dev`, failed `composer update`, then `runComposerChecked('switch-prod')`: the main `composer.json` equals `composer/composer-prod.json`, and `composer.lock` equals `composer/composer-prod.lock` — AC-24, AC-13.
- `composer analyze` — zero PHPStan errors at level 6 across `src/`, `tests/TestClasses`, `tests/TestSuites`, `tests/IntegrationSuites` — AC-18.
- `composer test` — all Tier 1 suites green — AC-18.
- `composer test-integration` — all Tier 2 suites green, with no skip in `TestGitRunner` or `TestVersionOverride` — AC-18, AC-23, AC-24.
- Manual documentation check against the `AGENTS.md` §2 table: confirm each changed artefact (new class → `file-tree.md` + `api-surface.md`; public method change → `api-surface.md`; new error code → `api-surface.md` + `constraints.md`; new data flow → `data-flows.md`; convention change → `constraints.md`; test structure change → `file-tree.md` + `constraints.md`) — AC-19, AC-20, AC-21.

## Documentation Updates

- `README.md` — best-effort reconciliation note beside the L87–L88 warning; five new script keys in the wiring example; updated verify section; new sections for state inspection, preview, reconciliation, programmatic/agent usage and 2.x migration (step 10).
- `changelog.md` — new `## v3.0.0` entry above the predecessor's `## v2.0.0`, with an explicit BREAKING block (step 11).
- `AGENTS.md` — §1 manifest index, §3 efficiency rules, §4 decision matrix row, §5 Namespace stat (step 11).
- `docs/agents/project-manifest/switching-decision-table.md` — **new**: state × action → command → file effects → message codes (step 11).
- `docs/agents/project-manifest/README.md` — Sections table entry and version line set to `3.0.0` (step 11).
- `docs/agents/project-manifest/api-surface.md` — new namespace, classes, constants, entry points and changed signatures; per `AGENTS.md` §2 rows "New class or file added", "Public method added/removed/renamed", "Error code constant added" (step 11).
- `docs/agents/project-manifest/file-tree.md` — `src/State/`, `src/Utils/FileSystem.php`, six new Tier 1 suites (including `TestFixtureFileSystem.php`), the new manifest document; per §2 rows "New class or file added" and "Test structure changed" (step 11).
- `docs/agents/project-manifest/data-flows.md` — rewritten flow 4, new flows for describe / preview / operation log, extended entry-point list; per §2 row "New data flow or switching behavior" (step 11).
- `docs/agents/project-manifest/constraints.md` — third namespace, extended code ranges, choke-point rule, `tryCopyTo()` semantics, new test suites, `FixtureFileSystem::pathExists()` as the harness's single existence predicate; per §2 rows "Error code constant added", "Coding convention changed", "Test structure changed" (step 11).
- `docs/agents/project-manifest/tech-stack.md` — value-object and write-choke-point patterns; per §2 row "Coding convention changed" (step 11).

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **A mutation site is missed during the `FileSystem` migration**, so a "preview" writes to disk. | `test_noFilesystemCallsOutsideFacade` scans `src/` for forbidden calls (AC-17), and `test_previewLeavesDiskUntouched` snapshots content and mtimes for every file in the work directory before and after a preview (AC-11). The scan makes the rule enforceable on future edits, not just this one. |
| **The dry-run overlay diverges from real filesystem semantics**, so a preview reports operations a real switch would not perform. | `test_previewOperationsMatchRealSwitch` compares a preview against a real switch from an identical starting state, and `test_previewOfInitialSwitchSucceeds` covers the hardest case — reading a file that only a pending write created. |
| **Unifying reconciliation changes behaviour users depend on**, e.g. a PROD→PROD switch that used to copy on an mtime difference alone now does nothing. | The change is deliberate and documented in the changelog BREAKING block and the README reconciliation section; AC-06 pins the new behaviour, AC-10 pins the equivalence of the switch path and `reconcile()`. A user who wants an unconditional copy has an explicit direction argument. |
| **Removing the missing-lock early return exposes an unguarded `copyTo()`** at `src/ConfigSwitcher.php` L456, turning a silent no-op into a thrown exception. | Step 6 guards that copy *before* the early return is removed, and `test_prodSwitchWithoutProdLockDeletesMainLock` covers exactly that path (AC-13). The step ordering is a dependency, not a suggestion. |
| **`getMessages()` changing its return type breaks an unknown consumer.** | Both known consumers were grepped and call only the static entry points. The change ships as a major version; `getMessageTexts()` gives any caller a one-token migration, and the README migration section names it. The residual exposure is `../mailforge`'s constraint if it is still unbounded, flagged as Human Action 3. |
| **`describe()` throws in a broken state** — a malformed or missing dev config — making the one call agents rely on for diagnosis the one that fails when diagnosis is needed. | `describe()` is specified to degrade to an empty repository list plus a warning rather than propagate; `test_describeToleratesMissingDevFile` enforces it (AC-04). |
| **`src/` ends up half-modernised** — PHP 8 constructs in the new and rewritten code, legacy `@var` style in the files this plan does not touch. | Accepted and intended: the project policy converts each file as work reaches it, rather than funding a single large sweep. `Pattern Alignment` states which code this plan modernises and which it leaves, so the boundary is deliberate. PHPStan level 6 covers both styles, enforced by AC-18. |
| **The README script block has moved** since this plan was written, because the predecessor renamed two keys and documents the block as the library's authoritative wiring example. | Recorded in `Constraints`: the block already carries `switch-` prefixed keys when this plan starts, and every key added here uses the same prefix, so the block is extended rather than reconciled. |
| **The plan is large enough that a partial delivery leaves the library in a half-migrated state** — some messages coded, some not; some I/O routed, some not. | The steps are ordered so each is independently coherent, and `Dependencies` states which may not be reordered. AC-02 and AC-17 are absolute ("every", "no") precisely so a partial migration fails the criteria rather than passing quietly. |
| **A Tier 2 test added here is silently skipped** where `git`, the network or Composer is unavailable, so AC-23 / AC-24 look met without having run. | Step 12's sweep must report the Tier 2 skip count; any skip in `TestGitRunner` or `TestVersionOverride` blocks handoff rather than counting as a pass. |
| **The `allocate()` collision test couples to `WorkCopy`'s private counter and path format** via reflection. | The coupling is confined to one test and is deliberate — the alternative is a production seam used by no caller. Pre-creating paths for two consecutive seconds removes the timing flake; a change to the path format makes the test fail loudly rather than pass vacuously, because it asserts the throw. |
| **The read-only-directory test behaves differently as root** (permissions are not enforced). | It checks `is_writable()` after `chmod` and skips with a named reason instead of failing; it restores permissions before `tearDown()` cleanup. |
| **The new manifest document drifts from the code** as the switch flow changes later. | Step 11 requires every decision-table row to be traceable to a code path in `src/ConfigSwitcher.php`, and the document is added to the `AGENTS.md` §1 index so future agents read it before the source. |

## Recommended Workflow

- **Workflow:** ledger
- **Rationale:** Twelve sequenced steps across six modified and twelve new files introduce a new namespace, a new architectural seam, three public return-type changes and three defect fixes in the switch state machine — a scope that needs formal QA, security-free-but-real regression review, and documentation stages rather than a single developer session.
