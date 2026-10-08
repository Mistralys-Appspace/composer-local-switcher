<?php
/**
 * @package Composer Switcher
 * @subpackage Core
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher;

use Mistralys\ComposerSwitcher\State\ComposerCommand;
use Mistralys\ComposerSwitcher\State\ConfigChangeSet;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\InstalledState;
use Mistralys\ComposerSwitcher\State\LocalRepository;
use Mistralys\ComposerSwitcher\State\LockStatus;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
use Mistralys\ComposerSwitcher\Utils\BaseFile;
use Mistralys\ComposerSwitcher\Utils\ConfigDiff;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\ComposerProcess;
use Mistralys\ComposerSwitcher\Utils\ConsoleWriter;
use Mistralys\ComposerSwitcher\Utils\DevConfigTransformer;
use Mistralys\ComposerSwitcher\Utils\EventContext;
use Mistralys\ComposerSwitcher\Utils\FileSystem;
use Mistralys\ComposerSwitcher\Utils\FlagFile;
use Mistralys\ComposerSwitcher\Utils\InstalledPackages;
use Mistralys\ComposerSwitcher\Utils\LockFile;
use Mistralys\ComposerSwitcher\Utils\OutcomeRenderer;
use Mistralys\ComposerSwitcher\Utils\StatusFile;
use Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner;

/**
 * @package Composer Switcher
 * @subpackage Core
 */
class ConfigSwitcher
{
    public const MODE_DEV = 'dev';
    public const MODE_PROD = 'prod';

    /**
     * {@see self::switchUpdate()} mode reported when no switch has ever
     * been run yet (the status file does not exist), replacing the
     * previous silent no-op with an explicit, inspectable outcome.
     */
    public const MODE_INITIAL = 'initial';

    /**
     * {@see self::switch_planProdToDev()} — the main lock is `Missing`
     * rather than `Stale`: the switch still completes, planning a full
     * `update` instead of a partial one.
     */
    public const MESSAGE_NO_LOCK_FILE_FOUND = 182201;
    public const MESSAGE_USING_DEV_CONFIG = 182203;
    public const MESSAGE_USING_PROD_CONFIG = 182204;
    public const MESSAGE_REBUILT_DEV_CONFIG = 182209;
    public const MESSAGE_DRY_RUN_ACTIVE = 182213;

    /**
     * {@see self::switch_planDevToProd()} — re-scoped under v3 from "no
     * DEV lock backup to restore" to "no production *snapshot* lock
     * backup to restore" (`composer-prod.lock`), since the DEV lock
     * concept no longer exists — `composer.lock` always stays the PROD
     * lock throughout a DEV session.
     */
    public const MESSAGE_PROD_LOCK_MISSING = 182214;

    /**
     * {@see self::switch_planProdToDev()} — the main lock's
     * {@see LockFile::getLockStatus()} is {@see LockStatus::Stale}
     * before the switch even starts, blocking it with no file effects:
     * the committed PROD state is already inconsistent, so the user
     * must run `composer update` (or `update --lock`) first.
     */
    public const MESSAGE_PROD_LOCK_OUTDATED = 182217;

    /**
     * {@see self::switch_planDevRefresh()} / {@see self::switch_planDevToProd()} —
     * the production snapshot (`composer-prod.json`/`.lock`) has been
     * edited since it was taken (its recorded `snapshotHash` no longer
     * matches its current content), blocking the switch with no file
     * effects: a manually edited snapshot can no longer be trusted as
     * the three-way revert's base/target.
     */
    public const MESSAGE_SNAPSHOT_MODIFIED = 182218;

    /**
     * {@see self::switch_planDevToProd()} — the production snapshot is
     * missing entirely, blocking the switch with no file effects.
     */
    public const MESSAGE_SNAPSHOT_MISSING = 182219;

    /**
     * {@see self::switch_planDevToProd()} — the `prodConfig` section of
     * the resulting {@see ConfigChangeSet} is non-empty: one or more
     * DEV-time edits to non-managed entries are becoming permanent in
     * production.
     */
    public const MESSAGE_DEV_CHANGES_CARRIED_BACK = 182220;

    /**
     * {@see self::switch_planDevRefresh()} / {@see self::switch_planDevToProd()} —
     * a managed (local package) entry was edited directly in DEV and is
     * being reset to its snapshot value instead of kept, per
     * {@see \Mistralys\ComposerSwitcher\State\RevertResult::getOverriddenManagedEntries()}.
     */
    public const MESSAGE_MANAGED_ENTRY_OVERRIDDEN = 182221;

    /**
     * {@see self::switch_cleanLegacyArtifacts()} — a v2-era artifact
     * (a committed-style `composer-prod.json` found outside an active
     * DEV session, or a leftover `local-repositories.lock`) was found
     * and cleaned up.
     */
    public const MESSAGE_LEGACY_FILES_FOUND = 182225;

    /**
     * {@see self::switch_planProdToDev()} / {@see self::switch_planDevRefresh()} —
     * a local package's path repository alias was derived (from an
     * explicit override or the PROD lock's locked version), per
     * {@see \Mistralys\ComposerSwitcher\State\DevTransformResult::getVersionDerivations()}.
     */
    public const MESSAGE_VERSION_DERIVED = 182226;

    /**
     * {@see self::switch_planDevRefresh()} / {@see self::switch_planDevToProd()} /
     * {@see self::switch_planProdToProd()} — the installed state already
     * matches what the target mode expects, so no Composer command is
     * planned.
     */
    public const MESSAGE_ALREADY_INSTALLED = 182227;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} —
     * printed immediately before a planned command is actually executed.
     */
    public const MESSAGE_COMPOSER_COMMAND = 182222;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} —
     * printed instead of executing the planned command, when `--no-install`
     * was passed.
     */
    public const MESSAGE_COMPOSER_SKIPPED = 182223;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — a
     * switch entry point no-opped because `COMPOSER_SWITCHER_NESTED` is
     * set, i.e. this process was itself launched by the switcher (e.g. a
     * leftover `post-update-cmd` hook firing during the switcher's own
     * `composer update`), preventing recursion.
     */
    public const MESSAGE_NESTED_RUN_SKIPPED = 182224;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — a
     * switch that would change `composer.json` was run non-interactively
     * without `--yes`.
     */
    public const MESSAGE_CONFIRMATION_REQUIRED = 182228;

    /**
     * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} — the
     * user declined the interactive confirmation prompt; nothing was
     * written.
     */
    public const MESSAGE_SWITCH_CANCELLED = 182229;

    public const KEY_LOCAL_REPOSITORIES = 'local-repositories';
    public const KEY_REPOSITORIES = 'repositories';

    /**
     * @var ConfigFile
     */
    private $prodFile;

    /**
     * @var ConfigFile
     */
    private $devFile;

    /**
     * @var ConfigFile
     */
    private $mainFile;

    /**
     * @var StatusFile
     */
    private $statusFile;

    /**
     * @var ConsoleWriter
     */
    private $console;

    /**
     * @var FileSystem
     */
    private $fileSystem;

    /**
     * @var bool
     */
    private $displayMessages = true;

    /**
     * @var bool Whether to create a flag file alongside the main composer.json file.
     */
    private $flagFileEnabled = true;

    /**
     * @var SwitchMessage[]
     */
    private $messages = array();

    /**
     * Creates a ConfigSwitcher using the three-path convention
     * shared by both consumer projects.
     *
     * @param string $rootPath Absolute path to the project root.
     * @return self
     */
    public static function fromProjectRoot(string $rootPath) : self
    {
        $rootPath = rtrim($rootPath, '/');

        return new self(
            new ConfigFile($rootPath . '/composer.json'),
            new ConfigFile($rootPath . '/composer/composer-prod.json'),
            new ConfigFile($rootPath . '/composer/local-repositories.json')
        );
    }

    /**
     * Switches to DEV mode, via a {@see SwitchCommandRunner} built from
     * `$event` — the only place `switch-dev` executes Composer or
     * prompts the user. See "Confirmation (transparency)" in the plan
     * for the full sequence.
     */
    public static function composerSwitchDev(?object $event = null) : void
    {
        self::buildRunner($event)->runSwitch(self::MODE_DEV);
    }

    /**
     * Switches to PROD mode. See {@see self::composerSwitchDev()}.
     */
    public static function composerSwitchProd(?object $event = null) : void
    {
        self::buildRunner($event)->runSwitch(self::MODE_PROD);
    }

    /**
     * Refreshes the current mode (DEV stays DEV, PROD/INITIAL stays
     * PROD) — see {@see SwitchCommandRunner::runUpdate()}.
     */
    public static function composerSwitchUpdate(?object $event = null) : void
    {
        self::buildRunner($event)->runUpdate();
    }

    /**
     * Builds the {@see SwitchCommandRunner} every switch/update/preview
     * entry point delegates to: an {@see EventContext} from `$event`
     * (the only place this library duck-types Composer's event/IO), a
     * switcher rooted at the current working directory, a real
     * {@see ComposerProcess}, and an {@see OutcomeRenderer} writing
     * through that same context.
     */
    private static function buildRunner(?object $event) : SwitchCommandRunner
    {
        $context = EventContext::fromEvent($event);

        return new SwitchCommandRunner(
            $context,
            self::fromProjectRoot(getcwd()),
            new ComposerProcess(),
            new OutcomeRenderer($context)
        );
    }

    public static function composerInstallHooks() : void
    {
        $root = getcwd();
        $switcher = self::fromProjectRoot($root);
        $installed = $switcher->installGitHooks($root);

        if($installed) {
            echo 'Git hooks installed successfully.' . PHP_EOL;
        } else {
            echo 'WARNING: .git/hooks/ directory not found — hooks were not installed.' . PHP_EOL;
        }
    }

    /**
     * Renders {@see self::describe()}'s state-of-the-world snapshot as a
     * human-readable report, through an {@see OutcomeRenderer} built
     * from `$event`.
     */
    public static function composerSwitchDescribe(?object $event = null) : void
    {
        $context = EventContext::fromEvent($event);

        (new OutcomeRenderer($context))->renderDescription(self::fromProjectRoot(getcwd())->describe());
    }

    /**
     * Renders {@see self::describe()}'s state-of-the-world snapshot as
     * pretty-printed JSON, for programmatic consumption.
     */
    public static function composerSwitchDescribeJson(?object $event = null) : void
    {
        EventContext::fromEvent($event)->write(self::fromProjectRoot(getcwd())->describe()->toJSON());
    }

    /**
     * Previews a switch to DEV mode: renders every {@see FileOperation}
     * that a real `switch-dev` would perform, without touching disk.
     */
    public static function composerSwitchPreviewDev(?object $event = null) : void
    {
        self::buildRunner($event)->runPreview(self::MODE_DEV);
    }

    /**
     * Previews a switch to PROD mode: renders every {@see FileOperation}
     * that a real `switch-prod` would perform, without touching disk.
     */
    public static function composerSwitchPreviewProd(?object $event = null) : void
    {
        self::buildRunner($event)->runPreview(self::MODE_PROD);
    }

    /**
     * @param ConfigFile $mainFile The main `composer.json` file.
     * @param ConfigFile $prodFile The production `composer-prod.json` file.
     * @param ConfigFile $devConfig The development configuration file, containing the list of local repositories.
     */
    public function __construct(ConfigFile $mainFile, ConfigFile $prodFile, ConfigFile $devConfig)
    {
        $this->fileSystem = new FileSystem();

        $this->prodFile = $prodFile;
        $this->devFile = $devConfig;
        $this->mainFile = $mainFile;
        $this->statusFile = new StatusFile(str_replace('.json', '.status', $devConfig->getPath()));
        $this->console = new ConsoleWriter();

        // Propagate the single shared facade to every file this
        // switcher owns, so that dry-run mode and recorded file
        // operations apply consistently across all of them.
        $this->mainFile->setFileSystem($this->fileSystem);
        $this->prodFile->setFileSystem($this->fileSystem);
        $this->devFile->setFileSystem($this->fileSystem);
        $this->statusFile->setFileSystem($this->fileSystem);
    }

    /**
     * The single {@see FileSystem} facade shared by this switcher and
     * every file it owns (`$mainFile`, `$prodFile`, `$devFile`,
     * `$statusFile`, and every {@see FlagFile} returned by
     * {@see self::getFlagFile()}).
     */
    public function getFileSystem() : FileSystem
    {
        return $this->fileSystem;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setFlagFileEnabled(bool $enabled) : self
    {
        $this->flagFileEnabled = $enabled;
        return $this;
    }

    /**
     * @param bool $write
     * @return $this
     */
    public function setWriteToConsole(bool $write) : self
    {
        $this->console->setEnabled($write);
        return $this;
    }

    public function getMainFile(): ConfigFile
    {
        return $this->mainFile;
    }

    public function getDevFile(): ConfigFile
    {
        return $this->devFile;
    }

    public function getProdFile(): ConfigFile
    {
        return $this->prodFile;
    }

    public function getStatus(): StatusFile
    {
        return $this->statusFile;
    }

    /**
     * Assembles the full state-of-the-world snapshot: mode, last switch
     * date, per-file existence/modification-date records, active flag
     * file, {@see LockStatus}, {@see InstalledState}, the DEV-only
     * pending production changes ({@see SwitchDescription::getPendingProdChanges()}),
     * and the parsed `local-repositories` list (each entry carrying its
     * {@see SwitchDescription::getLocalRepositories() derivedVersion}).
     *
     * Never throws: a missing/malformed dev config, a missing/modified
     * snapshot, or a missing/malformed `installed.json` all degrade to
     * a `null`/safe-default field plus a {@see SwitchDescription::getWarnings()}
     * entry, so this call is safe in any state (INITIAL, DEV, or PROD).
     *
     * @return SwitchDescription
     */
    public function describe() : SwitchDescription
    {
        $mode = $this->statusFile->getMode() ?? self::MODE_INITIAL;

        $activeFlag = null;
        if($this->getFlagFile(self::MODE_PROD)->exists()) {
            $activeFlag = self::MODE_PROD;
        } else if($this->getFlagFile(self::MODE_DEV)->exists()) {
            $activeFlag = self::MODE_DEV;
        }

        [$repos, $warnings] = $this->describe_readLocalRepositories();

        $referenceLock = $this->statusFile->isDEV() ? $this->prodFile->getLockFile() : $this->mainFile->getLockFile();

        $localRepositories = array_map(
            static function(LocalRepository $repo) use ($referenceLock) : array {
                return array(
                    'packageName' => $repo->getPackageName(),
                    'path' => $repo->getPath(),
                    'version' => $repo->getVersion(),
                    'derivedVersion' => $repo->getVersionOverride() ?? $referenceLock->getLockedVersion($repo->getPackageName())
                );
            },
            $repos
        );

        $installedState = InstalledState::fromInstalledPackages(
            $this->statusFile->isDEV() ? self::MODE_DEV : self::MODE_PROD,
            array_map(static fn(LocalRepository $repo) : string => $repo->getPackageName(), $repos),
            $this->switch_installedPackages()
        );

        [$pendingProdChanges, $pendingWarnings] = $this->describe_computePendingProdChanges($mode);

        return new SwitchDescription(
            $mode,
            $this->statusFile->getDate(),
            $this->describe_buildFileRecords(),
            $activeFlag,
            $this->mainFile->getLockFile()->getLockStatus(),
            $installedState,
            $pendingProdChanges,
            $localRepositories,
            array_merge($warnings, $pendingWarnings, $this->describe_legacyArtifactWarnings($mode))
        );
    }

    /**
     * Computes {@see SwitchDescription::getPendingProdChanges()}: the
     * DEV-time edits that would become permanent production changes on
     * a DEV→PROD switch, via the same `revert()` + {@see ConfigDiff}
     * pipeline a real switch uses — only its `prodConfig` section is
     * ever filled. `null` (with an explanatory warning, never a thrown
     * exception) outside DEV mode, or when the snapshot is missing,
     * modified, or malformed.
     *
     * @return array{0:?ConfigChangeSet,1:string[]}
     */
    private function describe_computePendingProdChanges(string $mode) : array
    {
        if($mode !== self::MODE_DEV) {
            return array(null, array());
        }

        if(!$this->prodFile->exists()) {
            return array(null, array('Cannot compute pending production changes: the production snapshot (`' . $this->prodFile->getBaseName() . '`) is missing.'));
        }

        if($this->switch_isSnapshotModified()) {
            return array(null, array('Cannot compute pending production changes: the production snapshot (`' . $this->prodFile->getBaseName() . '`) has been modified since it was taken.'));
        }

        try {
            $snapshotConfig = $this->prodFile->getData();
            $current = $this->mainFile->getData();
        } catch(ComposerSwitcherException $e) {
            return array(null, array('Cannot compute pending production changes: ' . $e->getMessage()));
        }

        $appliedRepos = $this->switch_readAppliedRepositories();
        $prodLock = $this->prodFile->getLockFile();

        $revertResult = DevConfigTransformer::revert($current, $snapshotConfig, $appliedRepos, $prodLock);

        $classifier = DevConfigTransformer::makeOriginClassifier($appliedRepos, $revertResult);
        $prodConfigChanges = ConfigDiff::between($snapshotConfig, $revertResult->getEffectiveConfig(), $classifier);

        return array(new ConfigChangeSet(array(), $prodConfigChanges), array());
    }

    /**
     * Surfaces (without cleaning up — `describe()` is read-only) the
     * same v2-era legacy artifacts {@see self::switch_cleanLegacyArtifacts()}
     * removes on the next real switch.
     *
     * @return string[]
     */
    private function describe_legacyArtifactWarnings(string $mode) : array
    {
        $warnings = array();

        if($this->devFile->getLockFile()->exists()) {
            $warnings[] = sprintf(
                'Legacy artifact found: `%s` (a pre-v3 DEV lock backup) will be cleaned up on the next switch.',
                $this->devFile->getLockFile()->getBaseName()
            );
        }

        if($mode !== self::MODE_DEV && $this->prodFile->exists()) {
            $warnings[] = sprintf(
                'Legacy artifact found: `%s` exists outside an active DEV session and will be cleaned up on the next switch.',
                $this->prodFile->getBaseName()
            );
        }

        return $warnings;
    }

    /**
     * @return array<int,array{label:string,path:string,exists:bool,modifiedDate:string|null}>
     */
    private function describe_buildFileRecords() : array
    {
        return array(
            $this->describe_buildFileRecord('main', $this->mainFile),
            $this->describe_buildFileRecord('prod', $this->prodFile),
            $this->describe_buildFileRecord('dev', $this->devFile),
            $this->describe_buildFileRecord('status', $this->statusFile),
            $this->describe_buildFileRecord('mainLock', $this->mainFile->getLockFile()),
            $this->describe_buildFileRecord('prodLock', $this->prodFile->getLockFile()),
            $this->describe_buildFileRecord('devLock', $this->devFile->getLockFile()),
        );
    }

    /**
     * @param string $label
     * @param BaseFile $file
     * @return array{label:string,path:string,exists:bool,modifiedDate:string|null}
     */
    private function describe_buildFileRecord(string $label, BaseFile $file) : array
    {
        $modifiedDate = $file->getModifiedDate();

        return array(
            'label' => $label,
            'path' => $file->getPath(),
            'exists' => $file->exists(),
            'modifiedDate' => $modifiedDate !== null ? $modifiedDate->format('Y-m-d H:i:s') : null
        );
    }

    /**
     * Reads and parses the dev file's `local-repositories` list for
     * {@see self::describe()}. Degrades to an empty list plus a
     * human-readable warning — rather than propagating an exception —
     * when the dev file is missing, is not valid JSON, or does not
     * contain a well-formed `local-repositories` array, so `describe()`
     * itself never throws. The list-structure and per-entry validation
     * is delegated to {@see LocalRepository::parseListLenient()}, which
     * also backs the strict validator used elsewhere in the switching
     * model — the file-existence and read-failure checks here are the
     * only part of this method's original logic that remains, since
     * they precede having any decoded data to hand off.
     *
     * Returns the {@see LocalRepository} objects themselves (not yet
     * flattened into the `describe()` array shape), since `describe()`
     * also needs each entry's `versionOverride` to compute its
     * `derivedVersion`.
     *
     * @return array{0:LocalRepository[],1:string[]}
     */
    private function describe_readLocalRepositories() : array
    {
        if(!$this->devFile->exists())
        {
            return array(array(), array('Dev configuration file not found: ' . $this->devFile->getPath()));
        }

        try {
            $devConfig = $this->devFile->getData();
        } catch(ComposerSwitcherException $e) {
            return array(array(), array('Failed to read the dev configuration file: ' . $e->getMessage()));
        }

        return LocalRepository::parseListLenient($devConfig, $this->devFile->getPath());
    }

    /**
     * Copies the bundled pre-commit hook into the project's
     * `.git/hooks/` directory with executable permissions.
     *
     * @param string $projectRoot Absolute path to the project root (containing `.git/`).
     * @return bool True on success, false when `.git/hooks/` does not exist.
     */
    public function installGitHooks(string $projectRoot) : bool
    {
        $hooksDir = rtrim($projectRoot, '/') . '/.git/hooks';

        if(!is_dir($hooksDir)) {
            return false;
        }

        $source = __DIR__ . '/../resources/git-hooks/pre-commit';
        $target = $hooksDir . '/pre-commit';

        copy($source, $target);
        chmod($target, 0755);

        return true;
    }

    /**
     * Refreshes the current configuration: in DEV mode this is a
     * refresh (the DEV→DEV row of the decision table — DEV edits are
     * preserved, a repository delta is planned); in PROD mode, and in
     * the INITIAL state (no switch has ever been run), this is the
     * PROD/INITIAL→PROD row — no file effects beyond legacy cleanup,
     * status and flags, planning `install` only when the installed
     * state does not already match.
     *
     * @return SwitchOutcome
     */
    public function switchUpdate() : SwitchOutcome
    {
        if($this->getStatus()->isDEV()) {
            return $this->switchToDevelopment();
        }

        return $this->switchToProduction();
    }

    public function switchToDevelopment() : SwitchOutcome
    {
        return $this->switchTo(self::MODE_DEV);
    }

    public function switchToProduction() : SwitchOutcome
    {
        return $this->switchTo(self::MODE_PROD);
    }

    /**
     * Switches to the target mode, via the v3 decision table's single
     * post-dispatch planner ({@see self::switch_plan()}): preconditions
     * decide whether the switch is blocked, file effects follow from
     * the final state, and exactly one optional {@see ComposerCommand}
     * is planned — this method itself never executes Composer.
     *
     * A missing lock file no longer aborts the switch: it is a
     * recoverable situation (e.g. a freshly cloned project that has
     * never run `composer update`), so a PROD/INITIAL→DEV switch with
     * no main lock completes in full and only records
     * {@see self::MESSAGE_NO_LOCK_FILE_FOUND} as a warning, planning a
     * full `update` instead of a partial one.
     *
     * @param string $mode
     * @param bool $dryRun When `true`, the facade's dry-run flag is set
     *        for the duration of this call — no file is actually
     *        touched, and {@see self::MESSAGE_DRY_RUN_ACTIVE} is
     *        recorded — and restored to its previous value afterward,
     *        in a `finally` block, so an exception mid-switch can never
     *        leave the switcher stuck in dry-run mode.
     * @return SwitchOutcome A blocked outcome ({@see SwitchOutcome::isBlocked()})
     *         carries no file effects — only messages and the config
     *         changes computed before the block was detected (empty, for
     *         every blocker in this decision table).
     *
     * @see self::MODE_PROD
     * @see self::MODE_DEV
     * @see self::previewSwitch()
     */
    public function switchTo(string $mode, bool $dryRun = false) : SwitchOutcome
    {
        $this->requireValidMode($mode);
        $this->clearMessages();

        $previousDryRun = $this->fileSystem->isDryRun();
        $this->fileSystem->setDryRun($dryRun);
        $this->fileSystem->clearOperations();

        try {
            $this->console->header('Switching to %s composer config', strtoupper($mode));

            if($dryRun)
            {
                $this->addMessage(self::MESSAGE_DRY_RUN_ACTIVE, 'Dry run: no files will actually be changed.');
            }

            $plan = $this->switch_plan($mode);

            if($plan['blocked'])
            {
                $this->autoDisplayMessages();

                return new SwitchOutcome(
                    $mode,
                    $this->fileSystem->isDryRun(),
                    $this->messages,
                    $this->fileSystem->getOperations(),
                    null,
                    true,
                    $plan['changes']
                );
            }

            $this->statusFile->saveState($mode, $this, $plan['snapshotHash'], $plan['appliedRepositories']);

            $this->writeFlagFiles();

            $this->autoDisplayMessages();

            return new SwitchOutcome(
                $mode,
                $this->fileSystem->isDryRun(),
                $this->messages,
                $this->fileSystem->getOperations(),
                $plan['command'],
                false,
                $plan['changes']
            );
        } finally {
            $this->fileSystem->setDryRun($previousDryRun);
        }
    }

    /**
     * Previews a switch to the target mode without touching disk: runs
     * the real {@see self::switchTo()} code path with its `$dryRun`
     * flag set, so the returned {@see SwitchOutcome} describes exactly
     * the operations a real switch from the current state would
     * perform — there is no separate, parallel preview implementation
     * to drift out of sync with the real one.
     *
     * Automatic message display (see {@see self::setDisplayMessages()})
     * is suppressed for the duration of this call and restored
     * afterward, since the caller is expected to render the preview
     * itself (see {@see self::composerSwitchPreviewDev()} and
     * {@see self::composerSwitchPreviewProd()}).
     *
     * @param string $mode
     * @return SwitchOutcome
     *
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_INVALID_SWITCH_MODE}
     */
    public function previewSwitch(string $mode) : SwitchOutcome
    {
        $this->requireValidMode($mode);

        $previousDisplay = $this->displayMessages;
        $this->displayMessages = false;

        try {
            return $this->switchTo($mode, true);
        } finally {
            $this->displayMessages = $previousDisplay;
        }
    }

    private function requireValidMode(string $mode) : void
    {
        $mode = strtolower($mode);

        if(in_array($mode, array(self::MODE_DEV, self::MODE_PROD), true)) {
            return;
        }

        throw (new ComposerSwitcherException(
            sprintf(
                'Invalid switch mode. Allowed modes are: %s',
                implode(', ', array(self::MODE_DEV, self::MODE_PROD)),
            ),
            ComposerSwitcherException::ERROR_INVALID_SWITCH_MODE
        ))
            ->setContext(array(
                ComposerSwitcherException::KEY_MODE => $mode,
                ComposerSwitcherException::KEY_EXPECTED => array(self::MODE_DEV, self::MODE_PROD)
            ));
    }

    public function getFlagFile(string $mode) : FlagFile
    {
        $this->requireValidMode($mode);

        return (new FlagFile($this, $mode))->setFileSystem($this->fileSystem);
    }

    private function writeFlagFiles() : void
    {
        $prod = $this->getFlagFile(self::MODE_PROD);
        $dev = $this->getFlagFile(self::MODE_DEV);

        $prod->delete();
        $dev->delete();

        if($this->flagFileEnabled)
        {
            if($this->getStatus()->isPROD()) {
                $prod->create();
            } else if($this->getStatus()->isDEV()) {
                $dev->create();
            }
        }
    }

    /**
     * The v3 decision table's single post-dispatch planner: runs legacy
     * cleanup first (every switch, regardless of direction), then
     * dispatches to exactly one of the four row-specific planners based
     * on the current status and the target mode.
     *
     * @return array{blocked:bool,command:?ComposerCommand,changes:ConfigChangeSet,snapshotHash:?string,appliedRepositories:?array<int,array<string,mixed>>}
     */
    private function switch_plan(string $mode) : array
    {
        $isDev = $this->getStatus()->isDEV();
        $isProd = $this->getStatus()->isPROD();
        $isInitial = !$isDev && !$isProd;

        $this->switch_cleanLegacyArtifacts($mode, $isProd || $isInitial);

        if($mode === self::MODE_DEV)
        {
            if($isDev) {
                return $this->switch_planDevRefresh();
            }

            return $this->switch_planProdToDev();
        }

        if($isDev) {
            return $this->switch_planDevToProd();
        }

        return $this->switch_planProdToProd();
    }

    /**
     * Cleans up v2-era legacy artifacts, run at the start of every
     * switch regardless of direction:
     *
     * - The DEV config's own lock file (`local-repositories.lock`) is a
     *   v2 artifact — v3 never backs up a separate DEV lock, since
     *   `composer.lock` always stays the PROD lock throughout a DEV
     *   session.
     * - A committed-style `composer-prod.json` found while in PROD/INITIAL
     *   mode predates the transient-snapshot model: switching to DEV
     *   overwrites it as part of the normal snapshot step below;
     *   staying in (or switching to) PROD deletes it outright, since it
     *   has no reason to exist outside an active DEV session.
     *
     * @param bool $isProdOrInitial Whether the switch starts from PROD or the INITIAL state.
     */
    private function switch_cleanLegacyArtifacts(string $mode, bool $isProdOrInitial) : void
    {
        $legacyFound = false;

        if($this->devFile->getLockFile()->exists())
        {
            $this->devFile->getLockFile()->delete();
            $legacyFound = true;
        }

        if($isProdOrInitial && $this->prodFile->exists())
        {
            if($mode === self::MODE_PROD)
            {
                $this->prodFile->delete();
                $this->prodFile->getLockFile()->delete();
            }

            $legacyFound = true;
        }

        if($legacyFound)
        {
            $this->addMessage(self::MESSAGE_LEGACY_FILES_FOUND, 'Found and cleaned up legacy v2 switcher files.');
        }
    }

    /**
     * PROD/INITIAL→DEV: blocked on a `Stale` main lock; otherwise
     * snapshots the main config and lock into `composer-prod.*`,
     * writes `apply(snapshot, repos, mainLock)` into `composer.json`,
     * and plans `update <local names>` (a full `update` when the main
     * lock is `Missing`).
     *
     * @return array{blocked:bool,command:?ComposerCommand,changes:ConfigChangeSet,snapshotHash:?string,appliedRepositories:?array<int,array<string,mixed>>}
     */
    private function switch_planProdToDev() : array
    {
        $this->console->line1('Switching from PROD to DEV...');
        $this->addMessage(self::MESSAGE_USING_DEV_CONFIG, 'Using Composer DEV configuration.');

        $lockStatus = $this->mainFile->getLockFile()->getLockStatus();

        if($lockStatus === LockStatus::Stale)
        {
            $this->addMessage(
                self::MESSAGE_PROD_LOCK_OUTDATED,
                'WARNING: The production lock file is outdated. Run `composer update` (or `update --lock`) before switching to DEV.'
            );

            return $this->switch_blockedPlan();
        }

        $repos = $this->switch_requireCurrentLocalRepositories();

        $snapshotConfig = $this->mainFile->getData();
        $lockExists = $this->mainFile->getLockFile()->exists();
        $lockContent = $lockExists ? $this->mainFile->getLockFile()->getContent() : '';

        // Snapshot the main config and lock into composer-prod.* before
        // rewriting composer.json — composer.lock itself stays the PROD
        // lock throughout the DEV session.
        $this->mainFile->copyTo($this->prodFile);
        $this->mainFile->getLockFile()->tryCopyTo($this->prodFile->getLockFile());

        $applyResult = DevConfigTransformer::apply($snapshotConfig, $repos, $this->mainFile->getLockFile());

        $this->mainFile->putData($applyResult->getConfig());

        $this->switch_recordVersionDerivations($applyResult->getVersionDerivations());

        $this->addMessage(self::MESSAGE_REBUILT_DEV_CONFIG, 'Rebuilt a fresh DEV `composer.json`.');

        $classifier = DevConfigTransformer::makeOriginClassifier($repos);
        $composerJsonChanges = ConfigDiff::between($snapshotConfig, $applyResult->getConfig(), $classifier);

        if(!$lockExists)
        {
            $this->addMessage(
                self::MESSAGE_NO_LOCK_FILE_FOUND,
                'WARNING: No lock file found. Please run `composer update` after switching the config.'
            );

            $command = new ComposerCommand(array('update'), 'No lock file exists yet; installing all dependencies.');
        }
        else
        {
            $packageNames = array_map(static fn(LocalRepository $r) : string => $r->getPackageName(), $repos);

            $command = count($packageNames) > 0
                ? new ComposerCommand(array_merge(array('update'), $packageNames), 'Installing the switched local packages at their aliased versions.')
                : null;
        }

        return array(
            'blocked' => false,
            'command' => $command,
            'changes' => new ConfigChangeSet($composerJsonChanges, array()),
            'snapshotHash' => md5(json_encode($snapshotConfig, JSON_THROW_ON_ERROR) . '|' . $lockContent),
            'appliedRepositories' => $this->switch_serializeRepositories($repos)
        );
    }

    /**
     * DEV→DEV refresh (`switch-dev`/`switch-update` while already in
     * DEV): blocked on a modified snapshot; otherwise computes
     * `effective = revert(current, snapshot, appliedRepos)` and writes
     * `apply(effective, currentRepos)` only when it differs from the
     * current `composer.json` — no write operation is recorded, and
     * the file's bytes stay untouched, when nothing changed.
     *
     * @return array{blocked:bool,command:?ComposerCommand,changes:ConfigChangeSet,snapshotHash:?string,appliedRepositories:?array<int,array<string,mixed>>}
     */
    private function switch_planDevRefresh() : array
    {
        $this->console->line1('Already in DEV mode, refreshing config...');
        $this->addMessage(self::MESSAGE_USING_DEV_CONFIG, 'Using Composer DEV configuration.');

        if($this->switch_isSnapshotModified())
        {
            $this->addMessage(
                self::MESSAGE_SNAPSHOT_MODIFIED,
                'WARNING: The production snapshot (`%s`) has been modified since it was taken; switch to PROD and back to DEV to retake it.',
                $this->prodFile->getBaseName()
            );

            return $this->switch_blockedPlan();
        }

        $snapshotConfig = $this->prodFile->exists() ? $this->prodFile->getData() : array();
        $appliedRepos = $this->switch_readAppliedRepositories();
        $prodLock = $this->prodFile->getLockFile();

        $current = $this->mainFile->getData();

        $revertResult = DevConfigTransformer::revert($current, $snapshotConfig, $appliedRepos, $prodLock);

        $this->switch_recordOverriddenEntries($revertResult->getOverriddenManagedEntries());

        $currentRepos = $this->switch_requireCurrentLocalRepositories();

        $applyResult = DevConfigTransformer::apply($revertResult->getEffectiveConfig(), $currentRepos, $prodLock);
        $newConfig = $applyResult->getConfig();

        $classifier = DevConfigTransformer::makeOriginClassifier($currentRepos, $revertResult);
        $composerJsonChanges = ConfigDiff::between($current, $newConfig, $classifier);

        if(count($composerJsonChanges) > 0)
        {
            $this->mainFile->putData($newConfig);
            $this->addMessage(self::MESSAGE_REBUILT_DEV_CONFIG, 'Rebuilt a fresh DEV `composer.json`.');
        }

        $this->switch_recordVersionDerivations($applyResult->getVersionDerivations());

        $command = $this->switch_planRefreshCommand($appliedRepos, $currentRepos, $prodLock);

        return array(
            'blocked' => false,
            'command' => $command,
            'changes' => new ConfigChangeSet($composerJsonChanges, array()),
            'snapshotHash' => $this->statusFile->getSnapshotHash(),
            'appliedRepositories' => $this->switch_serializeRepositories($currentRepos)
        );
    }

    /**
     * Plans the command for a DEV→DEV refresh: a repository delta
     * (added, path-changed, override-changed, or removed, by package
     * name) plans a partial `update`, with `--with <removed>:<prod-locked
     * version>` for each removed package; with no delta, `install` is
     * planned unless the installed state already {@see InstalledState::Matches}.
     *
     * @param LocalRepository[] $appliedRepos
     * @param LocalRepository[] $currentRepos
     */
    private function switch_planRefreshCommand(array $appliedRepos, array $currentRepos, LockFile $prodLock) : ?ComposerCommand
    {
        $appliedByName = array();
        foreach($appliedRepos as $repo) {
            $appliedByName[$repo->getPackageName()] = $repo;
        }

        $currentByName = array();
        foreach($currentRepos as $repo) {
            $currentByName[$repo->getPackageName()] = $repo;
        }

        $changedOrAdded = array();
        foreach($currentByName as $name => $repo) {
            $previous = $appliedByName[$name] ?? null;

            if($previous === null || $previous->getPath() !== $repo->getPath() || $previous->getVersionOverride() !== $repo->getVersionOverride()) {
                $changedOrAdded[] = $name;
            }
        }

        $removed = array();
        foreach($appliedByName as $name => $repo) {
            if(!isset($currentByName[$name])) {
                $removed[] = $name;
            }
        }

        if(count($changedOrAdded) > 0 || count($removed) > 0)
        {
            $arguments = array_merge(array('update'), $changedOrAdded);

            foreach($removed as $name) {
                $arguments[] = '--with';
                $arguments[] = $name . ':' . ($prodLock->getLockedVersion($name) ?? '*');
            }

            return new ComposerCommand($arguments, 'Refreshing the switched local packages after a `local-repositories.json` change.');
        }

        $installedState = InstalledState::fromInstalledPackages(
            self::MODE_DEV,
            array_map(static fn(LocalRepository $r) : string => $r->getPackageName(), $currentRepos),
            $this->switch_installedPackages()
        );

        if($installedState === InstalledState::Matches)
        {
            $this->addMessage(self::MESSAGE_ALREADY_INSTALLED, 'The switched packages are already installed; nothing to do.');

            return null;
        }

        return new ComposerCommand(array('install'), 'Installing the switched local packages.');
    }

    /**
     * DEV→PROD: blocked on a modified or missing snapshot; otherwise
     * writes the three-way `revert()`'s `effectiveConfig` into
     * `composer.json`, restores the snapshot lock into `composer.lock`,
     * deletes the (now-applied) snapshot, and plans a command per the
     * decision table.
     *
     * @return array{blocked:bool,command:?ComposerCommand,changes:ConfigChangeSet,snapshotHash:?string,appliedRepositories:?array<int,array<string,mixed>>}
     */
    private function switch_planDevToProd() : array
    {
        $this->console->line1('Switching from DEV to PROD...');
        $this->addMessage(self::MESSAGE_USING_PROD_CONFIG, 'Using Composer PROD configuration.');

        if(!$this->prodFile->exists())
        {
            $this->addMessage(
                self::MESSAGE_SNAPSHOT_MISSING,
                'WARNING: The production snapshot (`%s`) is missing; cannot switch back to PROD safely.',
                $this->prodFile->getBaseName()
            );

            return $this->switch_blockedPlan();
        }

        if($this->switch_isSnapshotModified())
        {
            $this->addMessage(
                self::MESSAGE_SNAPSHOT_MODIFIED,
                'WARNING: The production snapshot (`%s`) has been modified since it was taken.',
                $this->prodFile->getBaseName()
            );

            return $this->switch_blockedPlan();
        }

        $snapshotConfig = $this->prodFile->getData();
        $appliedRepos = $this->switch_readAppliedRepositories();
        $prodLock = $this->prodFile->getLockFile();

        $current = $this->mainFile->getData();

        $revertResult = DevConfigTransformer::revert($current, $snapshotConfig, $appliedRepos, $prodLock);
        $effectiveConfig = $revertResult->getEffectiveConfig();

        $this->switch_recordOverriddenEntries($revertResult->getOverriddenManagedEntries());

        $classifier = DevConfigTransformer::makeOriginClassifier($appliedRepos, $revertResult);
        $prodConfigChanges = ConfigDiff::between($snapshotConfig, $effectiveConfig, $classifier);
        $composerJsonChanges = ConfigDiff::between($current, $effectiveConfig, $classifier);

        $this->mainFile->putData($effectiveConfig);

        $lockRestored = false;
        if($prodLock->exists())
        {
            $prodLock->copyTo($this->mainFile->getLockFile());
            $lockRestored = true;
        }

        // The snapshot is transient: delete it now that it has been
        // applied back onto the main files.
        $this->prodFile->delete();
        $this->prodFile->getLockFile()->delete();

        $changes = new ConfigChangeSet($composerJsonChanges, $prodConfigChanges);

        if($changes->hasPermanentChanges())
        {
            $this->addMessage(
                self::MESSAGE_DEV_CHANGES_CARRIED_BACK,
                'DEV-time edits were carried back into production — keys: [%s], packages: [%s].',
                implode(', ', $changes->getProdChangedKeys()),
                implode(', ', $changes->getProdChangedPackages())
            );
        }

        if(!$lockRestored)
        {
            // Force re-creation of the lock file: with no snapshot lock
            // to restore, whatever is currently at `composer.lock`
            // (e.g. a DEV-session lock) cannot be trusted as a PROD lock.
            $this->mainFile->getLockFile()->delete();

            $this->addMessage(
                self::MESSAGE_PROD_LOCK_MISSING,
                'WARNING: No production lock backup found. Please run `composer update` after switching the config.'
            );

            return array(
                'blocked' => false,
                'command' => new ComposerCommand(array('update'), 'No production lock backup exists; installing all dependencies.'),
                'changes' => $changes,
                'snapshotHash' => null,
                'appliedRepositories' => null
            );
        }

        $changedPackages = $changes->getProdChangedPackages();

        if(count($changedPackages) > 0)
        {
            $command = new ComposerCommand(array_merge(array('update'), $changedPackages), 'Updating the packages carried back from DEV.');
        }
        else if($this->mainFile->getLockFile()->getLockStatus() === LockStatus::Stale)
        {
            $command = new ComposerCommand(array('update', '--lock'), 'Refreshing the lock hash after a non-package config change.');
        }
        else
        {
            $installedState = InstalledState::fromInstalledPackages(
                self::MODE_PROD,
                array_map(static fn(LocalRepository $r) : string => $r->getPackageName(), $appliedRepos),
                $this->switch_installedPackages()
            );

            if($installedState === InstalledState::Matches) {
                $this->addMessage(self::MESSAGE_ALREADY_INSTALLED, 'The production dependencies are already installed; nothing to do.');
                $command = null;
            } else {
                $command = new ComposerCommand(array('install'), 'Installing the production dependencies.');
            }
        }

        return array(
            'blocked' => false,
            'command' => $command,
            'changes' => $changes,
            'snapshotHash' => null,
            'appliedRepositories' => null
        );
    }

    /**
     * PROD/INITIAL→PROD: no file effects beyond legacy cleanup, status
     * and flags; plans `install` only when nothing has ever been
     * installed yet.
     *
     * @return array{blocked:bool,command:?ComposerCommand,changes:ConfigChangeSet,snapshotHash:?string,appliedRepositories:?array<int,array<string,mixed>>}
     */
    private function switch_planProdToProd() : array
    {
        $this->console->line1('Already in PROD mode.');
        $this->addMessage(self::MESSAGE_USING_PROD_CONFIG, 'Using Composer PROD configuration.');

        $installed = $this->switch_installedPackages();

        if(!$installed->exists())
        {
            // Nothing has ever been installed: a bare file-existence
            // check is enough here, since InstalledState::fromInstalledPackages()
            // would otherwise report `Matches` trivially whenever there
            // are no local packages to check, regardless of whether
            // `vendor/` exists at all.
            $command = new ComposerCommand(array('install'), 'No installed dependencies found.');
        }
        else
        {
            $installedState = InstalledState::fromInstalledPackages(
                self::MODE_PROD,
                $this->switch_currentLocalPackageNamesLenient(),
                $installed
            );

            if($installedState === InstalledState::Matches)
            {
                $this->addMessage(self::MESSAGE_ALREADY_INSTALLED, 'The production dependencies are already installed; nothing to do.');
                $command = null;
            }
            else
            {
                $command = new ComposerCommand(array('install'), 'Installing the production dependencies.');
            }
        }

        return array(
            'blocked' => false,
            'command' => $command,
            'changes' => new ConfigChangeSet(array(), array()),
            'snapshotHash' => null,
            'appliedRepositories' => null
        );
    }

    /**
     * @return array{blocked:bool,command:?ComposerCommand,changes:ConfigChangeSet,snapshotHash:?string,appliedRepositories:?array<int,array<string,mixed>>}
     */
    private function switch_blockedPlan() : array
    {
        return array(
            'blocked' => true,
            'command' => null,
            'changes' => new ConfigChangeSet(array(), array()),
            'snapshotHash' => null,
            'appliedRepositories' => null
        );
    }

    /**
     * Whether the production snapshot's current content (its config
     * plus lock) still matches the `snapshotHash` recorded in the
     * status file at the moment it was taken. A legacy status file
     * (or one written before a snapshot hash existed) has no stored
     * hash at all, and the tamper check is skipped entirely — a `null`
     * stored hash never blocks a switch.
     */
    private function switch_isSnapshotModified() : bool
    {
        $storedHash = $this->statusFile->getSnapshotHash();

        if($storedHash === null) {
            return false;
        }

        $configData = $this->prodFile->exists() ? $this->prodFile->getData() : array();
        $lockContent = $this->prodFile->getLockFile()->exists() ? $this->prodFile->getLockFile()->getContent() : '';

        return $storedHash !== md5(json_encode($configData, JSON_THROW_ON_ERROR) . '|' . $lockContent);
    }

    /**
     * @return LocalRepository[]
     */
    private function switch_readAppliedRepositories() : array
    {
        $data = $this->statusFile->getAppliedRepositories();

        if($data === null) {
            return array();
        }

        return array_map(
            static fn(array $entry) : LocalRepository => LocalRepository::fromArray($entry),
            $data
        );
    }

    /**
     * @param LocalRepository[] $repos
     * @return array<int,array<string,mixed>>
     */
    private function switch_serializeRepositories(array $repos) : array
    {
        return array_map(
            static fn(LocalRepository $repo) : array => $repo->toArray(),
            $repos
        );
    }

    /**
     * Reads and strictly validates the DEV configuration's current
     * `local-repositories` list — identical file-existence guard and
     * validator ({@see LocalRepository::parseList()}) as the pre-v3
     * `switch_adjustConfigForDev()` used.
     *
     * @return LocalRepository[]
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_DEV_FILE_MISSING} {@see ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE}
     */
    private function switch_requireCurrentLocalRepositories() : array
    {
        if(!$this->devFile->exists()) {
            throw (new ComposerSwitcherException(
                'ERROR: The DEV composer config file does not exist.',
                ComposerSwitcherException::ERROR_DEV_FILE_MISSING
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $this->devFile->getPath()
                ));
        }

        return LocalRepository::parseList($this->devFile->getData(), $this->devFile->getPath());
    }

    /**
     * The current `local-repositories` package names, read leniently
     * (never throws) — used by {@see self::switch_planProdToProd()},
     * where a missing or malformed DEV config must not block an
     * otherwise file-effect-free PROD/INITIAL→PROD switch.
     *
     * @return string[]
     */
    private function switch_currentLocalPackageNamesLenient() : array
    {
        if(!$this->devFile->exists()) {
            return array();
        }

        try {
            $devConfig = $this->devFile->getData();
        } catch(ComposerSwitcherException) {
            return array();
        }

        [$repos, ] = LocalRepository::parseListLenient($devConfig, $this->devFile->getPath());

        return array_map(
            static fn(LocalRepository $repo) : string => $repo->getPackageName(),
            $repos
        );
    }

    /**
     * @param array<int,array{packageName:string,version:string|null,source:string}> $derivations
     */
    private function switch_recordVersionDerivations(array $derivations) : void
    {
        foreach($derivations as $derivation) {
            if($derivation['version'] === null) {
                continue;
            }

            $this->addMessage(
                self::MESSAGE_VERSION_DERIVED,
                'Package [%s] aliased to version [%s] (source: %s).',
                $derivation['packageName'],
                $derivation['version'],
                $derivation['source']
            );
        }
    }

    /**
     * @param array<int,array{section:string,packageName:string,snapshotValue:mixed,currentValue:mixed}> $overrides
     */
    private function switch_recordOverriddenEntries(array $overrides) : void
    {
        foreach($overrides as $override) {
            $this->addMessage(
                self::MESSAGE_MANAGED_ENTRY_OVERRIDDEN,
                'A manual edit to the managed [%s] entry for [%s] was discarded (restored to the production-aliased value).',
                $override['section'],
                $override['packageName']
            );
        }
    }

    private function switch_installedPackages() : InstalledPackages
    {
        $projectRoot = dirname($this->mainFile->getPath());
        $configData = $this->mainFile->exists() ? $this->mainFile->getData() : array();

        return (new InstalledPackages($projectRoot, $configData))->setFileSystem($this->fileSystem);
    }

    private function autoDisplayMessages() : void
    {
        if($this->displayMessages === true) {
            $this->displayMessages();
        }
    }

    /**
     * Prints every accumulated message text to the console, each on its own
     * line, surrounded by a blank line before and after. Prints nothing if
     * the message log is empty. Unlike {@see self::autoDisplayMessages()},
     * a direct call to this method always prints and is **not** gated by
     * {@see self::setDisplayMessages()} — that flag only suppresses the
     * automatic call made internally after a switch completes.
     *
     * @return $this
     */
    public function displayMessages() : self
    {
        $texts = $this->getMessageTexts();

        if(!empty($texts)) {
            echo PHP_EOL;
            foreach($texts as $text) {
                echo $text . PHP_EOL;
            }
            echo PHP_EOL;
        }

        return $this;
    }

    /**
     * Enables or disables automatic display of the switch messages
     * on the console after a switch completes (see {@see self::autoDisplayMessages()}).
     * Enabled by default. Only gates that automatic internal call — a
     * direct call to {@see self::displayMessages()} always prints,
     * regardless of this setting.
     *
     * @param bool $display
     * @return $this
     */
    public function setDisplayMessages(bool $display) : self
    {
        $this->displayMessages = $display;
        return $this;
    }

    /**
     * Clears the accumulated switch messages. Called at the start
     * of {@see self::switchTo()} so each switch starts with a clean slate.
     *
     * @return void
     */
    private function clearMessages() : void
    {
        $this->messages = array();
    }

    /**
     * @param int $code One of the `self::MESSAGE_*` constants.
     * @param string $message
     * @param string|int|float ...$args
     */
    private function addMessage(int $code, string $message, ...$args) : void
    {
        $this->messages[] = new SwitchMessage($code, sprintf($message, ...$args));
    }

    /**
     * @return SwitchMessage[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return string[]
     */
    public function getMessageTexts(): array
    {
        return array_map(
            static fn(SwitchMessage $message) : string => $message->getText(),
            $this->messages
        );
    }

}
