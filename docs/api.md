# API Guide

Everything the Composer commands do is available as PHP methods on `Mistralys\ComposerSwitcher\ConfigSwitcher`, returning typed value objects. This lets you build automation or agent tooling without parsing console text. For the commands themselves, see the [Usage Guide](usage.md).

## Overview

| You want to... | Call | Returns |
|---|---|---|
| Perform a switch or update | `switchTo()`, `switchToDevelopment()`, `switchToProduction()`, `switchUpdate()` | `SwitchOutcome` |
| Preview a switch with no disk writes | `previewSwitch()` | `SwitchOutcome` (`isDryRun()` always `true`) |
| Get a full state snapshot | `describe()` | `SwitchDescription` |

Every one of these value objects is immutable and exposes a `toArray()` (`SwitchDescription` also exposes `toJSON()`).

Create a switcher for the standard file layout with `ConfigSwitcher::fromProjectRoot($projectRoot)`, or with the three-argument constructor for a custom layout (see [Setup](setup.md#custom-file-layout)).

## Switch outcomes

`switchTo()`, `switchToDevelopment()`, `switchToProduction()` and `switchUpdate()` return a `SwitchOutcome` describing what the switch did:

```php
$outcome = $switcher->switchToProduction();

$outcome->getMode();              // string - the mode that was switched to
$outcome->getMessages();          // SwitchMessage[]
$outcome->getMessageTexts();      // string[]
$outcome->getOperations();        // FileOperation[] - files copied or removed, in order
$outcome->hasOperations();        // bool - true if at least one file was touched
$outcome->getComposerCommand();   // ?ComposerCommand - the Composer command the switch wants run next
$outcome->isBlocked();            // bool - true when a precondition blocked the switch (no file effects)
$outcome->getConfigChanges();     // ConfigChangeSet - what changed (or would change) in composer.json/production
$outcome->requiresConfirmation(); // bool - true when composer.json itself would change
$outcome->hasSameEffectsAs($other); // bool - "shown equals applied": same blocked/changes/command/operations
```

`switchTo()`/`switchToDevelopment()`/`switchToProduction()`/`switchUpdate()` never execute Composer or prompt
for confirmation themselves - "plan in the core, execute at the edge". They only ever hand back a
`ComposerCommand` (via `getComposerCommand()`) for the caller to run. The built-in `composer switch-*`
commands run that command themselves, through `Utils\SwitchCommandRunner` - see
["Running the planned command"](#running-the-planned-command) below. A library consumer that wants the
same single-command behavior can run `getComposerCommand()` with its own process runner, or use
`Utils\ComposerProcess` directly.

`switchUpdate()` returns the outcome of whichever of `switchToDevelopment()`/`switchToProduction()` it dispatches to. If no switch has ever been run yet, it performs no file operations and returns a `SwitchOutcome` with mode `initial` (`ConfigSwitcher::MODE_INITIAL`) and no operations.

`MODE_INITIAL` is not a valid argument to `switchTo()`; it only appears as an outcome mode.

### Inspecting and showing changes

`getConfigChanges()` returns a `ConfigChangeSet`, split into two sections: `getComposerJsonChanges()` (what
changes in `composer.json` itself) and `getProdConfigChanges()` (what becomes permanent in production - only
meaningful on a DEV→PROD switch, or `describe()`'s pending-carry-back view in DEV). `requiresConfirmation()`
is `true` exactly when the `composerJson` section is non-empty, which is also the condition the built-in
commands prompt on. Showing a `ConfigChangeSet` to a user before applying it is exactly what
`Utils\OutcomeRenderer::renderChangeSet()` does for the console - a custom tool can build the same summary
from `getConfigChanges()`/`getComposerJsonChanges()`/`getProdConfigChanges()` without parsing console text.

`hasSameEffectsAs(SwitchOutcome $other)` compares two outcomes' blocked flag, config changes, planned command
and file operations (messages are deliberately excluded). This is how the built-in commands confirm that what
they show is what they apply: preview, render to the user, preview again right before writing, and refuse to
write if the two previews no longer agree.

### Running the planned command

`Utils\ComposerProcess::run(ComposerCommand $command, string $workingDir): int` runs a planned command as a
real child process (via `proc_open()`, inherited STDIN/STDOUT/STDERR), returning its exit code. It resolves
the Composer binary from an injected path, else `COMPOSER_BINARY`, else an executable `composer` on `PATH`,
and sets `COMPOSER_SWITCHER_NESTED=1` in the child's environment so a `post-update-cmd` hook that re-invokes
a switch command can detect the nesting and no-op instead of recursing:

```php
use Mistralys\ComposerSwitcher\Utils\ComposerProcess;

$outcome = $switcher->switchToProduction();
$command = $outcome->getComposerCommand();

if ($command !== null) {
    $exitCode = (new ComposerProcess())->run($command, getcwd());
}
```

A non-zero exit throws `ComposerSwitcherException::ERROR_COMPOSER_COMMAND_FAILED` only when you route
execution through `Utils\SwitchCommandRunner` (what the built-in `composer switch-*` commands use) - calling
`ComposerProcess::run()` directly just returns the exit code, leaving the decision to the caller.

## Previewing a switch

### previewSwitch()

`previewSwitch(string $mode)` runs the same code path as a real switch, under a dry-run overlay that never touches disk. Its `getOperations()` matches exactly what a real switch from the same starting state would perform, including from the `INITIAL` state. In PROD mode, a preview has no file effects of its own beyond legacy cleanup/status/flags, since `composer-prod.json` is a transient DEV-session snapshot rather than a committed baseline to compare against.

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
$description->getLockStatus();        // LockStatus - the main lock's own freshness against its config
$description->getInstalledState();    // InstalledState - whether what's installed matches the active mode
$description->getPendingProdChanges(); // ?ConfigChangeSet - DEV-only: edits a DEV->PROD switch would carry back
$description->hasPendingProdChanges(); // bool
$description->getLocalRepositories(); // array<int,array{packageName,path,version,derivedVersion}>
$description->getWarnings();          // string[] - e.g. an unreadable dev config, or a legacy artifact found on disk
$description->hasWarnings();          // bool
```

`getPendingProdChanges()` reuses the same `revert()` + `ConfigDiff` pipeline a real DEV→PROD switch runs, so what it reports always matches what that switch would actually carry back — it degrades to `null` plus a warning (never a thrown exception) when the production snapshot is missing, modified, or unreadable. `toArray()` returns the equivalent nested array, and `toJSON()` encodes it as pretty-printed JSON (`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`). See the [Usage Guide](usage.md#inspecting-switcher-state) for an example payload.

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
- `mode` - an offending `switchTo()` mode string.
- `expected` / `actual` - an offending value paired with what was expected.
- `command` / `exitCode` - the shell-rendered Composer command and its exit code, on `ERROR_COMPOSER_COMMAND_FAILED`.

See the `KEY_*` constants on `ComposerSwitcherException` for the full list.

Errors specific to running the planned command (thrown by `Utils\SwitchCommandRunner`/`Utils\ComposerProcess`,
i.e. by the built-in `composer switch-*` commands, not by `switchTo()` itself):

- `ERROR_SWITCH_BLOCKED` - the switch is blocked by a precondition; nothing was written.
- `ERROR_CONFIRMATION_REQUIRED` - a non-interactive run needed confirmation (`composer.json` would change) but was not given `--yes`; the changes were printed, nothing was written.
- `ERROR_INPUTS_CHANGED` - the post-confirmation preview no longer matches the one shown to the user; nothing was written.
- `ERROR_COMPOSER_COMMAND_FAILED` - the executed `composer` command exited non-zero (context: `command`, `exitCode`).
- `ERROR_COMPOSER_BINARY_NOT_FOUND` - neither `COMPOSER_BINARY` nor an executable `composer` on `PATH` could be resolved.

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
