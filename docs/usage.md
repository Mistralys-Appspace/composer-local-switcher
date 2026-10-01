# Usage Guide

Once the [setup](setup.md) is complete, use the following Composer commands to switch between configurations and inspect the switcher's state. To drive the same operations from PHP code, see the [API Guide](api.md).

## Switching

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

### Update the current configuration

Re-applies the current configuration (DEV or PROD).

```bash
composer switch-update
composer update
```

- In DEV mode, `composer.json` is rebuilt from the production baseline and the current `local-repositories.json`.
- In PROD mode, `composer.json` and `composer-prod.json` are reconciled: if their content already matches, nothing happens; otherwise the file with the more recent modification time is copied over the other one, along with its lock file. Use this if you modified either `composer-prod.json` or `composer.json` directly.
- Before any switch has ever been run (the `INITIAL` state), it performs no file operations.

If `composer.json`'s lock file is missing (for example right after cloning the project), the switch still completes in full - config rewrite, status file, flag file - with a console warning instead of aborting. A DEV to PROD switch with no `composer-prod.lock` backup to restore from behaves the same way: the stale DEV lock file is removed and a warning is printed, rather than the switch failing.

## Previewing a switch

```bash
composer switch-preview-dev
composer switch-preview-prod
```

A preview runs the same code path as a real switch, but never touches disk: every file stays byte-identical and every modification time stays unchanged. The planned operations match what a real switch from the same starting state would perform, even from the `INITIAL` state where `composer-prod.json` does not exist yet.

Each planned file operation is printed as `would <type>: <source> -> <target> (<reason>)`, followed by the same messages a real switch would display.

## Verifying configuration sync

After editing `composer-prod.json`, verify that it is still in sync with the active `composer.json`:

```bash
composer switch-verify-config
```

This prints an in-sync confirmation, a list of differing top-level keys, or a DEV-mode message (the comparison is not meaningful in DEV mode).

## Reconciling drift

```bash
composer switch-reconcile
```

Reconciliation fixes drift between `composer.json` and `composer-prod.json` in PROD mode without a full switch. `composer switch-update` performs the same reconciliation when in PROD mode.

Content decides whether to act; modification time decides the direction:

- **Already in sync:** no-op, regardless of modification times.
- **`composer.json` is newer:** it is copied to `composer-prod.json` (plus its lock file).
- **`composer-prod.json` is newer:** it is copied to `composer.json` (plus its lock file).
- **Ambiguous:** the content differs but the modification times are equal, so no direction can be chosen. Nothing is written. Resolve it by passing an explicit direction through the [PHP API](api.md#reconciling-and-previewing).

Edge cases where reconciliation is a no-op instead of acting or failing:

| State | Behaviour |
|---|---|
| **DEV mode** | Skipped entirely. `composer.json` has been rewritten with local path repositories and is not meaningful to compare against `composer-prod.json`. Only `composer/composer-prod.json` reflects your real production configuration. |
| **`INITIAL` state** (no switch ever run) | No-op for every direction: there is nothing to compare yet. The outcome mode is `initial`. |
| **`composer-prod.json` missing** (PROD mode) | No-op, because there is no production baseline on disk - except for an explicit `RECONCILE_TO_PROD`, which recreates `composer-prod.json` (and its lock file, when present) from `composer.json`. |

To recover a missing `composer-prod.json`, either call `$switcher->reconcile(ConfigSwitcher::RECONCILE_TO_PROD)` from PHP, or from the command line delete the status file and run `composer switch-prod`. A fresh switch to PROD always recreates `composer-prod.json` from the current `composer.json` if it is missing. The `composer switch-reconcile` command cannot do this itself, because it always resolves the direction automatically.

## Inspecting switcher state

```bash
composer switch-describe       # human-readable report
composer switch-describe-json  # pretty-printed JSON
```

The snapshot covers the mode, the last switch date, existence and modification date for every config and lock file the switcher manages, which flag file (if any) is active, the verification result, and the parsed `local-repositories` list. It never fails: if the dev configuration file is missing or malformed, the repository list comes back empty and a warning is attached instead.

Example JSON payload for a project in PROD mode with one local repository configured and no warnings:

```json
{
  "mode": "prod",
  "lastSwitchDate": "2026-09-30 08:12:45",
  "files": [
    { "label": "main", "path": "/project/composer.json", "exists": true, "modifiedDate": "2026-09-30 08:12:45" },
    { "label": "prod", "path": "/project/composer/composer-prod.json", "exists": true, "modifiedDate": "2026-09-29 17:03:11" },
    { "label": "dev", "path": "/project/composer/local-repositories.json", "exists": true, "modifiedDate": "2026-09-28 11:40:02" },
    { "label": "status", "path": "/project/composer/local-repositories.status", "exists": true, "modifiedDate": "2026-09-30 08:12:45" },
    { "label": "mainLock", "path": "/project/composer.lock", "exists": true, "modifiedDate": "2026-09-30 08:12:46" },
    { "label": "prodLock", "path": "/project/composer/composer-prod.lock", "exists": true, "modifiedDate": "2026-09-29 17:03:20" },
    { "label": "devLock", "path": "/project/composer/local-repositories.lock", "exists": false, "modifiedDate": null }
  ],
  "activeFlag": "prod",
  "verification": {
    "inSync": true,
    "differences": [],
    "devMode": false
  },
  "localRepositories": [
    { "packageName": "vendor/package-name", "path": "/path/to/package", "version": "*" }
  ],
  "warnings": []
}
```

## Related guides

- [Setup Guide](setup.md) - script wiring and file layout
- [API Guide](api.md) - the same operations from PHP
- [Git Hooks](git-hooks.md) - guard against committing DEV configuration
