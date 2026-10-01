# Composer Local Switcher

[![Packagist](https://img.shields.io/packagist/v/mistralys/composer-local-switcher)](https://packagist.org/packages/mistralys/composer-local-switcher)
[![PHP](https://img.shields.io/packagist/php-v/mistralys/composer-local-switcher)](https://packagist.org/packages/mistralys/composer-local-switcher)
[![License](https://img.shields.io/github/license/Mistralys/composer-local-switcher)](LICENSE)

Switch a project between its live Composer dependencies and local clones of those packages, using plain Composer scripts.

## Develop your packages side by side

When you work on a library and the project that uses it at the same time, you want edits in the library to show up in the project right away, without tagging a release or pushing a branch. Composer Local Switcher makes that a one-command switch: it swaps the packages you name for symlinked path repositories pointing at your local clones, and restores your production `composer.json` and lock file when you switch back.

## Features

- Switch between a production and a local development configuration with `composer switch-dev` and `composer switch-prod`.
- Work on local package clones through symlinked path repositories, so changes in a library are visible in the project that uses it.
- Keep a separate lock file for each configuration, so you can run Composer commands in either mode.
- Preview any switch before it happens; the preview lists the planned file changes and writes nothing.
- Inspect the current mode and the state of every managed file, as a readable report or as JSON.
- Detect when `composer.json` and your production configuration have drifted apart, and bring them back in line.
- Spot the active mode at a glance in your file browser through DEV/PROD flag files.
- Block commits of the development configuration with a bundled git pre-commit hook.
- Drive the switcher from PHP code or tooling: results and errors come back as structured objects, so you never need to parse console output.
- Add it to a project with no runtime dependencies beyond PHP.

## Requirements

- PHP 8.4 or newer
- [Composer](https://getcomposer.org/)

## Quick Start

Run these commands in the root of a project that has a `composer.json`. The steps install the library, wire up three scripts, set up the configuration files, and preview a switch to development mode without changing anything.

```bash
composer require mistralys/composer-local-switcher

composer config scripts.switch-dev "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchDev"
composer config scripts.switch-prod "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchProd"
composer config scripts.switch-preview-dev "Mistralys\\ComposerSwitcher\\ConfigSwitcher::composerSwitchPreviewDev"

mkdir -p composer
cp composer.json composer/composer-prod.json
echo '{"local-repositories": []}' > composer/local-repositories.json

composer switch-preview-dev
```

The preview prints each file operation it would perform as `would <type>: <source> -> <target> (<reason>)`, followed by the messages a real switch would show:

```text
Dry run: no files will actually be changed.
Using Composer DEV configuration.
Run `composer update` to create a DEV lock file.
Rebuilt a fresh DEV `composer.json`.
```

From here:

1. List the packages you want to work on locally in `composer/local-repositories.json`, with the path to each clone. The [Setup Guide](docs/setup.md) shows the format.
2. Run `composer switch-dev`, then `composer update`.
3. When you are done, run `composer switch-prod`, then `composer update`.

> **Note:** After the setup above, edit `composer/composer-prod.json` rather than `composer.json`. The switcher rewrites `composer.json` whenever you switch.

## Learn More

| Resource | Description |
|----------|-------------|
| [Setup Guide](docs/setup.md) | How it works, the configuration files, script wiring, custom file layouts, pinned package versions, and what to commit. |
| [Usage Guide](docs/usage.md) | Switching, previewing, verifying, reconciling drift, and inspecting state from the command line. |
| [API Guide](docs/api.md) | Using the switcher from PHP: outcomes, options, and exception handling. |
| [Git Hooks](docs/git-hooks.md) | Installing the pre-commit hook that guards against committing the development configuration. |
| [Migrating from 1.x](docs/migrating-from-1x.md) | The PHP 8.4 requirement and the PHP API changes that affect code calling the library directly. |
| [Changelog](changelog.md) | Release history. |
| [Issues](https://github.com/Mistralys/composer-local-switcher/issues) | Report a bug or request a feature. |

## License

MIT. See [LICENSE](LICENSE).
