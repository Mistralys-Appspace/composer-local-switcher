# API Guide

Everything the Composer commands do is available as PHP methods on `Mistralys\ComposerSwitcher\ConfigSwitcher`, returning typed value objects. This lets you build automation or agent tooling without parsing console text. For the commands themselves, see the [Usage Guide](usage.md).

## Overview

| You want to... | Call | Returns |
|---|---|---|
| Perform a switch or update | `switchTo()`, `switchToDevelopment()`, `switchToProduction()`, `switchUpdate()` | `SwitchOutcome` |
| Fix drift without switching | `reconcile()` | `SwitchOutcome` |
| Preview a switch with no disk writes | `previewSwitch()` | `SwitchOutcome` (`isDryRun()` always `true`) |
| Compare `composer.json` vs `composer-prod.json` | `verify()` | `VerificationResult` |
| Get a full state snapshot | `describe()` | `SwitchDescription` |

Every one of these value objects is immutable and exposes a `toArray()` (`SwitchDescription` also exposes `toJSON()`).

Create a switcher for the standard file layout with `ConfigSwitcher::fromProjectRoot($projectRoot)`, or with the three-argument constructor for a custom layout (see [Setup](setup.md#custom-file-layout)).

## Switch outcomes

`switchTo()`, `switchToDevelopment()`, `switchToProduction()` and `switchUpdate()` return a `SwitchOutcome` describing what the switch did:

```php
$outcome = $switcher->switchToProduction();

$outcome->getMode();          // string - the mode that was switched to
$outcome->getMessages();      // SwitchMessage[]
$outcome->getMessageTexts();  // string[]
$outcome->getOperations();    // FileOperation[] - files copied or removed, in order
$outcome->hasOperations();    // bool - true if at least one file was touched
```

`switchUpdate()` returns the outcome of whichever of `switchToDevelopment()`/`switchToProduction()` it dispatches to. If no switch has ever been run yet, it performs no file operations and returns a `SwitchOutcome` with mode `initial` (`ConfigSwitcher::MODE_INITIAL`) and no operations.

`MODE_INITIAL` is not a valid argument to `switchTo()`; it only appears as an outcome mode.

## Verification

`verify()` returns a `VerificationResult`:

```php
$result = $switcher->verify();

$result->isDevMode();      // bool - whether the switcher was in DEV mode
$result->isComparable();   // bool - false in DEV mode, since the comparison isn't meaningful
$result->isInSync();       // bool - always false when not comparable
$result->getDifferences(); // string[] - top-level key names that differ
```

`toArray()` returns the equivalent `array{inSync:bool,differences:string[],devMode:bool}` shape.

## Reconciling and previewing

### reconcile()

`composer switch-update` calls `reconcile()` internally in PROD mode, but it is also available directly - for example to preview a reconciliation, force a direction, or inspect exactly which files were touched:

```php
$outcome = $switcher->reconcile();

$outcome->getMode();          // string - the mode reconciliation ran in
$outcome->isDryRun();         // bool
$outcome->getMessages();      // SwitchMessage[]
$outcome->getMessageTexts();  // string[]
$outcome->getOperations();    // FileOperation[] - files copied, in order
$outcome->hasOperations();    // bool - true if at least one file was (or would be) touched
```

Content decides whether a reconciliation is needed; by default the more recently modified file wins. To resolve an ambiguous case (equal modification times) or to force a specific outcome, pass an explicit direction:

```php
use Mistralys\ComposerSwitcher\ConfigSwitcher;

$switcher->reconcile(ConfigSwitcher::RECONCILE_TO_PROD); // composer.json -> composer-prod.json
$switcher->reconcile(ConfigSwitcher::RECONCILE_TO_MAIN); // composer-prod.json -> composer.json
```

Pass `true` as the second argument to preview the outcome without writing any files:

```php
$preview = $switcher->reconcile(null, true);
```

The no-op states (DEV mode, `INITIAL`, missing `composer-prod.json`) are described in [Reconciling drift](usage.md#reconciling-drift). In API terms:

| State | Message code | Notes |
|---|---|---|
| DEV mode | `MESSAGE_DEV_MODE_NOT_RECONCILABLE` | No-op. |
| `INITIAL` | `MESSAGE_INITIAL_NOT_RECONCILABLE` | No-op for every direction; outcome mode is `ConfigSwitcher::MODE_INITIAL`. |
| `composer-prod.json` missing | `MESSAGE_PROD_CONFIG_MISSING` | No-op, except for an explicit `RECONCILE_TO_PROD`, which recreates `composer-prod.json` (and its lock file, when present) from `composer.json` and reports `MESSAGE_BACKED_UP_MAIN_TO_PROD`. |
| Already in sync | `MESSAGE_ALREADY_IN_SYNC` | No-op. |
| Equal modification times, differing content | `MESSAGE_RECONCILE_AMBIGUOUS` | No-op unless a direction is passed. |

An invalid direction (anything other than `RECONCILE_TO_MAIN`/`RECONCILE_TO_PROD`) throws `ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION` in every state, `INITIAL` included.

A preview never writes to disk. If `reconcile()` throws partway through a call, the switcher's dry-run flag is always restored to its prior value before the exception propagates.

### previewSwitch()

`previewSwitch(string $mode)` runs the same code path as a real switch, under a dry-run overlay that never touches disk. Its `getOperations()` matches exactly what a real switch from the same starting state would perform. In PROD mode with drifted configs, the nested reconcile is planned but not written.

```php
$outcome = $switcher->previewSwitch(ConfigSwitcher::MODE_PROD);

$outcome->isDryRun();       // bool - always true for previewSwitch()
$outcome->getOperations();  // FileOperation[] - what would be copied/written/removed
$outcome->getMessages();    // SwitchMessage[]
```

`switchTo(string $mode, bool $dryRun = false)` exposes the same dry-run overlay directly; `previewSwitch()` calls `switchTo($mode, true)` with message display suppressed. The dry-run flag is always restored to its previous value afterward - even if an exception is thrown mid-preview - so a subsequent real switch writes to disk normally.

## Describing state

`describe()` returns a `SwitchDescription` and never throws, even when the dev configuration file is missing or malformed (the repository list is then empty and a warning is attached):

```php
$description = $switcher->describe();

$description->getMode();              // string|null - 'dev'/'prod'/'initial', or null if unknown
$description->getLastSwitchDate();    // string|null
$description->getFiles();             // array<int,array{label,path,exists,modifiedDate}>
$description->getActiveFlag();        // string|null - mode whose flag file currently exists
$description->getVerification();      // VerificationResult, same object verify() returns
$description->getLocalRepositories(); // array<int,array{packageName,path,version}>
$description->getWarnings();          // string[] - e.g. an unreadable dev config
$description->hasWarnings();          // bool
```

`toArray()` returns the equivalent nested array, and `toJSON()` encodes it as pretty-printed JSON (`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`). See the [Usage Guide](usage.md#inspecting-switcher-state) for an example payload.

## Options

### Flag files

Flag files are created in the same folder as `composer.json` to make the current configuration mode easily visible in a file browser. They are enabled by default, but can be disabled:

```php
use Mistralys\ComposerSwitcher\ConfigSwitcher;

$switcher = new ConfigSwitcher(/* ... */);

$switcher->setFlagFileEnabled(false);
```

### Console logging

By default, only relevant messages are printed to the console. Enable verbose logging to see all actions taken by the library:

```php
$switcher->setWriteToConsole(true);
```

After a switch completes, a summary of what happened is printed automatically. This can be disabled:

```php
$switcher->setDisplayMessages(false);
```

### Messages

`getMessages()` returns the switch messages as `SwitchMessage` objects, each pairing a human-readable text with a numeric `ConfigSwitcher::MESSAGE_*` code:

```php
foreach($switcher->getMessages() as $message) {
    echo $message->getCode() . ': ' . $message->getText();
}
```

`getMessageTexts()` returns the same messages as a plain `string[]`.

## Handling exceptions

All errors raised by the library are instances of `ComposerSwitcherException`, each carrying a numeric `ERROR_*` code and a structured context array, so you do not need to parse the message text:

```php
use Mistralys\ComposerSwitcher\ComposerSwitcherException;

try {
    $switcher->switchToProduction();
} catch (ComposerSwitcherException $e) {
    $e->getCode();                   // int - one of the ERROR_* constants
    $e->getContext();                // array - the full context payload
    $e->getContextValue('filePath'); // mixed - null if the key isn't present
}
```

Common context keys:

- `filePath` / `targetPath` - the file involved, or a copy's source/destination pair.
- `mode` / `direction` - an offending `switchTo()` mode or `reconcile()` direction string.
- `expected` / `actual` - an offending value paired with what was expected.

See the `KEY_*` constants on `ComposerSwitcherException` for the full list.

A filesystem failure (a failed read, write, copy, or delete) never leaks a bare PHP warning, even when a stricter host such as Composer's own error handler would turn an unsuppressed warning into an `\ErrorException`. It always surfaces as a `ComposerSwitcherException`, with the native error message under `KEY_NATIVE_ERROR` and the captured native error chained as `getPrevious()`:

```php
} catch (ComposerSwitcherException $e) {
    $e->getContextValue(ComposerSwitcherException::KEY_NATIVE_ERROR); // string|null - native PHP error message
    $e->getPrevious();                                                // ?Throwable - the captured \ErrorException
}
```

## Programmatic and agent usage

A complete example that drives a switch, inspects its outcome, and handles failure, entirely without parsing console text:

```php
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;

$switcher = ConfigSwitcher::fromProjectRoot($projectRoot);
$switcher->setWriteToConsole(false);   // suppress verbose step-by-step logging
$switcher->setDisplayMessages(false);  // suppress the automatic post-switch summary

try {
    $outcome = $switcher->switchToProduction();

    // SwitchOutcome: what happened
    $outcome->getMode();                           // 'prod'
    $outcome->isDryRun();                          // false
    $outcome->hasOperations();                     // bool

    foreach($outcome->getMessages() as $message) { // SwitchMessage[]
        $message->getCode();                       // int - ConfigSwitcher::MESSAGE_* constant, or 0
        $message->hasCode();                       // bool - false when code is 0
        $message->getText();                       // string
    }

    foreach($outcome->getOperations() as $operation) { // FileOperation[]
        $operation->getType();                         // FileOperation::TYPE_COPY|TYPE_WRITE|TYPE_DELETE
        $operation->getSourcePath();                   // string|null - null for write/delete
        $operation->getTargetPath();                   // string
        $operation->getReason();                       // string - human-readable
        $operation->isApplied();                       // bool - false in a dry run
    }

    // Every value object round-trips to a plain array for JSON APIs, logs, etc.
    json_encode($outcome->toArray());
} catch (ComposerSwitcherException $e) {
    // Structured failure: no message-string parsing required
    $e->getCode();                                                 // int - a ComposerSwitcherException::ERROR_* constant
    $e->getContext();                                              // array - full structured payload
    $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH); // mixed - null if absent
}
```

For a read-only, agent-friendly snapshot without performing a switch, use `describe()->toJSON()`, or run `composer switch-describe-json` from the console.

## Installing git hooks

`installGitHooks()` is also available programmatically - see [Git Hooks](git-hooks.md).

## Related guides

- [Setup Guide](setup.md)
- [Usage Guide](usage.md)
- [Migrating from 1.x](migrating-from-1x.md) - return type changes in the API above
