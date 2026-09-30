# Plan

## Plan Audit Cycles
- Audits: 1 (Sonnet 5 ×1) — Plan Auditor v1.9.3
- Architectural Reviews: 1 (Sonnet 5 ×1) — Plan Architect Reviewer v2.3.3

## Prior Project Context

This library's public wiring surface — `fromProjectRoot()` and the five static Composer entry
points — was delivered by two archived projects:
`2026-08-26-switching-gaps-and-sync-hardening` (v1.1.0) introduced them, and
`2026-08-27-switching-gaps-and-sync-hardening-rework-1` (v1.1.1) stabilised them. The research
brief establishes that those entry points — the library's entire public wiring surface — have
**no behavioural test coverage** in this repository, because the Tier 1 fixture does not follow
the three-path convention they hardcode.

Three edits are already committed to the repository (`d4e2b8c` "Composer: Updated to PHP8.4." and
`29052cc` "Docs: Removed PHP7 references.", both on 2026-09-28). This plan builds on them as its
starting point; it does not re-apply or revise them:

- `README.md` (L103–L104, L222, L307) — the Composer script keys renamed `verify-config` →
  `switch-verify-config` and `install-hooks` → `switch-install-hooks`. The static entry points
  (`ConfigSwitcher::composerVerifyConfig`, `ConfigSwitcher::composerInstallHooks`) and the
  programmatic API (`verify()`, `installGitHooks()`) are untouched by the rename.
- `composer.json` — the PHP requirement raised from `>=7.3` to `>=8.4`, with the
  `config.platform.php` pin removed.
- `phpstan.neon` — the `phpVersion` parameter removed.

Neither user-visible change is recorded in `changelog.md`, and the PHP bump left five stale PHP 7.3
claims behind, all confined to `docs/agents/project-manifest/` (`constraints.md` L6;
`tech-stack.md` L5, L6, L12; the manifest's own `README.md` L6) — one of which instructs every
future agent to write
PHP 7.3-era code. This plan records and corrects both.

The plan is designed around a user decision: **the sibling consumer repositories `../hcp-editor`
and `../mailforge` are not a test surface.** Both install this library from Packagist
(`../hcp-editor/composer.json` L145 `^1.0.4`, `../mailforge/composer.json` L74 `>=1.0.4`), so no
change to `src/` in this clone is reachable from either without adding a path repository pointing
back here — scaffolding that would exist purely for testing, in repositories this project does
not own, and that this library's own pre-commit hook is designed to block from being committed.

The repository has no declared strategic vision and no repository-scoped insights. Three global
insights informed the design: `c3e68fdc-bc4e-476c-b605-7f4eb4e0bb44` (a fixture that works around
a production constraint has documented a defect) frames the Tier 1 fixture problem;
`e134b941-9f92-4582-8855-834889687d26` (fixtures must diverge on the two candidate fields) shapes
the `version`-override test; `2cd87f16-2556-4523-af27-0b88a6adbf0b` (pre-commit hooks as testable
guard registries) is addressed and deliberately not acted on under Structural Improvements.

## Summary

Establish a real, in-repository test project as the validation surface for this library, and
close out the script-key namespacing and PHP-floor work already committed to the repository.

The test suite gains a second tier. **Tier 1** is the existing fixture-copy harness: fast,
offline, no Composer process, asserting file orchestration. **Tier 2** is new: a genuine
three-path-convention Composer project under `tests/assets/integration-project/` that requires
`mistralys/simple_html_dom` — a package whose only live requirement is `ext-mbstring`, so a
local clone works with no `vendor/` folder, exactly as `README.md` (L172–L183) recommends.
Tier 2 bootstraps a shallow clone of that package, runs the real `composer` binary, and asserts
what no test in this repository has ever asserted: that a DEV switch produces a manifest Composer
actually resolves, that `vendor/mistralys/simple_html_dom` becomes a **symlink** into the clone,
that an edit in the clone is visible through the vendor path, that the lock file follows each
switch, that the `version` override pins the path package to a version Composer would not
otherwise infer, and that all five static entry points dispatch when invoked as
`composer switch-*` from a project directory.

The governing goal is **self-sufficiency**: after this plan, every usage scenario the library
documents is provable inside this repository alone. The consumer projects have no role in
verification at all — they are adopters that confirm the released package works in the field,
after the fact.

Alongside this, the Tier 1 fixture is moved onto the three-path convention it currently
contradicts, the library's `changelog.md` records the script rename and the PHP floor bump from
`>=7.3` to `>=8.4` as **v2.0.0**, and the five stale PHP 7.3 claims the bump left behind in
`docs/agents/project-manifest/` — one of which instructs every future agent to write PHP 7.3-era
code — are corrected. The consumer
repositories appear nowhere in the plan's steps; adopting the published release into one
afterwards is a Human Action.

## Architectural Context

`src/ConfigSwitcher.php` is a single orchestrator over six utility classes in `src/Utils/`.
Its public wiring surface is `fromProjectRoot()` (L72–L81) plus five static entry points
(L83–L129) — `composerSwitchDev`, `composerSwitchProd`, `composerSwitchUpdate`,
`composerVerifyConfig`, `composerInstallHooks` — each of which calls
`self::fromProjectRoot(getcwd())`. Because they resolve from the **current working directory**,
the only way to exercise them as a consumer does is to invoke `composer <script>` from inside a
project directory.

`fromProjectRoot()` hardcodes the three-path convention documented in
`docs/agents/project-manifest/constraints.md`: `<root>/composer.json`,
`<root>/composer/composer-prod.json`, `<root>/composer/local-repositories.json`.

State lives on disk: a status file whose path derives from the **dev config** path with `.json`
replaced by `.status`, and `composer.json.DEV` / `composer.json.PROD` flag files. `switchTo()`
(L312–L335) returns early with `MESSAGE_NO_LOCK_FILE_FOUND` when `composer.lock` is absent —
before writing any status or flag file — so a real lock must exist before the first switch.
`switch_copyLockFiles()` (L383–L410) dispatches the four state cases, preceded on INITIAL by
`switch_initProductionFiles()` (L498–L515), which seeds `composer/composer-prod.json` and
`composer/composer-prod.lock` from the active pair. `switchUpdate()` (L280–L290) is a silent
no-op in INITIAL state.

`switch_adjustConfigForDev()` (L600–L636) is the only place `composer.json` is synthesised
rather than copied. It rewrites the require constraint to the configured version (in
`require-dev` when the package sits there) and emits
`['type' => 'path', 'url' => $path, 'options' => ['symlink' => true]]`, adding
`options.versions` — with a hyphen-normalised alias for underscore names — when the configured
version is not `*`.

The existing test harness (`tests/TestClasses/ComposerSwitcherTestCase.php`) copies
`tests/assets/test-project/` into `tests/assets/work-projects/{date}-{counter}` per test and
removes it on teardown unless the test failed or `setKeepWorkFiles()` was called.
`phpunit.xml` registers one testsuite over `./tests/TestSuites`, and `composer test` is a bare
`php vendor/bin/phpunit`, so it currently runs every registered testsuite.

## Approach / Architecture

**Two tiers, separated by what they can prove.**

*Tier 1 — orchestration.* The existing `tests/assets/test-project/` fixture and its 16 tests
stay as they are in substance. One correction: its dev config is renamed from
`composer/dev-config.json` to `composer/local-repositories.json`, putting it on the convention
`fromProjectRoot()` hardcodes. Only two source references change
(`ComposerSwitcherTestCase.php` L80, `TestSwitching.php` L324). Tier 1 remains offline, runs in
milliseconds, and remains the default `composer test`.

*Tier 2 — resolution.* A new fixture, `tests/assets/integration-project/`, is a real Composer
project laid out exactly as `README.md` §3 teaches: `composer.json` at the root,
`composer/composer-prod.json` and `composer/local-repositories.json` beneath, and a `scripts`
block wiring all five static entry points under their namespaced keys. It requires
`mistralys/simple_html_dom: ^2.0`.

The committed `composer/local-repositories.json` carries the literal placeholder
`__LOCAL_CLONE_PATH__` as its path, because a path repository URL must be an absolute path on the
executing machine and no such value can be committed. The harness substitutes the resolved clone
path into the **work copy** after the fixture is copied, leaving the committed fixture
machine-independent while still being a complete, valid, inspectable project.

Three new harness classes under `tests/TestClasses/`, following the existing classmap-autoloaded
convention:

- `LocalPackageClone` — owns acquisition of the switched package. It resolves a cache directory
  at `tests/assets/local-clones/simple_html_dom` (gitignored), shallow-clones
  `https://github.com/Mistralys/simple_html_dom.git` when absent, and returns the absolute path
  or `null` when git or the network is unavailable. One place decides whether Tier 2 can run.
- `ComposerRunner` — the single choke-point through which every Composer invocation passes. It
  locates the binary (the `COMPOSER_BINARY` environment variable, else `composer` on `PATH`),
  always applies `--no-interaction --no-progress`, runs in a given working directory, and
  captures exit code, stdout, and stderr — executing through `Symfony\Component\Process\Process`
  (a new `require-dev` dependency, step 9) rather than a hand-rolled `proc_open()` pipe loop.
  Concentrating this means the binary lookup, the flag set, the environment, and the execution
  mechanism are decided once rather than at six call sites.
- `ComposerResult` — the value object `ComposerRunner` returns: exit code, stdout, stderr, and
  the predicates the suites assert on (`isSuccess()`, `containsOutput()`). This is a structure
  that will accumulate behaviour — output parsing for `composer show`, for one — so it is a class
  from the start rather than an array that later has to become one.

`IntegrationTestCase extends ComposerSwitcherTestCase` overrides the fixture source to
`integration-project/`, resolves the clone, rewrites the placeholder, and skips the whole suite
with a clear message when the clone is unavailable. Tier 2 suites live in a new
`tests/IntegrationSuites/` directory, registered as a second PHPUnit testsuite named
`Integration` and **excluded from the default run**: `composer test` becomes
`--testsuite "Test suites"` and a new `composer test-integration` runs `--testsuite Integration`.

Each Tier 2 suite begins from the same starting point — a PROD-state project with a real lock
file, produced by one `composer update` in the work copy — and then drives real
`composer switch-*` commands.

Documentation and changelog work rides along: the PHP floor bump already applied to
`composer.json` is recorded as v2.0.0 and the five stale PHP 7.3 claims it left in
`docs/agents/project-manifest/` corrected, and the manifest
documents are updated per `AGENTS.md` §2 for the new test structure.

## Rationale

**Why a real Composer process, rather than more fixture assertions.** The library's entire
purpose is to produce a `composer.json` that Composer resolves into a symlinked vendor tree. A
fixture assertion can prove the JSON has the shape the code intended; only Composer can prove
that shape is correct. Today `tests/assets/test-project/composer.lock` is four bytes containing
the text `PROD`, and the configured paths (`/path/to/application-framework`) do not exist — so
every lock-following assertion in the suite is a string-copy assertion. Global insight
`c3e68fdc-bc4e-476c-b605-7f4eb4e0bb44` names this pattern precisely: a fixture built around a
production path it never exercises is a filed defect report.

**Why `mistralys/simple_html_dom`.** `README.md` (L172–L183) states the intended workflow for an
attached clone: never run `composer install` in it, so no `vendor/` folder exists. Only a package
with no live Composer dependencies behaves identically under that rule. `simple_html_dom` 2.0.0
requires `ext-mbstring` and nothing else. The alternative first considered, `mistralys/text-diff`,
requires `mistralys/application-utils` in every published version, which would have made the
clone's behaviour diverge from the documented workflow.

**Why the entry points are driven as real `composer switch-*` commands.** Every consumer wires
the static entry points into a `scripts` block, and each resolves paths from `getcwd()`. Calling
`ConfigSwitcher::composerSwitchDev()` in-process would bypass the working-directory resolution,
Composer's script dispatch, and the autoload path that actually breaks in the field — that is
exactly the failure mode recorded in `../mailforge`, where a stale vendor lock made every
`switch-*` command uncallable while the PHP API itself was fine. Only a real invocation catches
it.

**Why `symfony/process` rather than a hand-rolled `proc_open()`.** `ComposerRunner` captures
stdout and stderr separately from a foreground `composer update` whose combined output is
unbounded. That is the exact shape in which `proc_open()`'s two-pipe read loop deadlocks: the
child blocks on a full pipe buffer while PHP blocks reading the other one. Every one of the six
Tier 2 suites passes through this single choke-point, so the failure would not be one red test —
it would be `composer test-integration` hanging with no cause-naming skip message, a worse
outcome than any of the network failures the Risks table already covers. `symfony/process` owns
the buffering, the non-blocking reads, and the cross-platform argument escaping, at the same
implementation cost step 9 already budgets. The dependency is `require-dev` only, which is where
this project already draws its line: `phpstan/phpstan`, `phpstan/phpstan-phpunit`,
`phpunit/phpunit`, and `roave/security-advisories` all sit there, while `require`
(`composer.json` L28–L30) holds only `php: >=8.4`. The "zero runtime dependencies" property
named in `docs/agents/project-manifest/tech-stack.md` is a property of `require`, so it survives
intact — and the published `autoload` block covers `src/` alone, so nothing a consumer installs
gains a new package.

**Why the clone path is a placeholder rather than an environment variable.** An absolute path
cannot be committed. A placeholder inside a committed, valid `local-repositories.json` keeps the
fixture complete and readable — someone opening it sees the real file a consumer would have —
while the substitution happens against the throwaway work copy. An environment variable would
leave the committed fixture incomplete and push setup onto whoever runs the suite.

**Why Tier 2 is opt-in.** Each Tier 2 test runs at least one `composer update` against the
network. The user has accepted that cost for the coverage it buys, but paying it on every
`composer test` during ordinary development would push developers away from running tests at all.
A separate testsuite makes the cost deliberate without making it optional in the sense that
matters — it still runs, just when asked.

**Why the consumer repositories leave the plan.** Beyond the user's decision, the mechanics make
them unusable as evidence: they install this library from Packagist, so nothing in `src/` is
reachable from them. The rename in both working trees is already applied; what remains is review
and commit, which is the user's call over repositories this project does not own.

No abstraction in this plan lacks a named consumer. `LocalPackageClone` is consumed by
`IntegrationTestCase`; `ComposerRunner` and `ComposerResult` are consumed by
`IntegrationTestCase` and all six Tier 2 suites.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| Validation surface | A dedicated in-repository test project under `tests/assets/integration-project/` | Continue using `../hcp-editor` and `../mailforge`; add a path repository in each pointing back at this clone | Both consumers install from Packagist, so library changes are unreachable without scaffolding in repositories this project does not own — scaffolding this library's own pre-commit Guard 2 would block from being committed. A dedicated in-repository project is reachable, ownable, and provable without leaving this clone. |
| Switched package | `mistralys/simple_html_dom` ^2.0 | `mistralys/text-diff`; a hand-written stub package committed to the repository | `text-diff` requires `mistralys/application-utils` in every version, so the clone could not follow `README.md` L172–L183's no-`vendor/` workflow. A committed stub would never be resolvable from Packagist, so the PROD half of the round trip could not be tested at all. `simple_html_dom` has one live requirement, `ext-mbstring`. |
| Clone acquisition | Shallow `git clone` into a gitignored cache at `tests/assets/local-clones/`, skipping cleanly when unavailable | A git submodule; `composer create-project` into the work directory; committing a vendored copy | A submodule binds this repository's checkout to a fixed commit of an unrelated package — a permanent commitment for a test fixture. Re-fetching per test would multiply an already slow suite. A cached clone is re-used across runs and costs nothing when present. |
| Clone path in the fixture | Committed `local-repositories.json` carrying `__LOCAL_CLONE_PATH__`, substituted into the work copy | An environment variable read at runtime; generating the whole file in `setUp()` | A path repository URL must be absolute and machine-specific. The placeholder keeps the committed fixture a complete, valid, inspectable project — which is what the user asked for — while keeping the machine-specific part out of version control. |
| Tier 2 scheduling | A separate `Integration` testsuite behind `composer test-integration` | Folding Tier 2 into the default `composer test`; a PHPUnit `@group` annotation | Network-bound `composer update` calls in the default run would discourage running tests at all. A testsuite is declared once in `phpunit.xml` and cannot be forgotten on a new file, unlike a per-method group annotation. |
| Tier 1 fixture layout | Rename `composer/dev-config.json` to `composer/local-repositories.json` | Leave the fixture as it is; delete Tier 1 and move everything to Tier 2 | The current name is one the three-path convention does not recognise, which is why `fromProjectRoot()` has only a path-string test. Two source references change. Deleting Tier 1 would trade millisecond feedback for network-bound feedback on assertions that never needed Composer. |
| `version` override evidence | Assert Composer reports the package at the configured `2.0.0` rather than the inferred `dev-master` | Reproduce the documented `has higher repository priority` error; assert only the generated JSON shape | Reproducing that error needs a third package constraining `simple_html_dom`, which a zero-dependency package cannot supply. Asserting the resolved version is a real Composer-level observation, and per insight `e134b941` the two candidate values (`dev-master` vs `2.0.0`) differ, so the assertion proves which one was used. |
| Subprocess execution in `ComposerRunner` | `symfony/process` (`^7.0 \|\| ^8.0`) as a `require-dev` dependency | A hand-rolled `proc_open()` loop over separate stdout/stderr pipes; `exec()` / `shell_exec()` with redirection | `exec()`/`shell_exec()` cannot satisfy the plan's own contract of separately captured streams and would require hand-written `escapeshellarg()` per argument. A `proc_open()` two-pipe loop can satisfy the contract but deadlocks unless both pipes are drained non-blockingly — and all six Tier 2 suites run through this one choke-point, so the failure mode is a hung `composer test-integration` rather than a failing test. `symfony/process` removes the hazard at the same implementation cost, with no Composer dependencies of its own and no effect on `require`. |
| Release version | v2.0.0, for the PHP floor bump from `>=7.3` to `>=8.4` | v1.2.0; leaving the changelog entry unversioned | Dropping support for PHP 7.3–8.3 is breaking for any consumer on those versions, and `../mailforge`'s unbounded `>=1.0.4` constraint would pull it in silently otherwise. An unversioned entry defers a decision that has already effectively been made in `composer.json`. |

## Pattern Alignment

- Follows the three-path convention (`docs/agents/project-manifest/constraints.md`,
  "Three-Path Convention") in both the new integration fixture and the corrected Tier 1 fixture.
- Follows the `switch-` command-namespace convention now established in `README.md` (L100–L104)
  by wiring the integration fixture's `scripts` block with all five namespaced keys.
- Follows the fixture-copy harness pattern of
  `tests/TestClasses/ComposerSwitcherTestCase.php` (L44–L48): `IntegrationTestCase` extends it
  rather than reimplementing the copy/teardown logic, and inherits the retain-on-failure
  debugging behaviour (L50–L70).
- Follows the classmap autoload split in `composer.json` (L18–L26): new harness classes go in
  `tests/TestClasses/`, new suites in a suite directory registered by `phpunit.xml`.
- Follows the `changelog.md` heading convention `## v{X.Y.Z} - {Title}` with a flat bullet list.
- **Deliberate departure:** test suites are split across two directories
  (`tests/TestSuites/` and `tests/IntegrationSuites/`) where the project currently has one.
  Justified because the separation is what makes the default run stay offline and fast; a single
  directory would require every consumer of `composer test` to know which files to exclude.
  Recorded in `docs/agents/project-manifest/file-tree.md` and `constraints.md` per `AGENTS.md` §2.
- **Deliberate departure:** `composer test` stops being a bare `phpunit` invocation and names its
  testsuite explicitly. Without this, adding a second testsuite would silently pull network-bound
  tests into every default run.

## Structural Improvements

| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `tests/assets/test-project/composer/dev-config.json` | Uses a dev-config filename the three-path convention does not recognise, so `fromProjectRoot()` and all five static entry points have no behavioural coverage. Only two source references depend on the name. | Promoted to step 4 | The fixture is already being extended by this plan's work, and the mismatch is the direct cause of the coverage gap Tier 2 exists to close. Leaving two fixture layouts in one repository for no reason would be the more expensive outcome. |
| `tests/assets/test-project/composer.lock` | Four bytes containing the literal text `PROD`. Every lock-following assertion in the 16-test suite is therefore a string-copy assertion. | Rejected | Tier 1's value is that it is offline and instant; a real lock file there would be a large committed artefact proving nothing Tier 2 does not prove properly. The gap is closed by Tier 2, not by inflating Tier 1. |
| `tests/TestClasses/ComposerSwitcherTestCase.php` | `createSwitcher()` (L75–L84) hardcodes the fixture's three paths, and `setUp()` (L44–L48) hardcodes `test-project` as the copy source. Tier 2 needs a different source directory. | Promoted to step 5 | Extracting the source directory into an overridable member is the minimum seam that lets `IntegrationTestCase` reuse the copy and teardown logic instead of duplicating it. Scoped narrowly to the fixture source; no other behaviour changes. |
| `composer.json` `test` script (L41) | A bare `php vendor/bin/phpunit` runs every registered testsuite, so adding an `Integration` testsuite would silently make the default run network-bound. | Promoted to step 6 | The change is forced by this plan's own work; discovering it after the fact would mean a default test run that fails on an offline machine. |
| `docs/agents/project-manifest/constraints.md` (L6) | States "PHP 7.3 compatibility: No union types, typed properties, named arguments, or other PHP 7.4+ features" in a package that now requires `>=8.4`. | Promoted to step 2 | This line actively instructs every future agent to write PHP 7.3-era code. It is the highest-cost stale claim in the repository and sits directly in the blast radius of the version bump this plan documents. |
| `docs/agents/project-manifest/tech-stack.md` (L6, L37) | L6 describes a `config.platform.php` pin that has been removed from `composer.json`. L37's build-tools table says PHPStan has "no config file in repo", but `phpstan.neon` exists and `composer analyze` passes it explicitly. | Promoted to step 2 | Both lines are in a document this plan must edit anyway for the PHP floor and the new test structure. Correcting them costs the same pass. |
| `docs/agents/project-manifest/README.md` (L4) | Declares `**Version:** 1.0.4` while `changelog.md` records v1.1.1 as shipped and this plan releases v2.0.0. | Promoted to step 3 | The file is being edited for the PHP floor in the same step; leaving the version two releases stale would contradict the changelog entry this plan writes. |
| `README.md` (L319–L333, "Version control") | Names `composer-production.json` / `composer-production.lock` (the actual files are `composer-prod.json` / `composer-prod.lock`) and `dev-config.json` / `dev-config.status` (under the standard layout these are `local-repositories.json` / `local-repositories.status`). | Promoted to step 18 | The integration fixture becomes the repository's authoritative example of the standard layout, and this section teaches a different one. It is the last drift left in `README.md` after commit `29052cc` removed the PHP 7.3 content. |
| `resources/git-hooks/pre-commit` | Inline Unix-only bash using `grep`, `git show`, and bash arrays — the exact shape global insight `2cd87f16-2556-4523-af27-0b88a6adbf0b` warns is untestable and Windows-hostile. | Rejected | Reshaping it into a guard registry would change a shipped resource that consumers have already installed into their `.git/hooks/`, turning a test-infrastructure plan into a behavioural change with a migration story. Tier 2 tests it as-is (step 11), which is the first coverage it has ever had; reshaping it belongs to its own plan. |

## Detailed Steps

1. **Record the release in `changelog.md`.** Add a new top entry
   `## v2.0.0 - PHP 8.4 and switch-* script namespace` above the existing `## v1.1.1` heading,
   with bullets for: the PHP requirement raised from `>=7.3` to `>=8.4` and the
   `config.platform.php` pin removed (both already applied in `composer.json`); the Composer
   script keys `verify-config` → `switch-verify-config` and `install-hooks` →
   `switch-install-hooks`, with the underlying static entry points
   (`ConfigSwitcher::composerVerifyConfig`, `ConfigSwitcher::composerInstallHooks`) and the
   programmatic API (`verify()`, `installGitHooks()`) unchanged; and the new Tier 2 integration
   test suite.

2. **Correct the PHP version claims in the manifest.**
   - `docs/agents/project-manifest/constraints.md` (L6): replace the
     "PHP 7.3 compatibility" bullet with a **PHP 8.4 baseline** bullet codifying the project's
     modernisation policy, as decided by the maintainer on 2026-09-28:
     - PHP 8 constructs are fully available — typed properties, union types, constructor
       promotion, `readonly`, named arguments, match expressions, enums, nullsafe operators.
       There is no PHP 7 compatibility requirement of any kind.
     - **All new code uses PHP 8 constructs.** The `@var`-docblock property style in existing
       `src/` files is legacy, not a convention to copy.
     - **Existing code may be modernised opportunistically**, in the same pass as any work that
       already touches it. This is explicitly permitted rather than deferred — no separate
       approval, plan, or cleanup task is required to bring a touched file up to PHP 8
       standards.
     - A wholesale sweep of untouched files remains out of scope for any given plan, so a
       modernisation diff never exceeds the blast radius of the work that carries it.
   - `docs/agents/project-manifest/tech-stack.md` (L5): `- **Language:** PHP >=8.4`.
   - `docs/agents/project-manifest/tech-stack.md` (L6): replace the platform-pin bullet; the
     `config.platform.php` block no longer exists in `composer.json`.
   - `docs/agents/project-manifest/tech-stack.md` (L12): "zero runtime dependencies — only
     `php >=8.4`".
   - `docs/agents/project-manifest/tech-stack.md` (L37): change the PHPStan build-tools row from
     `*(no config file in repo)*` to `phpstan.neon`, matching `composer.json` L38's
     `--configuration phpstan.neon`.

3. **Correct the manifest overview.** In `docs/agents/project-manifest/README.md`: L4
   `**Version:** 2.0.0`; L6 `> **PHP:** >=8.4`.

4. **Move the Tier 1 fixture onto the three-path convention.**
   - Rename `tests/assets/test-project/composer/dev-config.json` to
     `tests/assets/test-project/composer/local-repositories.json`. Content is unchanged.
   - `tests/TestClasses/ComposerSwitcherTestCase.php` (L80): update the third `ConfigFile`
     argument to `/composer/local-repositories.json`.
   - `tests/TestSuites/TestSwitching.php` (L324): update the `ConfigFile` path in
     `test_devSwitchRespectsRequireDevPlacement`.
   - Run `composer test` and confirm all 16 tests still pass. The status file produced by the
     suite changes name from `composer/dev-config.status` to
     `composer/local-repositories.status` as a consequence of the path-derivation rule in
     `docs/agents/project-manifest/constraints.md`; no test asserts on that filename, which the
     run confirms.

5. **Add the fixture-source seam to the Tier 1 harness, and fix its teardown.** In
   `tests/TestClasses/ComposerSwitcherTestCase.php`:
   - Replace the hardcoded `test-project` source in `setUp()` (L44–L48) with a protected method
     returning the fixture directory name, defaulting to `test-project`. `setUp()` calls it to
     build `$this->testSource`. `createSwitcher()` (L75–L84) stays as it is and remains Tier 1's
     constructor.
   - Fix `removeDirectory()` (L86–L105): it currently calls `rmdir()` on any entry for which
     `$item->isDir()` is true, which includes a **symlink to a directory**. `rmdir()` fails on a
     symlink, so the entry survives and the enclosing `rmdir($dir)` then fails too, leaving the
     whole work directory behind. A probe on 2026-09-28 reproduced this against a symlinked
     vendor package. Add an `$item->isLink()` branch that calls `unlink()` before the `isDir()`
     branch. The probe also confirmed the cached clone is **not** at risk —
     `RecursiveDirectoryIterator` does not follow symlinks unless `FOLLOW_SYMLINKS` is set, and
     the clone's 146 files were intact afterwards — so this is a leak, not a destructive bug.
     Without the fix, every Tier 2 test would orphan a work directory containing a full
     `vendor/` tree.

6. **Wire the two testsuites.**
   - `phpunit.xml`: keep the existing `<testsuite name="Test suites">` over
     `./tests/TestSuites` and add a second `<testsuite name="Integration">` over
     `./tests/IntegrationSuites`.
   - `composer.json` `scripts`: change `test` to
     `php vendor/bin/phpunit --testsuite "Test suites"`, and add
     `test-integration` as `php vendor/bin/phpunit --testsuite Integration`.
   - `.gitignore`: add `/tests/assets/local-clones/`.

7. **Create the integration fixture** under `tests/assets/integration-project/` (new):
   - `composer.json` — the active manifest, in PROD shape: `name`
     `mistralys/switcher-integration-fixture`, `type` `project`, `require` of
     `php: >=8.4` and `mistralys/simple_html_dom: ^2.0`, a `scripts` block wiring all five
     namespaced keys to their `Mistralys\ComposerSwitcher\ConfigSwitcher::composer*` handlers
     exactly as `README.md` L100–L104 shows, and `minimum-stability: dev` with
     `prefer-stable: true` so a `dev-master` path repository resolves.
   - `composer/composer-prod.json` — byte-identical to the above. Shipping it explicitly rather
     than letting `switch_initProductionFiles()` seed it on first switch makes the fixture's
     starting state deterministic and independent of switch order.
   - `composer/local-repositories.json` — one entry, `package-name`
     `mistralys/simple_html_dom`, `path` `__LOCAL_CLONE_PATH__`. No `version` key; the
     override case is constructed per-test in step 14.
   - An `autoload.classmap` entry holding the placeholder `__LIBRARY_SRC_PATH__`, substituted
     by the harness with the absolute path to this repository's `src/`. **This is required.**
     A probe on 2026-09-28 established that Composer refuses a handler it cannot autoload —
     `Class Mistralys\ComposerSwitcher\ConfigSwitcher is not autoloadable, can not call
     switch-verify-config script` — and that an absolute classmap makes all five handlers
     dispatch with no `require` of the library and no `path` repository pointing at this
     repository. See the research brief, "Verification Probe".
   - No `composer.lock` is committed. The harness produces a real one in the work copy
     (step 10), which is what `switchTo()` (L312–L335) requires before it will write any state.

8. **Create `tests/TestClasses/LocalPackageClone.php`** (new). A class owning acquisition of the
   switched package:
   - `getCacheDirectory(): string` — `tests/assets/local-clones/simple_html_dom`.
   - `ensureAvailable(): ?string` — returns the absolute clone path; clones
     `https://github.com/Mistralys/simple_html_dom.git` with `--depth 1` when the directory is
     absent; returns `null` when `git` is not on `PATH`, the clone fails, or the resulting
     directory has no `composer.json`.
   - `getUnavailableReason(): string` — the message a skipped suite reports, so a skip says
     which of the three causes applied rather than just "skipped".
   - It must never run `composer install` in the clone: `README.md` (L172–L183) makes the
     absence of a `vendor/` folder there part of the documented workflow, and
     `simple_html_dom`'s dev dependencies require PHPUnit ^12.

9. **Create `tests/TestClasses/ComposerRunner.php` and `ComposerResult.php`** (new), and add the
    subprocess library they depend on.
    - `composer.json`: add `"symfony/process": "^7.0 || ^8.0"` to `require-dev` (L31–L36),
      alongside the existing `phpstan/phpstan`, `phpstan/phpstan-phpunit`, `phpunit/phpunit`, and
      `roave/security-advisories` entries. `require` (L28–L30) is untouched. Both majors require
      only a PHP constraint and no Composer packages of their own, and the `^7.0 || ^8.0` range
      resolves under this project's `php >=8.4` floor in either direction (7.x needs `>=8.2`, 8.x
      needs `>=8.4.1`). Run `composer update symfony/process` so the dependency is installed
      before the class is written.
    - `ComposerRunner::__construct(string $workingDirectory)`.
    - `ComposerRunner::run(string ...$arguments): ComposerResult` — resolves the binary from the
      `COMPOSER_BINARY` environment variable, falling back to `composer` on `PATH`; always
      appends `--no-interaction` and `--no-progress`; executes in the working directory with
      stdout and stderr captured separately. **The execution mechanism is
      `Symfony\Component\Process\Process`, not a hand-rolled `proc_open()` pipe loop.** `run()`
      constructs the process through the array-argument constructor — the resolved binary
      followed by the argument list, with the working directory passed as the constructor's
      second argument — calls `->run()`, and builds the `ComposerResult` from `->getExitCode()`,
      `->getOutput()`, and `->getErrorOutput()`. Array arguments mean no `escapeshellarg()`
      handling is written by hand, and the library owns the non-blocking drain of both pipes,
      which is what removes the deadlock hazard described in the Risks table.
    - `ComposerRunner::isAvailable(): bool` — whether a usable binary was found.
    - `ComposerResult` — `getExitCode()`, `getOutput()`, `getErrorOutput()`, `isSuccess()`,
      `containsOutput(string $needle)`. A class rather than an array because step 14 adds
      `composer show` output parsing to it.

10. **Create `tests/TestClasses/IntegrationTestCase.php`** (new), extending
    `ComposerSwitcherTestCase`:
    - Overrides the step 5 fixture-source method to return `integration-project`.
    - In `setUp()`, after the inherited copy: resolve the clone via `LocalPackageClone`, and
      call `markTestSkipped()` with `getUnavailableReason()` when it is unavailable or when
      `ComposerRunner::isAvailable()` is false. Then perform both placeholder substitutions in
      the work copy — `__LOCAL_CLONE_PATH__` in `composer/local-repositories.json` with the
      resolved clone path, and `__LIBRARY_SRC_PATH__` in **both** `composer.json` and
      `composer/composer-prod.json` with this repository's `src/` directory. Both files need it:
      `switch_adjustConfigForDev()` rebuilds the active manifest from the prod baseline, so a
      substitution applied only to `composer.json` would be discarded by the first DEV switch.
    - `bootstrapProd(): void` — runs `composer update` once in the work copy, producing a real
      `composer.lock` and a real `vendor/` tree, leaving the project in a PROD-shaped INITIAL
      state. Every Tier 2 suite starts here.
    - `runComposer(string ...$arguments): ComposerResult` — delegates to a `ComposerRunner`
      bound to the work copy.
    - `setLocalRepositoryVersion(?string $version): void` — writes or removes the optional
      `version` key in the work copy's `composer/local-repositories.json`, for step 14.
    - Teardown is inherited, including retain-on-failure, and depends on the step 5 symlink fix
      to remove a work copy whose `vendor/` holds a symlinked package.

11. **Create `tests/IntegrationSuites/TestProdBootstrap.php`** (new) — the PROD baseline.
    Asserts that `composer update` from the committed fixture succeeds, that
    `vendor/mistralys/simple_html_dom` exists and is **not** a symlink, that `composer.lock`
    exists and is valid JSON listing the package, and that no switcher state artefact
    (`composer.json.DEV`, `composer.json.PROD`, `composer/local-repositories.status`) exists yet.

12. **Create `tests/IntegrationSuites/TestDevSwitch.php`** (new) — the core promise.
    After `bootstrapProd()`, runs `composer switch-dev`, then `composer update`. Asserts the
    generated `composer.json` carries a `type: path` repository with `options.symlink: true`
    pointing at the clone and a `require` constraint of `*`; that `composer.json.DEV` and
    `composer/local-repositories.status` were created; that
    `vendor/mistralys/simple_html_dom` **is** a symlink resolving into the clone directory; and
    that a marker file written into the clone is readable through the vendor path — the
    "changes are immediately available" claim of `README.md` L35–L47, asserted for the first
    time. The marker file is removed in teardown so the cached clone is left clean.

13. **Create `tests/IntegrationSuites/TestRoundTrip.php`** (new) — lock following and restoration.
    From the DEV state of step 12, runs `composer switch-prod` and `composer update`. Asserts the
    path repository is gone from `composer.json`, the original `^2.0` constraint is restored,
    `vendor/mistralys/simple_html_dom` is a real directory again, `composer.json.PROD` replaced
    `composer.json.DEV`, and both `composer/composer-prod.lock` and
    `composer/local-repositories.lock` exist and differ. Then asserts what "the lock file follows
    the switching" (`README.md` L24–L26) actually means, as established by the 2026-09-28 probe:
    after each switch the active `composer.lock` is **byte-identical** to the saved lock for that
    mode, and a following `composer install` completes the transition. It does **not** assert
    that `install` is a no-op — a switch restores the lock, not `vendor/`, which is why
    `README.md` L204–L209 tells the user to run `composer install` next.

14. **Create `tests/IntegrationSuites/TestVersionOverride.php`** (new) — the `version` property.
    Using `setLocalRepositoryVersion()`, runs one case with no `version` (the clone's inferred
    `dev-master`) and one with `"2.0.0"`. Asserts the generated repository entry gains
    `options.versions` only in the second case, and that after `composer update`,
    `composer show mistralys/simple_html_dom` reports `2.0.0` rather than `dev-master`. Per
    insight `e134b941-9f92-4582-8855-834889687d26`, the two candidate values must differ for the
    assertion to prove which was used, which is why both cases live in one suite.

15. **Create `tests/IntegrationSuites/TestEntryPoints.php`** (new) — command dispatch.
    Drives all five namespaced commands as real Composer invocations and asserts on their
    documented output (`src/ConfigSwitcher.php` L100–L129):
    - `composer switch-verify-config` in PROD → `composer.json and composer-prod.json are in sync.`
    - After editing `composer/composer-prod.json`, `composer switch-verify-config` → the
      `differ in the following keys:` header followed by the changed key.
    - `composer switch-verify-config` in DEV → `DEV mode is active — config comparison skipped.`
    - `composer switch-update` in PROD after a `composer-prod.json` edit → the edit reaches
      `composer.json`.
    - `composer switch-update` in INITIAL state → exits successfully and changes nothing, the
      documented no-op of `switchUpdate()` (L280–L290).
    - `composer switch-dev` and `composer switch-prod` exit zero and write the expected flag
      file.

16. **Create `tests/IntegrationSuites/TestGitHooks.php`** (new) — hook installation and guards.
    `git init` the work copy so `.git/hooks/` exists, then:
    - `composer switch-install-hooks` → exit zero, output
      `Git hooks installed successfully.`, and `.git/hooks/pre-commit` exists and is executable.
      Safe to run here, unlike in a developer checkout, because the work copy is ephemeral.
    - Guard 1: with `composer.json.DEV` present and `composer.json` staged, the hook exits
      non-zero and its output names the blocked file.
    - Guard 2: in DEV state with a `type: path` entry in the staged `composer.json` and no DEV
      marker, the hook exits non-zero.
    - Both guards pass (exit zero) in PROD state with `composer.json` staged.
    - Skip the suite when `git` is unavailable, reusing the step 8 availability check.

17. **Update the manifest for the new test structure**, per `AGENTS.md` §2
    ("New class or file added", "Directory restructured", "Test structure changed"):
    - `docs/agents/project-manifest/file-tree.md`: add `tests/IntegrationSuites/`,
      `tests/assets/integration-project/`, `tests/assets/local-clones/` (gitignored), and the
      three new `tests/TestClasses/` entries; correct the `test-project/` annotation to name
      `local-repositories.json`.
    - `docs/agents/project-manifest/constraints.md` (Testing): describe both tiers, the
      `composer test` / `composer test-integration` split, the `__LOCAL_CLONE_PATH__`
      substitution, and the skip conditions.
    - `docs/agents/project-manifest/data-flows.md` (L56, L119–L121): replace the `dev-config.*`
      filenames with `local-repositories.*` to match the convention and the corrected fixture.
    - `docs/agents/project-manifest/tech-stack.md`: record the new `require-dev` dependency
      `symfony/process ^7.0 || ^8.0` in the dependency listing, per the `AGENTS.md` §2 row
      "Dependency added/removed". Keep step 2's "zero runtime dependencies — only `php >=8.4`"
      wording intact: it describes `require`, which this plan does not touch.
    - `docs/agents/project-manifest/api-surface.md`: no change — no public `src/` signature is
      touched by this plan. Confirm this rather than assume it.

18. **Correct the README's remaining drift.** In the `## Version control` section (heading at
    L319; the file listing at L322–L329 and the `.dist` note at L331–L333): correct
    `composer-production.json` / `composer-production.lock` to `composer-prod.json` /
    `composer-prod.lock`, and `dev-config.json` / `dev-config.status` to
    `local-repositories.json` / `local-repositories.status`. Keep the `.dist` template note,
    renaming its example to `local-repositories.dist.json`. No PHP 7.3 drift remains in
    `README.md` — commit `29052cc` already removed the `## Why PHP v7.3?` section, and the file
    now contains no `7.3` reference at all.

19. **Run the full verification**: `composer test`, `composer test-integration`, and
    `composer analyze`, plus the documentation checks enumerated in the Test Plan.

## Dependencies

- Steps 1, 2, 3 and 18 are documentation-only and mutually independent; all may run in parallel.
- Step 4 must precede step 5 (the seam is added to a harness whose fixture has settled).
- Step 5 must precede step 10 (`IntegrationTestCase` overrides the seam step 5 introduces).
- Step 6 must precede steps 11–16 (the `Integration` testsuite must exist before suites are
  placed in it).
- Step 7 must precede steps 9 and 10 (the fixture is the working directory both operate on).
- Step 8 must precede step 10 (`IntegrationTestCase` consumes `LocalPackageClone`).
- Step 9 must precede step 10 (`IntegrationTestCase` consumes `ComposerRunner`). Within step 9,
  the `require-dev` addition of `symfony/process` and its `composer update` must come before
  `ComposerRunner` is written, since the class is unusable until the library is installed. Step 9
  and step 6 both edit `composer.json` but in disjoint blocks (`require-dev` vs `scripts`), so
  they may run in either order.
- Step 5's teardown fix must precede step 11; without it every Tier 2 test orphans a work
  directory containing a full `vendor/` tree.
- Step 10 must precede steps 11–16 (all six suites extend `IntegrationTestCase`).
- Step 11 must precede steps 12–16 (`bootstrapProd()` is validated there first).
- Step 12 must precede step 13 (the round trip starts from the DEV state step 12 establishes).
- Step 17 depends on steps 4–16 being settled, since it documents their outcome.
- Step 19 depends on all preceding steps.

## Required Components

New — test fixture (all three committed with the `__LOCAL_CLONE_PATH__` and
`__LIBRARY_SRC_PATH__` placeholders, substituted per-run into the work copy):
- `tests/assets/integration-project/composer.json`
- `tests/assets/integration-project/composer/composer-prod.json`
- `tests/assets/integration-project/composer/local-repositories.json`

New — harness classes (`tests/TestClasses/`, classmap-autoloaded via `composer.json` L22–L26):
- `tests/TestClasses/LocalPackageClone.php`
- `tests/TestClasses/ComposerRunner.php`
- `tests/TestClasses/ComposerResult.php`
- `tests/TestClasses/IntegrationTestCase.php`

New — Tier 2 suites (`tests/IntegrationSuites/`):
- `tests/IntegrationSuites/TestProdBootstrap.php`
- `tests/IntegrationSuites/TestDevSwitch.php`
- `tests/IntegrationSuites/TestRoundTrip.php`
- `tests/IntegrationSuites/TestVersionOverride.php`
- `tests/IntegrationSuites/TestEntryPoints.php`
- `tests/IntegrationSuites/TestGitHooks.php`

New — generated at runtime, gitignored:
- `tests/assets/local-clones/simple_html_dom/` — shallow clone of
  `https://github.com/Mistralys/simple_html_dom.git`

Renamed:
- `tests/assets/test-project/composer/dev-config.json` →
  `tests/assets/test-project/composer/local-repositories.json`

Modified:
- `phpunit.xml`
- `composer.json` (`scripts.test`, new `scripts.test-integration`, and a new `require-dev` entry
  `symfony/process: ^7.0 || ^8.0` added in step 9)
- `.gitignore`
- `tests/TestClasses/ComposerSwitcherTestCase.php`
- `tests/TestSuites/TestSwitching.php` (L324 only)
- `README.md`
- `changelog.md`
- `docs/agents/project-manifest/README.md`
- `docs/agents/project-manifest/tech-stack.md`
- `docs/agents/project-manifest/constraints.md`
- `docs/agents/project-manifest/file-tree.md`
- `docs/agents/project-manifest/data-flows.md`

New — dev-only Composer dependency (`require-dev`, added in step 9):
- `symfony/process: ^7.0 || ^8.0` — the subprocess mechanism inside `ComposerRunner`. Not added
  to `require`, so the published package keeps its zero-runtime-dependency property.

External services and infrastructure:
- `git` on `PATH`, and network access to `github.com` — for the clone.
- A `composer` binary on `PATH` or via `COMPOSER_BINARY`, and network access to
  `repo.packagist.org` — for Tier 2 resolution.
- PHP 8.4 with `ext-mbstring` — required by `mistralys/simple_html_dom` 2.0.0.

No `src/` file is created or modified by this plan.

## Assumptions

- `mistralys/simple_html_dom` 2.0.0 remains resolvable from Packagist with `ext-mbstring` as its
  only live requirement, and its GitHub repository remains publicly clonable.
- The PHP version running PHPUnit is 8.4 or higher with `ext-mbstring` loaded. Tier 2 cannot
  resolve `simple_html_dom` otherwise; the suite skips rather than fails. The development
  machine was verified on 2026-09-28 as PHP 8.5.9 with `mbstring`, Composer 2.9.5, and git
  available.
- The fixture's `autoload.classmap` entry is what makes the five `switch-*` handlers
  resolvable. This is no longer an assumption: a probe on 2026-09-28 confirmed that Composer
  refuses the handler without it and dispatches all five with it, across a full PROD → DEV →
  PROD round trip. Recorded in the research brief under "Verification Probe".
- The already-committed `composer.json` and `phpstan.neon` edits (PHP floor raised to
  `>=8.4`, platform pin removed, `phpVersion` parameter removed) are intentional and final. This
  plan documents them; it does not re-apply or revise them.
- `src/` contains no PHP 7.3-only construct that PHP 8.4 rejects. The v1.1.1 suite and PHPStan
  level 6 run against it today, and step 19's `composer analyze` confirms it under the new
  configuration.
- No consumer of this library still requires PHP 7.3–8.3 in a way that makes the v2.0.0 floor
  unacceptable. `../mailforge`'s unbounded `>=1.0.4` constraint is called out in Human Action 3.

## Constraints

- No file under `../hcp-editor/` or `../mailforge/` is read, written, executed against, or
  asserted on by any step or any work package. Those repositories appear
  only in Human Actions, and only as things to set aside or to adopt the release into later.
- No file under `src/` or `resources/` is modified. This is a test-infrastructure and
  documentation plan.
- `LocalPackageClone` must never run `composer install` or `composer update` inside the cloned
  package. A `vendor/` folder there would contradict `README.md` L172–L183 and pull in PHPUnit
  ^12.
- Tier 1 must remain runnable with no network and no `composer` binary. `composer test` must not
  reach `tests/IntegrationSuites/`.
- Every Tier 2 suite must skip — not fail — when `git`, the network, or the Composer binary is
  unavailable, and the skip message must name the cause.
- All Tier 2 filesystem effects stay inside `tests/assets/work-projects/`, except the shared
  clone cache at `tests/assets/local-clones/`, which is read-only apart from step 12's marker
  file (written and removed within the test).
- Teardown must `unlink()` a symlinked directory rather than `rmdir()` it, or the work
  directory survives removal. It must not be changed to follow symlinks, which would delete the
  cached clone's contents.
- `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md` runs after this one and was
  originally written to release v2.0.0 as well. This plan claims v2.0.0 for the PHP floor bump,
  and the ergonomics plan has been renumbered to v3.0.0 accordingly (2026-09-28). No version
  collision remains.
- `AGENTS.md` §2 governs which manifest documents must change; step 17 works from that table
  rather than from intuition.

## Out of Scope

- **`../hcp-editor` and `../mailforge` entirely.** No file in either is read, written, executed
  against, or verified — including the uncommitted script-key changes already present in both
  working trees. They are adopters of the published package, not a validation surface, and
  nothing in this plan depends on their state.
- A **wholesale** modernisation sweep of `src/` to PHP 8.4 idioms. Per the policy step 2
  codifies, any file this plan touches may be modernised in the same pass; this plan touches no
  `src/` file, so none is modernised here. Converting the untouched remainder of `src/` is a
  mechanical diff that belongs to the plans that next touch those files.
- Reshaping `resources/git-hooks/pre-commit` into a testable guard registry, per insight
  `2cd87f16-2556-4523-af27-0b88a6adbf0b`. Rejected with reasons under Structural Improvements.
- Everything in `docs/agents/plans/2026-09-25-switcher-ergonomics/plan.md` — value objects, the
  `FileSystem` choke-point, `describe()`, `reconcile()`, dry-run previews. That plan is
  unexecuted and untouched by this one.
- Enlarging `tests/assets/test-project/composer.lock` into a real lock file. Rejected under
  Structural Improvements.
- CI configuration. The repository has none, and adding a pipeline for a two-tier suite is a
  separate decision.
- Publishing the v2.0.0 tag to Packagist.

## Human Actions

| # | Action | When | Why an agent cannot do it |
|---|--------|------|---------------------------|
| 1 | Review, commit, or discard the uncommitted working trees in `../hcp-editor` and `../mailforge`. Both already carry a script-key rename alongside unrelated drift — `../mailforge`'s `composer.lock` shows a 535-line diff across ~22 packages. Neither tree is needed by, or touched by, this plan. | Any time | Both repositories are out of scope by your decision, and judging which of those changes to keep requires knowledge of those projects' release state. |
| 2 | Tag and publish `v2.0.0` once the plan's work is merged. | After the run | Publishing a release to Packagist requires credentials and a judgement call on timing. |
| 3 | Adopt the published `v2.0.0` in one consumer as a final real-world confirmation, and set its constraint deliberately — `../hcp-editor/composer.json` L145 (`^1.0.4`, will not resolve 2.0.0) and `../mailforge/composer.json` L74 (unbounded `>=1.0.4`, will pull 2.0.0 in silently and raise that project's PHP floor to 8.4). | After the run | Whether either project can move to PHP 8.4 is a decision about those codebases. This is confirmation that the library works in the field, not part of its test suite — the suite proves correctness on its own. |

## Acceptance Criteria

- AC-01: `tests/assets/integration-project/` exists as a valid three-path-convention project — `composer.json`, `composer/composer-prod.json`, `composer/local-repositories.json` — whose `scripts` block wires all five static entry points under their `switch-` namespaced keys, and whose committed files carry the `__LOCAL_CLONE_PATH__` and `__LIBRARY_SRC_PATH__` placeholders rather than machine-specific paths.
- AC-02: `composer update` succeeds in a bootstrapped work copy of the integration fixture, producing a real `composer.lock` and a `vendor/mistralys/simple_html_dom` directory that is not a symlink, with no switcher state artefact present. The invocation completes rather than blocking, and `ComposerResult` exposes stdout and stderr as separate, correctly attributed streams — `ComposerRunner` executes via `Symfony\Component\Process\Process`, not a hand-rolled `proc_open()` pipe loop.
- AC-03: After `composer switch-dev` and `composer update`, `vendor/mistralys/simple_html_dom` is a symlink resolving into the cloned package directory.
- AC-04: A file written into the cloned package is readable through the work copy's `vendor/mistralys/simple_html_dom` path while in DEV mode, and the marker is removed before the test ends.
- AC-05: The DEV `composer.json` contains a `repositories` entry with `type: path`, `options.symlink: true`, and a `url` equal to the resolved clone path, and the `require` constraint for the package is `*`.
- AC-06: After `composer switch-prod` and `composer update`, the path repository is gone, the `^2.0` constraint is restored, and `vendor/mistralys/simple_html_dom` is a real directory again.
- AC-07: `composer/composer-prod.lock` and `composer/local-repositories.lock` both exist after a full round trip and differ from one another, and after `switch-prod` the active `composer.lock` is byte-identical to `composer/composer-prod.lock`. A following `composer install` then completes the transition — it reports pending operations, not "nothing to install", because the lock is restored before `vendor/` is, which is exactly what `README.md` L204–L209 instructs the user to do.
- AC-08: With a `version` of `2.0.0` configured, the generated repository entry carries `options.versions` holding **both** `mistralys/simple_html_dom` and the hyphen-normalised alias `mistralys/simple-html-dom`, and `composer show mistralys/simple_html_dom` reports `2.0.0`; with no `version` configured, `options.versions` is absent and the reported version is `dev-master`.
- AC-09: All five `composer switch-*` commands dispatch successfully from the work copy and produce the output documented at `src/ConfigSwitcher.php` L100–L129, including the DEV-mode message, the in-sync message, the differing-keys listing, and the INITIAL-state no-op of `switch-update`.
- AC-10: `composer switch-install-hooks` in a git-initialised work copy installs an executable `.git/hooks/pre-commit`, and that hook blocks a staged `composer.json` under both Guard 1 (DEV marker present) and Guard 2 (`type: path` entry present), while passing in PROD state.
- AC-11: Every Tier 2 suite skips with a cause-naming message when `git`, the Composer binary, or the network is unavailable, and no Tier 2 suite fails for those reasons.
- AC-12: `composer test` runs only `tests/TestSuites/`, passes all 16 existing tests against the renamed `composer/local-repositories.json` fixture, and completes with no network access and no Composer binary invocation.
- AC-13: `composer test-integration` runs only `tests/IntegrationSuites/`, and `composer analyze` reports zero PHPStan errors at level 6.
- AC-14: No file under `src/`, `resources/`, `../hcp-editor/`, or `../mailforge/` is modified by the plan's execution.
- AC-15: Work-directory teardown fully removes a work copy whose `vendor/` contains a symlinked package, leaving no orphaned directory under `tests/assets/work-projects/`, and the cached clone at `tests/assets/local-clones/` is intact with an unchanged file count after a full Tier 2 run.
- AC-16: `changelog.md` carries a `## v2.0.0` entry recording the PHP floor bump, the script-key rename with unchanged handlers, and the integration suite; `docs/agents/project-manifest/README.md` reports version 2.0.0 and PHP >=8.4.
- AC-17: No file in `README.md`, `AGENTS.md`, or `docs/agents/project-manifest/` claims PHP 7.3 compatibility, references the removed `config.platform.php` pin, or states that PHPStan has no configuration file in the repository; and `docs/agents/project-manifest/constraints.md` states the PHP 8.4 baseline together with the opportunistic-modernisation policy (new code uses PHP 8 constructs; touched files may be modernised in the same pass; wholesale sweeps stay out of scope).
- AC-18: `README.md` §"Version control" names `composer-prod.json`, `composer-prod.lock`, `local-repositories.json`, and `local-repositories.status`, matching the three-path convention and the integration fixture.
- AC-19: `docs/agents/project-manifest/file-tree.md`, `constraints.md`, and `data-flows.md` describe the two-tier test structure, the new directories, and the `local-repositories.*` filenames, satisfying the `AGENTS.md` §2 rows for new files, directory restructuring, and test-structure change.

## Testing Strategy

Tier 1 keeps the existing fixture-copy harness: every test copies
`tests/assets/test-project/` into an ephemeral work directory and drives a real `ConfigSwitcher`
over real files with no Composer process. It is the fast, offline, always-run tier, and this plan
changes only the fixture's dev-config filename.

Tier 2 asserts what Tier 1 structurally cannot. Each suite copies
`tests/assets/integration-project/`, substitutes the resolved clone path, runs one real
`composer update` to reach a PROD baseline with a genuine lock file, then drives real
`composer switch-*` commands and inspects the result at the level Composer itself sees it:
resolved versions via `composer show`, pending operations via `composer install --dry-run`, and
the vendor tree via `is_link()` and `realpath()`. Assertions are on observable filesystem and
process facts — exit codes, symlink targets, file contents read through the vendor path — rather
than on the library's own reports of what it did, because the library reporting success is the
claim under test.

Availability is decided once, in `LocalPackageClone` and `ComposerRunner`, so an offline machine
produces a uniform, cause-naming skip across all six suites rather than six different failures.

The git-hook suite runs the shipped bash hook against a real `git init`-ed work copy with a real
staging area. That is safe only because the work copy is ephemeral and thrown away on teardown;
running `install-hooks` against a developer checkout would overwrite that developer's own
`.git/hooks/pre-commit`, which is why no step does so.

PHPStan level 6 over `src/` guards the unchanged production code under the new PHP 8.4
configuration. Documentation is verified by reading the changed files against the `AGENTS.md` §2
table and by a residual grep for the stale claims this plan removes.

## Test Plan

- `tests/TestSuites/TestSwitching.php` (all 16 existing tests, updated fixture path) — every existing assertion still passes after `composer/dev-config.json` is renamed to `composer/local-repositories.json` — AC-12.
- `tests/TestSuites/TestSwitching.php::test_devSwitchRespectsRequireDevPlacement` (L313–L341, updated) — the `ConfigFile` at L324 resolves to `composer/local-repositories.json` and the require-dev placement assertion is unchanged — AC-12.
- `composer test` — runs `--testsuite "Test suites"` only; asserted by confirming no `tests/IntegrationSuites/` test appears in the output and the run succeeds with no Composer binary on `PATH` — AC-12.
- `tests/IntegrationSuites/TestProdBootstrap.php::test_fixtureIsValidThreePathProject` — the copied work directory contains `composer.json`, `composer/composer-prod.json` and `composer/local-repositories.json`; the `scripts` block maps all five `switch-*` keys to their `ConfigSwitcher::composer*` handlers; neither placeholder survives substitution in the work copy, and `composer.json` and `composer/composer-prod.json` both carry the resolved classmap path — AC-01.
- `tests/IntegrationSuites/TestProdBootstrap.php::test_committedFixtureCarriesPlaceholders` — the **committed** fixture files contain `__LOCAL_CLONE_PATH__` and `__LIBRARY_SRC_PATH__` and no absolute filesystem path, guarding against a machine-specific path being committed — AC-01.
- `tests/IntegrationSuites/TestProdBootstrap.php::test_prodUpdateInstallsRealPackage` — `composer update` exits zero, `composer.lock` is valid JSON naming `mistralys/simple_html_dom`, `vendor/mistralys/simple_html_dom` exists and `is_link()` is false — AC-02.
- `tests/IntegrationSuites/TestProdBootstrap.php::test_composerRunnerSeparatesStreams` — the `ComposerResult` from the bootstrap `composer update` (the plan's largest-output invocation) returns a non-empty value from exactly one of `getOutput()` / `getErrorOutput()` for a successful run and the other for a deliberately failing invocation, and the run completes rather than blocking — the observable evidence that the `symfony/process` execution path captures both streams without deadlocking — AC-02.
- `tests/IntegrationSuites/TestProdBootstrap.php::test_noStateArtefactsBeforeFirstSwitch` — `composer.json.DEV`, `composer.json.PROD` and `composer/local-repositories.status` are all absent after bootstrap — AC-02.
- `tests/IntegrationSuites/TestDevSwitch.php::test_devSwitchGeneratesPathRepository` — after `composer switch-dev`, the `repositories` array holds one entry with `type: path`, `options.symlink: true` and `url` equal to the resolved clone path, and `require['mistralys/simple_html_dom']` is `*` — AC-05.
- `tests/IntegrationSuites/TestDevSwitch.php::test_devSwitchWritesStateArtefacts` — `composer.json.DEV` and `composer/local-repositories.status` exist, the status reports mode `dev`, and `composer.json.PROD` is absent — AC-05.
- `tests/IntegrationSuites/TestDevSwitch.php::test_devUpdateCreatesVendorSymlink` — after `composer update` in DEV, `is_link(vendor/mistralys/simple_html_dom)` is true and `realpath()` of the link resolves inside the clone directory — AC-03.
- `tests/IntegrationSuites/TestDevSwitch.php::test_cloneEditIsVisibleThroughVendorPath` — a marker file written into the clone is readable at `vendor/mistralys/simple_html_dom/{marker}`; the marker is deleted in teardown and the clone asserted clean — AC-04, AC-15.
- `tests/IntegrationSuites/TestRoundTrip.php::test_prodSwitchRestoresPublishedPackage` — after `composer switch-prod` and `composer update`, `composer.json` has no `type: path` entry, the require constraint is `^2.0` again, and `vendor/mistralys/simple_html_dom` is a real directory — AC-06.
- `tests/IntegrationSuites/TestRoundTrip.php::test_flagFilesFollowTheSwitch` — `composer.json.PROD` exists and `composer.json.DEV` does not, inverting the DEV-state assertion — AC-06.
- `tests/IntegrationSuites/TestRoundTrip.php::test_bothLockFilesExistAndDiffer` — `composer/composer-prod.lock` and `composer/local-repositories.lock` both exist after a full round trip and their contents differ — AC-07.
- `tests/IntegrationSuites/TestRoundTrip.php::test_switchRestoresMatchingLockFile` — after `switch-prod` the active `composer.lock` is byte-identical to `composer/composer-prod.lock`, and after `switch-dev` it is byte-identical to `composer/local-repositories.lock` — AC-07.
- `tests/IntegrationSuites/TestRoundTrip.php::test_installCompletesTheTransition` — `composer install --dry-run` immediately after `switch-prod` reports a pending operation for `mistralys/simple_html_dom` (the lock is restored before `vendor/` is), and a following `composer install` exits zero and leaves a real directory in `vendor/` — AC-07.
- `tests/IntegrationSuites/TestVersionOverride.php::test_wildcardVersionOmitsVersionsOption` — with no `version` key, the generated repository entry has no `options.versions`, and `composer show mistralys/simple_html_dom` reports `dev-master` — AC-08.
- `tests/IntegrationSuites/TestVersionOverride.php::test_explicitVersionPinsResolvedPackage` — with `"version": "2.0.0"`, the entry carries `options.versions['mistralys/simple_html_dom'] === '2.0.0'` and `composer show` reports `2.0.0`, a value that differs from the inferred one — AC-08.
- `tests/IntegrationSuites/TestVersionOverride.php::test_underscoreNameGetsHyphenAlias` — `options.versions` also holds `mistralys/simple-html-dom`, covering the underscore-normalisation branch at `src/ConfigSwitcher.php` L625–L632. The fixture package name contains an underscore, so this branch is exercised by the standard scenario rather than a contrived one — AC-08.
- `tests/IntegrationSuites/TestEntryPoints.php::test_verifyConfigReportsInSync` — `composer switch-verify-config` in PROD exits zero and prints `composer.json and composer-prod.json are in sync.` — AC-09.
- `tests/IntegrationSuites/TestEntryPoints.php::test_verifyConfigListsDifferences` — after editing `composer/composer-prod.json`, the command prints `composer.json and composer-prod.json differ in the following keys:` followed by a line naming the changed key — AC-09.
- `tests/IntegrationSuites/TestEntryPoints.php::test_verifyConfigReportsDevMode` — in DEV state the command prints `DEV mode is active — config comparison skipped.` — AC-09.
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchUpdatePropagatesProdEdit` — in PROD state, an edit to `composer/composer-prod.json` followed by `composer switch-update` reaches `composer.json` — AC-09.
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchUpdateIsNoOpInInitialState` — before any switch, `composer switch-update` exits zero and leaves `composer.json`, the flag files and the status file untouched — AC-09.
- `tests/IntegrationSuites/TestEntryPoints.php::test_switchDevAndProdExitSuccessfully` — both commands exit zero and write their respective flag file — AC-09.
- `tests/IntegrationSuites/TestGitHooks.php::test_installHooksCopiesExecutableHook` — after `git init`, `composer switch-install-hooks` exits zero, prints `Git hooks installed successfully.`, and `.git/hooks/pre-commit` exists and is executable — AC-10.
- `tests/IntegrationSuites/TestGitHooks.php::test_guard1BlocksCommitInDevMode` — with `composer.json.DEV` present and `composer.json` staged, running the hook exits non-zero and its output names `composer.json` — AC-10.
- `tests/IntegrationSuites/TestGitHooks.php::test_guard2BlocksPathRepository` — with a staged `composer.json` containing `"type": "path"` and no DEV marker, the hook exits non-zero — AC-10.
- `tests/IntegrationSuites/TestGitHooks.php::test_hookPassesInProdState` — in PROD state with `composer.json` staged and no DEV marker, the hook exits zero — AC-10.
- `tests/IntegrationSuites/TestGitHooks.php::test_skipsWhenGitUnavailable` — with git reported unavailable, the suite is skipped and the message names git as the cause — AC-11.
- `tests/TestSuites/TestSwitching.php` or a dedicated harness test — `LocalPackageClone::ensureAvailable()` returns `null` and `getUnavailableReason()` names the cause when the cache directory is present but contains no `composer.json`; this path is exercised without network access so it belongs in Tier 1 — AC-11.
- `tests/IntegrationSuites/TestDevSwitch.php::test_workDirectoryIsFullyRemoved` — after a DEV work copy with a symlinked `vendor/` package is torn down, no directory remains under `tests/assets/work-projects/` for that test, and the clone directory still exists with an unchanged file count — AC-15.
- `composer test-integration` — runs `--testsuite Integration`; asserted by confirming the six Tier 2 suites execute and no `tests/TestSuites/` test appears — AC-13.
- `composer analyze` — zero PHPStan errors at level 6 over `src/` — AC-13.
- Manual check: `git status --short` in `../hcp-editor` and `../mailforge` reports the same file list before and after the plan's execution; `git diff --stat` on `src/` and `resources/` is empty — AC-14.
- Manual check: `changelog.md` has `## v2.0.0` as its top entry covering the PHP floor, the script rename with unchanged handlers, and the integration suite; `docs/agents/project-manifest/README.md` L4 reads `2.0.0` and L6 reads `>=8.4` — AC-16.
- Residual grep across `README.md`, `AGENTS.md`, and `docs/agents/project-manifest/` for `7\.3`, `70300`, `platform`, and `no config file in repo`; assert zero hits outside `docs/agents/implementation-history/` and `docs/agents/plans/` — AC-17.
- Manual check: `docs/agents/project-manifest/constraints.md` carries the PHP 8.4 baseline bullet with all four policy clauses (PHP 8 constructs available, new code uses them, touched files may be modernised in the same pass, wholesale sweeps out of scope) — AC-17.
- Manual check: `README.md` §"Version control" names `composer-prod.json`, `composer-prod.lock`, `local-repositories.json`, `local-repositories.status`, and `local-repositories.dist.json` — AC-18.
- Manual check against the `AGENTS.md` §2 table: new files → `file-tree.md` + `api-surface.md` (confirming no public signature changed); directory restructured → `file-tree.md`; test structure changed → `file-tree.md` + `constraints.md`; new data flow → `data-flows.md`; PHP version requirement changed → `tech-stack.md` + `constraints.md` — AC-19.

## Deferred Items

| # | Deferred Item | Origin | Reason Deferred | Notes |
|---|---------------|--------|-----------------|-------|
| 1 | All consumer-side propagation of the script-key rename in `../hcp-editor` and `../mailforge`, including `../mailforge`'s vendor copy of this library being locked at pre-hardening commit `c248cadb` | Observed in both working trees during research | Both repositories are fully out of scope: they adopt the published package rather than validate it, and nothing in this plan depends on their state | Not tracked as library debt. Human Action 1 covers the working trees; Human Action 3 covers adopting v2.0.0 later, which incidentally refreshes the stale vendor copy |
| 2 | Reproduce the documented `has higher repository priority` resolver error end-to-end | Raised during this plan's design | Needs a third package constraining `simple_html_dom`, which a zero-dependency fixture cannot supply | Step 14 asserts the resolved version instead, which proves the `version` property works without manufacturing the conflict |
| 3 | Reshape `resources/git-hooks/pre-commit` into a cross-platform guard registry | Insight `2cd87f16-2556-4523-af27-0b88a6adbf0b` | Changes a shipped resource already installed in consumers' `.git/hooks/`; needs its own plan and migration story | Step 16 gives the hook its first test coverage, which is the prerequisite for reshaping it safely later |
| 4 | Modernise the untouched remainder of `src/` to PHP 8.4 idioms | The PHP floor bump documented in step 1 | A large mechanical diff across every file in `src/`, unrelated to the test-surface problem this plan solves. This plan touches no `src/` file, so none falls inside its blast radius | Not a blocked item: step 2 codifies opportunistic modernisation, so each file gets converted by whichever future plan next touches it. No dedicated cleanup plan is needed |

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **Tier 2 becomes flaky because it depends on Packagist, GitHub, and a Composer binary.** | Availability is decided once in `LocalPackageClone` and `ComposerRunner`, and every suite skips with a cause-naming message rather than failing (AC-11). The clone is cached across runs, so the network cost falls to one `composer update` per test after the first run. Tier 2 is excluded from the default `composer test`, so an outage never blocks ordinary development. |
| **`ComposerRunner` hangs instead of failing, because a subprocess whose stdout and stderr are captured separately fills an OS pipe buffer while the reader is blocked on the other pipe.** | Step 9 names `symfony/process` as the execution mechanism rather than leaving `proc_open()` to the implementer; the library drains both pipes non-blockingly. Because all six Tier 2 suites call through this single choke-point, the mechanism is decided once and cannot drift per suite. The dependency is `require-dev` only, so nothing a consumer installs changes. |
| **`mistralys/simple_html_dom` gains a live dependency in a future release, breaking the no-`vendor/` clone workflow.** | The fixture pins `^2.0`, and 2.0.0 is the only published 2.x release. `TestProdBootstrap` asserts the installed tree, so a dependency appearing would surface as a test failure rather than silent drift. |
| **Teardown leaves an orphaned work directory for every Tier 2 test.** | Confirmed by probe: `removeDirectory()` calls `rmdir()` on the vendor symlink, which fails, so the work directory survives. Step 5 adds the `isLink()` → `unlink()` branch and `test_workDirectoryIsFullyRemoved` asserts it (AC-15). The same probe showed the cached clone is **not** at risk, since `RecursiveDirectoryIterator` does not follow symlinks by default — this is a leak, not data loss. |
| **The fixture's `scripts` handlers do not resolve.** | Resolved before planning completed. A 2026-09-28 probe confirmed Composer refuses the handler without an autoloader (`Class ... is not autoloadable`) and dispatches all five with an absolute `autoload.classmap` entry, across a full PROD → DEV → PROD round trip, with no `require` of the library. `test_fixtureIsValidThreePathProject` asserts both manifests carry the resolved path, so a substitution applied to only one would fail fast rather than surface as an inexplicable dispatch error after the first DEV switch. |
| **v2.0.0 collided with `2026-09-25-switcher-ergonomics`, which originally claimed v2.0.0 too.** | Resolved on 2026-09-28: the ergonomics plan runs second and was renumbered to v3.0.0, with its changelog step, manifest version line, acceptance criteria and consumer-constraint Human Actions updated to match. Both releases are breaking, so neither could take a minor version. |
| **`../mailforge`'s unbounded `>=1.0.4` constraint silently pulls v2.0.0 in and raises that project's PHP floor to 8.4.** | Human Action 3 names the exact file and line and frames the constraint as a deliberate choice for that project's maintainer when adopting the release published under Human Action 2. |
| **Renaming the Tier 1 fixture's dev config breaks a test in a way the 16-test run does not catch.** | Only two source references exist, both verified in the research brief. The status filename changes as a documented consequence of the path-derivation rule, and step 4 requires a full green `composer test` before any later step proceeds. |
| **`src/` contains a construct PHP 8.4 rejects, and Tier 2 surfaces it as an unrelated failure.** | Step 19 runs `composer analyze` under the new configuration, and Tier 1 runs offline against the same `src/`. A PHP-level incompatibility would fail Tier 1 first, where it is trivially diagnosable. |

## Recommended Workflow

- **Workflow:** ledger
- **Rationale:** Nineteen steps spanning new test infrastructure, a fixture migration, a release
  entry, and six manifest documents, with a dense chain of sequencing constraints between the
  harness classes and the six suites that consume them — the QA and review stages are worth the
  ceremony.
