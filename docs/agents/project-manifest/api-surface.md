# Public API Surface

## `Mistralys\ComposerSwitcher`

### `ConfigSwitcher`

> `src/ConfigSwitcher.php` — Main orchestrator class.

#### Constants

```php
public const MODE_DEV = 'dev';
public const MODE_PROD = 'prod';
public const MESSAGE_NO_LOCK_FILE_FOUND = 182201;
public const MESSAGE_CREATE_NEW_LOCK_FILE = 182202;
public const KEY_LOCAL_REPOSITORIES = 'local-repositories';
public const KEY_REPOSITORIES = 'repositories';
```

#### Constructor

```php
public function __construct(ConfigFile $mainFile, ConfigFile $prodFile, ConfigFile $devConfig)
```

- `$mainFile` — The main `composer.json` (mutable working copy).
- `$prodFile` — The production config file (e.g. `composer-prod.json`).
- `$devConfig` — The dev config file containing the `local-repositories` list.

#### Methods

```php
public function setFlagFileEnabled(bool $enabled): self
public function setWriteToConsole(bool $write): self
public function getMainFile(): ConfigFile
public function getDevFile(): ConfigFile
public function getProdFile(): ConfigFile
public function getStatus(): StatusFile
public function switchUpdate(): void
public function switchToDevelopment(): void
public function switchToProduction(): void
public function switchTo(string $mode): void
public function getFlagFile(string $mode): FlagFile
public function displayMessages(): self
public function getMessages(): array
```

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
```

---

## `Mistralys\ComposerSwitcher\Utils`

### `BaseFile` (abstract)

> `src/Utils/BaseFile.php` — Base file abstraction.

```php
public function __construct(string $path)
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

---

### `ConfigFile` extends `BaseFile`

> `src/Utils/ConfigFile.php` — JSON config file handler.

```php
public function __construct(string $path)
public function getLockFile(): LockFile
public function getData(): array
public function putData(array $data): void
```

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
