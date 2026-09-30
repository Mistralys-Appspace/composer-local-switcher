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

This command will re-apply the current configuration (DEV or PROD) to 
update the configurations based on which files have been modified.
Use this if you modified either the `composer-production.json` or the
`composer.json` file directly.

```bash
composer switch-update
composer update
```

## Verifying configuration sync

After editing `composer-prod.json`, you can verify that the production configuration is still in
sync with the active `composer.json`:

```bash
composer switch-verify-config
```

This prints an in-sync confirmation, a list of differing keys, or a DEV-mode message.

For programmatic use, the `verify()` method returns an associative array with `inSync` (bool) 
and `differences` (top-level key names that differ). When called in DEV mode, it returns early 
with `devMode => true` since the comparison is only meaningful in PROD mode.

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
