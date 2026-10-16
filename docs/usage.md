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

Every switch entry point above starts by showing what it plans to do. Every block the switcher
prints begins with a header line starting with `Composer Local Switcher —`, so its output is easy to
tell apart from Composer's own. The first block is the plan: it names the transition the switch is
making (`PROD → DEV`, or `refresh DEV` when the mode stays the same), and lists the full set of
`composer.json` changes, any warnings, and the Composer command the switch plans to run next.
Unlike `composer switch-preview-dev`/`-prod` (see [below](#previewing-a-switch)), this is a real
switch's own plan, so the header carries no "preview"/"nothing will be changed" wording — it is
only shown ahead of the write for confirmation. Only then does it ask:

```text
Composer Local Switcher — switch PROD → DEV
composer.json changes (1):
  local path `../package-name` for `vendor/package-name` (symlinked, as version `1.4.2` from `options.versions`)
Next: composer update vendor/package-name (Installing the switched local packages at their aliased versions.)
Apply these changes and run `composer update vendor/package-name`? [yes]
```

Each change is one readable line, described by its shape:

- a path repository reads as `local path <path> for <package> (symlinked, as version <alias> from <source>)`;
- any other repository reads as `<type> repository <url>`;
- a `require`/`require-dev` constraint reads as `<section> › <package>: <before> → <after>`;
- a scalar setting reads as `<path>: <before> → <after>`;
- anything else falls back to compact, truncated JSON.

Paths inside or next to the project are shown relative to it (`./…`, `../…`). A path repository's
version alias already reads as a version change, so there is no separate `[version]` marker. A
`[permanent]` marker is added for a DEV-time edit being carried back into production, and
`[discarded]` for a DEV edit to a managed (local package) entry that is being reset instead of kept.
A switch with no `composer.json` change at all (e.g. a PROD→PROD refresh) never prompts.

After you confirm, the switcher frames the Composer run it executes for you with a start line and
either a `switch to DEV done` footer or a failure block:

```text
Composer Local Switcher — running `composer update vendor/package-name`
  Installing the switched local packages at their aliased versions.
...Composer's own output...
Composer Local Switcher — switch to DEV done
```

The `done` footer always closes a successful switch, even when there was no Composer command to
run at all, or `--no-install` (below) left it for you to run yourself - only a failed command, or a
blocked switch, ends without it.

### Verbose output

By default, only the headers, the change set, warnings, and the planned command are shown. Run
Composer with `-v` to also see each file operation (`will <type>: <source> -> <target> (<reason>)`
for a real switch's plan, `would <type>: …` for a preview) and the switcher's bookkeeping messages.
At `-v`, a bookkeeping message carries its numeric `[code]` prefix; at default verbosity it is
hidden entirely, and no machine code is printed anywhere.

```bash
composer -v switch-dev
```

### Confirmation defaults and non-interactive runs

The confirmation defaults to **no** whenever a `[permanent]` or `[discarded]` marker is shown, and
to **yes** otherwise. Declining writes nothing and exits `0`.

For agents, CI, or any other non-interactive run, pass `--yes` after a `--` separator to apply
without prompting:

```bash
composer switch-dev -- --yes
```

Without `--yes`, a non-interactive run prints the changes, then refuses with an error instead of
guessing - nothing is written, and the command exits non-zero. Read the printed change set before
passing `--yes`.

Other flags accepted the same way:

- `-- --no-install` prints the planned command instead of running it, leaving you to run it
  yourself (or skip it) once you've reviewed the written `composer.json`; the switch still ends
  with its `done` footer, since the `composer.json` change itself already succeeded.
- `-- --with-dependencies` is forwarded to a partial `update <packages>` command (not a full
  `update` or a plain `install`), matching Composer's own `--with-dependencies` flag.

Internally, the switch previews the change twice - once to show you, once right before writing -
and compares the two; if anything changed in between (e.g. a concurrent edit), it refuses to write
and asks you to re-run the switch instead of applying something you never saw.

### When a switch is blocked

A switch can be blocked by a precondition, for example an outdated production lock file, a modified
DEV snapshot, or a `version` override in `local-repositories.json` that cannot satisfy the
package's constraint in `composer.json` (such as a pin left over from an older release). A blocked
switch writes nothing, and the command exits non-zero with the reason printed. For a conflicting
pin:

```text
Composer Local Switcher — switch PROD → DEV blocked, nothing was changed
  ! WARNING: The version override [1.0.4] for [vendor/package-name] does not satisfy its root require constraint [^3.0.0] (PROD-locked version: 3.0.0). Fix: edit the override in `local-repositories.json`, or adjust the root constraint, so the override satisfies it.
ERROR: The switch is blocked by a precondition (see the messages above).
```

The remedy is the one the message names: remove the `version` key (the PROD-locked version is then
used) or change it to a version that satisfies the constraint, then run the switch again. The same
conflict is listed as a warning by `composer switch-describe`, so you can spot it before you switch.
A malformed pin (one Composer's semver parser rejects) is blocked the same way. A `dev-*` or
`*-dev` pin that the check cannot prove malformed is not blocked.

### When the Composer command fails

If the Composer command the switch runs exits non-zero, the config has already been switched, and
is not rolled back automatically (Composer may already have written a new lock file). The switcher
prints a failure block, then a recovery hint naming the mode the project is now in:

```text
Composer Local Switcher — composer command failed (exit code 2)
  `composer update vendor/package-name`: Installing the switched local packages at their aliased versions.
WARNING: The switch to DEV did not finish — the Composer command above failed, so `composer.json` may not match what is installed. Fix the underlying issue, then run `composer switch-update` to retry, or `composer switch-prod` to fall back to production.
```

Fix the cause, then run `composer switch-update` to retry, or `composer switch-prod` to return to
production. The command exits non-zero, with no PHP stack trace.

## Previewing a switch

```bash
composer switch-preview-dev
composer switch-preview-prod
```

A preview runs the same code path as a real switch, but never touches disk: every file stays byte-identical and every modification time stays unchanged. The planned operations match what a real switch from the same starting state would perform, even from the `INITIAL` state where `composer-prod.json` does not exist yet.

A preview prints the same transition-naming header and change set a real switch's plan does, but keeps the explicit "preview of switch … (nothing will be changed)" wording and labels the planned-command line `Would run:` (a real switch's own plan instead reads `switch …` with no such wording, and labels it `Next:` — see [above](#what-you-will-be-asked-to-confirm)), under the same `-v` gating described [above](#verbose-output): at default verbosity, each planned file operation (`would <type>: <source> -> <target> (<reason>)`) and the switcher's bookkeeping messages are hidden, visible only with `-v`.

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
