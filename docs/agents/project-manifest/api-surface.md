# Public API Surface

## `Mistralys\ComposerSwitcher`

### `ConfigSwitcher`

> `src/ConfigSwitcher.php` — Main orchestrator class.

#### Constants

```php
public const MODE_DEV = 'dev';
public const MODE_PROD = 'prod';
public const MODE_INITIAL = 'initial';
public const MESSAGE_NO_LOCK_FILE_FOUND = 182201;
public const MESSAGE_USING_DEV_CONFIG = 182203;
public const MESSAGE_USING_PROD_CONFIG = 182204;
public const MESSAGE_REBUILT_DEV_CONFIG = 182209;
public const MESSAGE_DRY_RUN_ACTIVE = 182213;
public const MESSAGE_PROD_LOCK_MISSING = 182214;
public const MESSAGE_PROD_LOCK_OUTDATED = 182217;
public const MESSAGE_SNAPSHOT_MODIFIED = 182218;
public const MESSAGE_SNAPSHOT_MISSING = 182219;
public const MESSAGE_DEV_CHANGES_CARRIED_BACK = 182220;
public const MESSAGE_MANAGED_ENTRY_OVERRIDDEN = 182221;
public const MESSAGE_LEGACY_FILES_FOUND = 182225;
public const MESSAGE_VERSION_DERIVED = 182226;
public const MESSAGE_ALREADY_INSTALLED = 182227;
public const MESSAGE_COMPOSER_COMMAND = 182222;
public const MESSAGE_COMPOSER_SKIPPED = 182223;
public const MESSAGE_NESTED_RUN_SKIPPED = 182224;
public const MESSAGE_CONFIRMATION_REQUIRED = 182228;
public const MESSAGE_SWITCH_CANCELLED = 182229;
public const MESSAGE_VERSION_OVERRIDE_CONFLICT = 182230;
public const MESSAGE_COMPOSER_RECOVERY = 182231;
public const KEY_LOCAL_REPOSITORIES = 'local-repositories';
public const KEY_REPOSITORIES = 'repositories';
```

> `MESSAGE_COMPOSER_COMMAND`/`MESSAGE_COMPOSER_SKIPPED`/`MESSAGE_NESTED_RUN_SKIPPED`/`MESSAGE_CONFIRMATION_REQUIRED`/`MESSAGE_SWITCH_CANCELLED`/`MESSAGE_COMPOSER_RECOVERY` are the entry-point edge's own messages — written by `Utils\SwitchCommandRunner` through `Utils\EventContext::writeMessage()` (which prefixes the written line with `[<code>] ` only when `EventContext::isVerbose()`; at default verbosity the line is plain human text), not accumulated on `ConfigSwitcher::getMessages()`. `MESSAGE_NESTED_RUN_SKIPPED` is written when `COMPOSER_SWITCHER_NESTED` is set and the switch no-ops before any preview; `MESSAGE_CONFIRMATION_REQUIRED` precedes `ComposerSwitcherException::ERROR_CONFIRMATION_REQUIRED` on a non-interactive run without `--yes`; `MESSAGE_SWITCH_CANCELLED` is written when the user declines the interactive confirmation prompt; `MESSAGE_COMPOSER_SKIPPED` is written when `--no-install` was given, in place of the real invocation. `MESSAGE_COMPOSER_COMMAND` is no longer emitted — `runCommand()` used to write it immediately after `OutcomeRenderer::renderCommandStart()`, duplicating the command and reason that frame already prints; the constant is kept only for backward compatibility with any consumer still referencing it. `MESSAGE_COMPOSER_RECOVERY` (`Warning` level) is written by `runCommand()` right before it throws `ComposerSwitcherException::ERROR_COMPOSER_COMMAND_FAILED` on a non-zero exit code — it names the current mode and the two recovery commands (`composer switch-update`, `composer switch-prod`); this is guidance only, the switch itself is never rolled back automatically.

> `MESSAGE_DRY_RUN_ACTIVE` is reported by `previewSwitch()`/`switchTo($mode, true)` for the duration of a dry-run call. `MESSAGE_PROD_LOCK_MISSING` is emitted by `switch_planDevToProd()` (the DEV→PROD row of the v3 decision table's post-dispatch planner, `switch_plan()`) when no `composer-prod.lock` snapshot backup exists to restore — `composer.lock` is force-deleted instead of the unconditional copy throwing, and a full `update` is planned. `MODE_INITIAL` is not a valid `switchTo()`/`switchToDevelopment()`/`switchToProduction()` argument; it is only ever the `SwitchOutcome::getMode()` value returned by `switchUpdate()` when no switch has ever been run yet.
>
> `MESSAGE_PROD_LOCK_OUTDATED`/`MESSAGE_SNAPSHOT_MODIFIED`/`MESSAGE_SNAPSHOT_MISSING`/`MESSAGE_VERSION_OVERRIDE_CONFLICT` are the v3 decision table's blocking messages (a blocked switch has no file effects — see `SwitchOutcome::isBlocked()`); `MESSAGE_DEV_CHANGES_CARRIED_BACK`/`MESSAGE_MANAGED_ENTRY_OVERRIDDEN` describe how a DEV-time edit was resolved on a DEV→PROD or DEV→DEV-refresh switch; `MESSAGE_LEGACY_FILES_FOUND` is emitted by `switch_cleanLegacyArtifacts()`, run at the start of every switch regardless of direction; `MESSAGE_VERSION_DERIVED` and `MESSAGE_ALREADY_INSTALLED` describe planning decisions (a derived path-repository alias version, or an `InstalledState::Matches` result that skips planning a Composer command). `MESSAGE_VERSION_OVERRIDE_CONFLICT` (`Warning` level) is reported — one per conflicting `version` override — by `switch_reportVersionConflicts()`, called from `switch_planProdToDev()`/`switch_planDevRefresh()` before any write, from `Utils\DevConfigTransformer::apply()`'s `DevTransformResult::getVersionConflicts()` (see `DevConfigTransformer`/`DevTransformResult` below); the same conflicts are reported non-blockingly as `describe()` warnings by `describe_versionConflictWarnings()`. See [switching-decision-table.md](switching-decision-table.md) for the full row-by-row mapping. The retired v2 constants `MESSAGE_CREATE_NEW_LOCK_FILE` (182202), `MESSAGE_RUN_INSTALL_PROD` (182207) and `MESSAGE_RUN_INSTALL_DEV` (182208) no longer exist — their only callers were the four `switch_case_*` methods the v3 decision table replaced. The retired reconcile-family constants `MESSAGE_BACKED_UP_MAIN_TO_PROD` (182205), `MESSAGE_RESTORED_PROD_TO_MAIN` (182206), `MESSAGE_ALREADY_IN_SYNC` (182210), `MESSAGE_RECONCILE_AMBIGUOUS` (182211), `MESSAGE_DEV_MODE_NOT_RECONCILABLE` (182212), `MESSAGE_INITIAL_NOT_RECONCILABLE` (182215) and `MESSAGE_PROD_CONFIG_MISSING` (182216) also no longer exist, along with `RECONCILE_TO_MAIN`/`RECONCILE_TO_PROD` — their only caller was the now-deleted `reconcile()`/`reconcileCore()` family.

#### Static Factory

```php
public static function fromProjectRoot(string $rootPath): self
```

Creates a `ConfigSwitcher` using the three-path convention shared by both consumer projects: `$rootPath/composer.json`, `$rootPath/composer/composer-prod.json`, `$rootPath/composer/local-repositories.json`.

#### Composer Script Entry Points

```php
public static function composerSwitchDev(?object $event = null): bool
public static function composerSwitchProd(?object $event = null): bool
public static function composerSwitchUpdate(?object $event = null): bool
public static function composerInstallHooks(): void
public static function composerSwitchDescribe(?object $event = null): void
public static function composerSwitchDescribeJson(?object $event = null): void
public static function composerSwitchPreviewDev(?object $event = null): void
public static function composerSwitchPreviewProd(?object $event = null): void
```

Static entry points for use in `composer.json` scripts. `$event` is whatever Composer's `EventDispatcher` passes to the script callback (or `null` when called directly) — the only thing any of these does with it is pass it to `Utils\EventContext::fromEvent()`, the library's sole duck-typing site. `composerSwitchPreviewDev()`/`composerSwitchPreviewProd()` are one-line delegations to a private `buildRunner($event): Utils\SwitchCommandRunner` helper's `runPreview()`; `composerSwitchDev()`/`composerSwitchProd()`/`composerSwitchUpdate()` are one-line delegations to private `runSwitchEntryPoint()`/`runUpdateEntryPoint()` helpers, which go through `guardEntryPoint()` and `buildRunnerFromContext()` to the runner's `runSwitch()`/`runUpdate()`. The three return `bool`: `true` on success, `false` after an explained failure — a `ComposerSwitcherException` with code `ERROR_SWITCH_BLOCKED`, `ERROR_CONFIRMATION_REQUIRED`, `ERROR_INPUTS_CHANGED` or `ERROR_COMPOSER_COMMAND_FAILED` is caught, its message written as a styled (`<warning>`) line via `EventContext::writeStyled()`, and `false` returned (Composer treats a `false` script-callback return as a failed script: non-zero exit, no PHP exception box or usage dump). Any other exception propagates unchanged. `buildRunner()`/`buildRunnerFromContext()` — between them these are the only place that constructs an `EventContext`, a `fromProjectRoot(getcwd())` switcher, a `Utils\ComposerProcess` and a `Utils\OutcomeRenderer`, wiring all four into the runner. `ConfigSwitcher` itself never executes Composer or prompts the user; `SwitchCommandRunner` is the only place that does (see `SwitchCommandRunner` below). `composerSwitchDescribe()` builds an `EventContext` and renders `describe()`'s snapshot through `OutcomeRenderer::renderDescription()`; `composerSwitchDescribeJson()` writes `describe()->toJSON()` through the same `EventContext`.

#### Constructor

```php
public function __construct(ConfigFile $mainFile, ConfigFile $prodFile, ConfigFile $devConfig)
```

- `$mainFile` — The main `composer.json` (mutable working copy).
- `$prodFile` — The production config file (e.g. `composer-prod.json`).
- `$devConfig` — The dev config file containing the `local-repositories` list.

#### Methods

```php
public function getFileSystem(): FileSystem
public function setFlagFileEnabled(bool $enabled): self
public function setWriteToConsole(bool $write): self
public function getMainFile(): ConfigFile
public function getProjectRoot(): string
public function getDevFile(): ConfigFile
public function getProdFile(): ConfigFile
public function getStatus(): StatusFile
public function installGitHooks(string $projectRoot): bool
public function switchUpdate(): SwitchOutcome
public function switchToDevelopment(): SwitchOutcome
public function switchToProduction(): SwitchOutcome
public function switchTo(string $mode, bool $dryRun = false): SwitchOutcome
public function describe(): SwitchDescription
public function previewSwitch(string $mode): SwitchOutcome
public function getFlagFile(string $mode): FlagFile
public function displayMessages(): self
public function setDisplayMessages(bool $display): self
public function isDisplayMessagesEnabled(): bool
public function getMessages(): array
public function getMessageTexts(): array
```

- `switchTo(string $mode, bool $dryRun = false)` (and its `switchToDevelopment()`/`switchToProduction()` wrappers) runs the v3 decision table's single post-dispatch planner, `switch_plan()`: it always runs legacy-artifact cleanup first (every switch, regardless of direction — see `switch_cleanLegacyArtifacts()` and `MESSAGE_LEGACY_FILES_FOUND`), then dispatches to exactly one of `switch_planProdToDev()`/`switch_planDevRefresh()`/`switch_planDevToProd()`/`switch_planProdToProd()` based on the current status and the target mode (see [switching-decision-table.md](switching-decision-table.md) for the full row-by-row mapping). A plan can be blocked by a precondition (a `Stale` PROD lock, or a modified/missing production snapshot) — a blocked plan has no file effects, only messages, and the returned `SwitchOutcome::isBlocked()` is `true`. An unblocked plan writes its own file effects, computes a `ConfigChangeSet`, and plans at most one `ComposerCommand` — `ConfigSwitcher` itself never executes Composer. A missing lock file no longer aborts the switch: it is a recoverable situation (e.g. a freshly cloned project), so a PROD/INITIAL→DEV switch with no main lock completes in full and only records `MESSAGE_NO_LOCK_FILE_FOUND` as a warning, planning a full `update` instead of a partial one. `switchUpdate()` propagates whichever of `switchToDevelopment()`/`switchToProduction()` it dispatches to (DEV state → `switchToDevelopment()`; PROD *or* the `INITIAL` state → `switchToProduction()`, which runs the PROD/INITIAL→PROD row rather than a bespoke `MODE_INITIAL` no-op). `$dryRun` sets the facade's dry-run flag (and adds `MESSAGE_DRY_RUN_ACTIVE`) for the duration of the call only, restoring the previous flag value in a `finally` block — so a mid-switch exception can never leave the switcher stuck in dry-run mode.
- `describe()` assembles a `SwitchDescription` (see below) covering mode, last switch date, per-file existence/modification-date records for all four configs and three lock files, flag-file presence per mode, `lockStatus`/`installedState`, `pendingProdChanges` (DEV only), and the parsed `local-repositories` list (each entry now also carrying a `derivedVersion`). It never throws: a missing/malformed dev config, an unreadable/modified production snapshot, or an unreadable `installed.json` all degrade to `null`/`Unknown`/an empty repository list plus a warning recorded on the returned `SwitchDescription` instead. Legacy v2 artifacts (a leftover `local-repositories.lock`, or a `composer-prod.json` found outside an active DEV session) are also surfaced as warnings, read-only — `describe()` never cleans them up itself. A `version` override that violates its package's root constraint is likewise surfaced as a `MESSAGE_VERSION_OVERRIDE_CONFLICT` warning (via `describe_versionConflictWarnings()`, reusing `DevConfigTransformer::apply()`'s `getVersionConflicts()`), non-blockingly — unlike the same check in `switch_planProdToDev()`/`switch_planDevRefresh()`, which blocks the switch.
- `previewSwitch(string $mode)` validates `$mode` then calls `switchTo($mode, true)` with message display suppressed, returning the resulting `SwitchOutcome` without writing anything to disk. Because it runs the exact same code path as a real switch (under the `FileSystem` dry-run overlay), its `getOperations()`/`getComposerCommand()`/`getConfigChanges()` are guaranteed to match what a real switch from the same starting state would perform, including from the `INITIAL` state. A `previewSwitch(MODE_PROD)` call in PROD mode runs `switch_planProdToProd()` under the dry-run overlay — that row has no file effects of its own beyond legacy cleanup/status/flags, since `composer-prod.json`/`.lock` are a transient DEV-session snapshot rather than a committed baseline to compare against — so previewing PROD→PROD only ever plans (or skips) an `install`.
- `getProjectRoot()` returns `dirname($this->getMainFile()->getPath())` — the single derivation of the project root directory, used wherever a working directory is needed for shelling out to Composer or resolving relative paths (`buildRunnerFromContext()`, `composerSwitchDescribe()`, `switch_installedPackages()`, and `Utils\SwitchCommandRunner::getWorkingDir()` all call it instead of repeating the `dirname()` call).
- `getMessages()` returns `SwitchMessage[]` — each message pairs free-form text with a `MESSAGE_*` code (see `SwitchMessage` below).
- `getMessageTexts()` returns `string[]`, the text-only projection of `getMessages()`; used internally by `displayMessages()`.
- `setDisplayMessages(bool $display)` toggles automatic message display on the console after a switch completes (enabled by default). This is independent of `setWriteToConsole()`, which controls the verbose step-by-step `ConsoleWriter` output during the switch itself. `isDisplayMessagesEnabled()` is the matching getter — `Utils\SwitchCommandRunner::runSwitch()` reads it before its own real `switchTo()` call, disables it for that single call, and restores the saved value in a `finally` block, so the switcher's legacy echo channel never duplicates what the runner's `Utils\OutcomeRenderer` already renders.
- `getFileSystem()` returns the single `FileSystem` facade (see `FileSystem` below) this switcher shares with every file it owns (`$mainFile`, `$prodFile`, `$devFile`, `$statusFile`, and every `FlagFile` returned by `getFlagFile()`), propagated to each of them in the constructor via `setFileSystem()`. This is the single write choke-point behind dry-run mode and `SwitchOutcome::getOperations()`.

---

### `ComposerSwitcherException`

> `src/ComposerSwitcherException.php` — Extends `\Exception`.

#### Error Code Constants

```php
public const ERROR_DEV_FILE_MISSING = 182101;
public const ERROR_CANNOT_DECODE_JSON = 182102;
public const ERROR_INVALID_JSON_STRUCTURE = 182103;
public const ERROR_CANNOT_ENCODE_JSON = 182104;
public const ERROR_CANNOT_WRITE_FILE = 182105;
public const ERROR_CANNOT_DELETE_FILE = 182106;
public const ERROR_CANNOT_COPY_FILE = 182107;
public const ERROR_CANNOT_READ_FILE = 182108;
public const ERROR_CANNOT_GET_MODIFIED_DATE = 182109;
public const ERROR_INVALID_SWITCH_MODE = 182110;

public const ERROR_COMPOSER_COMMAND_FAILED = 182112;
public const ERROR_COMPOSER_BINARY_NOT_FOUND = 182113;
public const ERROR_SWITCH_BLOCKED = 182114;
public const ERROR_CONFIRMATION_REQUIRED = 182115;
public const ERROR_INPUTS_CHANGED = 182116;
```

> `182111` (`ERROR_INVALID_RECONCILE_DIRECTION`) is retired along with the `reconcile()` family that threw it — the number is not reused. `182112`–`182116` are the entry-point edge's own codes, thrown by `Utils\SwitchCommandRunner`/`Utils\ComposerProcess` (never by `ConfigSwitcher::switchTo()`/`previewSwitch()` themselves): `ERROR_COMPOSER_COMMAND_FAILED` when the executed `composer` command exits non-zero (context: `KEY_COMMAND`/`KEY_EXIT_CODE`) or the child process fails to start; `ERROR_COMPOSER_BINARY_NOT_FOUND` when neither `COMPOSER_BINARY` nor an executable `composer` on `PATH` could be resolved; `ERROR_SWITCH_BLOCKED` when the first preview reports `isBlocked()`; `ERROR_CONFIRMATION_REQUIRED` when a non-interactive run needs confirmation but was not given `--yes`; `ERROR_INPUTS_CHANGED` when the post-confirmation preview no longer `hasSameEffectsAs()` the one shown to the user.

#### Context Keys

```php
public const KEY_FILE_PATH = 'filePath';
public const KEY_TARGET_PATH = 'targetPath';
public const KEY_MODE = 'mode';
public const KEY_EXPECTED = 'expected';
public const KEY_ACTUAL = 'actual';
public const KEY_PACKAGE_NAME = 'packageName';
public const KEY_NATIVE_ERROR = 'nativeError';
public const KEY_COMMAND = 'command';
public const KEY_EXIT_CODE = 'exitCode';
```

#### Context Methods

```php
public function setContext(array $context): self
public function getContext(): array
public function getContextValue(string $key): mixed
```

`setContext()` attaches structured data to an exception instance in addition to its free-form message, without changing the inherited `Exception` constructor signature. `getContextValue()` returns `null` for an absent key. Every throw site in the library attaches context using these keys: `KEY_FILE_PATH` (the file being read/written/deleted/checked, or the copy source), `KEY_TARGET_PATH` (a copy's destination, paired with `KEY_FILE_PATH` as the source), `KEY_MODE` (the offending `switchTo()` mode string), `KEY_EXPECTED`/`KEY_ACTUAL` (an offending value paired with what was expected — `KEY_EXPECTED` alone for a validation throw, both together for a type/shape mismatch), `KEY_PACKAGE_NAME` (the local-repository package name being processed, when available), `KEY_NATIVE_ERROR` (the captured native PHP error message for every real-mode `FileSystem`/`ComposerProcess` failure — the same error is also chained as the exception's `getPrevious()`), and `KEY_COMMAND`/`KEY_EXIT_CODE` (the shell-rendered Composer command and its exit code, on `ERROR_COMPOSER_COMMAND_FAILED`).

---

## `Mistralys\ComposerSwitcher\State`

### `ComposerCommand`

> `src/State/ComposerCommand.php` — Immutable value object describing a single planned Composer invocation.

```php
public function __construct(array $arguments, string $reason)
public function getArguments(): array
public function getReason(): string
public function toArray(): array
public static function fromArray(array $data): self
public function toShellString(): string
public function toDisplayString(): string
```

`$arguments` is the command's argument list, excluding the `composer` binary itself — the caller decides how to locate and invoke it. `$reason` is a human-readable explanation of why this command is being run. `toArray()`/`fromArray()` round-trip `array{arguments:string[],reason:string}`. `toShellString()` renders the argument list (still without the binary) as a shell-escaped string, each argument independently `escapeshellarg()`-escaped. `toDisplayString()` renders the same argument list for a human-readable console line (e.g. `update vendor/pkg`) instead of a shell-safe one: a plain argument is left bare, and only an argument containing whitespace or a shell metacharacter (or an empty string) is wrapped in single quotes — unlike `toShellString()`, which quotes every argument unconditionally.

---

### `InstalledState` (enum)

> `src/State/InstalledState.php` — Whether what is actually installed (`vendor/composer/installed.json`) matches what the active mode expects.

```php
enum InstalledState: string
{
    case Matches = 'matches';
    case Pending = 'pending';
    case Unknown = 'unknown';
}

public static function fromInstalledPackages(string $mode, array $localPackageNames, InstalledPackages $installed): self
```

In DEV mode, `Matches` means every local package is installed from its path repository; in PROD mode, `Matches` means none is. `Pending` means an `install`/`update` is needed to bring the installed state in sync with the active mode. `Unknown` means whether a local package is path-installed could not be determined (`installed.json` missing or malformed) — `fromInstalledPackages()` escalates straight to `Unknown` the moment any per-package check returns `null`, never throwing. An empty `$localPackageNames` list always resolves to `Matches`, since there is nothing for either mode to be out of sync about. This is "mode describes what is installed", not just what the config says.

---

### `LockStatus` (enum)

> `src/State/LockStatus.php` — Backed string enum describing a lock file's freshness relative to its config file.

```php
enum LockStatus: string
{
    case Missing = 'missing';
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Unknown = 'unknown';
}

public function requiresUpdate(): bool
```

Four cases: `Missing` (no lock file at all), `Fresh` (the lock's content hash still matches its config — Composer's own `Locker::isFresh()` would return `true`), `Stale` (the content hash no longer matches), and `Unknown` (freshness could not be determined, e.g. an unreadable or malformed lock file). `requiresUpdate()` returns `true` only for `Stale` — `Missing` and `Unknown` are handled by dedicated recovery paths elsewhere in the switching model rather than being treated as "needs an update".

---

### `LocalRepository`

> `src/State/LocalRepository.php` — Immutable value object for a single `local-repositories` entry (package name, local checkout path, optional version override).

```php
public function __construct(string $packageName, string $path, ?string $versionOverride = null)
public function getPackageName(): string
public function getPath(): string
public function getVersionOverride(): ?string
public function hasVersionOverride(): bool
public function getVersion(): string
public function toArray(): array
public static function fromArray(array $data): self
public static function parseList(array $data, string $filePath): array
public static function parseListLenient(array $data, string $filePath): array
```

The single parser behind what used to be two duplicated, hand-rolled readers of the DEV configuration file's `local-repositories` list. `getVersion()` returns the override when present, or Composer's wildcard `*` otherwise. `toArray()`/`fromArray()` round-trip `array{packageName:string,path:string,versionOverride:string|null}` for status-file persistence. `parseList(array $data, string $filePath)` strictly parses the list, throwing `ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE` (with `KEY_FILE_PATH`/`KEY_EXPECTED` context for a missing/invalid list key, or `KEY_FILE_PATH`/`KEY_PACKAGE_NAME` for a malformed entry) — this is the same error code and context keys `DevConfigTransformer::apply()`'s callers (`ConfigSwitcher::switch_planProdToDev()`/`switch_planDevRefresh()`, see `Utils\DevConfigTransformer` below) use, via `switch_requireCurrentLocalRepositories()`, to validate the DEV config's `local-repositories` list before handing the parsed `LocalRepository[]` to `apply()`. `parseListLenient(array $data, string $filePath)` never throws: returns `array{0:self[],1:string[]}`, producing a single list-level warning on a missing/invalid list key, or skipping each malformed entry with its own warning while keeping the rest — this is what `ConfigSwitcher::describe_readLocalRepositories()` now delegates to. Both parsers share one private `isValidEntry()` check (an array with string `package-name` and `path` keys; a non-string or absent `version` simply means no override), so the two call sites can no longer silently drift on what counts as a valid entry.

---

### `DevTransformResult`

> `src/State/DevTransformResult.php` — Immutable result of `Utils\DevConfigTransformer::apply()`.

```php
public const SOURCE_OVERRIDE = 'override';
public const SOURCE_LOCKED = 'locked';
public const SOURCE_NONE = 'none';

public function __construct(array $config, array $versionDerivations, array $versionConflicts = array())
public function getConfig(): array
public function getVersionDerivations(): array
public function getVersionConflicts(): array
public function hasVersionConflicts(): bool
```

Holds the DEV-style `composer.json` data `apply()` produced, plus a fact per local package (`array{packageName:string,version:string|null,source:string}`) recording which version it was aliased to and where that alias came from — `SOURCE_OVERRIDE` (the local repository entry's explicit `version`), `SOURCE_LOCKED` (derived from the PROD lock's locked version for that package), or `SOURCE_NONE` (neither available; the package falls back to Composer's wildcard `*`). This is the data `ConfigSwitcher::switch_recordVersionDerivations()` turns into `MESSAGE_VERSION_DERIVED` messages. `getVersionConflicts()` returns `array<int,array{packageName:string,version:string,constraint:string,section:string,check:ConstraintCheck,lockedVersion:string|null}>` — one entry per `SOURCE_OVERRIDE` alias whose `ConstraintCheck::blocks()` against the package's root `require`/`require-dev` constraint; empty when every override is satisfied, unverifiable, or there is no root constraint to check. A `SOURCE_LOCKED` alias is never checked, since it was derived from the PROD lock itself and cannot conflict with the constraint that produced it. `hasVersionConflicts()` is `count(getVersionConflicts()) > 0`. `ConfigSwitcher::switch_reportVersionConflicts()` turns these into `MESSAGE_VERSION_OVERRIDE_CONFLICT` warnings and uses the return value to block the switch before any write (see `ConfigSwitcher` above).

---

### `RevertResult`

> `src/State/RevertResult.php` — Immutable result of `Utils\DevConfigTransformer::revert()`.

```php
public function __construct(array $effectiveConfig, array $overrides)
public function getEffectiveConfig(): array
public function getOverrides(): array
public function getOverriddenManagedEntries(): array
public function hasOverriddenManagedEntries(): bool
```

`getEffectiveConfig()` is the three-way-merged `composer.json` data: every DEV-time edit to a non-managed key/package/repository carried back, every managed (local package) entry restored to its snapshot value. `getOverrides()` is `ManagedEntryOverride[]` (see below) — one entry per managed package the user edited anyway in the live DEV config, which `revert()` discards in favor of the snapshot value rather than silently applying; a package moved between `require` and `require-dev` during DEV is a single entry with `isMove(): true`, never two. `getOverriddenManagedEntries()` is `@deprecated 3.3.0` — a read-only adapter deriving the legacy `array<int,array{section:string,packageName:string,snapshotValue:mixed,currentValue:mixed}>` shape from `getOverrides()`, one legacy entry per override (so a move still yields one legacy entry, unlike pre-3.3.0 versions of this method). `hasOverriddenManagedEntries()` is `count(getOverrides()) > 0`.

---

### `ManagedEntryOverride`

> `src/State/ManagedEntryOverride.php` — Immutable value object for a single managed (local package) entry `Utils\DevConfigTransformer::revert()` discards in favor of the snapshot value.

```php
public function __construct(string $packageName, string $section, mixed $snapshotValue, mixed $currentValue, ?string $currentSection = null)
public function getPackageName(): string
public function getSection(): string
public function getSnapshotValue(): mixed
public function getCurrentValue(): mixed
public function getCurrentSection(): ?string
public function isMove(): bool
public function getAffectedSections(): array
public function toArray(): array
public static function fromArray(array $data): self
```

Represents one of three cases: an in-place edit (`currentSection === section`, value changed), a removal (`currentSection === null`, the entry disappeared from the live DEV config), or a move (`currentSection` differs from `section` — the entry reappeared, edited or not, under a different section). `getSection()` is always the section the package is managed under in the snapshot (the section the override is reported against); `getCurrentSection()` is where it currently lives, or `null` for a removal. `isMove()` is `$currentSection !== null && $currentSection !== $section`. `getAffectedSections()` is `[$section, $currentSection]` for a move, `[$section]` otherwise — `Utils\DevConfigTransformer::makeOriginClassifier()` uses this to keep both diff paths of a moved package classified `Discarded`. `toArray()`/`fromArray()` round-trip the `ManagedEntryOverrideShape` (`array{packageName:string,section:string,snapshotValue:mixed,currentValue:mixed,currentSection:string|null}`).

---

### `MessageLevel` (enum)

> `src/State/MessageLevel.php` — Backed string enum: a `SwitchMessage`'s severity, carried as data instead of the former `WARNING:` text-prefix convention.

```php
enum MessageLevel: string
{
    case Detail = 'detail';
    case Info = 'info';
    case Warning = 'warning';
}
```

`Detail`: bookkeeping information only shown at increased verbosity (e.g. "Using Composer DEV configuration.", "Rebuilt a fresh DEV `composer.json`.", the dry-run notice, the per-package version-derivation line). `Info`: ordinary, always-visible information about what the switch did. `Warning`: a message that previously carried the free-form `WARNING:` text prefix — a non-fatal condition worth the user's attention (e.g. a missing lock file, a modified snapshot). This lets a console renderer both filter (`Detail` hidden unless verbose) and style (`Warning`) without parsing free-form message text.

---

### `SwitchMessage`

> `src/State/SwitchMessage.php` — Immutable value object returned by `ConfigSwitcher::getMessages()`.

```php
public function __construct(int $code, string $text, MessageLevel $level = MessageLevel::Info)
public function getCode(): int
public function getText(): string
public function getLevel(): MessageLevel
public function hasCode(): bool
public function __toString(): string
public function toArray(): array
```

Pairs a switch message's text with a `ConfigSwitcher::MESSAGE_*` code and a `MessageLevel` severity (defaulting to `Info` when not supplied). A code of `0` is treated as "no code" (`hasCode()` returns `false`); `toArray()` returns `array{code:int,text:string,level:string}` (`level` as the enum's `->value`). For a message that previously carried the free-form `WARNING:` text prefix, `getText()` still returns that exact pre-existing text (including the literal prefix) unchanged — only `getLevel()` (`Warning`) carries the severity as data now; no existing `getText()` call site needs to change to keep working.

---

### `SwitchOutcome`

> `src/State/SwitchOutcome.php` — Immutable value object returned by `ConfigSwitcher::switchTo()` (and its `switchToDevelopment()`/`switchToProduction()`/`switchUpdate()`/`previewSwitch()` wrappers).

```php
public function __construct(string $mode, bool $dryRun, array $messages, array $operations, ?ComposerCommand $composerCommand = null, bool $blocked = false, ?ConfigChangeSet $configChanges = null, ?string $previousMode = null)
public function getMode(): string
public function getPreviousMode(): ?string
public function isDryRun(): bool
public function getComposerCommand(): ?ComposerCommand
public function isBlocked(): bool
public function getConfigChanges(): ConfigChangeSet
public function requiresConfirmation(): bool
public function hasSameEffectsAs(self $other): bool
public function getMessages(): array
public function getMessageTexts(): array
public function getOperations(): array
public function hasOperations(): bool
public function toArray(): array
```

Summarizes a single switch/preview result: the target mode, whether it was a dry run, the messages and file operations produced along the way, the single `ComposerCommand` (if any) the caller should run next, whether the switch was blocked, the `ConfigChangeSet` describing what changed, and the previous mode the switch transitioned from. `$messages` is `SwitchMessage[]`; `$operations` is `FileOperation[]` (see `FileOperation` below). `$composerCommand`, `$blocked`, `$configChanges` and `$previousMode` all default so every pre-existing call site keeps compiling unchanged; `getConfigChanges()` returns an empty `ConfigChangeSet` (`new ConfigChangeSet(array(), array())`) when none was supplied at construction. `getPreviousMode()` returns the mode (`MODE_INITIAL`/`MODE_PROD`/`MODE_DEV`) the switcher was in immediately before this switch, or `null` when unknown — only `ConfigSwitcher::switchTo()` supplies it; a legacy or hand-built outcome has no transition to name, and `OutcomeRenderer::describeTransition()` falls back to the old `to DEV` header wording in that case. `isBlocked()` reports whether this switch was blocked by a precondition — a blocked outcome carries no file effects, only messages. `requiresConfirmation()` is `true` exactly when `getConfigChanges()->getComposerJsonChanges()` is non-empty (i.e. `composer.json` itself would be rewritten). `hasSameEffectsAs(self $other)` is the "shown equals applied" check: it compares `isBlocked()`, `getConfigChanges()->toArray()`, `getComposerCommand()?->toArray()` (or both `null`) and every file operation's type/source/target — messages are deliberately excluded, since a message's wording (e.g. a derived-version fact) can differ without the switch's actual effect changing. `getMessageTexts()` is the text-only projection of `getMessages()`. `hasOperations()` returns `true` when at least one file operation was recorded (applied or dry-run-planned). `toArray()` returns `array{mode:string,dryRun:bool,messages:array<int,array{code:int,text:string,level:string}>,operations:array<int,array{type:string,target:string,source:string|null,reason:string,applied:bool}>,composerCommand:array{arguments:string[],reason:string}|null,blocked:bool,configChanges:array{composerJson:array<int,array<string,mixed>>,prodConfig:array<int,array<string,mixed>>},previousMode:string|null}` — each `messages` entry is a `SwitchMessage::toArray()` result, now including the `level` key (see `MessageLevel`/`SwitchMessage` above).

---

### `ConfigChange`

> `src/State/ConfigChange.php` — Immutable record of a single structural difference between two `composer.json`-shaped arrays, produced by `Utils\ConfigDiff::between()`.

```php
public const KIND_ADDED = 'added';
public const KIND_REMOVED = 'removed';
public const KIND_CHANGED = 'changed';

public function __construct(array $path, mixed $before, mixed $after, string $kind, ConfigChangeOrigin $origin)
public function getPath(): array
public function getPathString(): string
public function getBefore(): mixed
public function getAfter(): mixed
public function getKind(): string
public function getOrigin(): ConfigChangeOrigin
public function isVersionChange(): bool
public function toArray(): array
```

`$path` is the leaf path as segments (e.g. `['require', 'vendor/pkg']`); `getPathString()` renders it joined with `' › '`. `$before`/`$after` are `null` for an added/removed entry respectively. `isVersionChange()` is `true` for a `require`/`require-dev` package path, or for a `repositories` path whose entry (content-based, not path-based, since list entries are diffed whole-item rather than recursed into) carries an `options.versions` alias map. `toArray()` returns `array{path:string[],before:mixed,after:mixed,kind:string,origin:string}`.

---

### `ConfigChangeOrigin` (enum)

> `src/State/ConfigChangeOrigin.php` — Why a given `ConfigChange` exists, from the switcher's own point of view.

```php
enum ConfigChangeOrigin: string
{
    case LocalSwitch = 'local-switch';
    case CarriedBack = 'carried-back';
    case Discarded = 'discarded';
}
```

`LocalSwitch`: an entry the switcher itself manages (a local package's require/require-dev constraint or its path repository entry, written by `Utils\DevConfigTransformer::apply()`, or a pre-existing VCS entry `apply()` prunes/replaces for it). `CarriedBack`: a DEV-time edit to a non-managed entry becoming — or already being — permanent, carried back by `Utils\DevConfigTransformer::revert()`. `Discarded`: a DEV-time edit to a managed entry, reset back to its snapshot value by `revert()` rather than kept. The classifier `Utils\DevConfigTransformer::makeOriginClassifier()` produces never returns `Discarded` for its `repositories` branch — only `LocalSwitch`/`CarriedBack` — since `apply()` always regenerates managed path entries wholesale rather than ever carrying a DEV-time edit to one back; this asymmetry with the `require`/`require-dev` branch is intentional, not a bug.

---

### `ConfigChangeSet`

> `src/State/ConfigChangeSet.php` — Immutable set of `ConfigChange` records for a single switch, split into two sections answering two different questions.

```php
public function __construct(array $composerJson, array $prodConfig)
public function getComposerJsonChanges(): array
public function getProdConfigChanges(): array
public function isEmpty(): bool
public function hasPermanentChanges(): bool
public function getProdChangedKeys(): array
public function getProdChangedPackages(): array
public function toArray(): array
```

`composerJson`: what changes in `composer.json` itself — present in every switch that writes the file (on a DEV switch this is mostly the DEV transform being applied/refreshed, which is routine). `prodConfig`: what changes permanently in production (snapshot → effective production config) — only meaningful on a DEV→PROD switch (or `describe()`'s pending-carry-back view in DEV); this is the part that needs a deliberate decision, which is why it has its own section rather than being folded into `composerJson`. `isEmpty()` is `true` when both sections are empty. `hasPermanentChanges()` is `count($prodConfig) > 0` — the condition a confirmation prompt should default to "no" for. `getProdChangedKeys()` returns the top-level `composer.json` keys that differ in the `prodConfig` section; `getProdChangedPackages()` returns the root package names added, removed or re-constrained in `require`/`require-dev`, in the `prodConfig` section — both are derived from `prodConfig` rather than kept as a separate production-change type. `toArray()` returns `array{composerJson:array<int,array<string,mixed>>,prodConfig:array<int,array<string,mixed>>}`.

---

### `SwitchDescription`

> `src/State/SwitchDescription.php` — Immutable value object returned by `ConfigSwitcher::describe()`.

```php
public function __construct(?string $mode, ?string $lastSwitchDate, array $files, ?string $activeFlag, LockStatus $lockStatus, InstalledState $installedState, ?ConfigChangeSet $pendingProdChanges, array $localRepositories, array $warnings)
public function getMode(): ?string
public function getLastSwitchDate(): ?string
public function getFiles(): array
public function getActiveFlag(): ?string
public function hasActiveFlag(): bool
public function getLockStatus(): LockStatus
public function getInstalledState(): InstalledState
public function getPendingProdChanges(): ?ConfigChangeSet
public function hasPendingProdChanges(): bool
public function getLocalRepositories(): array
public function getWarnings(): array
public function hasWarnings(): bool
public function toArray(): array
public function toJSON(): string
```

A read-only snapshot of the switcher's current state, assembled entirely by `describe()` — this class only holds already-gathered data and exposes it through typed getters. `$files` is `array<int,array{label:string,path:string,exists:bool,modifiedDate:string|null}>` covering the main/prod/dev configs, the status file, and all three lock files. `getActiveFlag()` returns the mode (`dev`/`prod`) whose flag file currently exists, or `null` if neither does. `getLockStatus()` is the main lock's own `LockStatus` (see `LockStatus` above) against its own config — not a comparison between two configs. `getInstalledState()` is the `InstalledState` (see `InstalledState` above) of what is actually installed versus what the active mode expects. `getPendingProdChanges()` is the DEV-time edits that would become permanent on a DEV→PROD switch — only its `prodConfig` section is ever filled, computed via the same `DevConfigTransformer::revert()` + `ConfigDiff` pipeline a real DEV→PROD switch uses (sharing `DevConfigTransformer::makeOriginClassifier()`), so it always matches what a real switch would carry back. It is `null` outside DEV mode, or when it could not be computed (a missing/modified/malformed snapshot) — degraded rather than thrown, with the reason added to `getWarnings()`. `hasPendingProdChanges()` is `$pendingProdChanges !== null && $pendingProdChanges->hasPermanentChanges()`. `$localRepositories` is `array<int,array{packageName:string,path:string,version:string,derivedVersion:string|null}>`, the parsed DEV configuration's `local-repositories` list, each entry's `derivedVersion` being its `versionOverride` if set, otherwise the reference lock's (the prod snapshot's lock in DEV, the main lock otherwise) locked version for that package. `getWarnings()`/`hasWarnings()` surface non-fatal issues encountered while assembling the description — e.g. a missing or malformed dev config file (degrades to an empty repository list), an uncomputable `pendingProdChanges`, or a legacy v2 artifact found on disk (surfaced read-only, never cleaned up by `describe()` itself) — instead of throwing. `toArray()` returns `array{mode:string|null,lastSwitchDate:string|null,files:array,activeFlag:string|null,lockStatus:string,installedState:string,pendingProdChanges:array{composerJson:array<int,array<string,mixed>>,prodConfig:array<int,array<string,mixed>>}|null,localRepositories:array,warnings:string[]}`, with `lockStatus`/`installedState` as their enum's `->value` and `pendingProdChanges` as `->toArray()` or `null`. `toJSON()` encodes `toArray()` with `JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.

---

### `FileOperation`

> `src/State/FileOperation.php` — Immutable value object describing a single file operation performed, or planned in dry-run mode, during a switch.

```php
public const TYPE_COPY = 'copy';
public const TYPE_WRITE = 'write';
public const TYPE_DELETE = 'delete';

public function __construct(string $type, string $targetPath, ?string $sourcePath, string $reason, bool $applied)
public function getType(): string
public function getTargetPath(): string
public function getSourcePath(): ?string
public function getReason(): string
public function isApplied(): bool
public function toArray(): array
```

`$type` is one of the `TYPE_*` constants. `$sourcePath` is `null` for a write or delete (there is no source file to copy from). `isApplied()` is `false` when the operation was only planned (dry-run mode) rather than actually performed. `toArray()` returns `array{type:string,target:string,source:string|null,reason:string,applied:bool}`.

---

### `ConstraintCheck` (enum)

> `src/State/ConstraintCheck.php` — Backed string enum: the outcome of checking a `version` alias against a package's root `require`/`require-dev` constraint, as computed by `Utils\VersionConstraintChecker::check()`.

```php
enum ConstraintCheck: string
{
    case Satisfied = 'satisfied';
    case Violated = 'violated';
    case Invalid = 'invalid';
    case Unverifiable = 'unverifiable';
}

public function blocks(): bool
```

`Satisfied`: the version satisfies the constraint. `Violated`: both parsed successfully, but `Composer\Semver\Semver::satisfies()` returned `false`. `Invalid`: the version or constraint could not be parsed by Composer's semver engine (e.g. `not-a-version`), or normalisation failed for a non-`dev-*`-alias version. `Unverifiable`: no semver engine was available (the duck-typed `Composer\Semver\Semver`/`VersionParser` classes are not loaded), or the version is a `dev-*` alias whose normalisation failed — Composer accepts alias forms this checker cannot prove malformed, so this never blocks. `blocks()` is `true` only for `Violated`/`Invalid` — the two results a switch writing a `version` override should refuse to proceed past; `Unverifiable` never blocks, matching current (pre-check) behavior when no engine is available.

---

## `Mistralys\ComposerSwitcher\Utils`

### `InstalledPackages` extends `BaseFile`

> `src/Utils/InstalledPackages.php` — Reads `<vendor-dir>/composer/installed.json` (Composer 2's installed-package manifest) through `FileSystem`.

```php
public function __construct(string $projectRoot, array $configData)
public function isInstalledFromPath(string $packageName): ?bool
```

Tells the planning layer whether a given package is *actually* installed from a local path repository — as opposed to merely being configured as one in the DEV config, which says nothing about whether `composer install`/`update` has run since. The vendor dir is resolved from `config.vendor-dir` in the supplied `composer.json` data (default `vendor`). Never throws: `isInstalledFromPath()` returns `true`/`false` when known (`false` for a package simply absent from the list — a definitively known fact), and `null` only when `installed.json` itself is missing, unreadable, or not valid JSON (an indeterminate fact) — this is a read-only inspection tool, not a strict validator like `LocalRepository::parseList()`.

---

### `DevConfigTransformer`

> `src/Utils/DevConfigTransformer.php` — Pure, static transform between a PROD-style `composer.json` and its DEV counterpart. No filesystem access of its own beyond the injected `LockFile` read.

```php
public static function apply(array $prodConfig, array $repos, LockFile $prodLock, ?VersionConstraintChecker $checker = null): DevTransformResult
public static function revert(array $devConfig, array $snapshotConfig, array $appliedRepos, LockFile $prodLock): RevertResult
public static function makeOriginClassifier(array $repos, ?RevertResult $revertResult = null): callable
```

`apply(array $prodConfig, LocalRepository[] $repos, LockFile $prodLock, ?VersionConstraintChecker $checker = null)` builds the DEV `composer.json` data: for each local repository, the alias version is the explicit `versionOverride`, else the PROD lock's locked version for that package (`LockFile::getLockedVersion()`), else `null`. The root `require`/`require-dev` constraint is read (before being overwritten in the same pass) and kept from PROD when the package is already root-required there and an alias was derived; otherwise it is overwritten with `*`. Placement (`require` vs `require-dev`) always follows the PROD baseline. `$checker` (defaulting to a new `VersionConstraintChecker` instance when omitted) validates every `SOURCE_OVERRIDE` alias against that same root constraint, populating `DevTransformResult::getVersionConflicts()` per violating/unparseable override — `apply()` itself never blocks on a conflict, it only reports; the caller (`ConfigSwitcher::switch_reportVersionConflicts()`) decides whether to block. A `SOURCE_LOCKED` alias, or a package with no root constraint, is never checked. The path repository entry's `options.versions` carries the alias (with an underscore→hyphen duplicate of the package name, since a repository URL may use either), and is omitted entirely when no alias could be derived. Repository matching replaces the first entry whose URL matches the package name, prunes further matches as stale duplicates, or appends a new entry when none match — ported verbatim (including the private `urlMatchesPackageName()` boundary check: a match must be followed by `.`, `/`, or end-of-string) from the former `ConfigSwitcher::switch_adjustConfigForDev()`/`urlMatchesPackageName()`, which no longer exist on `ConfigSwitcher`. Called from `switch_planProdToDev()` (PROD/INITIAL→DEV) and `switch_planDevRefresh()` (DEV→DEV refresh, on the `revert()` result's effective config) — both v3 decision-table rows, replacing the single former `switch_applyDevTransform()`/`switch_case_DEV_DEV()` call sites.

`revert(array $devConfig, array $snapshotConfig, LocalRepository[] $appliedRepos, LockFile $prodLock)` is `apply()`'s three-way inverse — base = `apply($snapshotConfig, $appliedRepos, $prodLock)`, current = the live `$devConfig`, target = `$snapshotConfig`. A top-level key (other than `require`/`require-dev`/`repositories`) where current equals base takes the snapshot value; a key `apply()` never touches takes the current value, carrying a user edit back. `require`/`require-dev` are merged per package via the private `revertSection()` (a pure per-section merge): a managed (local) package always takes the snapshot value — or is dropped entirely when the snapshot has none; every other package takes the current value, so `composer require`/`remove` additions, removals and re-constraints are carried back. Override detection is a separate pass, the private `detectManagedOverrides()`, run once per managed package across both sections rather than once per section — this is what makes a package moved between `require` and `require-dev` during DEV come back as a single `ManagedEntryOverride` with `isMove(): true`, instead of two entries (one per section, as a prior per-section implementation produced). `repositories` becomes the snapshot list plus current entries absent from base, minus base entries the user removed, excluding managed path entries (which `apply()` regenerates on its own and are invisible to this diff). Invariant: `revert(apply($snapshot, $repos, $lock)->getConfig(), $snapshot, $repos, $lock)->getEffectiveConfig()` is value-equal to `$snapshot`. `revert()` is wired into both `switch_planDevRefresh()` (DEV→DEV refresh — its `effective` config feeds back into `apply()`) and `switch_planDevToProd()` (DEV→PROD — its `effectiveConfig` is written straight into `composer.json`, carrying DEV-time edits back into production).

`makeOriginClassifier(array $repos, ?RevertResult $revertResult = null): callable` builds the `callable(string[] $path, mixed $before, mixed $after): ConfigChangeOrigin` that `Utils\ConfigDiff::between()` calls per change to attach its `State\ConfigChangeOrigin`. A `require`/`require-dev` path for one of `$repos`' package names is `ConfigChangeOrigin::Discarded` when `$revertResult` reports it as an overridden managed entry — built from `getOverrides()`, adding one key per `ManagedEntryOverride::getAffectedSections()` entry, so both diff paths of a moved package (the section it moved away from and the one it moved to) stay `Discarded` — otherwise `ConfigChangeOrigin::LocalSwitch`; a `repositories` entry is always `ConfigChangeOrigin::LocalSwitch` when it either matches one of `$repos`' own paths (by exact path equality, see `isManagedRepositoryEntry()` below) or is a pre-existing entry `apply()` prunes/replaces for a managed package (the same `repositoryUrlMatchesPackage()` match `mergeRepositoryEntry()` prunes with, via `isPrunableRepositoryEntry()`) — so a first DEV switch that prunes a managed package's VCS entry is routine, not `[permanent]`. This branch never returns `Discarded`, since `apply()` always regenerates managed path entries wholesale rather than carrying a DEV-time edit to one back. Every other path is `ConfigChangeOrigin::CarriedBack`. The private `isManagedRepositoryEntry()` helper this consumes matches a `repositories` entry against `$repos`' own `getPath()` values by exact equality, not by the package-name-in-URL substring heuristic `urlMatchesPackageName()` uses to find *pre-existing* VCS entries to replace — that heuristic does not generally hold for the `path` URL `apply()` itself writes (a relative/absolute filesystem path rarely contains the package's `vendor/name` string).

---

### `ConfigDiff`

> `src/Utils/ConfigDiff.php` — Structural diff between two `composer.json`-shaped arrays. No filesystem access.

```php
public static function between(array $before, array $after, callable $classifier): array
```

`between(array $before, array $after, callable(string[],mixed,mixed):ConfigChangeOrigin $classifier): ConfigChange[]` recurses associative structures to leaf paths (e.g. `require › vendor/pkg`), reporting an added/removed/changed `ConfigChange` per leaf. The `repositories` key (the only top-level key treated as a list) is compared item by item by value instead of recursed into: an `after` item with no value-equal match in `before` is `ConfigChange::KIND_ADDED`, a `before` item with no value-equal match in `after` is `KIND_REMOVED` — there is no `Changed` kind for a list item, since a modified entry is indistinguishable from a remove-then-add of two different values. `$classifier` is called with each change's path/before/after and returns the `ConfigChangeOrigin` to attach — in practice, `Utils\DevConfigTransformer::makeOriginClassifier()` (see above). This differ runs over the actual before/after arrays a switch writes, rather than having a transform log its own operations, so the shown change set is derived from the real output of every code path — including one that composes `revert()` and `apply()` — instead of a second, hand-maintained record that could drift from what is actually written.

---

### `ComposerProcess`

> `src/Utils/ComposerProcess.php` — Executes a planned `State\ComposerCommand` as a real child process. The only place in the library that actually spawns Composer.

```php
public function __construct(?string $binaryPath = null)
public function run(ComposerCommand $command, string $workingDir): int
```

`$binaryPath` is an explicit Composer binary path, for tests; when omitted, resolved from the `COMPOSER_BINARY` environment variable (set by Composer itself for every script it runs), falling back to the first executable `composer` found on `PATH` (checked via `is_executable()`, not a bare existence check). `run()` spawns `$command` via `proc_open()` with the array argument form (no shell string interpolation) and inherited STDIN/STDOUT/STDERR, so the child's output streams directly to the console; the environment is the current process's `getenv()` plus `COMPOSER_SWITCHER_NESTED=1`, so a `post-update-cmd` hook firing inside the child can detect it is nested and no-op. Returns the child's exit code. Throws `ComposerSwitcherException::ERROR_COMPOSER_BINARY_NOT_FOUND` when no binary can be resolved, or `ERROR_COMPOSER_COMMAND_FAILED` (context: `KEY_COMMAND`, `KEY_NATIVE_ERROR`) when `proc_open()` itself fails to start the process — a non-zero *exit code* from a successfully started process is returned, not thrown; `Utils\SwitchCommandRunner` is what turns that into `ERROR_COMPOSER_COMMAND_FAILED` (context: `KEY_COMMAND`, `KEY_EXIT_CODE`) for the built-in `composer switch-*` commands. Every native call is routed through a private `runNative()` helper mirroring `FileSystem`'s own scoped `set_error_handler()`/`restore_error_handler()` warning capture.

---

### `ConfigChangeFormatter`

> `src/Utils/ConfigChangeFormatter.php` — Turns a single `ConfigChange` into one human-readable line, via a shape-specific private describer, instead of ever printing a raw JSON object. Built with the project root, used by `OutcomeRenderer`.

```php
public const MAX_VALUE_LENGTH = 120;

public function __construct(string $projectRoot)
public function describe(ConfigChange $change): string
public function displayPath(string $path): string
```

`describe()` picks a describer by the change's shape, most to least specific: a `repositories` entry with `type: path` is described in words (package, relative path, symlink state, and the alias version together with the JSON key it was read from — `options.versions`, since a path repository's alias can also appear as the root `require`/`require-dev` constraint, so naming the key disambiguates which value actually governs resolution); a non-path (VCS/other) `repositories` entry is described by its `type` and `url`; a `require`/`require-dev` leaf is `<section> › <package>: <before> → <after>`; any other scalar-to-scalar change is `<path>: <before> → <after>`; anything else (arrays/objects on either side) falls back to a compact, slash-and-unicode-unescaped JSON encoding truncated at `self::MAX_VALUE_LENGTH` with a trailing `…`. No `[version]` marker is added here — a `require`/`require-dev` or path-repository-alias line already reads as a version change by construction; `OutcomeRenderer` only adds a `[permanent]`/`[discarded]` marker for the change's origin. `displayPath(string $path)` returns `.` for the project root itself, a project-relative `./…` path for anything under it, a sibling-relative `../…` path for anything adjacent to it (same parent directory), and the path unchanged otherwise — used for both change descriptions and file operation paths, so no absolute path inside or adjacent to the project root reaches the console.

---

### `OutcomeRenderer`

> `src/Utils/OutcomeRenderer.php` — Renders a `SwitchOutcome` or a `SwitchDescription` as human-readable text, through an injected `EventContext`. Took over the rendering `ConfigSwitcher`'s old `printPreview()`/`formatOperation()` used to own. A stateless layout component: every lifecycle moment takes what it prints as parameters and holds no run state between calls.

```php
public function __construct(EventContext $context, ConfigChangeFormatter $formatter)
public function render(SwitchOutcome $outcome): void
public function renderPlan(SwitchOutcome $outcome, bool $isPreview): void
public function renderBlocked(SwitchOutcome $outcome): void
public function renderCommandStart(ComposerCommand $command): void
public function renderDone(string $modeLabel): void
public function renderFailure(ComposerCommand $command, int $exitCode): void
public function renderChangeSet(ConfigChangeSet $changes): void
public function renderDescription(SwitchDescription $description): void
public function formatOperation(FileOperation $operation, bool $isPreview = true): string
```

Every block shares one header convention: a first line reading `Composer Local Switcher — <suffix>` (e.g. `switch PROD → DEV`, `preview of switch PROD → DEV (nothing will be changed)`, `switch PROD → DEV blocked, nothing was changed`, `running \`composer …\``, `switch to DEV done`, `composer command failed (exit code N)`, `status`). `render()` is a `@deprecated` thin compatibility wrapper over `renderPlan($outcome, $outcome->isDryRun())`, kept only for backward compatibility with external callers — it equates a `SwitchOutcome`'s own `isDryRun()` with "preview", but a real switch's pre-confirmation plan is also computed via a dry run internally, so it wrongly labels that real plan a preview; `SwitchCommandRunner` no longer calls it (see `renderPlanned()` below). `renderPlan(SwitchOutcome $outcome, bool $isPreview)` writes the header (naming the transition via the private `describeTransition()` — see below), the `ConfigChangeSet` (via `renderChangeSet()`), the outcome's messages (via the message-level filtering described below), then the planned command line — labelled `Would run:` when `$isPreview`, `Next:` otherwise — and, only at `-v` (`EventContext::isVerbose()`), every `FileOperation` (via `formatOperation($operation, $isPreview)`). `renderBlocked()` writes the header (also via `describeTransition()`) and the messages only (a blocked outcome has no file effects). The private `describeTransition(SwitchOutcome $outcome)` reads `getPreviousMode()`: `null` renders the legacy `to DEV` wording; a previous mode equal to the target renders `refresh DEV`; otherwise it renders `PROD → DEV` — `ConfigSwitcher::MODE_INITIAL` displays as `MODE_PROD` ("PROD"), an assumption that holds only because the very first switch a project ever makes always starts from the PROD-equivalent initial state. `renderCommandStart()`/`renderDone()`/`renderFailure()` frame a real Composer invocation the caller (`SwitchCommandRunner::runCommand()`) runs around — a start line before the invocation, then either the `done` footer (exit code `0`) or the failure header plus command/reason recap — see `SwitchCommandRunner` below. `renderChangeSet()` renders the `composerJson`/`prodConfig` sections as a grouped, counted list (`composer.json changes (N):`, `Permanent production changes (N):`; a no-op for an empty set), each line built by `ConfigChangeFormatter::describe()` with a `[permanent]` (`ConfigChangeOrigin::CarriedBack`) or `[discarded]` (`ConfigChangeOrigin::Discarded`) marker appended — no `[version]` marker, since that is folded into the description itself. A message is filtered/styled by `MessageLevel`: `Detail` is skipped entirely unless verbose, and only then carries its `[code] ` prefix; `Warning` is marked with a leading `!`; any other level is written plain — every message's text passes through `EventContext::escape()` first, so a dynamic value containing a literal `<` (e.g. a version constraint like `<2.0`) renders literally. `renderDescription()` renders `ConfigSwitcher::describe()`'s snapshot (mode, last switch, active flag, files, lock/installed state, pending production changes via `renderChangeSet()`, local repositories, warnings), paths shown via `ConfigChangeFormatter::displayPath()`. `formatOperation(FileOperation $operation, bool $isPreview = true)` renders a single operation as `would <type>: <source> -> <target> (<reason>)` when `$isPreview` (the default, kept for `render()`'s backward compatibility), or `will <type>: …` otherwise, both paths through `displayPath()` (`-` in place of a missing source). All output goes through `EventContext::write()` — never a bare `echo` — so it flows through Composer's own IO when one is available.

---

### `EventContext`

> `src/Utils/EventContext.php` — The single duck-typing site for Composer's event/IO objects. Not `final`, so a test double can extend it with queued answers.

```php
public static function fromEvent(?object $event): self
public function getArguments(): array
public function hasFlag(string $flag): bool
public function isInteractive(): bool
public function isVerbose(): bool
public function confirm(string $question, bool $default): bool
public function write(string $line): void
public function writeStyled(string $line): void
public function escape(string $text): string
public function writeMessage(int $code, string $text, MessageLevel $level = MessageLevel::Info): void
```

`fromEvent(?object $event)` is the only place in the library that probes an object via `method_exists()` — for `getArguments()`, `getIO()`, `isInteractive()`, `isVerbose()`, `askConfirmation()`, `write()` — building a typed `EventContext` everything downstream consumes instead. A `null` event (or one missing a method) degrades to no arguments and a non-interactive context, never throwing. `getArguments()` returns the event's string-filtered argument list; `hasFlag()` is an `in_array()` check against it (e.g. `--yes`/`--no-install`/`--with-dependencies`, passed after Composer's own `--` separator). `isInteractive()`/`confirm()` delegate to the IO's own methods, defaulting to `false` when there is no IO or no matching method; `isVerbose()` duck-types the IO's `isVerbose()` the same way, `false` when absent. `write()` delegates to the IO's `write()` when available, falling back to a bare `echo … . PHP_EOL`, and never runs the line through Composer's tag formatter — a caller who wants `<info>`/`<comment>`/`<warning>` tags honored, or who is writing a dynamic value that might contain a literal `<`, uses `writeStyled()`/`escape()` instead. `writeStyled(string $line)` delegates to the IO's `write()` exactly like `write()` (so Composer's formatter resolves the tags into terminal styling), but the `echo` fallback strips exactly those three tag names first. `escape(string $text)` escapes a literal `<` (e.g. in a version constraint like `<2.0`) so it renders literally once written — a no-op when there is no IO, since the `echo` fallback never interprets tags anyway. `writeMessage(int $code, string $text, MessageLevel $level = MessageLevel::Info)` is the edge-layer equivalent of `State\SwitchMessage`'s code/text pairing: the `[<code>] ` prefix is only rendered when `isVerbose()` — at default verbosity the line reads as plain human text, with no machine-code noise; `$level` carries no filtering behavior in this class, it exists purely so a test double can record `(code, text, level)` as data without a dedicated recording hook in production code.

---

### `SwitchCommandRunner`

> `src/Utils/SwitchCommandRunner.php` — The only place in the library that executes Composer or prompts the user. Every `composerSwitch*`/`composerSwitchPreview*` static entry point builds one and delegates to it immediately.

```php
public function __construct(EventContext $context, ConfigSwitcher $switcher, ComposerProcess $process, OutcomeRenderer $renderer)
public function runSwitch(string $mode): void
public function runUpdate(): void
public function runPreview(string $mode): void
```

`runSwitch($mode)` runs the full confirmation sequence: no-ops (writing `MESSAGE_NESTED_RUN_SKIPPED`) when `COMPOSER_SWITCHER_NESTED` is set; otherwise previews, renders the pre-confirmation plan via the private `renderPlanned($outcome, false)` — `false` because this is a real switch's own plan, not a preview, so the header names the transition without "preview"/"nothing will be changed" wording and the planned command is labelled `Next:` — throws `ComposerSwitcherException::ERROR_SWITCH_BLOCKED` on a blocked outcome; confirms when `requiresConfirmation()` (`--yes` skips the prompt; an interactive prompt defaults to "no" when the change set has a permanent/discarded entry, "yes" otherwise; a non-interactive context without `--yes` throws `ERROR_CONFIRMATION_REQUIRED`; a decline writes `MESSAGE_SWITCH_CANCELLED` and returns); previews a second time and throws `ERROR_INPUTS_CHANGED` when it no longer `hasSameEffectsAs()` the first; disables the switcher's own `setDisplayMessages()` for the duration of the real `switchTo($mode)` call only, restoring the saved `isDisplayMessagesEnabled()` value in a `finally` block immediately afterward (so the switcher's legacy echo channel never duplicates what this runner's `OutcomeRenderer` already renders); then executes the outcome's planned command unless `--no-install` (writes `MESSAGE_COMPOSER_SKIPPED` instead), appending `--with-dependencies` to a partial `update <packages>` command when requested and `--no-interaction` whenever the parent context is non-interactive. Every human-facing command line (`--no-install` notice, "Running" announcement, confirmation question, exception message) renders the command via `ComposerCommand::toDisplayString()` — unquoted unless an argument needs it — while the thrown exception's `KEY_COMMAND` context value keeps `toShellString()`'s shell-escaped form for a programmatic consumer. The real invocation is framed by `OutcomeRenderer::renderCommandStart()` and either `renderDone()` (exit code `0`) or `renderFailure()` plus `MESSAGE_COMPOSER_RECOVERY` (naming the current mode and both recovery commands) on a non-zero exit code, before throwing `ERROR_COMPOSER_COMMAND_FAILED` (context: `KEY_COMMAND`, `KEY_EXIT_CODE`) — the switch itself is never rolled back automatically. `runUpdate()` resolves the current mode (`getStatus()->isDEV()`) and calls `runSwitch()` with it — mirroring `ConfigSwitcher::switchUpdate()`'s own dispatch, with the same nested-run guard and confirmation sequence. `runPreview($mode)` previews and renders via the private `renderPlanned($outcome, true)` — no nested-run guard (a preview has no side effects to guard), no confirmation, no command execution. The private `renderPlanned(SwitchOutcome $outcome, bool $isPreview)` is this class's single dispatch point for both planning moments: it calls `OutcomeRenderer::renderBlocked($outcome)` when the outcome is blocked, otherwise `renderPlan($outcome, $isPreview)` — `$isPreview` is always the caller's own moment, deliberately never `SwitchOutcome::isDryRun()` (which `OutcomeRenderer::render()` conflates with it, and which this method exists to avoid — see that method's `@deprecated` note). See [data-flows.md](data-flows.md) §7a for the full sequence diagram.

---

### `ComposerContentHash`

> `src/Utils/ComposerContentHash.php` — Computes Composer's own lock-file `content-hash` without depending on Composer's classes.

```php
public static function fromConfigData(array $configData): string
```

An exact mirror of Composer 2.9.5's `Composer\Package\Locker::getContentHash()`: extracts a fixed set of top-level `composer.json` keys (`name`, `version`, `require`, `require-dev`, `conflict`, `replace`, `provide`, `minimum-stability`, `prefer-stable`, `repositories`, `extra`) plus `config.platform`, `ksort()`s the result at the top level only (nested key order is preserved verbatim), and hashes it with `md5(json_encode($relevant, 0))`. Used to detect whether a lock file is stale against a given `composer.json` payload. Irrelevant keys and top-level key reordering do not change the hash; a changed `require` constraint does.

---

### `BaseFile` (abstract)

> `src/Utils/BaseFile.php` — Base file abstraction. Every I/O method delegates to the {@see FileSystem} choke-point (see `FileSystem` below) rather than calling PHP filesystem functions directly.

```php
public function __construct(string $path)
public function setFileSystem(FileSystem $fileSystem): static
public function getFileSystem(): FileSystem
public function getPath(): string
public function getBaseName(): string
public function exists(): bool
public function getModifiedDate(): ?DateTime
public function requireModifiedDate(): DateTime
public function delete(): void
public function getName(): string
public function copyTo(BaseFile $target): void
public function tryCopyTo(BaseFile $target): void
```

`setFileSystem()` replaces the `FileSystem` instance this file performs all of its I/O through — used to propagate a single shared facade (e.g. from `ConfigSwitcher`) to every file instance, so dry-run mode and recorded operations apply consistently across all of them. Every `BaseFile` constructs its own private `FileSystem` by default; without an explicit `setFileSystem()` call, a file's I/O is real and untracked by any shared operation log. `tryCopyTo()` copies only if the source file exists — unlike a plain existence check on the target, this lets a *missing target* be created from an existing source; the target existing beforehand is not required.

---

### `ConfigFile` extends `BaseFile`

> `src/Utils/ConfigFile.php` — JSON config file handler.

```php
public function __construct(string $path)
public function setFileSystem(FileSystem $fileSystem): static
public function getLockFile(): LockFile
public function getData(): array
public function putData(array $data): void
```

`setFileSystem()` overrides `BaseFile::setFileSystem()` to also propagate the facade to the eagerly-constructed `LockFile` returned by `getLockFile()`, since that lock file is created in the constructor before a shared facade can be injected.

---

### `ConsoleWriter`

> `src/Utils/ConsoleWriter.php` — Console output helper.

```php
public function header(string $header, ...$placeholders): void
public function line1(string $line, ...$placeholders): void
public function line2(string $line, ...$placeholders): void
public function line3(string $line, ...$placeholders): void
public function newline(): void
public function separator(): void
public function setEnabled(bool $enabled): void
```

---

### `FlagFile` extends `BaseFile`

> `src/Utils/FlagFile.php` — Mode indicator flag files.

```php
public function __construct(ConfigSwitcher $switcher, string $mode)
public function create(): self
```

The flag file path is derived as `composer.json.DEV` or `composer.json.PROD` (appended to the main file path).

---

### `LockFile` extends `BaseFile`

> `src/Utils/LockFile.php` — Lock file abstraction and reader.

```php
public function __construct(ConfigFile $configFile)
public function getContent(): string
public function getConfigFile(): ConfigFile
public function getContentHash(): ?string
public function getLockStatus(): LockStatus
public function getLockedVersion(string $packageName): ?string
```

The lock file path is derived by replacing `.json` with `.lock` in the parent `ConfigFile` path.

`getContentHash()` returns the lock's own recorded `content-hash`, or `null` when the lock is missing, unreadable, not valid JSON, or has no `content-hash` key. `getLockStatus()` derives the lock's freshness against its own `ConfigFile` by comparing `getContentHash()` with `ComposerContentHash::fromConfigData()` on the config's current data — `LockStatus::Missing` when the lock file does not exist, `LockStatus::Unknown` when either side cannot be determined, `LockStatus::Fresh`/`LockStatus::Stale` otherwise. `getLockedVersion()` searches both `packages` and `packages-dev` for the named package and returns its locked version, or `null` when the package is not locked or the lock is missing/unreadable/malformed. All three go through `BaseFile`'s `FileSystem`-backed I/O (so the dry-run overlay applies), never throw, and never cache — each call re-reads and re-decodes the lock fresh, matching `StatusFile`'s no-caching convention.

---

### `FileSystem`

> `src/Utils/FileSystem.php` — Single choke-point through which every file mutation performed by the library passes. Returned by `ConfigSwitcher::getFileSystem()` and shared by every `BaseFile` instance the switcher owns via `setFileSystem()`.

```php
public function setDryRun(bool $dryRun): self
public function isDryRun(): bool
public function exists(string $path): bool
public function read(string $path): string
public function modifiedTime(string $path): ?DateTime
public function write(string $path, string $content, string $reason = ''): void
public function copy(string $source, string $target, string $reason = ''): void
public function delete(string $path, string $reason = ''): void
public function getOperations(): array
public function clearOperations(): void
```

In real mode, each mutating method (`write()`, `copy()`, `delete()`) performs the corresponding I/O and records an applied `FileOperation`. In dry-run mode (`setDryRun(true)`), those same methods never call a mutating PHP filesystem function — they only update an in-memory overlay (`path => pending content|null`, where `null` marks a pending delete) which `exists()`, `read()`, and `modifiedTime()` consult before falling back to disk; this is what lets a dry run observe its own pending changes (e.g. `copy()` resolves its source through `read()`, so copying a file that was only just pending-written works without touching disk). Enabling or disabling dry-run mode discards any pending overlay state. `delete()` is a no-op (no exception, no recorded operation) when the path does not exist. `getOperations()` returns the accumulated `FileOperation[]` log; `clearOperations()` resets it — `ConfigSwitcher` calls this at the start of every `switchTo()` call so each outcome only reflects that call's own operations. `read()`/`write()`/`copy()`/`delete()` throw `ComposerSwitcherException` (`ERROR_CANNOT_READ_FILE`/`ERROR_CANNOT_WRITE_FILE`/`ERROR_CANNOT_COPY_FILE`/`ERROR_CANNOT_DELETE_FILE`) on real I/O failure, with `KEY_FILE_PATH` (and `KEY_TARGET_PATH` for `copy()`) context attached.

Every real-mode native call (`file_get_contents()`/`file_put_contents()`/`unlink()`/`filemtime()`) is routed through a private `runNative()` helper that installs a temporary `set_error_handler()`, captures any native warning as an `\ErrorException` without letting it propagate, and restores the previous handler via `restore_error_handler()` in `finally` — no native PHP warning ever escapes this facade, even under a Composer-style error handler that throws on any unsuppressed warning. Every real-mode throw site above also attaches the captured error's message under `KEY_NATIVE_ERROR` and chains it as the thrown exception's `getPrevious()`.

---

### `VersionConstraintChecker`

> `src/Utils/VersionConstraintChecker.php` — Checks whether a `version` alias (as written into a path repository's `require`/`require-dev` entry by `DevConfigTransformer`) satisfies a package's root constraint, using Composer's own semver engine. A deliberate second, narrowly scoped duck-typing site alongside `EventContext` (which duck-types Composer's event/IO objects, not semver) — the library has zero runtime dependencies, so it cannot type-hint against `Composer\Semver\Semver`/`Composer\Semver\VersionParser` directly.

```php
public function __construct(string $semverClass = 'Composer\\Semver\\Semver', string $parserClass = 'Composer\\Semver\\VersionParser')
public function check(string $version, string $constraint): ConstraintCheck
```

`$semverClass`/`$parserClass` default to the real Composer classes — every real invocation of the switcher runs inside a Composer script process, where `Composer\Semver\*` is always loaded, so this never needs a runtime dependency to hold in practice. The constructor arguments exist purely so tests can exercise `ConstraintCheck::Unverifiable` with a non-existent class name, and the normalisation-failure path with a throwing stub parser.

`check()` returns `ConstraintCheck::Unverifiable` immediately if either `$semverClass` or `$parserClass` does not exist. Otherwise it instantiates `$parserClass` and calls `normalize(string $version)` on the version first, then `parseConstraints(string $constraint)` on the constraint, then `Semver::satisfies()`. A version is a *dev alias* when, case-insensitively, it starts with `dev-` or ends with `-dev` (e.g. `dev-main`, `3.0.x-dev`) — the private `isDevAlias()` rule, the single place this is defined. If `normalize()` throws `UnexpectedValueException` for a dev alias, the result is `ConstraintCheck::Unverifiable` (never a block); if it throws for any other version, or `parseConstraints()` throws for the constraint, the result is `ConstraintCheck::Invalid`. A dev alias that normalises successfully is evaluated normally (`Satisfied`/`Violated`).

---

### `StatusFile` extends `ConfigFile`

> `src/Utils/StatusFile.php` — Persists switching state as JSON.

#### Constants

```php
public const KEY_MODE = 'mode';
public const KEY_DATE = 'date';
public const KEY_MAIN_FILE = 'mainFile';
public const KEY_PROD_FILE = 'prodFile';
public const KEY_DEV_FILE = 'devFile';
public const KEY_SNAPSHOT_HASH = 'snapshotHash';
public const KEY_APPLIED_REPOSITORIES = 'appliedRepositories';
```

`KEY_SNAPSHOT_HASH` is an MD5 of a switch's snapshot config plus lock contents, recorded at the moment the switch was applied, so a later read can detect that the on-disk config/lock no longer matches what that switch actually produced (a tampered or independently edited snapshot). `KEY_APPLIED_REPOSITORIES` is the list of local repositories the switch applied, as plain serializable data, letting a later read compute a refresh delta without re-deriving it from the DEV config alone.

#### Methods

```php
public function saveState(string $mode, ConfigSwitcher $switcher, ?string $snapshotHash = null, ?array $appliedRepositories = null): void
public function getMode(): ?string
public function getDate(): ?string
public function getSnapshotHash(): ?string
public function getAppliedRepositories(): ?array
public function isDEV(): bool
public function isPROD(): bool
public function getData(): array
```

`$snapshotHash`/`$appliedRepositories` are optional `saveState()` parameters that default to `null` and are omitted from the written JSON entirely (not written as an explicit `null`) when absent — this is what makes a legacy status file (written before these keys existed) and a file saved without supplying them indistinguishable by design. `getSnapshotHash()`/`getAppliedRepositories()` return `null` in both cases, and also on malformed on-disk data (a non-string hash or non-array repositories list) — never throwing. A `null` read always means "no tamper check possible" / "no applied-repositories data available", not an error.
