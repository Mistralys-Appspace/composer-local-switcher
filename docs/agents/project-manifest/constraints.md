# Constraints & Conventions

## Code Style

- **Strict types:** Every PHP file declares `declare(strict_types=1)`.
- **PHP 7.3 compatibility:** No union types, typed properties, named arguments, or other PHP 7.4+ features. Properties use `@var` docblocks instead of type declarations.
- **Namespace:** `Mistralys\ComposerSwitcher` for core classes, `Mistralys\ComposerSwitcher\Utils` for utility classes.

## Error Handling

- All error codes use a `1821xx` numbering scheme — the exception class owns `182101`–`182110`, and the switcher class owns `182201`–`182202`.
- Errors are thrown as `ComposerSwitcherException` with an integer error code constant. No other exception types are used.

## File Conventions

- **Status file path** is derived from the dev config path by replacing `.json` with `.status`.
- **Lock file path** is derived from any `ConfigFile` path by replacing `.json` with `.lock`.
- **Flag file path** is derived from the main file path by appending `.DEV` or `.PROD`.
- These path derivations use simple `str_replace()` — filenames must end in `.json`.
- Paths stored in the status file are canonicalized via `realpath()` (fallback to raw path when the file does not exist yet).

## Three-Path Convention

The `fromProjectRoot()` factory and all built-in Composer entry points assume the standard file layout:

- `<root>/composer.json` — mutable working copy
- `<root>/composer/composer-prod.json` — production baseline
- `<root>/composer/local-repositories.json` — local repository definitions

Projects that follow this convention can wire the built-in entry points directly in `composer.json` with zero PHP glue code.

## Configuration Format

- The dev config file must contain a `local-repositories` key with an array of objects, each having `package-name` (string) and `path` (string). An optional `version` (string) overrides the default `*` version constraint.
- Package names with underscores are also matched with hyphens when looking up existing repository entries (handles GitHub URL normalization).
- When a `local-repositories` package exists in `require-dev` (not `require`) in the PROD config, the DEV switch writes the version constraint to `require-dev` — it does not move the package to `require`.

## Testing

- Tests use **PHPUnit >=9.6** with classmap autoloading from `tests/TestClasses/`.
- Test suite directory: `tests/TestSuites/` (suffix `.php`).
- Each test copies the fixture from `tests/assets/test-project/` into an ephemeral directory under `tests/assets/work-projects/`. The directory is cleaned up on tearDown unless `setKeepWorkFiles()` is called or the test failed.
- The `work-projects/` directory contains only transient test data and should not be committed.

## Bundled Resources

- Non-PHP assets (e.g. shell scripts) live under `resources/` in the library root and are shipped with the Composer package.
- `resources/git-hooks/pre-commit` is the canonical shared pre-commit hook. Consumer projects install it via `ConfigSwitcher::installGitHooks()`.

## Workflow Rules

- **Never edit `composer.json` directly** in a consuming project once the switcher is set up — edit `composer-prod.json` instead. The switcher overwrites `composer.json` on every switch.
- Console output is **off by default** (`ConsoleWriter` starts disabled). Call `setWriteToConsole(true)` to enable verbose logging.
- Flag files are **on by default**. Call `setFlagFileEnabled(false)` to disable.
