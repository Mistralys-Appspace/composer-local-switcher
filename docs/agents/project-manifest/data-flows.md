# Key Data Flows

## 1. Switch to Development Mode

```
User calls ConfigSwitcher::switchToDevelopment()
  → switchTo(MODE_DEV)
    → Validates mode string
    → Checks main lock file exists (warns if not)
    → switch_copyLockFiles('dev')
      → If initial state (no prior switch):
        → switch_initProductionFiles(): copies composer.json → composer-prod.json (and lock)
      → Backs up PROD lock file → prod lock location
      → Restores DEV lock file if it exists, otherwise deletes lock to force `composer update`
      → switch_adjustConfigForDev():
        → Reads prod config as base
        → Reads dev config (local-repositories list)
        → For each local repo entry:
          → Sets require version to '*' (or explicit version if specified)
          → Preserves require-dev placement (does not move packages to require)
          → Builds path repository entry (type: path, symlink: true)
          → Scans all existing repository entries for URL matches via urlMatchesPackageName()
            (stripos + boundary check: next char must be `.`, `/`, or end-of-string):
            → First match: replaced in place with path entry (UPDATE)
            → Subsequent matches: removed as stale duplicates (PRUNE)
            → No match: path entry appended (ADD)
          → Re-indexes repositories array after pruning
        → Writes modified config → composer.json via ConfigFile::putData()
    → StatusFile::saveState('dev', ...) — persists mode + timestamp + file paths as JSON
    → Writes flag files: creates composer.json.DEV, deletes composer.json.PROD
    → Displays collected messages
```

## 2. Switch to Production Mode

```
User calls ConfigSwitcher::switchToProduction()
  → switchTo(MODE_PROD)
    → Validates mode string
    → Checks main lock file exists
    → switch_copyLockFiles('prod')
      → If initial state: switch_initProductionFiles()
      → Backs up DEV lock file → dev lock location
      → Restores PROD config → composer.json (via ConfigFile::copyTo())
      → Restores PROD lock file → composer.lock
    → StatusFile::saveState('prod', ...)
    → Writes flag files: creates composer.json.PROD, deletes composer.json.DEV
    → Displays collected messages
```

## 3. Update Current Configuration

```
User calls ConfigSwitcher::switchUpdate()
  → Reads StatusFile to determine current mode
  → If DEV: calls switchToDevelopment() (refreshes DEV config from current local-repositories.json)
  → If PROD: calls switchToProduction() (reconciles using modified dates)
  → If INITIAL (no prior switch): no-op
```

## 4. PROD-to-PROD Reconciliation (via switchUpdate or re-switch)

```
switchToProduction() when already in PROD mode
  → switch_case_PROD_PROD()
    → Compares modified dates of composer.json vs composer-prod.json
    → If main is newer: backs up main → prod (user edited composer.json directly)
    → If prod is newer: restores prod → main (user edited composer-prod.json)
    → If equal: no file operations
```

## 5. Verify Configuration

```
User calls ConfigSwitcher::verify()
  → If StatusFile::isDEV(): returns early with devMode=true, inSync=false, differences=[]
  → Reads mainFile->getData() and prodFile->getData()
  → Recursively ksort() both arrays (key-order normalization)
  → Collects union of all top-level keys
  → For each key: compares normalized values (strict equality)
  → differences[] = keys whose values differ
  → Returns array('inSync' => empty($differences), 'differences' => $differences)
  → No files are modified (read-only)
```

## 6. Install Git Hooks

```
User calls ConfigSwitcher::installGitHooks($projectRoot)
  → Checks if $projectRoot/.git/hooks/ directory exists
  → If missing: returns false (no directory creation, no exception)
  → Copies resources/git-hooks/pre-commit → $projectRoot/.git/hooks/pre-commit
  → Sets permissions to 0755
  → Returns true
```

## 7. Composer Script Entry Points

```
Consumer wires a built-in entry point in composer.json scripts
  → Composer invokes e.g. ConfigSwitcher::composerSwitchDev()
    → Calls fromProjectRoot(getcwd())
      → Constructs ConfigSwitcher with:
        - getcwd()/composer.json
        - getcwd()/composer/composer-prod.json
        - getcwd()/composer/local-repositories.json
    → Delegates to the corresponding method (switchToDevelopment, verify, etc.)
```

Available entry points: `composerSwitchDev`, `composerSwitchProd`, `composerSwitchUpdate`, `composerVerifyConfig`, `composerInstallHooks`.

## File Relationships

```
composer.json            ← mutable working copy (switched between DEV/PROD content)
composer.lock            ← follows the active configuration
composer-prod.json       ← immutable production baseline
composer-prod.lock       ← production lock file backup
local-repositories.json  ← local-repositories list (input only, never modified)
local-repositories.lock  ← development lock file backup
local-repositories.status ← JSON status file (mode, date, file paths)
composer.json.DEV        ← flag file (exists only in DEV mode)
composer.json.PROD       ← flag file (exists only in PROD mode)
```
