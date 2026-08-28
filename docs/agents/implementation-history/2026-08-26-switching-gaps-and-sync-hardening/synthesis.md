# Synthesis Report — Switching Gaps and Sync Hardening

**Plan:** `2026-08-26-switching-gaps-and-sync-hardening`
**Version:** v1.1.0 (minor bump from v1.0.4)
**Status:** COMPLETE — all 10 work packages delivered

---

## Executive Summary

Hardened the `mistralys/composer-local-switcher` library (v1.1.0) and its two consumer projects (HCP Editor, Mail Forge) against configuration drift between `composer.json` and `composer-prod.json`. Six categories of gaps were addressed: stale VCS repository pruning during DEV switch, a new read-only `verify()` method for config sync checking, status file path normalization, `require-dev` placement awareness, a shared git-hook installer bundled in the library, and a `fromProjectRoot()` factory with five Composer script entry points that eliminate all switcher boilerplate from consumer projects.

Both consumer projects were rewired to delegate directly to the library's static entry points, removing all PHP wrapper code for switching operations. The library gained two new user-facing commands (`verify-config`, `install-hooks`) available to all consumers via Composer scripts.

---

## Metrics

| Metric | Value |
|---|---|
| Work packages delivered | 10 / 10 |
| Pipeline stages passed | 36 / 36 |
| Library tests | 15 passed, 0 failed (81 assertions) |
| QA bounces | 1 (WP-009: missed call site in `CacheControl::build()` — fixed in rework) |
| Code-review fix-forwards | 2 (WP-007: DRY `getcwd()` variable; WP-008: stale docblock) |
| PHPStan new errors | 0 |
| PHPStan pre-existing errors | 2 (L479 redundant negated boolean, L545 untyped variadic `$args`) |
| Breaking changes | None — existing three-argument constructor preserved |

---

## Delivered Features

### Library (composer-local-switcher)

1. **VCS Repository Pruning** (WP-001) — DEV switch now removes duplicate VCS entries matching switched packages in a single loop pass (UPDATE first / PRUNE duplicates / ADD new).
2. **`verify()` Method** (WP-002) — Read-only comparison of `composer.json` vs `composer-prod.json` returning `inSync`, `differences`, and optional `devMode` flag.
3. **Status File Path Normalization** (WP-003) — `saveState()` canonicalizes stored paths via `realpath()` with fallback.
4. **`require-dev` Placement Awareness** (WP-006) — DEV switch writes to the correct section (`require` or `require-dev`) based on the PROD baseline.
5. **Shared Git-Hook Installer** (WP-005) — Bundled `resources/git-hooks/pre-commit` with `installGitHooks()` method.
6. **`fromProjectRoot()` Factory** (WP-007) — Encodes the three-path convention; five `composer*()` static entry points for zero-boilerplate consumer integration.

### Consumer Projects

7. **HCP Editor Wiring** (WP-008) — Switched to library entry points; removed `ComposerScripts` wrapper methods and project-local `pre-commit` hook.
8. **Mail Forge Wiring** (WP-009) — Switched to library entry points; removed `CacheControl` wrapper methods; added `verify-config` and `install-hooks` commands.
9. **Mail Forge .gitignore Cleanup** (WP-004) — Removed stale `composer-dev.lock` and `composer-dev.status` entries.

### Release

10. **Changelog** (WP-010) — v1.1.0 entry documenting all features. Git tag to be created by maintainer.

---

## Code Insights

### Developer Observations

- **WP-001 (convention):** The `composer test-file` script uses `--no-progress` which PHPUnit 9.6 does not support. Tests were run via `php vendor/bin/phpunit` directly.
- **WP-001 (debt):** 2 pre-existing PHPStan errors in `ConfigSwitcher.php` (L479 negated boolean always true, L545 untyped variadic parameter) — outside plan scope.
- **WP-007 (improvement):** Pre-existing `@subackage` typo in PHPDoc (should be `@subpackage`) at lines 4 and 19 — outside WP scope.
- **WP-009 (code-smell):** `CacheControl::build()` calls `ConfigSwitcher::composerSwitchProd()` directly rather than through Composer script indirection.

### QA Observations

- **WP-001 (coverage-gap):** AC3 (non-switched repos preserved) lacks a dedicated test — coverage is implicit since the fixture only contains VCS entries for switched packages.
- **WP-001 (edge-case):** The `stripos()` heuristic has a pre-existing substring collision: `mistralys/application-utils` matches the URL for `application-utils-core`. No functional bug due to in-place replacement, but imprecise.
- **WP-008 (edge-case):** `.context/modules/composer/architecture-core.md` is stale after method removal — will self-correct on next `composer build`.

### Reviewer Fix-Forwards Applied

- **WP-007:** Stored `getcwd()` in a local `$root` variable in `composerInstallHooks()` to avoid calling it twice (DRY improvement).
- **WP-008:** Updated class docblock in `ComposerScripts.php` — removed stale "primarily used to switch" language since all switching methods were removed.

---

## Deferred & Follow-Up Items

| Source | Agent | Description | Type | Priority |
|---|---|---|---|---|
| WP-001 | QA | Add dedicated test for non-switched VCS repos surviving DEV switch (AC3 explicit coverage) | Deferred | Low |
| WP-001 | QA | Tighten `stripos()` match to require path-segment boundaries (prevent substring collisions like `application-utils` matching `application-utils-core`) | Deferred | Low |
| WP-009 | Developer | Refactor `CacheControl::build()` to invoke `@switch-prod` via Composer's API instead of direct PHP call to `ConfigSwitcher::composerSwitchProd()` | Deferred | Low |
| WP-007 | Reviewer | Fix pre-existing `@subackage` typo in `ConfigSwitcher.php` PHPDoc (lines 4 and 19) | Out-of-scope | Low |
| WP-010 | Release | Fix 2 pre-existing PHPStan errors in `ConfigSwitcher.php` (L479 redundant negated boolean, L545 untyped variadic `$args`) | Out-of-scope | Low |
| WP-008 | QA | Run `composer build` in HCP Editor to refresh `.context/` generated docs after method removals | Out-of-scope | Low |

---

## Next Steps

1. **Tag v1.1.0** — All code is ready. The maintainer creates the git tag on the library repository.
2. **Update consumer `composer.json`** — Both HCP Editor and Mail Forge should require `^1.1` once the tag is pushed to Packagist.
3. **Run `composer build`** in HCP Editor to refresh `.context/` auto-generated docs (stale references to removed `ComposerScripts` methods).
4. **Address pre-existing PHPStan errors** — Two warnings in `ConfigSwitcher.php` predate this plan and should be cleaned up in a follow-up.
5. **Consider stripos() heuristic tightening** — Low priority but would improve precision of VCS repository matching.
