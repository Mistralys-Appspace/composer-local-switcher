# Setup Guide

This guide explains how Composer Local Switcher works and how to set it up in your project. For the daily commands, see the [Usage Guide](usage.md).

## How it works

### Two configurations

The main `composer.json` file is switched between two configurations:

- The production configuration
- The local development configuration

The lock file follows the switching, so you can run Composer commands separately for each configuration.

### Local development with symlinks

Using a list of local packages and their paths, the library replaces the packages in `composer.json` with path repositories that use symlinks to work directly with local package clones. When switching back to production mode, the original `composer.json` and lock file are restored.

### Library refactoring made easy

The library is intended to make local development of interdependent Composer packages easier, especially when coupled with an IDE like PHPStorm that can work with multiple projects at the same time. Refactoring classes in a library can then be done in the library project, and the changes are immediately available in the project that uses the library.

- If an entry exists in `repositories`, it is overwritten with a path repository. Any additional duplicate entries matching the same package are removed automatically. Otherwise, a new entry is added to ensure that the package is loaded from the specified path.
- Each path package is aliased to a version: the explicit `version` override from `local-repositories.json` when given, otherwise the version your production `composer.lock` already has locked for that package, or `*` when neither is available. The `require`/`require-dev` constraint itself is left untouched when an alias could be derived and the package is already required at the root level — only the alias changes, via the path repository's `options.versions` entry — and is only overwritten with `*` when no alias could be derived. Packages that are listed in `require-dev` in the production config stay in `require-dev` - they are not moved to `require`. See [Overriding the package version](#overriding-the-package-version) for when and why you might still want an explicit override.

## Setup steps

### 1. Local repository configuration

Create a JSON file anywhere you like in your project that lists the packages to switch:

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

All packages listed here are replaced with path repositories when switching to development mode, and restored to their original configuration when switching back to production mode. Packages that do not already have an entry in the `repositories` section of `composer.json` get a new one.

The optional `version` property is explained in [Overriding the package version](#overriding-the-package-version).

### 2. Production configuration

There is nothing to create here. `composer.json` is the single source of truth at all times - edit it
directly, the same way you would without the switcher.

`composer/composer-prod.json`/`.lock` are a transient snapshot of a DEV session under the hood, not a second committed baseline to keep in sync by hand - there is no drift to reconcile. A PROD/INITIAL→DEV switch creates them from `composer.json`/`.lock`, and a DEV→PROD switch deletes them once their content has been applied back onto the main files. Never create or hand-edit them: a `composer/composer-prod.json`/`.lock` found while already in PROD or INITIAL mode is treated as a leftover from a 2.x project and deleted automatically at the start of the next switch.

### 3. Script wiring

#### Standard layout

The library provides built-in Composer script entry points. If your project follows the standard file layout - `composer.json` in the project root, plus `composer-prod.json` and `local-repositories.json` in a `composer/` subdirectory - you can wire them directly without writing any PHP glue code:

```json
{
  "scripts": {
    "switch-dev": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDev",
    "switch-prod": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchProd",
    "switch-update": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchUpdate",
    "switch-install-hooks": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerInstallHooks"
  }
}
```

These entry points use `ConfigSwitcher::fromProjectRoot(getcwd())` internally, which resolves to:

- `<project-root>/composer.json`
- `<project-root>/composer/composer-prod.json`
- `<project-root>/composer/local-repositories.json`

#### Optional entry points

The library also ships these entry points, which use the same path convention:

```json
{
  "scripts": {
    "switch-describe": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDescribe",
    "switch-describe-json": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDescribeJson",
    "switch-preview-dev": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchPreviewDev",
    "switch-preview-prod": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchPreviewProd"
  }
}
```

See the [Usage Guide](usage.md) for what each command does.

> It is good practice to also have a `build` script that ensures the configuration is set to production mode before deploying the project. This minimizes the risk of accidentally deploying with development dependencies.

> **Do not wire `post-update-cmd: @composer switch-update`.** It is not required to keep DEV mode in
> sync - `switch-dev`/`switch-prod` already run the Composer command they plan themselves, and a
> DEV-time `composer require`/`remove` is carried back into production automatically on `switch-prod`.
> A hook left over from an older project layout is harmless (see
> [Migrating from 2.x](migrating-from-2x.md#what-a-retained-post-update-cmd-hook-does-under-v3)), but
> adds an extra Composer invocation and can fail a non-interactive run that changed
> `local-repositories.json`, so new setups should not add it.

#### Custom file layout

If your project uses a different file layout, use the three-argument constructor in a custom script handler class:

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

For the options available on the switcher instance, see the [API Guide](api.md#options).

## Overriding the package version

By default, each path package is aliased to the version your production `composer.lock` has locked for it, so the alias already satisfies every constraint production resolved — there is nothing to maintain manually. The `version` key in `local-repositories.json` is therefore optional; set it only when you need to override the derived version. If a package has no locked version yet (e.g. it was never installed before), the alias falls back to `*`, which can fail in some cases: if you have other version constraints in your `require` section for the same package, you get a Composer error like this:

```
vendor/packagename[dev-main] from path repo (/path/to/repo)
has higher repository priority. The packages from the higher
priority repository do not match your constraint and are
therefore not installable.
```

To work around this, or to pin the package to a version other than what is locked, specify an explicit `version` override for the package in the local repositories configuration file:

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

The repository is still loaded as a path repository, but the specified version is used whenever Composer needs to resolve the package version, taking precedence over the locked-version alias.

## Vendor dependencies in attached projects

A drawback of attaching local packages using path repositories is that the vendor dependencies of the attached packages are included in the IDE's class index. This causes duplicate class messages, and clicking on a class may not open the file you expect.

To fix this, attach a separate local clone of the package, and do not run `composer install` in that clone. Without a `vendor` folder, the IDE's index stays clean.

## Version control

What to commit and what not to:

| File | Commit? |
|---|---|
| `composer.json` | Yes |
| `composer.lock` | Yes |
| `composer.json.PROD` / `composer.json.DEV` | No (helper flag files) |
| `composer-prod.json` | No (transient DEV-session snapshot, auto-created and auto-deleted) |
| `composer-prod.lock` | No (transient DEV-session snapshot, auto-created and auto-deleted) |
| `local-repositories.json` | No (local-specific paths) |
| `local-repositories.status` | No |

> It is good practice to add a template for the `local-repositories.json` file to version control, so other developers can use it to create their own local configuration file. This is typically named something like `local-repositories.dist.json`.

To keep development-mode files from being committed by accident, see the [Git Hooks](git-hooks.md) guide.
