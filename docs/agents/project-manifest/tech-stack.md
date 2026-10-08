# Tech Stack & Patterns

## Runtime

- **Language:** PHP >=8.4
- **No platform pin:** `composer.json` no longer sets `config.platform.php`; dependency resolution targets the host PHP version directly.

## Dependencies

### Production

None. The library has zero runtime dependencies — only `php >=8.4`.

### Development

| Package | Constraint | Purpose |
|---|---|---|
| `phpunit/phpunit` | >=13.0 | Unit testing. Installing the dev toolchain needs PHP `>=8.4.1` (PHPUnit 13's own floor); the runtime `php` constraint stays `>=8.4`. |
| `phpstan/phpstan` | >=1.10 | Static analysis |
| `phpstan/phpstan-phpunit` | >=1.3 | PHPStan rules for PHPUnit |
| `roave/security-advisories` | dev-latest | Blocks packages with known vulnerabilities |
| `symfony/process` | ^7.0 \|\| ^8.0 | Runs the real `composer` and `git` binaries from Tier 2 integration tests (`ComposerRunner`, `GitRunner`) via array-form `Process` construction; `ExecutableFinder` resolves binaries on `PATH` |

## Package Manager

- **Composer** with `minimum-stability: dev` and `prefer-stable: true`.

## Autoloading

- **Production:** Classmap from `src/`.
- **Dev:** Classmap from `tests/TestClasses/`.

## Build & QA Tools

| Tool | Config File | Command |
|---|---|---|
| PHPUnit | `phpunit.xml` (bootstrap: `tests/bootstrap.php`) | `vendor/bin/phpunit` |
| PHPStan | `phpstan.neon` (covers `src/` and `tests/TestClasses/`, `tests/TestSuites/`, `tests/IntegrationSuites/`) | `vendor/bin/phpstan` |

## Architectural Patterns

- **Single-purpose library:** One main orchestrator class (`ConfigSwitcher`) with utility classes in `Utils/`.
- **File-based state management:** The switching state (current mode, file paths, timestamp) is persisted to a JSON status file on disk. Flag files provide a visible indicator of the active mode.
- **Single source of truth with a transient snapshot:** `composer.json` is the only committed, user-editable configuration. `composer/composer-prod.json`/`.lock` are created on a PROD/INITIAL→DEV switch purely as a runtime snapshot of that moment, and deleted again once a DEV→PROD switch has applied their content back — there is no second, hand-maintained baseline to keep in sync or reconcile.
- **Three-way revert:** DEV-time edits are carried back into production by `Utils\DevConfigTransformer::revert()`'s three-way diff (base = the snapshot re-applied, current = the live DEV `composer.json`, target = the snapshot), not by restoring a saved file. A managed (local package) entry always reverts to the snapshot value; every other key/package carries the current (DEV-edited) value back.
- **Plan/execute separation with the first enums:** `State\LockStatus`, `State\InstalledState` and `State\ConfigChangeOrigin` are the library's first `enum` types, used by the pure planning layer (`ConfigSwitcher::switchTo()`/`previewSwitch()`) to classify lock freshness, installed-vs-planned state and the origin of a config change without string comparisons.
- **No framework dependency:** Pure PHP with no framework or external runtime dependencies.
- **Value objects (`Mistralys\ComposerSwitcher\State`):** Every switcher operation that previously returned `void` or a bare bool/array now returns an immutable, typed value object — `SwitchOutcome` (`switchTo()`/`switchToDevelopment()`/`switchToProduction()`/`switchUpdate()`/`previewSwitch()`), `SwitchDescription` (`describe()`). Each carries a `toArray()` (and `SwitchDescription` additionally a `toJSON()`) for programmatic/agent consumption without parsing console prose. `SwitchMessage` pairs message text with a numeric code, and `FileOperation` records a single copy/write/delete performed or planned during a call.
- **Single file-system write choke-point:** Every file mutation (and the reads/checks that must observe it consistently) passes through one `Utils\FileSystem` facade, shared by `ConfigSwitcher` and every `BaseFile` it owns via `setFileSystem()`. This is what enables true dry-run previews (`previewSwitch()`) that run the exact same code path as a real switch instead of a separate, parallel preview implementation, and what backs the `FileOperation` log exposed on every `SwitchOutcome`.
- **Plan in the core, execute at the edge:** `ConfigSwitcher::switchTo()`/`previewSwitch()` only ever plan — they return a `SwitchOutcome` carrying at most one `State\ComposerCommand`, never executing it or prompting anyone. `Utils\ComposerProcess` is the only place in the library that spawns a real `composer` child process, via `proc_open()`'s array-argument form (no shell string interpolation) with inherited STDIN/STDOUT/STDERR — zero runtime dependencies, so it resolves the binary itself (`COMPOSER_BINARY`, else `PATH`) rather than relying on `composer/composer` or `symfony/process` classes. `Utils\SwitchCommandRunner` is the only place that prompts, built from an `Utils\EventContext` (the library's sole duck-typing site for Composer's event/IO objects) by every `composerSwitch*`/`composerSwitchPreview*` static entry point.
