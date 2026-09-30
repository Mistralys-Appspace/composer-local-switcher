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
public const MESSAGE_CREATE_NEW_LOCK_FILE = 182202;
public const MESSAGE_USING_DEV_CONFIG = 182203;
public const MESSAGE_USING_PROD_CONFIG = 182204;
public const MESSAGE_BACKED_UP_MAIN_TO_PROD = 182205;
public const MESSAGE_RESTORED_PROD_TO_MAIN = 182206;
public const MESSAGE_RUN_INSTALL_PROD = 182207;
public const MESSAGE_RUN_INSTALL_DEV = 182208;
public const MESSAGE_REBUILT_DEV_CONFIG = 182209;
public const MESSAGE_ALREADY_IN_SYNC = 182210;
public const MESSAGE_RECONCILE_AMBIGUOUS = 182211;
public const MESSAGE_DEV_MODE_NOT_RECONCILABLE = 182212;
public const MESSAGE_DRY_RUN_ACTIVE = 182213;
public const MESSAGE_PROD_LOCK_MISSING = 182214;
public const KEY_LOCAL_REPOSITORIES = 'local-repositories';
public const KEY_REPOSITORIES = 'repositories';
public const RECONCILE_TO_MAIN = 'to-main';
public const RECONCILE_TO_PROD = 'to-prod';
```

> `MESSAGE_ALREADY_IN_SYNC`, `MESSAGE_RECONCILE_AMBIGUOUS`, `MESSAGE_DEV_MODE_NOT_RECONCILABLE`, and `MESSAGE_DRY_RUN_ACTIVE` are emitted by `reconcile()` (see below). `MESSAGE_PROD_LOCK_MISSING` is emitted by `switch_case_DEV_PROD()` (the internal handler for a DEV→PROD `switchTo()` call) when no `composer-prod.lock` backup exists to restore — the stale DEV lock file is deleted instead of the unconditional copy throwing. `MODE_INITIAL` is not a valid `switchTo()`/`switchToDevelopment()`/`switchToProduction()` argument; it is only ever the `SwitchOutcome::getMode()` value returned by `switchUpdate()` when no switch has ever been run yet. `RECONCILE_TO_MAIN`/`RECONCILE_TO_PROD` are the two valid `reconcile()` direction arguments.

#### Static Factory

```php
public static function fromProjectRoot(string $rootPath): self
```

Creates a `ConfigSwitcher` using the three-path convention shared by both consumer projects: `$rootPath/composer.json`, `$rootPath/composer/composer-prod.json`, `$rootPath/composer/local-repositories.json`.

#### Composer Script Entry Points

```php
public static function composerSwitchDev(): void
public static function composerSwitchProd(): void
public static function composerSwitchUpdate(): void
public static function composerVerifyConfig(): void
public static function composerInstallHooks(): void
public static function composerSwitchDescribe(): void
public static function composerSwitchDescribeJson(): void
public static function composerSwitchReconcile(): void
public static function composerSwitchPreviewDev(): void
public static function composerSwitchPreviewProd(): void
```

Static entry points for use in `composer.json` scripts. Each calls `fromProjectRoot(getcwd())` internally. `composerSwitchDescribe()` renders `describe()`'s snapshot as a human-readable report; `composerSwitchDescribeJson()` renders the same snapshot via `SwitchDescription::toJSON()`. `composerSwitchReconcile()` calls `reconcile()` and prints its message texts. `composerSwitchPreviewDev()`/`composerSwitchPreviewProd()` call the shared private `printPreview()` helper, which runs `previewSwitch()` for the given mode and prints each `FileOperation` as `would <type>: <source> -> <target> (<reason>)` followed by the outcome's message texts.

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
public function getDevFile(): ConfigFile
public function getProdFile(): ConfigFile
public function getStatus(): StatusFile
public function installGitHooks(string $projectRoot): bool
public function switchUpdate(): SwitchOutcome
public function switchToDevelopment(): SwitchOutcome
public function switchToProduction(): SwitchOutcome
public function switchTo(string $mode, bool $dryRun = false): SwitchOutcome
public function verify(): VerificationResult
public function reconcile(?string $direction = null, bool $dryRun = false): SwitchOutcome
public function describe(): SwitchDescription
public function previewSwitch(string $mode): SwitchOutcome
public function getFlagFile(string $mode): FlagFile
public function displayMessages(): self
public function setDisplayMessages(bool $display): self
public function getMessages(): array
public function getMessageTexts(): array
```

- `switchTo(string $mode, bool $dryRun = false)` (and its `switchToDevelopment()`/`switchToProduction()` wrappers) always completes the switch — config rewrite, status file, flag file — even when `composer.json`'s lock file is missing; that case only adds `MESSAGE_NO_LOCK_FILE_FOUND` as a warning rather than aborting. Returns a `SwitchOutcome` describing the switch's mode, messages, and file operations. `switchUpdate()` propagates whichever of `switchToDevelopment()`/`switchToProduction()` it dispatches to; in the `INITIAL` state (no switch has ever been run) it is a no-op on the file system but still returns an explicit `SwitchOutcome` with mode `MODE_INITIAL` and no operations, rather than returning nothing. `$dryRun` sets the facade's dry-run flag (and adds `MESSAGE_DRY_RUN_ACTIVE`) for the duration of the call only, restoring the previous flag value in a `finally` block — so a mid-switch exception can never leave the switcher stuck in dry-run mode.
- `describe()` assembles a `SwitchDescription` (see below) covering mode, last switch date, per-file existence/modification-date records for all four configs and three lock files, flag-file presence per mode, the `verify()` result, and the parsed `local-repositories` list. It never throws: a missing or malformed dev config degrades to an empty repository list plus a warning recorded on the returned `SwitchDescription` instead.
- `previewSwitch(string $mode)` validates `$mode` then calls `switchTo($mode, true)` with message display suppressed, returning the resulting `SwitchOutcome` without writing anything to disk. Because it runs the exact same code path as a real switch (under the `FileSystem` dry-run overlay), its `getOperations()` list is guaranteed to match what a real switch from the same starting state would perform, including from the `INITIAL` state.
- `verify()` returns a `VerificationResult` (see `VerificationResult` below), comparing `composer.json` against the production config. In DEV mode it short-circuits with `isDevMode()` true, `isComparable()` false, `isInSync()` false.
- `reconcile(?string $direction = null, bool $dryRun = false)` unifies drift detection and correction in PROD mode: content (via `verify()`) decides *whether* to act, modification time decides *which direction* to copy in when `$direction` is omitted. Returns a `SwitchOutcome` (see below) describing the outcome in every case — in-sync no-op (`MESSAGE_ALREADY_IN_SYNC`), DEV-mode no-op (`MESSAGE_DEV_MODE_NOT_RECONCILABLE`), ambiguous no-op when mtimes are equal but content differs (`MESSAGE_RECONCILE_AMBIGUOUS`), or a directional copy (`MESSAGE_BACKED_UP_MAIN_TO_PROD`/`MESSAGE_RESTORED_PROD_TO_MAIN`) that also copies the winning config's lock file. Passing an explicit `$direction` (one of `RECONCILE_TO_MAIN`/`RECONCILE_TO_PROD`) overrides the modification-time heuristic and resolves the ambiguous case with a write; any other non-null value throws `ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION` with a context carrying the offending value under `ComposerSwitcherException::KEY_DIRECTION`. `$dryRun` previews the operation (`MESSAGE_DRY_RUN_ACTIVE`) without writing, and the facade's dry-run flag is restored to its prior value on return. `switch_case_PROD_PROD()` (the internal handler for a PROD→PROD `switchTo()` call) shares the same reconciliation core, so it produces file effects identical to a direct `reconcile()` call from the same starting state.
- `getMessages()` returns `SwitchMessage[]` — each message pairs free-form text with a `MESSAGE_*` code (see `SwitchMessage` below).
- `getMessageTexts()` returns `string[]`, the text-only projection of `getMessages()`; used internally by `displayMessages()`.
- `setDisplayMessages(bool $display)` toggles automatic message display on the console after a switch completes (enabled by default). This is independent of `setWriteToConsole()`, which controls the verbose step-by-step `ConsoleWriter` output during the switch itself.
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
public const ERROR_INVALID_RECONCILE_DIRECTION = 182111;
```

#### Context Keys

```php
public const KEY_DIRECTION = 'direction';
public const KEY_FILE_PATH = 'filePath';
public const KEY_TARGET_PATH = 'targetPath';
public const KEY_MODE = 'mode';
public const KEY_EXPECTED = 'expected';
public const KEY_ACTUAL = 'actual';
public const KEY_PACKAGE_NAME = 'packageName';
```

#### Context Methods

```php
public function setContext(array $context): self
public function getContext(): array
public function getContextValue(string $key): mixed
```

`setContext()` attaches structured data to an exception instance in addition to its free-form message, without changing the inherited `Exception` constructor signature. `getContextValue()` returns `null` for an absent key. Every throw site in the library attaches context using these keys: `KEY_FILE_PATH` (the file being read/written/deleted/checked, or the copy source), `KEY_TARGET_PATH` (a copy's destination, paired with `KEY_FILE_PATH` as the source), `KEY_MODE` (the offending `switchTo()` mode string), `KEY_DIRECTION` (the offending `reconcile()` direction string), `KEY_EXPECTED`/`KEY_ACTUAL` (an offending value paired with what was expected — `KEY_EXPECTED` alone for a validation throw, both together for a type/shape mismatch), and `KEY_PACKAGE_NAME` (the local-repository package name being processed, when available).

---

## `Mistralys\ComposerSwitcher\State`

### `SwitchMessage`

> `src/State/SwitchMessage.php` — Immutable value object returned by `ConfigSwitcher::getMessages()`.

```php
public function __construct(int $code, string $text)
public function getCode(): int
public function getText(): string
public function hasCode(): bool
public function __toString(): string
public function toArray(): array
```

Pairs a switch message's text with a `ConfigSwitcher::MESSAGE_*` code. A code of `0` is treated as "no code" (`hasCode()` returns `false`); `toArray()` returns `array{code:int,text:string}`.

---

### `VerificationResult`

> `src/State/VerificationResult.php` — Immutable value object returned by `ConfigSwitcher::verify()`.

```php
public function __construct(bool $devMode, bool $inSync, array $differences)
public function isDevMode(): bool
public function isComparable(): bool
public function isInSync(): bool
public function getDifferences(): array
public function toArray(): array
```

Holds the outcome of comparing `composer.json` against `composer-prod.json`. `isComparable()` is `false` in DEV mode (the comparison is not meaningful once `composer.json` has been rewritten for local repositories), in which case `isInSync()` always returns `false` regardless of the constructor's `$inSync` value. `getDifferences()` returns the top-level keys that differ (`string[]`). `toArray()` returns `array{inSync:bool,differences:string[],devMode:bool}`.

---

### `SwitchOutcome`

> `src/State/SwitchOutcome.php` — Immutable value object returned by `ConfigSwitcher::reconcile()`, `switchTo()` (and its `switchToDevelopment()`/`switchToProduction()`/`switchUpdate()`/`previewSwitch()` wrappers).

```php
public function __construct(string $mode, bool $dryRun, array $messages, array $operations)
public function getMode(): string
public function isDryRun(): bool
public function getMessages(): array
public function getMessageTexts(): array
public function getOperations(): array
public function hasOperations(): bool
public function toArray(): array
```

Summarizes a single switch/reconcile/preview result: the target mode, whether it was a dry run, and the messages and file operations produced along the way. `$messages` is `SwitchMessage[]`; `$operations` is `FileOperation[]` (see `FileOperation` below). `getMessageTexts()` is the text-only projection of `getMessages()`. `hasOperations()` returns `true` when at least one file operation was recorded (applied or dry-run-planned). `toArray()` returns `array{mode:string,dryRun:bool,messages:array<int,array{code:int,text:string}>,operations:array<int,array{type:string,target:string,source:string|null,reason:string,applied:bool}>}`.

---

### `SwitchDescription`

> `src/State/SwitchDescription.php` — Immutable value object returned by `ConfigSwitcher::describe()`.

```php
public function __construct(?string $mode, ?string $lastSwitchDate, array $files, ?string $activeFlag, VerificationResult $verification, array $localRepositories, array $warnings)
public function getMode(): ?string
public function getLastSwitchDate(): ?string
public function getFiles(): array
public function getActiveFlag(): ?string
public function hasActiveFlag(): bool
public function getVerification(): VerificationResult
public function getLocalRepositories(): array
public function getWarnings(): array
public function hasWarnings(): bool
public function toArray(): array
public function toJSON(): string
```

A read-only snapshot of the switcher's current state, assembled entirely by `describe()` — this class only holds already-gathered data and exposes it through typed getters. `$files` is `array<int,array{label:string,path:string,exists:bool,modifiedDate:string|null}>` covering the main/prod/dev configs, the status file, and all three lock files. `getActiveFlag()` returns the mode (`dev`/`prod`) whose flag file currently exists, or `null` if neither does. `$localRepositories` is `array<int,array{packageName:string,path:string,version:string}>`, the parsed DEV configuration's `local-repositories` list. `getWarnings()`/`hasWarnings()` surface non-fatal issues encountered while assembling the description — e.g. a missing or malformed dev config file, which `describe()` degrades to an empty repository list plus a warning here instead of throwing. `toArray()` returns `array{mode:string|null,lastSwitchDate:string|null,files:array,activeFlag:string|null,verification:array{inSync:bool,differences:string[],devMode:bool},localRepositories:array,warnings:string[]}`. `toJSON()` encodes `toArray()` with `JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.

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

## `Mistralys\ComposerSwitcher\Utils`

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

> `src/Utils/LockFile.php` — Lock file abstraction.

```php
public function __construct(ConfigFile $configFile)
public function getContent(): string
public function getConfigFile(): ConfigFile
```

The lock file path is derived by replacing `.json` with `.lock` in the parent `ConfigFile` path.

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

In real mode, each mutating method (`write()`, `copy()`, `delete()`) performs the corresponding I/O and records an applied `FileOperation`. In dry-run mode (`setDryRun(true)`), those same methods never call a mutating PHP filesystem function — they only update an in-memory overlay (`path => pending content|null`, where `null` marks a pending delete) which `exists()`, `read()`, and `modifiedTime()` consult before falling back to disk; this is what lets a dry run observe its own pending changes (e.g. `copy()` resolves its source through `read()`, so copying a file that was only just pending-written works without touching disk). Enabling or disabling dry-run mode discards any pending overlay state. `delete()` is a no-op (no exception, no recorded operation) when the path does not exist. `getOperations()` returns the accumulated `FileOperation[]` log; `clearOperations()` resets it — `ConfigSwitcher` calls this at the start of every `switchTo()`/`reconcile()` call so each outcome only reflects that call's own operations. `read()`/`write()`/`copy()` throw `ComposerSwitcherException` (`ERROR_CANNOT_READ_FILE`/`ERROR_CANNOT_WRITE_FILE`/`ERROR_CANNOT_COPY_FILE`) on real I/O failure, with `KEY_FILE_PATH` (and `KEY_TARGET_PATH` for `copy()`) context attached.

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
```

#### Methods

```php
public function saveState(string $mode, ConfigSwitcher $switcher): void
public function getMode(): ?string
public function getDate(): ?string
public function isDEV(): bool
public function isPROD(): bool
public function getData(): array
```
