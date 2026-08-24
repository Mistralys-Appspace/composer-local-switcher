# Tech Stack & Patterns

## Runtime

- **Language:** PHP >=7.3
- **Platform pin:** `config.platform.php` is set to `7.3` in `composer.json`, ensuring dependency resolution targets that version regardless of the host PHP.

## Dependencies

### Production

None. The library has zero runtime dependencies — only `php >=7.3`.

### Development

| Package | Constraint | Purpose |
|---|---|---|
| `phpunit/phpunit` | >=9.6 | Unit testing |
| `phpstan/phpstan` | >=1.10 | Static analysis |
| `phpstan/phpstan-phpunit` | >=1.3 | PHPStan rules for PHPUnit |
| `roave/security-advisories` | dev-latest | Blocks packages with known vulnerabilities |

## Package Manager

- **Composer** with `minimum-stability: dev` and `prefer-stable: true`.

## Autoloading

- **Production:** Classmap from `src/`.
- **Dev:** Classmap from `tests/TestClasses/`.

## Build & QA Tools

| Tool | Config File | Command |
|---|---|---|
| PHPUnit | `phpunit.xml` | `vendor/bin/phpunit` |
| PHPStan | *(no config file in repo)* | `vendor/bin/phpstan` |

## Architectural Patterns

- **Single-purpose library:** One main orchestrator class (`ConfigSwitcher`) with utility classes in `Utils/`.
- **File-based state management:** The switching state (current mode, file paths, timestamp) is persisted to a JSON status file on disk. Flag files provide a visible indicator of the active mode.
- **Immutable production config:** The production `composer.json` is kept as a separate file and copied back when restoring production mode. The main `composer.json` is treated as a mutable working copy.
- **No framework dependency:** Pure PHP with no framework or external runtime dependencies.
