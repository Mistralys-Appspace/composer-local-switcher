# Plan

## Plan Audit Cycles
- Audits: 1 — Plan Auditor v1.9.1
- Architectural Reviews: none — Plan Architect Reviewer v2.3.1

## Prior Project Context
This plan addresses deferred items and code insights from the completed `2026-08-26-switching-gaps-and-sync-hardening` project (v1.1.0). That project delivered six features across 10 work packages. The synthesis flagged five actionable items that were out of scope or deferred — this rework promotes the most valuable ones into concrete steps.

## Summary
Clean up five residual issues in the `composer-local-switcher` library identified during the v1.1.0 delivery: fix two pre-existing PHPStan errors in `ConfigSwitcher.php`, correct a PHPDoc typo, tighten the `stripos()` VCS URL matching heuristic to prevent substring collisions, add an explicit test for non-switched VCS repository survival during DEV switch, and remove the invalid `--no-progress` flag from Composer test scripts.

## Architectural Context
The library's core is `ConfigSwitcher` (`src/ConfigSwitcher.php`, 681 lines), which orchestrates switching between production and development Composer configurations. During DEV switch, `switch_adjustConfigForDev()` iterates over `local-repositories` entries and replaces or adds path-type repository entries in the config, pruning stale VCS duplicates. Repository matching uses `stripos()` to find URLs containing the package name — a substring heuristic that can produce false positives when one package name is a prefix of another.

Tests live in `tests/TestSuites/TestSwitching.php` using a fixture at `tests/assets/test-project/` that is copied into an ephemeral work directory per test.

## Approach / Architecture
All changes are scoped to the library — no consumer project changes. The five fixes are independent and can be implemented in any order:

1. **PHPStan fixes** — convert the redundant `else if` to a plain `else` (the negated condition is always true), and add a `@param` docblock for the untyped variadic parameter.
2. **PHPDoc typo** — replace `@subackage` with `@subpackage` in both occurrences.
3. **stripos() tightening** — after a substring match, verify the character following the matched portion is a path-segment boundary (`.`, `/`, end-of-string) to prevent `application-utils` matching `application-utils-core`.
4. **Test coverage** — add a non-switched VCS repo entry to the test fixture, then assert it survives the DEV switch unchanged.
5. **Composer scripts** — remove `--no-progress` from the four test scripts in `composer.json`.

## Rationale
These are low-risk, low-effort fixes that clean up known debt while the codebase context is fresh. Grouping them into a single rework plan avoids five separate micro-plans. Each fix is independently valuable and does not depend on the others.

## Considered Alternatives

| Decision | Chosen Shape | Alternatives Considered | Trade-Off Summary |
|----------|--------------|-------------------------|-------------------|
| stripos() boundary check | Post-match character validation against `.`, `/`, end-of-string | Regex match on the full URL; exact equality on extracted path segment | Post-match character check is minimal code change, preserves the existing loop structure, and handles all known URL formats (SSH, HTTPS, custom Git) without a regex engine. Regex would be more precise but over-engineered for the current URL variety. |
| PHPStan L479 fix | Convert `else if(!condition)` to plain `else` | Remove the dead branch entirely | The `else` preserves the original intent (handle the case when the DEV lock file doesn't exist) and is the minimal fix. Removing the branch would discard the lock file deletion logic. |
| --no-progress removal | Remove the flag entirely | Conditionally detect PHPUnit version and add flag only for ≥10 | The library targets PHPUnit ≥9.6 — the flag doesn't exist there. A version-conditional Composer script is not possible without a wrapper. Simply removing the flag is the correct fix. |

## Pattern Alignment
- PHPDoc `@package` / `@subpackage` header pattern — followed by correcting the typo to match existing convention in `src/ConfigSwitcher.php`.
- `@var` / `@param` docblock annotation pattern for PHP 7.3 compatibility — followed for the variadic parameter type.
- Test fixture pattern (copy `tests/assets/test-project/` per test) — followed by extending the fixture with a non-switched VCS entry.

## Structural Improvements
| Structure | Observation | Decision | Reason |
|-----------|-------------|----------|--------|
| `src/ConfigSwitcher.php` L648–L657 | `stripos()` substring match can collide when one package name is a prefix of another | Promoted to step 3 | Directly fixes a known imprecision; the fix is a single boundary check added to the existing match logic |
| `src/ConfigSwitcher.php` L479 | Redundant negated boolean — `else if(!condition)` where condition was already false | Promoted to step 1a | PHPStan error; trivial fix |
| `src/ConfigSwitcher.php` L545 | Untyped variadic parameter | Promoted to step 1b | PHPStan error; one docblock addition |

## Detailed Steps

### Step 1: Fix PHPStan errors in `ConfigSwitcher.php`

**1a.** At line 479 in `src/ConfigSwitcher.php`, change `else if(!$this->devFile->getLockFile()->exists())` to `else`. The `if` block above already checked `$this->devFile->getLockFile()->exists()` — if control reaches the `else`, the negated condition is always true.

**1b.** At line 545 in `src/ConfigSwitcher.php`, add a `@param` docblock above `addMessage()`:
```php
/**
 * @param string|int|float ...$args
 */
```
The variadic `$args` is forwarded to `sprintf()`, which accepts `string|int|float` values.

### Step 2: Fix PHPDoc `@subackage` typo

In `src/ConfigSwitcher.php`, replace `@subackage` with `@subpackage` at lines 4 and 19.

### Step 3: Tighten `stripos()` VCS URL matching

In `src/ConfigSwitcher.php`, in the `switch_adjustConfigForDev()` method (around L648–L657), after a `stripos()` match succeeds, add a boundary check: verify that the character immediately following the matched substring in the URL is one of `.`, `/`, or end-of-string. If the boundary check fails, treat it as a non-match.

Extract the boundary validation into a private helper method to keep the loop body readable:

```php
/**
 * @param string $url
 * @param string $name
 * @return bool
 */
private function urlMatchesPackageName(string $url, string $name) : bool
{
    $pos = stripos($url, $name);

    if($pos === false) {
        return false;
    }

    $afterPos = $pos + strlen($name);

    // Match is valid only if the package name ends at a segment boundary.
    return $afterPos >= strlen($url)
        || $url[$afterPos] === '.'
        || $url[$afterPos] === '/';
}
```

Then replace the two `stripos()` calls in the loop condition with:
```php
if(
    !$this->urlMatchesPackageName($repository['url'], $packageName)
    &&
    !$this->urlMatchesPackageName($repository['url'], str_replace('_', '-', $packageName))
) {
    continue;
}
```

**3c. Reconcile with the existing duplicate-pruning test.** The boundary rule (`.`, `/`, end-of-string) rejects the hyphen that follows the match in the existing fixture URL `git@github.com:Mistralys/application-framework-mirror.git` used by `test_devSwitchPrunesMultipleMatchingRepositories()` — the character after the matched package name is `-`, which is not a valid boundary, so the entry would no longer be pruned and the test's `assertSame(0, $vcsCount, ...)` would fail.

Widening the boundary set to accept `-` is not a safe fix: it would reopen the original collision this step exists to close, since `application-utils-core` also separates the shorter `application-utils` match from `-core` with a hyphen.

Instead, update the literal URL added by `test_devSwitchPrunesMultipleMatchingRepositories()` (in `tests/TestSuites/TestSwitching.php`) from:
```php
'url' => 'git@github.com:Mistralys/application-framework-mirror.git'
```
to an exact duplicate of the package's real VCS URL:
```php
'url' => 'git@github.com:Mistralys/application-framework.git'
```
This models a plausible real-world case (the same VCS repository accidentally declared twice) instead of a differently-named mirror, and the match now ends at the `.` in `.git` — a valid boundary — so the boundary check still recognizes it as a duplicate to prune. No other change to the test method is required; its assertions (`$pathCount === 1`, `$vcsCount === 0`) remain valid against the new URL.

### Step 4: Add test for non-switched VCS repo survival

**4a.** Add a third VCS repository entry to the test fixture `tests/assets/test-project/composer.json` for a package that is NOT listed in `tests/assets/test-project/composer/dev-config.json`:

```json
{
    "type": "vcs",
    "url": "git@github.com:Mistralys/some-unrelated-library.git"
}
```

Also add a corresponding `require` entry so the fixture remains consistent:
```json
"mistralys/some-unrelated-library": ">=1.0"
```

**4b.** Add a new test method `test_devSwitchPreservesNonSwitchedVCSRepository()` in `tests/TestSuites/TestSwitching.php` that:
1. Creates a switcher and switches to DEV.
2. Reads the resulting `composer.json` repositories.
3. Asserts that a VCS entry with URL containing `some-unrelated-library` still exists.
4. Asserts the entry's type is still `vcs`.

### Step 5: Remove `--no-progress` from Composer test scripts

In `composer.json`, remove `--no-progress` from the four scripts that use it:
- `test-file`: `php vendor/bin/phpunit --no-progress` → `php vendor/bin/phpunit`
- `test-suite`: `php vendor/bin/phpunit --no-progress --testsuite` → `php vendor/bin/phpunit --testsuite`
- `test-filter`: `php vendor/bin/phpunit --no-progress --filter` → `php vendor/bin/phpunit --filter`
- `test-group`: `php vendor/bin/phpunit --no-progress --group` → `php vendor/bin/phpunit --group`

## Dependencies
- Step 3's boundary-check fix and the existing `test_devSwitchPrunesMultipleMatchingRepositories()` test are coupled (see step 3c) — the fixture URL inside that test must be updated in the same change as the boundary check, or the test regresses. Steps 1, 2, 4, and 5 remain independent of each other and of external systems.

## Required Components
- `src/ConfigSwitcher.php` — modified (steps 1, 2, 3)
- `tests/TestSuites/TestSwitching.php` — modified (step 4b)
- `tests/assets/test-project/composer.json` — modified (step 4a)
- `composer.json` — modified (step 5)

## Assumptions
- PHPUnit 9.6 remains the minimum supported version. If the library upgrades to PHPUnit 10+, `--no-progress` could be re-added.
- The `stripos()` boundary fix covers all URL formats currently in use (SSH git@, HTTPS, custom Git hosting). URLs ending in `.git` are the standard case; the boundary check handles `.` as a valid boundary character.

## Constraints
- PHP 7.3 compatibility must be preserved — no typed properties, union types, or named arguments.
- The `@param` annotation for `...$args` must use PHPDoc syntax, not PHP 8 attribute syntax.

## Out of Scope
- Consumer project changes (HCP Editor, Mail Forge) — no code changes needed in consumer projects for this rework.
- Running `composer build` in HCP Editor to refresh `.context/` — a manual one-time action by the maintainer.

## Acceptance Criteria

- AC-01: PHPStan reports zero errors on `src/ConfigSwitcher.php` (the two pre-existing warnings are resolved).
- AC-02: `@subpackage` is correctly spelled in both PHPDoc headers.
- AC-03: DEV switch does not match `application-utils` against a VCS URL for `application-utils-core` — the boundary check prevents substring collisions.
- AC-04: A dedicated test verifies that non-switched VCS repository entries survive the DEV switch unchanged.
- AC-05: All four Composer test scripts (`test-file`, `test-suite`, `test-filter`, `test-group`) execute without "Unknown option" errors on PHPUnit 9.6.
- AC-06: All existing tests continue to pass.

## Testing Strategy
Run the full test suite after all changes to confirm no regressions. Verify PHPStan reports zero errors on `ConfigSwitcher.php`. Manually verify each Composer test script executes without option errors.

## Test Plan

- `tests/TestSuites/TestSwitching.php::test_devSwitchPreservesNonSwitchedVCSRepository` (new) — asserts that a VCS entry for a non-switched package survives DEV switch with type `vcs` intact — AC-04
- `tests/TestSuites/TestSwitching.php::test_devSwitchPrunesStaleVCSRepository` (existing) — re-run to confirm the boundary check does not break existing pruning — AC-03, AC-06
- `tests/TestSuites/TestSwitching.php::test_devSwitchPrunesMultipleMatchingRepositories` (existing, fixture URL updated per step 3c) — re-run to confirm the boundary check still prunes an exact-duplicate VCS entry — AC-03, AC-06
- `composer analyze` — must report zero errors for `src/ConfigSwitcher.php` — AC-01
- `composer test-file -- tests/TestSuites/TestSwitching.php` — must execute without "Unknown option" error — AC-05

## Documentation Updates

- `docs/agents/project-manifest/api-surface.md` — no changes needed; no public API surface is altered.
- `docs/agents/project-manifest/constraints.md` — no changes needed; no conventions are changed.
- `docs/agents/project-manifest/data-flows.md` — update the "Switch to Development Mode" flow's "Scans all existing repository entries for URL matches (stripos)" line to describe the new boundary-checked matching (via `urlMatchesPackageName()`), per the project's own manifest rule mapping switching-behavior changes to this document.
- `changelog.md` — add entry documenting the five fixes (PHPStan cleanup, typo fix, stripos tightening, test coverage, Composer script fix).

## Deferred Items

| # | Deferred Item | Origin | Reason Deferred | Notes |
|---|---------------|--------|-----------------|-------|
| 1 | Refactor `CacheControl::build()` to invoke `@switch-prod` via Composer script instead of direct PHP call to `ConfigSwitcher::composerSwitchProd()` | WP-009 developer code-smell | The direct static call is valid — both caller and callee are Composer script callbacks in the same PHP process; adding subprocess indirection via `@switch-prod` would add overhead (shell spawn, Composer bootstrap) with no real decoupling benefit since the library is already a direct dependency | Reconsider if `CacheControl::build()` is ever invoked outside of a Composer script context |
| 2 | Run `composer build` in HCP Editor to refresh `.context/` generated docs | WP-008 QA edge-case | Manual one-time action, not a code change — belongs in maintainer workflow, not a plan step | Should be done after the next HCP Editor change that triggers a build |

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **Boundary check breaks an edge-case URL format** | The boundary characters (`.`, `/`, end-of-string) cover SSH (`repo.git`), HTTPS (`/repo`), and bare names. The existing test suite covers the three packages in the fixture. The new helper is unit-testable if further URL formats emerge. |
| **Boundary check regresses the existing duplicate-pruning test** | Confirmed by audit: the `-mirror` suffix in `test_devSwitchPrunesMultipleMatchingRepositories()`'s fixture URL fails the boundary check. Mitigated by step 3c — the fixture URL is changed to an exact duplicate, which satisfies the boundary rule while still exercising duplicate-VCS pruning. |
| **Removing `--no-progress` changes test output format** | PHPUnit 9.6 always shows progress by default. The flag was being rejected anyway, so removing it changes nothing in practice — tests were already running with progress output. |

## Recommended Workflow
- **Workflow:** standalone
- **Rationale:** All five fixes are small, independent, scoped to a single module (`ConfigSwitcher`), and follow well-understood patterns — a single developer session with self-review is adequate.
