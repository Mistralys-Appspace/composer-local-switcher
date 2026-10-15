# Synthesis: Switch Output and Version Guard

Plan: `2026-10-08-switch-output-and-version-guard` | Release: v3.1.0 (minor) | Status: all active WPs COMPLETE (WP-009 and WP-011 were CANCELLED duplicates of WP-008 and WP-010).

### Outcome Summary

The switcher now validates every `version` override in `local-repositories.json` against the package's root `require`/`require-dev` constraint before any write, blocking the switch with message 182230 instead of letting `composer update` fail mid-switch. Console output was rebuilt around message levels (Detail/Info/Warning), a shape-aware change formatter, a stateless framed renderer, verbosity-gated details (`-v`), single (non-duplicated) message output, recovery guidance (182231) after a failed Composer command, and `bool`-returning entry points that end explained failures without a PHP stack trace. Two classifier defects were fixed and docs and manifest were brought in line for v3.1.0. One known gap remains: real-switch output still uses the preview header and "Would run:", and `renderBlocked()` is unused.

### Metrics

- Work packages: 9 active WPs COMPLETE (WP-001 to WP-008, WP-010); 2 CANCELLED duplicates (WP-009, WP-011). All stages PASS (9 of 9 WPs with all stages passing, 0 missing).
- Rework: none recorded; every pipeline passed first time.
- Final exit gate (WP-008 QA): `composer test` 312/312, `composer test-integration` 80/80, `composer analyze` (PHPStan, 86 files) 0 errors. No baseline comparison needed as nothing failed.
- Code review: 0 Blocking findings across all WPs; 0 Fix-Forward edits; 4 Documentation-Forward items (WP-001, WP-006, WP-007, WP-008), all closed by the Documentation stage.
- Test growth across the run: 261 to 312 unit tests, 77 to 80 integration tests.
- Failed metrics / blockers / security concerns: none. No security-audit stage was active.

### Strategic Recommendations

- Optional duck-typed use of Composer's own semver engine (`Unverifiable` when absent) gave a robust guard without a runtime dependency. `dev-*` normalisation failure maps to `Unverifiable`, not `Invalid`, defined in a single place (`isDevAlias()`), and was verified against the real engine.
- The guard is pure: `DevConfigTransformer::apply()` only reports conflicts, and `ConfigSwitcher` callers decide to block, before the first write. Keep that split.
- Levels as data (`MessageLevel`), not text prefixes, let the renderer filter and style safely. `[code]` prefixes are verbosity-gated, and tests assert via `hasWrittenMessageCode()` rather than output substrings.
- Scoped save/disable/restore (in `finally`) of the switcher's legacy echo channel avoids mutating an injected collaborator. Recovery guidance rather than auto-rollback preserves the confirm-every-change principle.
- Entry-point bodies were kept as one-line delegations to a guard helper so the existing no-control-flow source-scan test still holds.

### Code Insights

**Developer**
- WP-002 (refactor, medium): `SwitchOutcome::toArray()` PHPDoc duplicates `SwitchMessage::toArray()`'s shape; hoist into a shared `@phpstan-type` alias.
- WP-003 (debt, medium): `TestConfirmation.php`/`TestEntryPointEdge.php` broke from verbosity gating and were repointed to `hasWrittenMessageCode()`; grep for other `[<code>]` substring assertions.
- WP-003 (low): `escape()` escapes `<` whenever an IO is present; `toDisplayString()` does not escape internal single quotes (display-only).
- WP-004 (decision, medium): `apply()` reads the root constraint before overwriting it in the same loop iteration; `describe_versionConflictWarnings()` reuses full `apply()` and discards the result.
- WP-005 (debt, medium): `ScriptedEventContext` does not override `writeStyled()`, which falls back to a bare echo and bypasses capture. Add an override. Path-repository alias source is rendered as the literal key `options.versions`, not the derivation source.
- WP-005 (low): project-root derivation `dirname($switcher->getMainFile()->getPath())` is duplicated in `buildRunner()` and `composerSwitchDescribe()`; a `getProjectRoot()` helper would remove it.
- WP-006 (medium): the legacy `MESSAGE_COMPOSER_COMMAND` line was kept next to `renderCommandStart()` because `TestConfirmation::test_yesStillPrintsChanges` asserts on it; now documented in the docblock.
- WP-007 (low): explained failures use the `<warning>` tag, not `<error>`, because the echo fallback only strips info/comment/warning tags.
- WP-008 (medium): a package moved between sections may be reported as an override in both sections on revert.

**QA**
- WP-005 (coverage-gap, low): no test for a path repository with missing or empty `options.versions`, or a verbose Detail message with code 0.
- WP-006 (low): `runCommand()` returns early with no frame when no Composer command is needed; untested.
- WP-007 (coverage-gap, low): only `composerSwitchDev()` is directly tested for the false-return path; Prod/Update share the helper.
- WP-008 (edge-case, low): the moved-package double-override case has no test asserting the override list.
- WP-002 (low): the plan's AC-4 referenced a non-existent path and mechanism for 182224; the intent held.

**Reviewer**
- No blocking issues. Documentation-forward items were raised for WP-001, WP-006 (explain the retained legacy line), WP-007 and WP-008 and were resolved.

**Documentation**
- WP-005 (low): `SwitchCommandRunner::hasPermanentOrDiscardedChanges()` docblock still references the removed `OutcomeRenderer::formatChange()`.
- WP-007 (low): README does not mention the non-zero exit with a styled error line on explained failures.
- WP-008 (low): `data-flows.md` does not mention the moved-package double-override behavior.
- WP-010 (medium): output actually shipped differs from the plan's target (see Deferred items).

### Deferred & Follow-Up Items

| Source | Agent | Item | Type / Priority |
|---|---|---|---|
| WP-010 | Documentation | Real-switch output renders the first preview outcome (preview header, "Would run:"); `OutcomeRenderer::renderBlocked()` is unused in `src/`. Follow-up: render the plan with `isPreview=false`, use `renderBlocked()`, then update README/usage samples. Plan target ("switch PROD -> DEV", "Next:") not met. | Deferred, medium |
| WP-005 | Developer | Add a `writeStyled()` override to `ScriptedEventContext`. | Deferred, medium |
| WP-005/WP-006 | Developer/Documentation | Add `ConfigSwitcher::getProjectRoot()` helper; fix stale `formatChange()` reference in the `SwitchCommandRunner` docblock. | Deferred, low |
| WP-002 | Developer | Hoist the shared `@phpstan-type` alias for `SwitchMessage::toArray()` shape. | Deferred, medium |
| WP-006 | Developer | The legacy `MESSAGE_COMPOSER_COMMAND` line duplicates `renderCommandStart()` info; remove it and re-point `test_yesStillPrintsChanges`. | Deferred, low |
| WP-007 | Documentation | Add a README note about non-zero exit and styled error line on explained failures. | Deferred, low |
| WP-008 | Documentation | Note the moved-package double-override in `data-flows.md`; add a test for it. | Deferred, low |
| WP-005/006/007 | QA | Coverage gaps: missing `options.versions`, Detail code 0, no-command runner path, Prod/Update entry points false-return. | Deferred, low |
| WP-005 | Developer | Path-repository alias source is shown as `options.versions` rather than the derivation source (plumbing out of scope). | Out-of-scope, low |
| Plan | Planner | Hosting the verification gate on WP-008 instead of the documentation-only WP; plan draft numbering differs from ledger IDs (WP-009/WP-011 cancelled duplicates). | Process note |

### Next Steps

- Plan a small follow-up to fix the real-switch header and `renderBlocked()` wiring so shipped output matches the intended design, then refresh README/usage samples.
- Bundle the low-priority cleanups above (test double `writeStyled()`, `getProjectRoot()`, PHPDoc type alias, stale docblock, extra tests) into one housekeeping WP.
- The user decides on tagging and publishing v3.1.0 (the Release Engineer left this to the user).
- Knowledge Archiver: see the project comment below for its status.
