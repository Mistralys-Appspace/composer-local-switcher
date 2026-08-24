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
          → Builds path repository entry (type: path, symlink: true)
          → Updates or inserts repository entry in config
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
  → If DEV: calls switchToDevelopment() (refreshes DEV config from current dev-config)
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

## File Relationships

```
composer.json            ← mutable working copy (switched between DEV/PROD content)
composer.lock            ← follows the active configuration
composer-prod.json       ← immutable production baseline
composer-prod.lock       ← production lock file backup
dev-config.json          ← local-repositories list (input only, never modified)
dev-config.lock          ← development lock file backup
dev-config.status        ← JSON status file (mode, date, file paths)
composer.json.DEV        ← flag file (exists only in DEV mode)
composer.json.PROD       ← flag file (exists only in PROD mode)
```
