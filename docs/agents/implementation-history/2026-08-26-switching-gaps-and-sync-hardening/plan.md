# Plan

## Plan Audit Cycles
- Audits: 2 (cycle 1: PASS WITH FINDINGS, 3 Major findings resolved; cycle 2: PASS WITH FINDINGS, 1 Major finding resolved) — Plan Auditor v1.7.0
- Architectural Reviews: 2 — Plan Architect Reviewer v2.2.0

## Summary
Harden the `mistralys/composer-local-switcher` library (targeting v1.1.0) and its two consumer projects (HCP Editor, Mail Forge) against configuration drift between `composer.json` and `composer-prod.json`. The plan addresses six categories of gaps discovered during cross-project analysis: (1) stale path repository entries surviving across DEV switches, (2) the absence of a config verification command, (3) Mailforge's missing pre-commit hook, (4) `require-dev`-listed packages being incorrectly added to `require` during DEV switch, (5) housekeeping issues (stale `.gitignore` entries, non-canonical status file paths), and (6) duplicated consumer boilerplate — both consumers maintain identical `createSwitcher()` factories and one-liner wrapper methods that the library can provide directly as Composer script entry points. An existing plan (`2026-08-18-composer-dev-switch`) addresses workflow automation (switch + install in one command) and is orthogonal to this work.

## Architectural Context

The switching infrastructure has three layers:

1. **`mistralys/composer-local-switcher` library** (`src/`) — A zero-dependency PHP >=7.3 library. The `ConfigSwitcher` orchestrator manages file-level operations: it reads `composer-prod.json` as the baseline, overlays path repositories from `local-repositories.json` when switching to DEV, and restores the baseline when switching to PROD. State is tracked via a JSON status file and optional flag files (`composer.json.DEV` / `composer.json.PROD`).

2. **Consumer project wrapper classes** — HCP Editor's `ComposerScripts::createSwitcher()` (`assets/classes/Maileditor/Composer/ComposerScripts.php` L622–L639) and Mailforge's `CacheControl::createSwitcher()` (`mailings/assets/classes/CacheControl.php` L303–L318) each instantiate `ConfigSwitcher` with three project-specific paths (main, prod, dev config). Both expose `switch-dev`, `switch-prod`, and `switch-update` as Composer scripts. The three-path convention (`composer.json`, `composer/composer-prod.json`, `composer/local-repositories.json`) and the lazy-cached factory pattern are duplicated verbatim in both consumers, alongside one-liner switch wrapper methods that add no project-specific logic — all of this boilerplate can be absorbed into the library (see Step 6).

3. **Git hooks** — HCP Editor has a two-guard `pre-commit` hook (`tools/git-hooks/pre-commit`) that blocks commits when DEV mode is active or when `composer.json` contains path repositories. Mailforge has no equivalent hook.

Key design invariant: `switch_adjustConfigForDev()` always starts from `prodFile->getData()` as the baseline and overlays DEV-specific changes. This means the DEV `composer.json` is a deterministic function of `composer-prod.json` + `local-repositories.json` — the PROD file is the source of truth for everything except `require` version constraints and `repositories` entries for switched packages.

## Approach / Architecture

**Library changes** (all in `src/ConfigSwitcher.php` unless noted):

1. **Prune stale VCS repository entries** during `switch_adjustConfigForDev()` — the existing per-package matching loop (`src/ConfigSwitcher.php` L484–L512) already replaces the *first* matching repository entry (VCS or otherwise) in place; its only gap is the `break` after that first match, which leaves any *additional* matching entries (e.g. a duplicate or differently-cased URL variant) unpruned. The fix extends this single loop — drop the `break`, replace the first match, and remove any further matches within the same pass — rather than adding a second post-loop pruning scan with its own matching logic to keep in sync.

2. **Respect `require-dev` placement** — when a local-repository package is in `require-dev` (not `require`) in the prod config, the DEV switch should update `require-dev` instead of `require`. Currently, line 444 unconditionally writes to `$config['require']`, which would move a `require-dev` package into `require` during DEV mode.

3. **Add a `verify()` method** — a read-only comparison that reports whether `composer.json` matches `composer-prod.json` in content. Both files are already read via `ConfigFile::getData()` as plain PHP arrays, so `verify()` recursively `ksort()`s both arrays and diffs them key-by-key in a single normalization pass — `inSync` is derived as `empty($differences)` rather than computed separately via a content hash, so the boolean and the diff list can never disagree. The DEV-mode early return reuses the existing `StatusFile::isDEV()` helper rather than re-deriving mode state. This enables a `composer verify-config` script in consumer projects.

4. **Normalize paths in the status file** — use `realpath()` before storing paths in the status file to eliminate `/../` segments.

5. **Add a shared git-hook installer to the library** — bundle the pre-commit hook script as a resource file (`resources/git-hooks/pre-commit`) inside the switcher library, and add an `installGitHooks(string $projectRoot): bool` method to `ConfigSwitcher` that copies it into `$projectRoot/.git/hooks/pre-commit` with executable permissions, returning `true` on success. When `$projectRoot/.git/hooks/` does not exist, the method skips installation and returns `false` without creating the directory — mirroring HCP Editor's current skip-with-warning behavior (`hcp-editor/assets/classes/Maileditor/Composer/ComposerScripts.php` L48–L72) — rather than depending on HCP Editor's `BuildMessages` class, which would break the library's zero-dependency constraint; each consumer wrapper is responsible for turning a `false` result into its own warning message. This gives both consumers a single, library-maintained copy of the hook instead of two independently-maintained ones.

6. **Add a project-root factory and Composer script entry points to the library** — add a static `fromProjectRoot(string $rootPath): self` factory to `ConfigSwitcher` that encodes the three-path convention (`$root/composer.json`, `$root/composer/composer-prod.json`, `$root/composer/local-repositories.json`) shared by both consumers. On top of this factory, add static Composer script entry points (`composerSwitchDev()`, `composerSwitchProd()`, `composerSwitchUpdate()`, `composerVerifyConfig()`, `composerInstallHooks()`) that call `self::fromProjectRoot(getcwd())` and invoke the corresponding instance method. Composer always sets `getcwd()` to the project root when invoking custom script methods, so no path calculation is needed. Each entry point handles its own console output (e.g. `composerVerifyConfig()` prints the diff result, `composerInstallHooks()` prints a warning when `installGitHooks()` returns `false`). The existing three-argument constructor remains available for non-standard layouts or programmatic use (e.g. the test suite).

**Consumer changes:**

7. **Both consumers: Wire Composer scripts directly to the library; remove wrapper boilerplate** — replace all switcher-related Composer script entries in both consumers' `composer.json` and `composer-prod.json` to point directly at the library's new static entry points (e.g. `"switch-dev": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDev"`). Remove the now-unnecessary wrapper methods and `createSwitcher()` factories from HCP Editor's `ComposerScripts` and Mailforge's `CacheControl`. For HCP Editor, also remove `tools/git-hooks/pre-commit` (superseded by the library resource). Mailforge gains `install-hooks` and `verify-config` scripts pointing at the library entry points. This eliminates ~25 lines + 5 methods of duplicated boilerplate per consumer.

8. **Mailforge: Clean up stale `.gitignore` entries** — remove the obsolete `composer/composer-dev.lock` and `composer/composer-dev.status` lines.

## Rationale

- **Stale VCS repo pruning** prevents Composer from downloading metadata from both the VCS source and the local path for the same package. This is a minor performance issue but causes confusing output when Composer reports version conflicts.
- **Extending the existing matching loop instead of adding a second pass** keeps VCS-pruning logic in exactly one place — a future change to the matching heuristic (e.g. exact match instead of substring) only needs to be applied once, and the boolean-safety benefit is the same as a two-pass approach without the duplicated matching code.
- **`require-dev` awareness** is a correctness fix. Currently no consumer project has a `require-dev` package in `local-repositories.json`, but the switcher library should handle this correctly as a matter of API correctness — a future consumer may need it.
- **The `verify()` method** addresses the root cause of "are my configs in sync?" anxiety. It replaces manual JSON diffing with a single command. It's read-only and non-destructive — it never modifies files. Driving `inSync` and `differences` from one normalization pass (rather than a hash plus a separate diff loop) removes the risk of the two disagreeing.
- **Mailforge's missing pre-commit hook** is the single most impactful safety gap. Without it, a DEV-mode `composer.json` with path repositories can be committed and pushed, breaking the production deployment.
- **Bundling the pre-commit hook in the library** removes the "keep N copies in sync by hand" cost — with two consumers today and the switcher library explicitly designed to serve more than one, a shared installer scales better than verbatim per-project copies of a hook the plan itself confirms is identical across consumers.
- **Path normalization** is cosmetic but eliminates confusion when debugging status file contents.
- **A project-root factory and Composer script entry points in the library** eliminate the identical boilerplate both consumers maintain: a lazy-cached `createSwitcher()` factory hardcoding the same three-path convention, plus one-liner switch/verify/install-hooks wrapper methods that add no project-specific logic. With both consumers using the same file layout and Composer guaranteeing `getcwd()` is the project root during script execution, the library can provide ready-to-use static entry points that consumers wire directly in `composer.json` — reducing each consumer's switcher integration to pure configuration with zero PHP glue code. The full three-argument constructor remains available as an escape hatch for non-standard layouts.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| Drift detection mechanism | Single recursive-array comparison (`ksort()` both `getData()` results, diff key-by-key, `inSync = empty($differences)`) | File mtime comparison (existing); content-hash + separate key-diff loop; full JSON deep-diff | Mtime is unreliable (file touches, git operations). A hash-plus-separate-diff-loop risks `inSync` and `differences` disagreeing if normalization rules drift apart. Full deep-diff is complex and produces noisy output. A single normalization pass driving both outputs is simplest and self-consistent by construction. |
| VCS repo cleanup during DEV switch | Extend the existing per-package matching loop (drop the `break`, prune any further matches) | Add a second post-loop pruning pass with its own matching logic; leave VCS entries alone | The existing loop already replaces the first match in place — a second pass would duplicate its matching logic and need to stay in sync with it. Leaving VCS entries alone causes Composer to resolve against both sources. |
| `require-dev` detection | Check both `require` and `require-dev` in prod config to determine correct placement | Always use `require`; add a `section` key to `local-repositories.json` | Checking the prod config is zero-configuration and handles all cases. A `section` key adds config surface area for no benefit. |
| Pre-commit hook strategy | Bundle the hook as a library resource (`resources/git-hooks/pre-commit`) with a shared `ConfigSwitcher::installGitHooks()` installer, called by both consumers | Port HCP Editor's hook verbatim into Mailforge as an independent copy; third-party hook manager (e.g. `captainhook/captainhook`) | Verbatim copies mean a future guard/fix must be applied and tested in every consumer separately, despite the hook logic being identical across both today. Third-party managers require PHP >=8.0, incompatible with the library's PHP 7.3 floor. A bundled resource + shared installer keeps one copy, dependency-free, consistent with Composer packages routinely shipping non-autoloaded assets. |
| Consumer integration strategy | Library provides `fromProjectRoot()` factory + static Composer script entry points; consumers wire `composer.json` scripts directly to library methods with zero PHP wrapper code | Keep per-consumer `createSwitcher()` factories and one-liner wrapper methods (status quo extended to new commands); add a shared abstract base class that consumers extend | Both consumers use identical three-path conventions and identical one-liner wrappers — the factory and wrappers add no project-specific logic. Keeping them duplicated means every new library command requires adding a wrapper method and Composer script entry in every consumer. A shared base class adds inheritance coupling without eliminating the per-consumer script registration. Library-provided entry points using `getcwd()` (guaranteed by Composer during script execution) reduce consumer integration to pure `composer.json` configuration, and the three-argument constructor remains available for non-standard layouts. |

## Pattern Alignment

- **Library-provided Composer script entry points** — replaces the previous pattern of per-consumer thin wrappers. Both consumers' `switch-dev` / `switch-prod` / `switch-update` wrappers were structurally identical one-liners with no project-specific logic; absorbing them into the library eliminates duplicated boilerplate and ensures new commands (e.g. `verify-config`, `install-hooks`) are available to all consumers without per-project wrapper methods. Composer guarantees `getcwd()` is the project root during script execution, making `fromProjectRoot(getcwd())` a reliable, zero-configuration factory.
- **`array()` syntax** — all new PHP code uses `array()`, per the switcher library's PHP 7.3 compatibility constraint (`src/ConfigSwitcher.php` L451, L480).
- **Reusing `StatusFile::isDEV()`** — `verify()`'s DEV-mode early return calls the existing helper (`src/Utils/StatusFile.php`) rather than introducing a second mode-detection mechanism, consistent with `StatusFile` already being the single source of truth for mode state elsewhere in `ConfigSwitcher`.
- **Non-autoloaded resource files in a Composer package** — bundling `resources/git-hooks/pre-commit` departs from the switcher library's current file layout (`src/` only), but is a well-established Composer convention (packages routinely ship templates/scripts alongside classmap/PSR-4 code) and adds no runtime dependency, preserving the library's zero-dependency, PHP 7.3 constraint.
- **`composer install-hooks` script** — follows HCP Editor's existing `composer install-hooks` pattern; both consumers keep the same script name, now wired directly to the library's `composerInstallHooks()` entry point.
- **Git hooks in `tools/git-hooks/`** — HCP Editor's existing pattern is superseded for the pre-commit hook specifically, since it is now sourced from the library's bundled resource. This is a deliberate, review-recommended departure to eliminate the "two independently-maintained copies" problem noted for a hook the plan itself confirms is identical across consumers.

## Detailed Steps

### Step 1: Prune matching VCS repositories during DEV switch

In `src/ConfigSwitcher.php`, modify the existing repository-matching loop inside `switch_adjustConfigForDev()` (L484–L512): remove the `break` after the first match, replace that first match in place as today, and remove any further matching entries found later in the same loop pass (e.g. via `unset()` + `array_values()` after the loop completes for that package). This closes the gap without introducing a second pruning pass or a second copy of the matching logic.

**Files:**
- `src/ConfigSwitcher.php` — modify `switch_adjustConfigForDev()` (around L484–L512)

### Step 2: Respect `require-dev` placement

In `src/ConfigSwitcher.php`, modify `switch_adjustConfigForDev()` so that when setting the version constraint for a switched package (currently L444), it checks whether the package exists in `$config['require-dev']` rather than `$config['require']`. If so, it writes to `require-dev` instead.

**Files:**
- `src/ConfigSwitcher.php` — modify `switch_adjustConfigForDev()` (around L444)

### Step 3: Add `verify()` method to `ConfigSwitcher`

Add a public `verify(): array` method that:
1. Reads both `mainFile` and `prodFile` as arrays via `getData()`.
2. Recursively `ksort()`s both arrays, then diffs them key-by-key.
3. Returns `array('inSync' => bool, 'differences' => string[])` where `differences` lists the top-level keys that differ (e.g., `"require"`, `"repositories"`), and `inSync` is derived as `empty($differences)` — not computed independently via a separate hash, so the two values can never disagree.

The DEV-mode early return reuses the existing `$this->statusFile->isDEV()` helper (`src/Utils/StatusFile.php`) rather than re-deriving mode state; if true, return early with a result indicating verification is only meaningful in PROD mode.

**Files:**
- `src/ConfigSwitcher.php` — add `verify()` method

### Step 4: Normalize paths in the status file

In `src/Utils/StatusFile.php`, modify `saveState()` to use `realpath()` on the stored file paths before writing. Fall back to the raw path if `realpath()` returns `false` (file doesn't exist yet).

**Files:**
- `src/Utils/StatusFile.php` — modify `saveState()` (L20–L28)

### Step 5: Add a shared git-hook installer to the library

Add `resources/git-hooks/pre-commit` to the switcher library, containing HCP Editor's existing two-guard hook script (unchanged — the architectural review confirmed it has no project-specific logic). Add a public `installGitHooks(string $projectRoot): bool` method to `ConfigSwitcher` that copies this resource to `$projectRoot/.git/hooks/pre-commit` and sets it executable (mirroring the copy+chmod behavior already implemented in HCP Editor's `ComposerScripts::installGitHooks()`), returning `true` on success. If `$projectRoot/.git/hooks/` does not exist, the method must skip installation and return `false` without creating the directory or throwing — matching HCP Editor's current behavior of warning and returning early when `.git/hooks/` is missing (`hcp-editor/assets/classes/Maileditor/Composer/ComposerScripts.php` L48–L72). Since the library cannot depend on HCP Editor's `BuildMessages` class, surfacing the warning to the user is each consumer wrapper's responsibility based on the returned `bool`.

**Files:**
- `resources/git-hooks/pre-commit` — new file (moved from HCP Editor's `tools/git-hooks/pre-commit`)
- `src/ConfigSwitcher.php` — add `installGitHooks()` method
- `composer.json` (library) — ensure `resources/` is included in the distributed package (no `.gitattributes` `export-ignore` rule targeting it)

### Step 6: Add project-root factory and Composer script entry points

Add a static factory `fromProjectRoot(string $rootPath): self` to `ConfigSwitcher` that encodes the three-path convention both consumers share:
- `$rootPath . '/composer.json'` → main file
- `$rootPath . '/composer/composer-prod.json'` → prod file
- `$rootPath . '/composer/local-repositories.json'` → dev config

Add static Composer script entry points that call `self::fromProjectRoot(getcwd())` and delegate to the corresponding instance method:
- `composerSwitchDev()` → `switchToDevelopment()`
- `composerSwitchProd()` → `switchToProduction()`
- `composerSwitchUpdate()` → `switchUpdate()`
- `composerVerifyConfig()` → `verify()`, with console output of the result (in-sync confirmation or diff list; DEV-mode early-return message)
- `composerInstallHooks()` → `installGitHooks(getcwd())`, with a console warning when it returns `false` (missing `.git/hooks/`)

Composer guarantees `getcwd()` is the project root when invoking custom script methods, so no path calculation or `__DIR__` traversal is needed. The existing three-argument constructor remains available for programmatic use and the test suite.

**Files:**
- `src/ConfigSwitcher.php` — add `fromProjectRoot()` factory and five `composer*()` static entry points

### Step 7: Wire both consumers directly to the library; remove wrapper boilerplate

Replace all switcher-related Composer script entries in both consumers' `composer.json` and `composer-prod.json` to point directly at the library's new static entry points:

```json
"switch-dev": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDev",
"switch-prod": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchProd",
"switch-update": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchUpdate",
"verify-config": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerVerifyConfig",
"install-hooks": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerInstallHooks"
```

Remove the now-unnecessary wrapper methods and `createSwitcher()` factories:

**HCP Editor** (`assets/classes/Maileditor/Composer/ComposerScripts.php`):
- Remove `createSwitcher()`, `switchToDEV()`, `switchToPROD()`, `switchUpdate()`, `installGitHooks()`, and the `$switcher` static property.
- Remove `tools/git-hooks/pre-commit` (superseded by the library resource).

**Mailforge** (`mailings/assets/classes/CacheControl.php`):
- Remove `createSwitcher()`, `switchDEV()`, `switchPROD()`, `switchUpdate()`, and the `$switcher` static property.

**Both consumers** gain `verify-config` and `install-hooks` scripts (Mailforge gains both as new entries; HCP Editor's existing `install-hooks` entry is re-pointed to the library).

**Files:**
- `hcp-editor/assets/classes/Maileditor/Composer/ComposerScripts.php` — remove switcher methods
- `hcp-editor/tools/git-hooks/pre-commit` — remove (superseded by the library resource)
- `hcp-editor/composer.json` — update `switch-*`, `install-hooks` scripts; add `verify-config`
- `hcp-editor/composer/composer-prod.json` — update `switch-*`, `install-hooks` scripts; add `verify-config`
- `mailforge/mailings/assets/classes/CacheControl.php` — remove switcher methods
- `mailforge/composer.json` — update `switch-*` scripts; add `verify-config`, `install-hooks`
- `mailforge/composer/composer-prod.json` — update `switch-*` scripts; add `verify-config`, `install-hooks`

### Step 8: Clean up Mailforge `.gitignore`

Remove the stale `composer/composer-dev.lock` and `composer/composer-dev.status` lines from Mailforge's `.gitignore`.

**Files:**
- `mailforge/.gitignore` — remove 2 lines

### Step 9: Add unit tests for new library behavior

Add test cases to the switcher library's test suite covering:
- VCS repository pruning within the extended single-pass loop, including the case of multiple matching entries for one package.
- `require-dev` package placement preservation.
- The `verify()` method returning in-sync and out-of-sync results, and its DEV-mode early return via `StatusFile::isDEV()`.
- Status file path normalization — after a switch, the stored paths are `realpath()`-resolved with no `/../` segments (Step 4).
- `installGitHooks()` copying the bundled resource to a target directory and setting it executable, when `$projectRoot/.git/hooks/` already exists.
- `installGitHooks()` skipping installation and returning `false` when `$projectRoot/.git/hooks/` does not exist — the standard `tests/assets/test-project/` fixture has no `.git/` subtree at all, so this case can be exercised directly against the fixture as copied, with no setup needed; the success case above requires the test to `mkdir()` `.git/hooks/` in the ephemeral copy first, since the fixture doesn't ship one.
- `fromProjectRoot()` constructing a `ConfigSwitcher` with the correct three-path convention — verify that `getMainFile()->getPath()`, `getProdFile()->getPath()`, and `getDevFile()->getPath()` resolve to the expected paths relative to the given root.

**Files:**
- `tests/TestSuites/TestSwitching.php` — add test methods
- `tests/assets/test-project/composer.json` — may need a `require-dev` entry for the test

### Step 10: Version tag and changelog

This library has no `version` key in `composer.json` — it has never had one, and versions are established exclusively via git tags (`1.0.0`–`1.0.4`) alongside `changelog.md` entries. Add the `changelog.md` entry for `v1.1.0` documenting all changes from Steps 1–9. The version itself is established after this plan's changes are merged, by tagging the release `v1.1.0` in git (per the existing pattern) — tagging is a git write operation and is out of scope for this plan/implementation (see Out of Scope); it is called out here only so the release step is not lost.

**Files:**
- `changelog.md` — add v1.1.0 entry

## Dependencies

- Steps 1–5 are independent of each other and can be implemented in any order within the library.
- Step 6 depends on Steps 3 and 5 (the `verify()` and `installGitHooks()` methods must exist before the Composer entry points can call them).
- Step 7 depends on Step 6 (the library's Composer entry points must exist before consumers can wire to them).
- Step 8 (Mailforge `.gitignore`) is independent of the library changes.
- Step 9 depends on Steps 1–6 (tests cover all new library behavior including `fromProjectRoot()`).
- Step 10 depends on all other steps.

## Required Components

- `src/ConfigSwitcher.php` — modify (Steps 1, 2, 3, 5, 6)
- `src/Utils/StatusFile.php` — modify (Step 4)
- `resources/git-hooks/pre-commit` — new file (Step 5)
- `tests/TestSuites/TestSwitching.php` — modify (Step 9)
- `tests/assets/test-project/composer.json` — modify (Step 9)
- `changelog.md` — modify (Step 10)
- `composer.json` (library) — modify (Step 5; not Step 10 — this library has no `version` key, see Step 10)
- `mailforge/mailings/assets/classes/CacheControl.php` — modify (Step 7; remove switcher methods)
- `mailforge/composer.json` — modify (Step 7)
- `mailforge/composer/composer-prod.json` — modify (Step 7)
- `mailforge/.gitignore` — modify (Step 8)
- `hcp-editor/assets/classes/Maileditor/Composer/ComposerScripts.php` — modify (Step 7; remove switcher methods)
- `hcp-editor/tools/git-hooks/pre-commit` — remove (Step 7)
- `hcp-editor/composer.json` — modify (Step 7)
- `hcp-editor/composer/composer-prod.json` — modify (Step 7)

## Assumptions

- The switcher library is developed directly (not via vendor install) — changes are committed to the library's own repository.
- Consumer projects will update their `mistralys/composer-local-switcher` dependency constraint to `^1.1.0` after the library release.
- No consumer project currently has a `require-dev` package in `local-repositories.json` — the fix is preventive.
- The `resources/` directory is not excluded from the distributed Composer package (no `.gitattributes` `export-ignore` rule targets it), so `installGitHooks()` can rely on it being present under `vendor/mistralys/composer-local-switcher/resources/` after `composer install`.

## Constraints

- PHP 7.3 compatibility must be maintained in the switcher library.
- The `verify()` method must be read-only — it must never modify files.
- The pre-commit hook script (now bundled once in the library) must use `/usr/bin/env bash` for portability across both consumers.
- `installGitHooks()` must overwrite an existing `.git/hooks/pre-commit` unconditionally (matching HCP Editor's current behavior) — it is a one-way sync from the library's bundled copy, not a merge.
- `installGitHooks()` must not create `.git/hooks/` when it is missing — it returns `false` and leaves the filesystem untouched. The library's `composerInstallHooks()` entry point prints a console warning when this occurs; consumers using `installGitHooks()` directly are responsible for their own messaging.
- Both `composer.json` and `composer-prod.json` must be updated together in each consumer project for any new Composer script.
- The `fromProjectRoot()` factory must use the hardcoded three-path convention (`composer.json`, `composer/composer-prod.json`, `composer/local-repositories.json`). If a future consumer needs a different layout, they use the three-argument constructor directly.

## Out of Scope

- Creating and pushing the `v1.1.0` git tag — this is a git write operation performed by the user/maintainer after merge, per the project's existing tag-based versioning (see Step 10).
- The `SwitchWorkflow` automation (covered by `2026-08-18-composer-dev-switch` plan).
- Automated `composer install` / `composer update` after switching.
- Content-hash-based PROD→PROD reconciliation to replace the mtime-based approach (considered but deferred — the current mtime approach works and the `verify-config` command provides an explicit check).
- Changes to the `switch_case_PROD_PROD()` reconciliation logic beyond what's already implemented.
- Multi-project atomic switching (switching both HCP Editor and Mailforge in sync).

## Acceptance Criteria

- AC-01: When `switch_adjustConfigForDev()` encounters a matching repository entry for a switched package, the existing per-package loop replaces the first match and removes any additional matching entries within the same pass (no duplicates remain, and no second pruning pass is introduced).
- AC-02: When a package listed in `local-repositories.json` exists in `require-dev` (not `require`) in the PROD config, the DEV switch updates `require-dev` instead of `require`.
- AC-03: `ConfigSwitcher::verify()` returns `inSync: true` when `composer.json` and `composer-prod.json` have identical content, and `inSync: false` with a list of differing top-level keys when they differ, with `inSync` and `differences` derived from the same normalized comparison.
- AC-04: `verify()` returns early with a warning when `StatusFile::isDEV()` reports the current mode is DEV.
- AC-05: The status file stores canonical (resolved) paths without `/../` segments.
- AC-06: Mailforge has a `pre-commit` hook — installed via the library's shared `installGitHooks()` method — that blocks commits when `composer.json.DEV` is present or when `composer.json` contains path repositories.
- AC-07: Mailforge's `.gitignore` no longer contains the stale `composer/composer-dev.lock` and `composer/composer-dev.status` entries.
- AC-08: Both consumer projects expose `composer verify-config` that calls the library's `verify()` method and prints a human-readable result.
- AC-09: All new library behavior is covered by unit tests in the switcher library's test suite.
- AC-10: `changelog.md` has a `v1.1.0` entry documenting the changes in this plan; the release itself is marked by a git tag (`v1.1.0`) created after merge, per the project's existing tag-based versioning — not by a `version` field in `composer.json`, which this library does not have.
- AC-11: HCP Editor's and Mailforge's `install-hooks` scripts both delegate to `ConfigSwitcher::installGitHooks()`, which copies the library's bundled `resources/git-hooks/pre-commit` into `.git/hooks/pre-commit` with executable permissions and returns `true`; only one copy of the hook script exists across the codebases. When `.git/hooks/` does not exist, `installGitHooks()` returns `false` without creating it, matching HCP Editor's current skip-with-warning behavior.

## Testing Strategy

Unit tests are added to the switcher library's existing PHPUnit test suite (`tests/TestSuites/TestSwitching.php`). Tests use the existing fixture-copy pattern (copy `tests/assets/test-project/` to an ephemeral directory, run operations, assert file state). No integration tests are needed — the new behavior is purely file-manipulation logic, including `installGitHooks()`, which is a deterministic file copy and can be unit-tested the same way.

The installed pre-commit hook itself is a shell script and will be tested manually in both consumer projects by staging a `composer.json` with a path repository entry and verifying the commit is blocked.

## Test Plan

- `tests/TestSuites/TestSwitching.php::test_devSwitchPrunesStaleVCSRepository` — After DEV switch, assert that the existing matching-entry loop replaces a matching VCS repository entry with the path entry (no duplicates). Covers AC-01.
- `tests/TestSuites/TestSwitching.php::test_devSwitchPrunesMultipleMatchingRepositories` — Add a fixture with two repository entries matching the same switched package. After DEV switch, assert only one path entry remains and both original matches were removed within the same pass. Covers AC-01.
- `tests/TestSuites/TestSwitching.php::test_devSwitchRespectsRequireDevPlacement` — Add a fixture with a `require-dev` package in `local-repositories.json`. After DEV switch, assert the version is in `require-dev`, not `require`. Covers AC-02.
- `tests/TestSuites/TestSwitching.php::test_verifyReturnsInSyncWhenIdentical` — Switch to PROD, call `verify()`, assert `inSync: true`. Covers AC-03.
- `tests/TestSuites/TestSwitching.php::test_verifyReturnsOutOfSyncWhenDifferent` — Switch to PROD, manually modify `composer.json`, call `verify()`, assert `inSync: false` with correct key names. Covers AC-03.
- `tests/TestSuites/TestSwitching.php::test_verifyWarnsInDevMode` — Switch to DEV, call `verify()`, assert it returns the DEV-mode warning via `StatusFile::isDEV()`. Covers AC-04.
- `tests/TestSuites/TestSwitching.php::test_statusFileStoresCanonicalPaths` — Switch to any mode, read status file, assert no `/../` segments in paths. Covers AC-05.
- `tests/TestSuites/TestSwitching.php::test_installGitHooksCopiesResourceExecutable` — In the ephemeral project directory, `mkdir()` `.git/hooks/` first (the `tests/assets/test-project/` fixture has no `.git/` subtree), call `installGitHooks()`, assert it returns `true`, `.git/hooks/pre-commit` exists, matches the bundled resource content, and is executable. Covers AC-11.
- `tests/TestSuites/TestSwitching.php::test_installGitHooksSkipsWhenGitHooksDirMissing` — Call `installGitHooks()` against the ephemeral project directory as copied (no `.git/` subtree), assert it returns `false` and does not create `.git/` or `.git/hooks/`. Covers AC-11.

## Documentation Updates

- `docs/agents/project-manifest/api-surface.md` — Add `verify(): array` and `installGitHooks(string $projectRoot): bool` method signatures and return types, including the `false`-on-missing-`.git/hooks/` skip behavior.
- `docs/agents/project-manifest/data-flows.md` — Add a "5. Verify Configuration" flow and a "6. Install Git Hooks" flow describing the shared installer.
- `docs/agents/project-manifest/file-tree.md` — Add the new `resources/git-hooks/` directory.
- `docs/agents/project-manifest/constraints.md` — Document the `require-dev` awareness behavior and the bundled-resource pattern for the git hook.
- `changelog.md` — Add v1.1.0 entry.
- `mailforge/AGENTS.md` — Add `composer verify-config` and `composer install-hooks` to the commands table.
- `hcp-editor/AGENTS.md` — Add `composer verify-config` to the commands table; revise §9 ("Git Hooks")'s stale prose — the "Copies all hooks from `tools/git-hooks/` → `.git/hooks/`" description and the "To add a new hook: create the script in `tools/git-hooks/`, then run `composer install-hooks`" instruction — to describe the new model: the hook script now lives in the switcher library's `resources/git-hooks/`, `install-hooks` delegates to `ConfigSwitcher::installGitHooks()`, and adding or changing a hook means editing the library (and bumping the dependency version), not `tools/git-hooks/`.

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **VCS repo pruning removes a legitimate non-switched repository** | The pruning uses the same package-name substring match as the existing update logic. Only VCS entries matching switched package names are removed. Non-switched VCS repos are untouched. |
| **`realpath()` fails on paths that don't exist yet** | Falls back to the raw path. The status file is written after the switch completes, so all files should exist. |
| **Mailforge pre-commit hook breaks CI** | CI environments typically don't install Git hooks. The hook is only activated by `composer install-hooks`, which is a manual developer step. |
| **`verify()` is called during DEV mode and confuses users** | Returns early with a clear warning that verification is only meaningful in PROD mode. |
| **Migrating HCP Editor to the shared installer silently changes hook behavior** | The bundled resource is a byte-for-byte copy of HCP Editor's existing script (confirmed identical by the architectural review), so behavior is unchanged; the migration is verified by re-running the manual pre-commit test from the Testing Strategy against HCP Editor after the delegation lands. |

## Recommended Workflow
- **Workflow:** standalone
- **Rationale:** Single-concern library hardening across well-understood patterns, with straightforward consumer project updates — a single developer session suffices.
