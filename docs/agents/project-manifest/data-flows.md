# Key Data Flows

## 1. Switch to Development Mode

```
User calls ConfigSwitcher::switchToDevelopment(): SwitchOutcome
  → switchTo(MODE_DEV): SwitchOutcome
    → Validates mode string
    → Checks main lock file exists (warns if not — does not abort)
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
    → Returns a SwitchOutcome (mode, dry-run flag, messages, file operations)
```

## 2. Switch to Production Mode

```
User calls ConfigSwitcher::switchToProduction(): SwitchOutcome
  → switchTo(MODE_PROD): SwitchOutcome
    → Validates mode string
    → Checks main lock file exists (warns if not — does not abort)
    → switch_copyLockFiles('prod')
      → If initial state: switch_initProductionFiles()
      → If coming from DEV: switch_case_DEV_PROD()
        → Backs up DEV lock file → dev lock location, if it exists
        → Restores PROD config → composer.json (via ConfigFile::copyTo())
        → If a composer-prod.lock backup exists: restores it → composer.lock
        → Otherwise: deletes composer.lock and adds MESSAGE_PROD_LOCK_MISSING,
          instead of the unconditional copy throwing ERROR_CANNOT_COPY_FILE
    → StatusFile::saveState('prod', ...)
    → Writes flag files: creates composer.json.PROD, deletes composer.json.DEV
    → Displays collected messages
    → Returns a SwitchOutcome (mode, dry-run flag, messages, file operations)
```

## 3. Update Current Configuration

```
User calls ConfigSwitcher::switchUpdate(): SwitchOutcome
  → Reads StatusFile to determine current mode
  → If DEV: calls switchToDevelopment() (refreshes DEV config from current local-repositories.json)
  → If PROD: calls switchToProduction() (reconciles content-first, using
    modified dates only to pick a direction — see §4)
  → If INITIAL (no prior switch): no file-system effect, but still returns
    an explicit SwitchOutcome (mode: MODE_INITIAL, no operations) rather
    than a silent no-op with no return value
```

## 4. PROD-to-PROD Reconciliation (via switchUpdate, re-switch, or a direct reconcile() call)

```
switchToProduction() when already in PROD mode
  → switch_case_PROD_PROD()
    → Adds MESSAGE_USING_PROD_CONFIG
    → Delegates into the shared reconciliation core (reconcileCore(null)),
      the same core the public reconcile() method calls — guaranteeing
      identical file effects between a PROD→PROD switch and a direct
      reconcile() call from the same starting state. No dry-run flag of
      its own is passed: the nested call simply inherits whatever flag
      the enclosing switchTo() already set, so a PROD-mode preview can
      never let this nested reconcile perform a real write.

ConfigSwitcher::reconcile(?string $direction = null, bool $dryRun = false): SwitchOutcome
  → Validates $direction (throws ERROR_INVALID_RECONCILE_DIRECTION for a
    non-null, non-constant value — in every state, INITIAL included)
  → Sets the dry-run flag and clears the file-operation log
    → If $dryRun: adds MESSAGE_DRY_RUN_ACTIVE
  → Calls the private reconcileCore($direction) inside a try/finally that
    always restores the facade's previous dry-run flag afterward, even
    if reconcileCore() throws (e.g. a malformed composer.json)
  → reconcileCore() first calls reconcile_detectBlocker($direction), in order:
    → If DEV mode: blocked — composer.json has been rewritten for local
      repositories and is not meaningful to reconcile
    → If INITIAL state (no switch has ever been run): blocked,
      regardless of $direction — there is no baseline yet to reconcile
      against
    → If composer-prod.json does not exist and $direction is not
      RECONCILE_TO_PROD: blocked — reconciling against a file that does
      not exist would otherwise reach requireModifiedDate() and throw;
      naming RECONCILE_TO_PROD explicitly is the one direction that
      recovers instead (see below)
    → A blocker, if any, records its message (MESSAGE_DEV_MODE_NOT_RECONCILABLE
      / MESSAGE_INITIAL_NOT_RECONCILABLE / MESSAGE_PROD_CONFIG_MISSING) and
      returns (no-op) immediately — verify() is never called
  → Otherwise calls verify() (content-first: decides *whether* to act)
    → If in sync: adds MESSAGE_ALREADY_IN_SYNC, returns (no-op), even
      when composer.json and composer-prod.json have different
      modification times
  → Resolves a direction (decides *which way* to copy):
    → $direction explicit argument, if given (RECONCILE_TO_MAIN or
      RECONCILE_TO_PROD) — also used to resolve the ambiguous case
      below, and to recover a missing composer-prod.json when it is
      RECONCILE_TO_PROD
    → Otherwise, compares modification times of composer.json vs
      composer-prod.json:
      → If main is newer: direction = RECONCILE_TO_PROD (user edited
        composer.json directly)
      → If prod is newer: direction = RECONCILE_TO_MAIN (user edited
        composer-prod.json)
      → If equal (and content differs, since verify() already ruled
        out the in-sync case): adds MESSAGE_RECONCILE_AMBIGUOUS,
        returns (no-op) — the two files disagree but there is no
        modification-time signal to pick a direction automatically
  → Applies the resolved direction, if any:
    → RECONCILE_TO_PROD: adds MESSAGE_BACKED_UP_MAIN_TO_PROD, copies
      composer.json → composer-prod.json plus its lock file (this is
      also how a missing composer-prod.json is recreated)
    → RECONCILE_TO_MAIN: adds MESSAGE_RESTORED_PROD_TO_MAIN, copies
      composer-prod.json → composer.json plus its lock file
  → Returns a SwitchOutcome (mode, dry-run flag, messages, file operations)
    — mode is MODE_INITIAL when the INITIAL blocker fired, otherwise the
    current status mode
```

## 5a. Describe Current State

```
User calls ConfigSwitcher::describe(): SwitchDescription
  → Reads StatusFile: mode, last switch date
  → Builds a per-file record (label, path, exists, modifiedDate) for:
    main/prod/dev configs, the status file, and all three lock files
  → Determines the active flag file (dev/prod/none)
  → Calls verify() → VerificationResult
  → Reads the dev config's local-repositories list
    → If the dev file is missing or malformed: returns an empty list
      and attaches a warning instead of throwing
  → Returns a new SwitchDescription(mode, lastSwitchDate, files,
    activeFlag, verification, localRepositories, warnings)
  → No files are modified (read-only), never throws

SwitchDescription::toJSON(): string
  → Encodes toArray() with JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
```

## 5b. Preview a Switch (Dry Run)

```
User calls ConfigSwitcher::previewSwitch(string $mode): SwitchOutcome
  → Validates $mode
  → Suppresses message display for the duration of the call
  → Calls switchTo($mode, dryRun: true)
    → Sets the facade's dry-run flag and adds MESSAGE_DRY_RUN_ACTIVE
    → Runs the exact same switch code path as a real switchTo() call,
      routed through the FileSystem dry-run overlay so no file is
      actually copied/written/deleted
    → Restores the previous dry-run flag in a finally block, even if
      an exception is thrown mid-switch
  → Returns a SwitchOutcome whose getOperations() lists every planned
    FileOperation (isApplied(): false), matching exactly what a real
    switch from the same starting state would perform — including
    from the INITIAL state, where composer-prod.json does not yet exist,
    and from a drifted PROD state, where switch_case_PROD_PROD()'s nested
    reconcile inherits this call's dry-run flag rather than performing a
    real reconcile copy (see §4)
```

## 5c. Verify Configuration

```
User calls ConfigSwitcher::verify()
  → If StatusFile::isDEV(): returns early with new VerificationResult(devMode: true, inSync: false, differences: [])
  → Reads mainFile->getData() and prodFile->getData()
  → Recursively ksort() both arrays (key-order normalization)
  → Collects union of all top-level keys
  → For each key: compares normalized values (strict equality)
  → differences[] = keys whose values differ
  → Returns new VerificationResult(devMode: false, inSync: empty($differences), differences: $differences)
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

Available entry points: `composerSwitchDev`, `composerSwitchProd`, `composerSwitchUpdate`, `composerVerifyConfig`, `composerInstallHooks`, `composerSwitchDescribe`, `composerSwitchDescribeJson`, `composerSwitchReconcile`, `composerSwitchPreviewDev`, `composerSwitchPreviewProd`. The last four delegate to `describe()`, `reconcile()`, and `previewSwitch()` respectively (see §5a/§4/§5b) instead of `switchToDevelopment()`/`switchToProduction()`/`verify()`.

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
