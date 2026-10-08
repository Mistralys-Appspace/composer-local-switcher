# Git Hooks

The library ships with a pre-commit hook that prevents accidentally committing development configuration to version control. It blocks the commit when:

1. `composer.json` or `composer.lock` is staged while in DEV mode (the `composer.json.DEV` marker file exists).
2. `composer.json` is staged and contains a `"type": "path"` entry in its `repositories` key (a local symlink is active), in either list or keyed-object form. If the staged file is not valid JSON or `php` is unavailable, the check falls back to a file-wide match, so it never fails open.

When a commit is blocked, the hook tells you to run `composer switch-prod` (Guard 1 — DEV mode). `composer switch-prod` now finishes in one command: it writes `composer.json`/`.lock` back to production and runs whatever Composer command the switch itself plans, so no separate `composer install` step is needed afterward.

## Installing the hook

```bash
composer switch-install-hooks
```

This requires the `switch-install-hooks` script to be wired in your `composer.json` - see [Setup](setup.md#3-script-wiring).

Or programmatically:

```php
$switcher->installGitHooks('/path/to/project-root');
```

This copies the bundled hook to `.git/hooks/pre-commit` with executable permissions, **overwriting any existing `pre-commit` hook** at that path without prompting or backing it up. If `.git/hooks/` does not exist, the method returns `false` and prints a console warning.

## Related guides

- [Setup Guide](setup.md#version-control) - what to commit
- [Usage Guide](usage.md) - switching commands
