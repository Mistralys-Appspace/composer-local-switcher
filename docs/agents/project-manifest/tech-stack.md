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
- **Immutable production config:** The production `composer.json` is kept as a separate file and copied back when restoring production mode. The main `composer.json` is treated as a mutable working copy.
- **No framework dependency:** Pure PHP with no framework or external runtime dependencies.
- **Value objects (`Mistralys\ComposerSwitcher\State`):** Every switcher operation that previously returned `void` or a bare bool/array now returns an immutable, typed value object — `SwitchOutcome` (`switchTo()`/`switchToDevelopment()`/`switchToProduction()`/`switchUpdate()`/`reconcile()`/`previewSwitch()`), `VerificationResult` (`verify()`), `SwitchDescription` (`describe()`). Each carries a `toArray()` (and `SwitchDescription` additionally a `toJSON()`) for programmatic/agent consumption without parsing console prose. `SwitchMessage` pairs message text with a numeric code, and `FileOperation` records a single copy/write/delete performed or planned during a call.
- **Single file-system write choke-point:** Every file mutation (and the reads/checks that must observe it consistently) passes through one `Utils\FileSystem` facade, shared by `ConfigSwitcher` and every `BaseFile` it owns via `setFileSystem()`. This is what enables true dry-run previews (`previewSwitch()`) that run the exact same code path as a real switch instead of a separate, parallel preview implementation, and what backs the `FileOperation` log exposed on every `SwitchOutcome`.
