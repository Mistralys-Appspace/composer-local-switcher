# Synthesis Report — Switching Gaps and Sync Hardening (Rework 1)

**Date:** 2026-08-27
**Project:** composer-local-switcher v1.1.1 cleanup
**Status:** COMPLETE — all 5 work packages delivered, all 20 pipeline stages passed.

---

## Executive Summary

Resolved five residual issues in the `composer-local-switcher` library identified during the v1.1.0 delivery. The changes span static-analysis fixes, a PHPDoc typo correction, a VCS URL matching hardening to prevent substring collisions, a new regression test for non-switched repository survival, and removal of an invalid PHPUnit flag from Composer test scripts. All changes are backward-compatible with the PHP 7.3+ / PHPUnit 9.6 target.

---

## Work Package Results

| WP | Title | Status | Files Modified |
|---|---|---|---|
| WP-001 | Fix PHPStan errors in ConfigSwitcher | PASS | `src/ConfigSwitcher.php` |
| WP-002 | Fix PHPDoc @subackage typo | PASS | `src/ConfigSwitcher.php` |
| WP-003 | Tighten stripos() VCS URL matching with boundary check | PASS | `src/ConfigSwitcher.php`, `tests/TestSuites/TestSwitching.php`, `docs/agents/project-manifest/data-flows.md`, `changelog.md` |
| WP-004 | Add test for non-switched VCS repository survival | PASS | `tests/assets/test-project/composer.json`, `tests/TestSuites/TestSwitching.php` |
| WP-005 | Remove --no-progress from Composer test scripts | PASS | `composer.json`, `changelog.md` |

---

## Metrics

| Metric | Value |
|---|---|
| Tests passed | 16 |
| Tests failed | 0 |
| Assertions | 84 |
| PHPStan errors | 0 |
| Acceptance criteria met | 21/21 (100%) |
| Pipeline stages | 20/20 PASS |
| Rework cycles | 0 |

---

## Strategic Recommendations

1. **Dedicated unit tests for `urlMatchesPackageName()`** — The new boundary-check helper is tested indirectly via integration tests (`test_devSwitchPrunesMultipleMatchingRepositories`, `test_devSwitchPrunesStaleVCSRepository`). A dedicated unit test exercising all boundary characters (`.`, `/`, end-of-string, and rejection of `-`, `_`, alphanumeric) would provide tighter coverage and serve as a regression safety net if the boundary set is expanded.

2. **Consider `?` as a valid boundary character** — `urlMatchesPackageName()` currently accepts `.`, `/`, and end-of-string as valid post-match boundaries. VCS URLs with query strings (e.g., `?ref=main`) would produce false negatives. This is unlikely in practice but worth adding if the library's URL format coverage expands.

---

## Code Insights

### Developer (WP-003)
- `tests/TestSuites/TestSwitching.php`: `test_devSwitchPrunesMultipleMatchingRepositories` uses inline `stripos()` matching logic to detect `application-framework` entries, which mirrors the production code's matching logic. Consider asserting on the final repo structure more directly. *(low priority)*

### QA (WP-003)
- `src/ConfigSwitcher.php`: `urlMatchesPackageName()` does not accept `?` as a boundary char — VCS URLs with query strings would be false negatives. Unlikely but noted. *(low priority)*

### Reviewer (WP-003)
- `tests/TestSuites/TestSwitching.php`: A dedicated unit test for `urlMatchesPackageName()` would provide tighter boundary-char coverage than the current integration-level assertions. *(low priority)*

### Reviewer (WP-005)
- `changelog.md`: Documentation-forward acted upon — the v1.1.1 changelog now includes a bullet for the `--no-progress` removal.

---

## Deferred & Follow-Up Items

| Source | Agent | Description | Type | Priority |
|---|---|---|---|---|
| WP-003 | Reviewer | Add dedicated unit test for `urlMatchesPackageName()` boundary characters | Deferred | Low |
| WP-003 | QA | Consider adding `?` as a valid boundary character in `urlMatchesPackageName()` | Deferred | Low |

---

## Next Steps

- **Commit and tag v1.1.1** — all five fixes are complete, tests pass, PHPStan is clean, and the changelog is updated.
- **Planner:** If a future cycle revisits the switcher, consider the two deferred items above (dedicated boundary-char unit test, `?` boundary support).
