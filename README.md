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
  Otherwise, a new entry is added to ensure that the package is loaded from the specified path.
- The `require` section is updated so the package version constraint is set to `*`, 
  which is required for path repositories.

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

Copy your existing `composer.json` file to a new file, for example `composer-production.json`.
This file will be used as the base configuration when switching back to production mode.

**WARNING**: From now on, only edit the `composer-production.json` file. The `composer.json`
file will be modified automatically when switching between configurations.

### 3. Composer script handler class

Composer scripts are typically static methods in a class, which are called by
Composer when the script is executed.

You can use the following class as a starting point for your project:

```php
declare(strict_types=1);

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;

class ComposerScripts
{
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

    private static $initialized = false;

    /**
     * Initializes the Composer autoloader. Scripts do not
     * automatically load it, so we need to do it manually.
     * Additionally, we ensure that this is only done once
     * across multiple script calls.
     */
    private static function initAutoloader() : void
    {
        if(self::$initialized) {
            return;
        }
        
        self::$initialized = true;
        
        require_once __DIR__ . '/vendor/autoload.php';
    }
    
    /**
     * @var ConfigSwitcher|null 
     */
    private static $switcher;
    
    /**
     * Create/get the configuration switcher instance.
     * This automatically initializes the Composer
     * autoloader as well.
     * 
     * @return ConfigSwitcher
     */
    private static function createSwitcher() : ConfigSwitcher
    {
        if(self::$switcher !== null) {
            return self::$switcher;
        }
    
        self::initAutoloader();
        
        $switcher = new ConfigSwitcher(
            new ConfigFile('/path/to/composer.json'),
            new ConfigFile('/path/to/composer-production.json'),
            new ConfigFile('/path/to/composer-local-repositories.json'),
        );
        
        self::$switcher = $switcher;
        
        return $switcher;
    }
}
```

> NOTE: Adjust the paths in the `createSwitcher()` method to point to your
> local configuration files, as well as the autoloader path if needed.

### 4. Set up switching scripts

We will be adding scripts to the `composer.json` file to switch
between development and production configurations, as well as 
update the current configuration.

```json
{
  "scripts": {
    "switch-dev": "ComposerScripts::switchToDEV",
    "switch-prod": "ComposerScripts::switchToPROD",
    "switch-update": "ComposerScripts::switchUpdate"
  }
}
```

> NOTE: It's good practice to also have a `build` script that ensures the
> configuration is set to production mode before deploying the project. 
> This will minimize the risk of accidentally deploying with development 
> dependencies.

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

## Version control

Here is what you should and should not commit to version control:

- `composer.json` - YES
- `composer.lock` - YES
- `composer.json.PROD` / `composer.json.DEV` - NO (helper files)
- `composer-production.json` - YES
- `composer-production.lock` - YES
- `dev-config.json` - NO (local-specific paths)
- `dev-config.status` - NO

> NOTE: It is good practice to add a template for the `dev-config.json` file
> to version control, so other developers can use this to create their own local
> configuration file. This is typically named something like `dev-config.dist.json`.

## Why PHP v7.3?

The library is currently used in a legacy Composer environment that is still running 
PHP v7.3. It is planned to be modernized, but until then this package will remain 
compatible with PHP v7.3.
