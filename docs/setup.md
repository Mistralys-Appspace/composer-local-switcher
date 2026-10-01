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
- The `require` (or `require-dev`) section is updated so the package version constraint is set to `*`, which is required for path repositories. Packages that are listed in `require-dev` in the production config stay in `require-dev` - they are not moved to `require`.

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

The optional `version` property is explained in [Using specific package versions](#using-specific-package-versions).

### 2. Production configuration

Copy your existing `composer.json` file to `composer/composer-prod.json`. This file is used as the base configuration when switching back to production mode.

> **WARNING:** From now on, only edit `composer/composer-prod.json`. The `composer.json` file is modified automatically when switching between configurations.

`composer switch-update` (and `reconcile()`) can reconcile drift between `composer.json` and `composer-prod.json` on your behalf, but this is best-effort and not a substitute for the rule above. Some states cannot be resolved automatically, for example when both files were edited and share the same modification time, and in development mode `composer.json` is no longer meaningful to compare at all. See [Reconciling drift](usage.md#reconciling-drift) for the exact rules.

### 3. Script wiring

#### Standard layout

The library provides built-in Composer script entry points. If your project follows the standard file layout - `composer.json` in the project root, plus `composer-prod.json` and `local-repositories.json` in a `composer/` subdirectory - you can wire them directly without writing any PHP glue code:

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
    "switch-reconcile": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchReconcile",
    "switch-preview-dev": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchPreviewDev",
    "switch-preview-prod": "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchPreviewProd"
  }
}
```

See the [Usage Guide](usage.md) for what each command does.

> It is good practice to also have a `build` script that ensures the configuration is set to production mode before deploying the project. This minimizes the risk of accidentally deploying with development dependencies.

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

## Using specific package versions

By default, path packages get the version constraint `*` to always use the latest version from the local path. This does not work in all cases: if you have other version constraints in your `require` section for the same package, you get a Composer error like this:

```
vendor/packagename[dev-main] from path repo (/path/to/repo)
has higher repository priority. The packages from the higher
priority repository do not match your constraint and are
therefore not installable.
```

To work around this, specify a `version` for the package in the local repositories configuration file:

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

The repository is still loaded as a path repository, but the specified version is used whenever Composer needs to resolve the package version.

> The only drawback of this approach is that you need to maintain the version number manually in the configuration file.

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
| `composer-prod.json` | Yes |
| `composer-prod.lock` | Yes |
| `local-repositories.json` | No (local-specific paths) |
| `local-repositories.status` | No |

> It is good practice to add a template for the `local-repositories.json` file to version control, so other developers can use it to create their own local configuration file. This is typically named something like `local-repositories.dist.json`.

To keep development-mode files from being committed by accident, see the [Git Hooks](git-hooks.md) guide.
