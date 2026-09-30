# Composer Local Switcher

[![Packagist](https://img.shields.io/packagist/v/mistralys/composer-local-switcher)](https://packagist.org/packages/mistralys/composer-local-switcher)
[![PHP](https://img.shields.io/packagist/php-v/mistralys/composer-local-switcher)](https://packagist.org/packages/mistralys/composer-local-switcher)
[![License](https://img.shields.io/github/license/Mistralys/composer-local-switcher)](LICENSE)

PHP library that handles switching between live and local Composer dependencies using 
Composer scripts.

## Installation

```bash
composer require mistralys/composer-local-switcher
```

## How it works

### Two configurations

The main `composer.json` file is switched between two configurations: 

- The production configuration
- The local development configuration

> NOTE: The lock file follows the switching so you can run composer
> commands separately for each configuration.

### Local development with symlinks

Using a list of local packages and their paths, the library will replace the packages
in the `composer.json` file with path repositories that use symlinks to work directly 
with local package clones. When switching back to production mode, the original 
`composer.json` and lock file are restored.

### Library refactoring made easy

The library is intended to make local development of interdependent Composer packages easier,
especially when coupled with an IDE like PHPStorm that can work with multiple projects
at the same time. Refactoring classes in a library can then be done in the library project,
and the changes will be immediately available in the project that uses the library.

- If an entry exists in `repositories`, it is overwritten with a path repository. 
  Any additional duplicate entries matching the same package are removed automatically.
  Otherwise, a new entry is added to ensure that the package is loaded from the specified path.
- The `require` (or `require-dev`) section is updated so the package version constraint 
  is set to `*`, which is required for path repositories. Packages that are listed in 
  `require-dev` in the production config stay in `require-dev` — they are not moved to `require`.

## Setup

### 1. Local repository configuration

To specify which repositories to switch in the configuration, create a
JSON file anywhere you like in your project with the following structure:

```json
{
  "local-repositories": [
    {
      "package-name": "vendor/package-name",
      "path": "/path/to/package"
    },
    {
      "package-name": "vendor/other-package",
      "path": "/path/to/other-package",
      "version": "1.2.3"
    }
  ]
}
```

All packages listed here will be replaced with path repositories when switching to
development mode, and restored to their original configuration when switching back
to production mode.

For packages that do not already have an entry in the `repositories` section of the
`composer.json` file, a new entry will be added.

> NOTE: See the section [Using specific package versions](#using-specific-package-versions)
> for more information about the optional `version` property.

### 2. Production configuration

Copy your existing `composer.json` file to `composer/composer-prod.json`.
This file will be used as the base configuration when switching back to production mode.

**WARNING**: From now on, only edit `composer/composer-prod.json`. The `composer.json`
file will be modified automatically when switching between configurations.

> NOTE: `composer switch-update`/`reconcile()` can reconcile drift between `composer.json`
> and `composer-prod.json` on your behalf, but this is **best-effort**, not a substitute
> for the rule above. It resolves drift by comparing file content and picks a direction
> using file modification time — two cases it cannot resolve automatically:
> - **Ambiguous case**: both files were edited and happen to share the same modification
>   time. No direction can be chosen; see
>   [Reconciling PROD configuration drift](#reconciling-prod-configuration-drift) for the
>   programmatic API to resolve this explicitly.
> - **DEV case**: while in development mode, `composer.json` has already been rewritten
>   with local path repositories and is no longer meaningful to compare against
>   `composer-prod.json` at all — reconciliation is skipped entirely, and only
>   `composer/composer-prod.json` reflects your real production configuration.

### 3. Set up switching scripts

The library provides built-in Composer script entry points. If your project follows the
standard file layout — `composer.json` in the project root, and `composer-prod.json` plus
`local-repositories.json` in a `composer/` subdirectory — you can wire them directly 
without writing any PHP glue code:

```json
{
  "scripts": {
    "switch-dev": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDev",
    "switch-prod": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchProd",
    "switch-update": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchUpdate",
    "switch-verify-config": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerVerifyConfig",
    "switch-install-hooks": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerInstallHooks"
  }
}
```

These entry points use `ConfigSwitcher::fromProjectRoot(getcwd())` internally, which
resolves to:

- `<project-root>/composer.json`
- `<project-root>/composer/composer-prod.json`
- `<project-root>/composer/local-repositories.json`

The library also ships these optional entry points, which use the same path
convention:

```json
{
  "scripts": {
    "switch-describe": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDescribe",
    "switch-describe-json": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDescribeJson",
    "switch-reconcile": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchReconcile",
    "switch-preview-dev": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchPreviewDev",
    "switch-preview-prod": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchPreviewProd"
  }
}
```

See [Inspecting switcher state](#inspecting-switcher-state) and
[Previewing a switch](#previewing-a-switch) for what these print.

> NOTE: It's good practice to also have a `build` script that ensures the
> configuration is set to production mode before deploying the project.
> This will minimize the risk of accidentally deploying with development
> dependencies.

### Custom file layout

If your project uses a different file layout, you can use the three-argument constructor
directly in a custom script handler class:

```php
declare(strict_types=1);

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;

class ComposerScripts
{
    private static function createSwitcher() : ConfigSwitcher
    {
        return new ConfigSwitcher(
            new ConfigFile('/path/to/composer.json'),
            new ConfigFile('/path/to/composer-production.json'),
            new ConfigFile('/path/to/local-repositories.json')
        );
    }

    public static function switchToDEV() : void
    {
        self::createSwitcher()->switchToDevelopment();
    }
    
    public static function switchToPROD() : void
    {
        self::createSwitcher()->switchToProduction();
    }
    
    public static function switchUpdate() : void
    {
        self::createSwitcher()->switchUpdate();
    }
}
```

Then wire the scripts in `composer.json`:

```json
{
  "scripts": {
    "switch-dev": "ComposerScripts::switchToDEV",
    "switch-prod": "ComposerScripts::switchToPROD",
    "switch-update": "ComposerScripts::switchUpdate"
  }
}
```

### Vendor dependencies in attached projects

A drawback of attaching local packages using path repositories is that
the vendor dependencies of the attached packages will be included in the
class index of the IDE, causing duplicate class messages to appear, and
clicking on classes may be confusing at it often does not open the file
you expect.

To fix this, I usually attach a separate local clone of the package I wish
to attach. In this clone, I do not run `composer install` to avoid creating
a `vendor` folder altogether. This way, the IDE's index stays clean, and no
confusion is possible.

## Script usage

Once the setup is complete, you can use the following Composer commands to switch
between the configurations.

### Switch to development mode

```bash
composer switch-dev
composer update
```

### Switch to production mode

```bash
composer switch-prod
composer update
```

### Update current configuration

This command will re-apply the current configuration (DEV or PROD).
In PROD mode, `composer.json` and `composer-prod.json` are reconciled:
if their content already matches, nothing happens; otherwise the file
with the more recent modification time is copied over the other one
(along with its lock file). Use this if you modified either the
`composer-prod.json` or the `composer.json` file directly.

```bash
composer switch-update
composer update
```

> NOTE: If `composer.json`'s lock file is missing (for example right after cloning
> the project), the switch still completes in full — config rewrite, status file,
> flag file — with a console warning instead of aborting. A DEV→PROD switch with
> no `composer-prod.lock` backup to restore from behaves the same way: the stale
> DEV lock file is removed and a warning is printed, rather than the switch failing.

If `composer.json` and `composer-prod.json` were both edited and
happen to share the same modification time, reconciliation cannot pick
a direction automatically and performs no write — see
[Reconciling PROD configuration drift](#reconciling-prod-configuration-drift)
for the programmatic API that lets you resolve this explicitly.

### Inspecting a switch outcome

`switchTo()`, `switchToDevelopment()`, `switchToProduction()` and `switchUpdate()` all
return a `SwitchOutcome` object describing what the switch did, using the same shape
as `reconcile()`:

```php
$outcome = $switcher->switchToProduction();

$outcome->getMode();          // string — the mode that was switched to
$outcome->getMessages();      // SwitchMessage[]
$outcome->getMessageTexts();  // string[]
$outcome->getOperations();    // FileOperation[] — files copied or removed, in order
$outcome->hasOperations();    // bool — true if at least one file was touched
```

`switchUpdate()` returns the outcome of whichever of `switchToDevelopment()`/
`switchToProduction()` it dispatches to. If no switch has ever been run yet, it
performs no file operations and returns a `SwitchOutcome` with mode `initial`
(`ConfigSwitcher::MODE_INITIAL`) and no operations, rather than silently doing nothing.

## Verifying configuration sync

After editing `composer-prod.json`, you can verify that the production configuration is still in
sync with the active `composer.json`:

```bash
composer switch-verify-config
```

This prints an in-sync confirmation, a list of differing keys, or a DEV-mode message.

For programmatic use, the `verify()` method returns a `VerificationResult` object:

```php
$result = $switcher->verify();

$result->isDevMode();     // bool — whether the switcher was in DEV mode
$result->isComparable();  // bool — false in DEV mode, since the comparison isn't meaningful
$result->isInSync();      // bool — always false when not comparable
$result->getDifferences(); // string[] — top-level key names that differ
```

`toArray()` returns the equivalent `array{inSync:bool,differences:string[],devMode:bool}` shape.

## Reconciling PROD configuration drift

`composer switch-update` calls `reconcile()` internally when in PROD mode, but it is also available directly for programmatic use — for example to preview a reconciliation, force a direction, or inspect exactly which files were touched:

```php
$outcome = $switcher->reconcile();

$outcome->getMode();          // string — the mode reconciliation ran in
$outcome->isDryRun();         // bool
$outcome->getMessages();      // SwitchMessage[]
$outcome->getMessageTexts();  // string[]
$outcome->getOperations();    // FileOperation[] — files copied, in order
$outcome->hasOperations();    // bool — true if at least one file was (or would be) touched
```

Content decides *whether* a reconciliation is needed — if `composer.json` and `composer-prod.json` already match, `reconcile()` is a no-op regardless of their modification times. When they differ, the more recently modified file wins by default. To resolve an ambiguous case (equal modification times) or to force a specific outcome, pass an explicit direction:

```php
use Mistralys\ComposerSwitcher\ConfigSwitcher;

$switcher->reconcile(ConfigSwitcher::RECONCILE_TO_PROD); // composer.json → composer-prod.json
$switcher->reconcile(ConfigSwitcher::RECONCILE_TO_MAIN); // composer-prod.json → composer.json
```

Pass `true` as the second argument to preview the outcome without writing any files:

```php
$preview = $switcher->reconcile(null, true);
```

`reconcile()` is a no-op in DEV mode, since `composer.json` has already been rewritten for local repositories and is not meaningful to compare against `composer-prod.json`.

```bash
composer switch-reconcile
```

## Inspecting switcher state

`describe()` assembles a full snapshot of the switcher's current state — mode, last
switch date, per-file existence and modification date for every config and lock file
it manages, which flag file (if any) is active, the `verify()` result, and the parsed
`local-repositories` list — without throwing, even when the dev configuration file is
missing or malformed (in which case the repository list comes back empty and a warning
is attached instead):

```php
$description = $switcher->describe();

$description->getMode();              // string|null — 'dev'/'prod'/'initial', or null if unknown
$description->getLastSwitchDate();    // string|null
$description->getFiles();             // array<int,array{label,path,exists,modifiedDate}>
$description->getActiveFlag();        // string|null — mode whose flag file currently exists
$description->getVerification();      // VerificationResult, same object verify() returns
$description->getLocalRepositories(); // array<int,array{packageName,path,version}>
$description->getWarnings();          // string[] — e.g. an unreadable dev config
$description->hasWarnings();          // bool
```

`toArray()` returns the equivalent nested array shape, and `toJSON()` encodes it as
pretty-printed JSON (`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`) for programmatic
consumption. A full example payload, for a project in PROD mode with one local
repository configured and no warnings:

```json
{
  "mode": "prod",
  "lastSwitchDate": "2026-09-30 08:12:45",
  "files": [
    { "label": "main", "path": "/project/composer.json", "exists": true, "modifiedDate": "2026-09-30 08:12:45" },
    { "label": "prod", "path": "/project/composer/composer-prod.json", "exists": true, "modifiedDate": "2026-09-29 17:03:11" },
    { "label": "dev", "path": "/project/composer/local-repositories.json", "exists": true, "modifiedDate": "2026-09-28 11:40:02" },
    { "label": "status", "path": "/project/composer/local-repositories.status", "exists": true, "modifiedDate": "2026-09-30 08:12:45" },
    { "label": "mainLock", "path": "/project/composer.lock", "exists": true, "modifiedDate": "2026-09-30 08:12:46" },
    { "label": "prodLock", "path": "/project/composer/composer-prod.lock", "exists": true, "modifiedDate": "2026-09-29 17:03:20" },
    { "label": "devLock", "path": "/project/composer/local-repositories.lock", "exists": false, "modifiedDate": null }
  ],
  "activeFlag": "prod",
  "verification": {
    "inSync": true,
    "differences": [],
    "devMode": false
  },
  "localRepositories": [
    { "packageName": "vendor/package-name", "path": "/path/to/package", "version": "*" }
  ],
  "warnings": []
}
```

```bash
composer switch-describe       # human-readable report
composer switch-describe-json  # pretty-printed JSON
```

## Previewing a switch

`previewSwitch(string $mode)` runs the same code path as a real switch, under a
dry-run overlay that never touches disk — every file on disk stays byte-identical and
every modification time stays unchanged, and its returned `SwitchOutcome::getOperations()`
matches exactly what a real switch from the same starting state would perform (even from
the `INITIAL` state, where `composer-prod.json` does not yet exist):

```php
$outcome = $switcher->previewSwitch(ConfigSwitcher::MODE_PROD);

$outcome->isDryRun();       // bool — always true for previewSwitch()
$outcome->getOperations();  // FileOperation[] — what would be copied/written/removed
$outcome->getMessages();    // SwitchMessage[]
```

`switchTo(string $mode, bool $dryRun = false)` exposes the same dry-run overlay
directly; `previewSwitch()` calls `switchTo($mode, true)` with message display
suppressed. The dry-run flag is always restored to its previous value afterward —
even if an exception is thrown mid-preview — so a subsequent real switch still writes
to disk normally.

```bash
composer switch-preview-dev
composer switch-preview-prod
```

Each planned file operation is printed as `would <type>: <source> -> <target> (<reason>)`,
followed by the same messages a real switch would display.

## Options

### Flag files

These files are created in the same folder as the `composer.json` file to make
the current configuration mode easily visible when looking in a file browser.

They are enabled by default, but can be disabled:

```php
use Mistralys\ComposerSwitcher\ConfigSwitcher;

$switcher = new ConfigSwitcher();

$switcher->setFlagFileEnabled(false);
```

### Console logging

By default, only relevant messages are printed to the console. You can enable
verbose logging to see all actions taken by the library:

```php
use Mistralys\ComposerSwitcher\ConfigSwitcher;

$switcher = new ConfigSwitcher();

$switcher->setWriteToConsole(true);
```

After a switch completes, a summary of what happened is printed to the console
automatically. This can be disabled:

```php
$switcher->setDisplayMessages(false);
```

For programmatic use, `getMessages()` returns the switch messages as an array of
`SwitchMessage` objects, each pairing a human-readable text with a numeric
`ConfigSwitcher::MESSAGE_*` code:

```php
foreach($switcher->getMessages() as $message) {
    echo $message->getCode() . ': ' . $message->getText();
}
```

`getMessageTexts()` returns the same messages as a plain `string[]`, and is what
`displayMessages()` uses internally to render console output.

## Using specific package versions

By default, path packages get the version constraint `*` to always use the latest
version from the local path. This will not work in all cases however: If you have 
other version constraints in your `require` section for the same package, you will
get a Composer error like this:

```
vendor/packagename[dev-main] from path repo (/path/to/repo) 
has higher repository priority. The packages from the higher 
priority repository do not match your constraint and are 
therefore not installable.
```

To work around this, you can optionally specify a version to use for packages in the
local repositories configuration file:

```json
{
  "local-repositories": [
    {
      "package-name": "vendor/package-name",
      "path": "/path/to/package",
      "version": "1.2.3"
    }
  ]
}
```

The repository will still be loaded as a path repository, but the specified version will 
be used whenever Composer needs to resolve the package version.

> NOTE: The only drawback of this approach is that you will need to maintain the version
> number manually in the configuration file.

## Handling exceptions

All errors raised by the library are instances of `ComposerSwitcherException`, each
carrying a numeric `ERROR_*` code and, in addition to its message, a structured context
array describing the failure programmatically instead of requiring you to parse the
message text:

```php
use Mistralys\ComposerSwitcher\ComposerSwitcherException;

try {
    $switcher->switchToProduction();
} catch (ComposerSwitcherException $e) {
    $e->getCode();               // int — one of the ERROR_* constants
    $e->getContext();            // array — the full context payload
    $e->getContextValue('filePath'); // mixed — null if the key isn't present
}
```

Common context keys include `filePath`/`targetPath` (the file involved, or a copy's
source/destination pair), `mode`/`direction` (an offending `switchTo()` mode or
`reconcile()` direction string), and `expected`/`actual` (an offending value paired
with what was expected). See `ComposerSwitcherException`'s `KEY_*` constants for the
full list.

## Programmatic and agent usage

The sections above introduce each value object where it's returned, but for a caller
building automation or an AI agent around this library, here is the whole
programmatic surface in one place — everything you need to drive a switch, inspect
its outcome, and handle failure, entirely without parsing console text.

| You want to... | Call | Returns |
|---|---|---|
| Perform a switch or update | `switchTo()`, `switchToDevelopment()`, `switchToProduction()`, `switchUpdate()` | `SwitchOutcome` |
| Fix drift without switching | `reconcile()` | `SwitchOutcome` |
| Preview a switch with no disk writes | `previewSwitch()` | `SwitchOutcome` (`isDryRun()` always `true`) |
| Compare `composer.json` vs `composer-prod.json` | `verify()` | `VerificationResult` |
| Get a full state snapshot | `describe()` | `SwitchDescription` |

Every one of these value objects is immutable and exposes a `toArray()` (`SwitchDescription`
also exposes `toJSON()`), so a caller never needs to scrape console output or parse a
free-form message string to know what happened:

```php
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;

$switcher = ConfigSwitcher::fromProjectRoot($projectRoot);
$switcher->setWriteToConsole(false);   // suppress verbose step-by-step logging
$switcher->setDisplayMessages(false);  // suppress the automatic post-switch summary

try {
    $outcome = $switcher->switchToProduction();

    // SwitchOutcome: what happened
    $outcome->getMode();                          // 'prod'
    $outcome->isDryRun();                          // false
    $outcome->hasOperations();                     // bool

    foreach($outcome->getMessages() as $message) { // SwitchMessage[]
        $message->getCode();                       // int — ConfigSwitcher::MESSAGE_* constant, or 0
        $message->hasCode();                        // bool — false when code is 0
        $message->getText();                        // string
    }

    foreach($outcome->getOperations() as $operation) { // FileOperation[]
        $operation->getType();                          // FileOperation::TYPE_COPY|TYPE_WRITE|TYPE_DELETE
        $operation->getSourcePath();                     // string|null — null for write/delete
        $operation->getTargetPath();                     // string
        $operation->getReason();                         // string — human-readable
        $operation->isApplied();                         // bool — false in a dry run
    }

    // Every value object round-trips to a plain array for JSON APIs, logs, etc.
    json_encode($outcome->toArray());
} catch (ComposerSwitcherException $e) {
    // Structured failure: no message-string parsing required
    $e->getCode();                                  // int — a ComposerSwitcherException::ERROR_* constant
    $e->getContext();                                // array — full structured payload
    $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH); // mixed — null if absent
}
```

For a read-only, agent-friendly snapshot of everything the switcher knows (without
performing a switch), use `describe()->toJSON()` — see
[Inspecting switcher state](#inspecting-switcher-state) for the full example payload — or
drive it directly from the console with `composer switch-describe-json`.

## Git hooks

The library ships with a pre-commit hook that prevents accidentally committing development
configuration to version control. It blocks the commit when:

1. `composer.json` or `composer.lock` is staged while in DEV mode (the `composer.json.DEV` marker file exists).
2. `composer.json` is staged and contains a `"type": "path"` repository entry (a local symlink is active).

To install the hook in your project:

```bash
composer switch-install-hooks
```

Or programmatically:

```php
$switcher->installGitHooks('/path/to/project-root');
```

This copies the bundled hook to `.git/hooks/pre-commit` with executable permissions,
**overwriting any existing `pre-commit` hook** at that path without prompting or backing it up.
If `.git/hooks/` does not exist, the method returns `false` and prints a console warning.

## Migrating from 2.x

Version 3.0.0 introduces typed return values in place of the previous `void`/array
returns and one behavior fix. Existing `composer switch-*` Composer scripts and the
`composer.json`/`composer-prod.json`/`local-repositories.json` file layout are
**unchanged** — this migration only affects code that calls the library's PHP API
directly.

1. **`verify()` no longer returns an array.** It returns a `VerificationResult` object.

   ```php
   // Before (2.x)
   $result = $switcher->verify();
   $inSync = $result['inSync'];
   $differences = $result['differences'];

   // After (3.0.0)
   $result = $switcher->verify();
   $inSync = $result->isInSync();
   $differences = $result->getDifferences();
   ```

2. **`switchTo()`, `switchToDevelopment()`, `switchToProduction()`, and `switchUpdate()`
   no longer return `void`.** They return a `SwitchOutcome` object. If you have a
   subclass or wrapper that declares a `: void` return type on an override, or code
   that asserted these calls returned nothing, update the type/assertion — see
   [Inspecting a switch outcome](#inspecting-a-switch-outcome) and
   [Programmatic and agent usage](#programmatic-and-agent-usage) for the new shape.
   Console output and `getMessages()`/`getMessageTexts()` on `ConfigSwitcher` are
   unaffected — only the return type changed, not the side effects.

3. **`BaseFile::tryCopyTo()` no longer requires the target to already exist.** In 2.x,
   `tryCopyTo()` copied only when *both* the source and the target existed — a target
   that didn't exist yet was silently skipped. In 3.0.0, it copies whenever the
   *source* exists, regardless of whether the target exists yet. This is a bug fix
   (the documented behavior was always "copy if the source exists"), but if any
   custom code relied on `tryCopyTo()` refusing to create a new file, add an explicit
   `$target->exists()` guard before calling it.

4. **New capabilities are entirely additive** — `describe()`, `previewSwitch()`,
   `reconcile()`, structured message codes (`SwitchMessage`), and exception context
   (`ComposerSwitcherException::getContext()`) are new methods/features you can adopt
   incrementally; nothing about them requires touching existing call sites.

No changes are required to `composer.json` script wiring, the three-path file
convention, or the `local-repositories.json` format.

## Version control

Here is what you should and should not commit to version control:

- `composer.json` - YES
- `composer.lock` - YES
- `composer.json.PROD` / `composer.json.DEV` - NO (helper files)
- `composer-prod.json` - YES
- `composer-prod.lock` - YES
- `local-repositories.json` - NO (local-specific paths)
- `local-repositories.status` - NO

> NOTE: It is good practice to add a template for the `local-repositories.json` file
> to version control, so other developers can use this to create their own local
> configuration file. This is typically named something like `local-repositories.dist.json`.
