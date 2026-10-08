# Usage Guide

Once the [setup](setup.md) is complete, use the following Composer commands to switch between configurations and inspect the switcher's state. To drive the same operations from PHP code, see the [API Guide](api.md).

## Switching

Each of these finishes in a single command: the switch shows you what will change, asks for
confirmation when `composer.json` itself would change, and then runs whatever Composer command
(`install`/`update`) the switch itself planned — there is no separate `composer update` step to
remember.

### Switch to development mode

```bash
composer switch-dev
```

### Switch to production mode

```bash
composer switch-prod
```

### Update the current configuration

Re-applies the current configuration (DEV or PROD).

```bash
composer switch-update
```

- In DEV mode, `composer.json` is rebuilt from the production baseline and the current `local-repositories.json`.
- In PROD mode, there is nothing to rebuild: `composer.json` is already the single source of truth, and it (and `.lock`) stay untouched beyond the unconditional legacy cleanup, status file, and flag file update.
- Before any switch has ever been run (the `INITIAL` state), it performs no file operations.

If `composer.json`'s lock file is missing (for example right after cloning the project), the switch still completes in full - config rewrite, status file, flag file - with a console warning instead of aborting. A DEV to PROD switch with no `composer-prod.lock` backup to restore from behaves the same way: the stale DEV lock file is removed and a warning is printed, rather than the switch failing.

## What you will be asked to confirm

Every entry point above prints the full set of `composer.json` changes it would make, and the
Composer command it plans to run next, before writing anything:

```text
composer.json changes:
  require › vendor/package-name: changed "^2.0" -> "*" [version]
Permanent production changes:
  require › vendor/other-package: removed "^1.0" [permanent]
Planned command: composer update vendor/package-name (local repository added)
Apply these changes and run `composer update vendor/package-name`? [no]
```

Each changed entry carries a marker: `[version]` for a require/require-dev constraint or a path
repository's version alias, `[permanent]` for a DEV-time edit being carried back into production,
and `[discarded]` for a DEV edit to a managed (local package) entry that is being reset instead of
kept. A switch with no `composer.json` change at all (e.g. a PROD→PROD refresh) never prompts.

The confirmation defaults to **no** whenever a `[permanent]` or `[discarded]` marker is shown, and
to **yes** otherwise. Declining writes nothing and exits `0`.

For agents, CI, or any other non-interactive run, pass `--yes` after a `--` separator to apply
without prompting:

```bash
composer switch-dev -- --yes
```

Without `--yes`, a non-interactive run prints the changes, then refuses with an error instead of
guessing - nothing is written. Read the printed change set before passing `--yes`.

Other flags accepted the same way:

- `-- --no-install` prints the planned command instead of running it, leaving you to run it
  yourself (or skip it) once you've reviewed the written `composer.json`.
- `-- --with-dependencies` is forwarded to a partial `update <packages>` command (not a full
  `update` or a plain `install`), matching Composer's own `--with-dependencies` flag.

Internally, the switch previews the change twice - once to show you, once right before writing -
and compares the two; if anything changed in between (e.g. a concurrent edit), it refuses to write
and asks you to re-run the switch instead of applying something you never saw.

## Previewing a switch

```bash
composer switch-preview-dev
composer switch-preview-prod
```

A preview runs the same code path as a real switch, but never touches disk: every file stays byte-identical and every modification time stays unchanged. The planned operations match what a real switch from the same starting state would perform, even from the `INITIAL` state where `composer-prod.json` does not exist yet.

Each planned file operation is printed as `would <type>: <source> -> <target> (<reason>)`, followed by the same messages a real switch would display.

## Inspecting switcher state

```bash
composer switch-describe       # human-readable report
composer switch-describe-json  # pretty-printed JSON
```

The snapshot covers the mode, the last switch date, existence and modification date for every config and lock file the switcher manages, which flag file (if any) is active, the main lock's freshness (`lockStatus`) and whether what's installed matches the active mode (`installedState`), the DEV-time edits pending carry-back to production (`pendingProdChanges`, DEV only), and the parsed `local-repositories` list (each entry including a `derivedVersion`). It never fails: if the dev configuration file is missing or malformed, the repository list comes back empty and a warning is attached instead; legacy v2 artifacts found on disk are also reported as warnings rather than acted on.

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
  "lockStatus": "fresh",
  "installedState": "matches",
  "pendingProdChanges": null,
  "localRepositories": [
    { "packageName": "vendor/package-name", "path": "/path/to/package", "version": "*", "derivedVersion": "1.4.2" }
  ],
  "warnings": []
}
```

`pendingProdChanges` is only ever filled (a `{"composerJson":[],"prodConfig":[...]}` object, matching `ConfigChangeSet::toArray()`) while in DEV mode — it is `null` in PROD/`initial`, and also `null` (with an explanatory warning attached) when it could not be computed, e.g. a modified or missing production snapshot.

## Related guides

- [Setup Guide](setup.md) - script wiring and file layout
- [API Guide](api.md) - the same operations from PHP
- [Git Hooks](git-hooks.md) - guard against committing DEV configuration
