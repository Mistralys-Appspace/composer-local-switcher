<?php
/**
 * @package Composer Switcher
 * @subpackage Core
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher;

use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
use Mistralys\ComposerSwitcher\State\VerificationResult;
use Mistralys\ComposerSwitcher\Utils\BaseFile;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\ConsoleWriter;
use Mistralys\ComposerSwitcher\Utils\FileSystem;
use Mistralys\ComposerSwitcher\Utils\FlagFile;
use Mistralys\ComposerSwitcher\Utils\StatusFile;

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

    public const MESSAGE_NO_LOCK_FILE_FOUND = 182201;
    public const MESSAGE_CREATE_NEW_LOCK_FILE = 182202;
    public const MESSAGE_USING_DEV_CONFIG = 182203;
    public const MESSAGE_USING_PROD_CONFIG = 182204;
    public const MESSAGE_BACKED_UP_MAIN_TO_PROD = 182205;
    public const MESSAGE_RESTORED_PROD_TO_MAIN = 182206;
    public const MESSAGE_RUN_INSTALL_PROD = 182207;
    public const MESSAGE_RUN_INSTALL_DEV = 182208;
    public const MESSAGE_REBUILT_DEV_CONFIG = 182209;
    public const MESSAGE_ALREADY_IN_SYNC = 182210;
    public const MESSAGE_RECONCILE_AMBIGUOUS = 182211;
    public const MESSAGE_DEV_MODE_NOT_RECONCILABLE = 182212;
    public const MESSAGE_DRY_RUN_ACTIVE = 182213;
    public const MESSAGE_PROD_LOCK_MISSING = 182214;
    public const KEY_LOCAL_REPOSITORIES = 'local-repositories';
    public const KEY_REPOSITORIES = 'repositories';

    /**
     * {@see self::reconcile()} direction: prod is newer/wins, and is
     * copied onto `composer.json`.
     */
    public const RECONCILE_TO_MAIN = 'to-main';

    /**
     * {@see self::reconcile()} direction: `composer.json` is
     * newer/wins, and is copied onto the production config.
     */
    public const RECONCILE_TO_PROD = 'to-prod';

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

    public static function composerSwitchDev() : void
    {
        self::fromProjectRoot(getcwd())->switchToDevelopment();
    }

    public static function composerSwitchProd() : void
    {
        self::fromProjectRoot(getcwd())->switchToProduction();
    }

    public static function composerSwitchUpdate() : void
    {
        self::fromProjectRoot(getcwd())->switchUpdate();
    }

    public static function composerVerifyConfig() : void
    {
        $switcher = self::fromProjectRoot(getcwd());
        $result = $switcher->verify();

        if($result->isDevMode()) {
            echo 'DEV mode is active — config comparison skipped.' . PHP_EOL;
            return;
        }

        if($result->isInSync()) {
            echo 'composer.json and composer-prod.json are in sync.' . PHP_EOL;
            return;
        }

        echo 'composer.json and composer-prod.json differ in the following keys:' . PHP_EOL;
        foreach($result->getDifferences() as $key) {
            echo '  - ' . $key . PHP_EOL;
        }
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
     * human-readable report.
     */
    public static function composerSwitchDescribe() : void
    {
        $description = self::fromProjectRoot(getcwd())->describe();

        echo 'Mode: ' . $description->getMode() . PHP_EOL;
        echo 'Last switch: ' . ($description->getLastSwitchDate() ?? 'never') . PHP_EOL;
        echo 'Active flag: ' . ($description->getActiveFlag() ?? 'none') . PHP_EOL;
        echo PHP_EOL;

        echo 'Files:' . PHP_EOL;
        foreach($description->getFiles() as $file) {
            echo sprintf(
                '  - %-10s %s [%s%s]',
                $file['label'],
                $file['path'],
                $file['exists'] ? 'exists' : 'missing',
                $file['modifiedDate'] !== null ? ', modified ' . $file['modifiedDate'] : ''
            ) . PHP_EOL;
        }
        echo PHP_EOL;

        $verification = $description->getVerification();
        if($verification->isDevMode()) {
            echo 'Verification: DEV mode is active — config comparison skipped.' . PHP_EOL;
        } else if($verification->isInSync()) {
            echo 'Verification: composer.json and composer-prod.json are in sync.' . PHP_EOL;
        } else {
            echo 'Verification: composer.json and composer-prod.json differ in the following keys:' . PHP_EOL;
            foreach($verification->getDifferences() as $key) {
                echo '  - ' . $key . PHP_EOL;
            }
        }
        echo PHP_EOL;

        echo 'Local repositories:' . PHP_EOL;
        $localRepositories = $description->getLocalRepositories();
        if(empty($localRepositories)) {
            echo '  (none)' . PHP_EOL;
        } else {
            foreach($localRepositories as $repo) {
                echo sprintf('  - %s -> %s (%s)', $repo['packageName'], $repo['path'], $repo['version']) . PHP_EOL;
            }
        }

        if($description->hasWarnings()) {
            echo PHP_EOL;
            echo 'Warnings:' . PHP_EOL;
            foreach($description->getWarnings() as $warning) {
                echo '  - ' . $warning . PHP_EOL;
            }
        }
    }

    /**
     * Renders {@see self::describe()}'s state-of-the-world snapshot as
     * pretty-printed JSON, for programmatic consumption.
     */
    public static function composerSwitchDescribeJson() : void
    {
        echo self::fromProjectRoot(getcwd())->describe()->toJSON() . PHP_EOL;
    }

    /**
     * Reconciles `composer.json` and `composer-prod.json`, printing
     * whichever message {@see self::reconcile()} recorded for the
     * outcome (already-in-sync, backed-up, restored, ambiguous, or
     * not-reconcilable in DEV mode).
     */
    public static function composerSwitchReconcile() : void
    {
        $outcome = self::fromProjectRoot(getcwd())->reconcile();

        foreach($outcome->getMessageTexts() as $text) {
            echo $text . PHP_EOL;
        }
    }

    /**
     * Previews a switch to DEV mode: prints every {@see FileOperation}
     * that a real `switch-dev` would perform, without touching disk.
     */
    public static function composerSwitchPreviewDev() : void
    {
        self::printPreview(self::MODE_DEV);
    }

    /**
     * Previews a switch to PROD mode: prints every {@see FileOperation}
     * that a real `switch-prod` would perform, without touching disk.
     */
    public static function composerSwitchPreviewProd() : void
    {
        self::printPreview(self::MODE_PROD);
    }

    /**
     * Shared rendering logic for {@see self::composerSwitchPreviewDev()}
     * and {@see self::composerSwitchPreviewProd()}: runs the dry-run
     * preview, then prints the planned operations followed by the
     * accumulated messages.
     *
     * @param string $mode
     * @return void
     */
    private static function printPreview(string $mode) : void
    {
        $outcome = self::fromProjectRoot(getcwd())->previewSwitch($mode);

        foreach($outcome->getOperations() as $operation) {
            echo self::formatOperation($operation) . PHP_EOL;
        }

        foreach($outcome->getMessageTexts() as $text) {
            echo $text . PHP_EOL;
        }
    }

    /**
     * Formats a single {@see FileOperation} as `would <type>: <source>
     * -> <target> (<reason>)`. Operations with no source (e.g. a plain
     * write) render `-` in its place, keeping the format uniform.
     *
     * @param FileOperation $operation
     * @return string
     */
    private static function formatOperation(FileOperation $operation) : string
    {
        return sprintf(
            'would %s: %s -> %s (%s)',
            $operation->getType(),
            $operation->getSourcePath() ?? '-',
            $operation->getTargetPath(),
            $operation->getReason()
        );
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
     * Read-only comparison of `composer.json` and `composer-prod.json`.
     *
     * In DEV mode, `composer.json` has been rewritten with local
     * repository entries, so the comparison is skipped and not
     * meaningful — see {@see VerificationResult::isComparable()}.
     *
     * @return VerificationResult
     */
    public function verify() : VerificationResult
    {
        if($this->statusFile->isDEV()) {
            return new VerificationResult(true, false, array());
        }

        $mainData = $this->mainFile->getData();
        $prodData = $this->prodFile->exists() ? $this->prodFile->getData() : array();

        $this->recursiveKsort($mainData);
        $this->recursiveKsort($prodData);

        $allKeys = array_unique(array_merge(array_keys($mainData), array_keys($prodData)));
        sort($allKeys);

        $differences = array();
        foreach($allKeys as $key)
        {
            $mainValue = $mainData[$key] ?? null;
            $prodValue = $prodData[$key] ?? null;

            if($mainValue !== $prodValue) {
                $differences[] = $key;
            }
        }

        return new VerificationResult(false, empty($differences), $differences);
    }

    /**
     * Assembles the full state-of-the-world snapshot: mode, last switch
     * date, per-file existence/modification-date records, active flag
     * file, the {@see VerificationResult} from {@see self::verify()},
     * and the parsed `local-repositories` list.
     *
     * Never throws: a missing or malformed dev config degrades to an
     * empty repository list plus a {@see SwitchDescription::getWarnings()}
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

        [$localRepositories, $warnings] = $this->describe_readLocalRepositories();

        return new SwitchDescription(
            $mode,
            $this->statusFile->getDate(),
            $this->describe_buildFileRecords(),
            $activeFlag,
            $this->verify(),
            $localRepositories,
            $warnings
        );
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
     * itself never throws.
     *
     * @return array{0:array<int,array{packageName:string,path:string,version:string}>,1:string[]}
     */
    private function describe_readLocalRepositories() : array
    {
        $localRepositories = array();
        $warnings = array();

        if(!$this->devFile->exists())
        {
            $warnings[] = 'Dev configuration file not found: ' . $this->devFile->getPath();

            return array($localRepositories, $warnings);
        }

        try {
            $devConfig = $this->devFile->getData();
        } catch(ComposerSwitcherException $e) {
            $warnings[] = 'Failed to read the dev configuration file: ' . $e->getMessage();

            return array($localRepositories, $warnings);
        }

        if(!isset($devConfig[self::KEY_LOCAL_REPOSITORIES]) || !is_array($devConfig[self::KEY_LOCAL_REPOSITORIES]))
        {
            $warnings[] = sprintf(
                'Dev configuration file does not contain a valid [%s] list.',
                self::KEY_LOCAL_REPOSITORIES
            );

            return array($localRepositories, $warnings);
        }

        foreach($devConfig[self::KEY_LOCAL_REPOSITORIES] as $repo)
        {
            if(!isset($repo['package-name'], $repo['path']) || !is_string($repo['package-name']) || !is_string($repo['path']))
            {
                $warnings[] = sprintf(
                    'Skipped a malformed entry in the dev configuration [%s] list.',
                    self::KEY_LOCAL_REPOSITORIES
                );

                continue;
            }

            $localRepositories[] = array(
                'packageName' => $repo['package-name'],
                'path' => $repo['path'],
                'version' => is_string($repo['version'] ?? null) ? $repo['version'] : '*'
            );
        }

        return array($localRepositories, $warnings);
    }

    /**
     * @param array<int|string,mixed> $array
     * @return void
     */
    private function recursiveKsort(array &$array) : void
    {
        ksort($array);

        foreach($array as &$value) {
            if(is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
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
     * Updates the current configuration, if necessary.
     *
     * For example, if the current mode is PROD, it will
     * update the composer configurations depending on which
     * has been most recently modified.
     *
     * In the INITIAL state (no switch has ever been run), this is a
     * no-op on the file system, but still returns an explicit,
     * inspectable {@see SwitchOutcome} — mode {@see self::MODE_INITIAL},
     * no operations — rather than silently doing nothing.
     *
     * @return SwitchOutcome
     */
    public function switchUpdate() : SwitchOutcome
    {
        // NOTE: A simple ELSE would not have been enough here,
        // as the status may be INITIAL, in which case neither
        // condition would be true.

        if($this->getStatus()->isDEV()) {
            return $this->switchToDevelopment();
        }

        if($this->getStatus()->isPROD()) {
            return $this->switchToProduction();
        }

        $this->clearMessages();
        $this->fileSystem->clearOperations();

        return new SwitchOutcome(
            self::MODE_INITIAL,
            $this->fileSystem->isDryRun(),
            $this->messages,
            $this->fileSystem->getOperations()
        );
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
     * Switches to the target mode.
     *
     * A missing lock file no longer aborts the switch: it is a
     * recoverable situation (e.g. a freshly cloned project that has
     * never run `composer update`), so the switch completes in full —
     * config rewrite, status file, flag file — and only records
     * {@see self::MESSAGE_NO_LOCK_FILE_FOUND} as a warning.
     *
     * @param string $mode
     * @param bool $dryRun When `true`, the facade's dry-run flag is set
     *        for the duration of this call — no file is actually
     *        touched, and {@see self::MESSAGE_DRY_RUN_ACTIVE} is
     *        recorded — and restored to its previous value afterward,
     *        in a `finally` block, so an exception mid-switch can never
     *        leave the switcher stuck in dry-run mode.
     * @return SwitchOutcome
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

            if(!$this->mainFile->getLockFile()->exists())
            {
                $this->addMessage(
                    self::MESSAGE_NO_LOCK_FILE_FOUND,
                    'WARNING: No lock file found. Please run `composer update` after switching the config.'
                );
            }

            $this->switch_copyLockFiles($mode);

            $this->statusFile->saveState($mode, $this);

            $this->writeFlagFiles();

            $this->autoDisplayMessages();

            return new SwitchOutcome(
                $mode,
                $this->fileSystem->isDryRun(),
                $this->messages,
                $this->fileSystem->getOperations()
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

    private function switch_copyLockFiles(string $mode) : void
    {
        $this->console->line1('Copying files for %s mode...', $mode);

        $isDev = $this->getStatus()->isDEV();
        $isProd = $this->getStatus()->isPROD();
        $isInitial = !$isDev && !$isProd;

        // Initial switch: Initialize the production files if they do not exist yet.
        if($isInitial)
        {
            $this->switch_initProductionFiles();
        }

        if($mode === self::MODE_DEV)
        {
            if($isDev) {
                $this->switch_case_DEV_DEV();
            } else {
                $this->switch_case_PROD_DEV();
            }
        }
        else
        {
            if($isProd) {
                $this->switch_case_PROD_PROD();
            } else {
                $this->switch_case_DEV_PROD();
            }
        }
    }

    private function switch_case_DEV_DEV() : void
    {
        $this->console->line1('Already in DEV mode, refreshing config...');
        $this->addMessage(self::MESSAGE_USING_DEV_CONFIG, 'Using Composer DEV configuration.');

        $this->switch_adjustConfigForDev();
    }

    private function switch_case_PROD_PROD() : void
    {
        $this->console->line1('Ignoring switch, already in PROD mode.');
        $this->addMessage(self::MESSAGE_USING_PROD_CONFIG, 'Using Composer PROD configuration.');

        // Delegate to the same reconciliation core used by the public
        // reconcile() method, so a PROD->PROD switch and a direct
        // reconcile() call from the same starting state produce
        // identical file effects. Messages accumulated so far (the
        // "Using Composer PROD configuration" message above) are
        // preserved, since the core does not clear them itself.
        $this->reconcileCore(null, false);
    }

    /**
     * Reconciles `composer.json` and `composer-prod.json` in PROD mode:
     * content decides *whether* to act (via {@see self::verify()}),
     * modification time decides *which direction* to copy in.
     *
     * In DEV mode, `composer.json` has been rewritten for local
     * repositories and is not meaningful to reconcile — the call is a
     * no-op that reports {@see self::MESSAGE_DEV_MODE_NOT_RECONCILABLE}.
     *
     * @param string|null $direction Explicit direction overriding the
     *        modification-time heuristic. One of {@see self::RECONCILE_TO_MAIN}
     *        or {@see self::RECONCILE_TO_PROD}.
     * @param bool $dryRun When `true`, no file is actually written —
     *        the returned {@see SwitchOutcome} describes the operations
     *        that would have been performed.
     * @return SwitchOutcome
     *
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION} when `$direction` is non-null and not one of the two direction constants.
     */
    public function reconcile(?string $direction = null, bool $dryRun = false) : SwitchOutcome
    {
        $this->clearMessages();

        return $this->reconcileCore($direction, $dryRun);
    }

    /**
     * The reconciliation logic shared by the public {@see self::reconcile()}
     * and {@see self::switch_case_PROD_PROD()}. Unlike {@see self::reconcile()},
     * this does not clear the accumulated messages, so a caller already
     * mid-switch can add its own message beforehand and have it preserved
     * in the returned outcome.
     *
     * @param string|null $direction
     * @param bool $dryRun
     * @return SwitchOutcome
     *
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION}
     */
    private function reconcileCore(?string $direction, bool $dryRun) : SwitchOutcome
    {
        $this->requireValidReconcileDirection($direction);

        $previousDryRun = $this->fileSystem->isDryRun();
        $this->fileSystem->setDryRun($dryRun);
        $this->fileSystem->clearOperations();

        if($dryRun) {
            $this->addMessage(self::MESSAGE_DRY_RUN_ACTIVE, 'Dry run: no files will actually be changed.');
        }

        $mode = $this->getStatus()->getMode() ?? self::MODE_PROD;

        if($this->getStatus()->isDEV())
        {
            $this->addMessage(
                self::MESSAGE_DEV_MODE_NOT_RECONCILABLE,
                'DEV mode is active: `composer.json` has been rewritten for local repositories and cannot be reconciled.'
            );

            return $this->finishReconcile($mode, $previousDryRun);
        }

        $result = $this->verify();

        if($result->isInSync())
        {
            $this->addMessage(self::MESSAGE_ALREADY_IN_SYNC, '`composer.json` and `composer-prod.json` are already in sync.');

            return $this->finishReconcile($mode, $previousDryRun);
        }

        $resolvedDirection = $direction ?? $this->resolveReconcileDirection($result);

        if($resolvedDirection === null) {
            return $this->finishReconcile($mode, $previousDryRun);
        }

        if($resolvedDirection === self::RECONCILE_TO_PROD)
        {
            $this->addMessage(self::MESSAGE_BACKED_UP_MAIN_TO_PROD, 'Backing up the modified `composer.json`.');
            $this->mainFile->copyTo($this->prodFile);
            $this->mainFile->getLockFile()->tryCopyTo($this->prodFile->getLockFile());
        }
        else
        {
            $this->addMessage(self::MESSAGE_RESTORED_PROD_TO_MAIN, 'Updating `composer.json` with changes.');
            $this->prodFile->copyTo($this->mainFile);
            $this->prodFile->getLockFile()->tryCopyTo($this->mainFile->getLockFile());
        }

        return $this->finishReconcile($mode, $previousDryRun);
    }

    /**
     * Resolves the reconciliation direction from the modification times
     * of the main and production config files. Returns `null` for the
     * ambiguous case (equal modification times, differing content),
     * after recording {@see self::MESSAGE_RECONCILE_AMBIGUOUS}.
     *
     * @param VerificationResult $result
     * @return string|null One of {@see self::RECONCILE_TO_MAIN}, {@see self::RECONCILE_TO_PROD}, or `null` when ambiguous.
     */
    private function resolveReconcileDirection(VerificationResult $result) : ?string
    {
        $mainModified = $this->mainFile->requireModifiedDate();
        $prodModified = $this->prodFile->requireModifiedDate();

        if($mainModified > $prodModified) {
            return self::RECONCILE_TO_PROD;
        }

        if($mainModified < $prodModified) {
            return self::RECONCILE_TO_MAIN;
        }

        $this->addMessage(
            self::MESSAGE_RECONCILE_AMBIGUOUS,
            'Cannot determine a reconciliation direction: `composer.json` and `composer-prod.json` have the same modification time, but differ in [%s].',
            implode(', ', $result->getDifferences())
        );

        return null;
    }

    /**
     * Validates an explicit reconcile direction, if given.
     *
     * @param string|null $direction
     * @return void
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION}
     */
    private function requireValidReconcileDirection(?string $direction) : void
    {
        if($direction === null) {
            return;
        }

        $allowed = array(self::RECONCILE_TO_MAIN, self::RECONCILE_TO_PROD);

        if(in_array($direction, $allowed, true)) {
            return;
        }

        throw (new ComposerSwitcherException(
            sprintf(
                'Invalid reconcile direction [%s]. Allowed values are: %s',
                $direction,
                implode(', ', $allowed)
            ),
            ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION
        ))
            ->setContext(array(
                ComposerSwitcherException::KEY_DIRECTION => $direction,
                ComposerSwitcherException::KEY_EXPECTED => $allowed
            ));
    }

    /**
     * Builds the {@see SwitchOutcome} for the current call to
     * {@see self::reconcileCore()}, then restores the file system
     * facade's dry-run flag to whatever it was before this call.
     *
     * @param string $mode
     * @param bool $previousDryRun
     * @return SwitchOutcome
     */
    private function finishReconcile(string $mode, bool $previousDryRun) : SwitchOutcome
    {
        $outcome = new SwitchOutcome(
            $mode,
            $this->fileSystem->isDryRun(),
            $this->messages,
            $this->fileSystem->getOperations()
        );

        $this->fileSystem->setDryRun($previousDryRun);

        return $outcome;
    }

    private function switch_case_DEV_PROD() : void
    {
        $this->console->line1('Switching from DEV to PROD...');
        $this->addMessage(self::MESSAGE_USING_PROD_CONFIG, 'Using Composer PROD configuration.');

        $mainLockFile = $this->mainFile->getLockFile();

        // Back up the DEV lock file if present
        if($mainLockFile->exists()) {
            $mainLockFile->copyTo($this->devFile->getLockFile());
        }

        // Restore the PROD files
        $this->prodFile->copyTo($this->mainFile);

        $prodLockFile = $this->prodFile->getLockFile();

        // Guard against the unconditional copyTo() below throwing
        // ERROR_CANNOT_COPY_FILE when no production lock backup exists
        // yet (e.g. a failed `composer update` in DEV never produced
        // one). Falling back to deleting the main lock file mirrors
        // switch_case_PROD_DEV()'s "force re-creation" handling for the
        // equivalent missing-lock case in the other direction.
        if($prodLockFile->exists())
        {
            $prodLockFile->copyTo($mainLockFile);

            $this->addMessage(self::MESSAGE_RUN_INSTALL_PROD, 'Run `composer install` to use the production dependencies.');
        }
        else
        {
            $mainLockFile->delete();

            $this->addMessage(
                self::MESSAGE_PROD_LOCK_MISSING,
                'WARNING: No production lock file found. Please run `composer update` after switching the config.'
            );
        }
    }

    private function switch_case_PROD_DEV() : void
    {
        $this->console->line1('Switching from PROD to DEV...');
        $this->addMessage(self::MESSAGE_USING_DEV_CONFIG, 'Using Composer DEV configuration.');

        $prodLockFile = $this->mainFile->getLockFile();

        // Back up the PROD lock file if present
        if($prodLockFile->exists()) {
            $prodLockFile->copyTo($this->prodFile->getLockFile());
        }

        if($this->devFile->getLockFile()->exists())
        {
            $this->devFile->getLockFile()->copyTo($this->mainFile->getLockFile());

            $this->addMessage(self::MESSAGE_RUN_INSTALL_DEV, 'Run `composer install` to use the development dependencies.');
        }
        else
        {
            // Force re-creation of the lock file
            $this->mainFile->getLockFile()->delete();

            $this->addMessage(
                self::MESSAGE_CREATE_NEW_LOCK_FILE,
                'Run `composer update` to create a DEV lock file.'
            );
        }

        // Generate and copy the DEV files
        $this->switch_adjustConfigForDev();
    }

    /**
     * Called on the initial switch only, independent of the target mode.
     * Ensures that the production files exist by copying them from the main file.
     */
    private function switch_initProductionFiles() : void
    {
        if(!$this->prodFile->exists())
        {
            $this->console->line1('Creating production config...');

            $this->console->line2('%s -> %s', $this->mainFile->getName(), $this->prodFile->getName());
            $this->mainFile->copyTo($this->prodFile);
        }

        if($this->mainFile->getLockFile()->exists())
        {
            $this->console->line1('Creating production lock file...');

            $this->console->line2('%s -> %s', $this->mainFile->getLockFile()->getName(), $this->prodFile->getLockFile()->getName());
            $this->mainFile->getLockFile()->copyTo($this->prodFile->getLockFile());
        }
    }

    private function autoDisplayMessages() : void
    {
        if($this->displayMessages === true) {
            $this->displayMessages();
        }
    }

    /**
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
     * Enabled by default.
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

    private function switch_adjustConfigForDev() : void
    {
        $config = $this->prodFile->getData();

        if(!$this->devFile->exists()) {
            throw (new ComposerSwitcherException(
                'ERROR: The DEV composer config file does not exist.',
                ComposerSwitcherException::ERROR_DEV_FILE_MISSING
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $this->devFile->getPath()
                ));
        }

        $devConfig = $this->devFile->getData();

        $this->console->line1('Adjusting config for DEV...');

        if(!isset($devConfig[self::KEY_LOCAL_REPOSITORIES]) || !is_array($devConfig[self::KEY_LOCAL_REPOSITORIES])) {
            throw (new ComposerSwitcherException(
                sprintf(
                    'ERROR: The DEV composer config does not contain the [%s] key, or it is not an array.',
                    self::KEY_LOCAL_REPOSITORIES
                ),
                ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_FILE_PATH => $this->devFile->getPath(),
                    ComposerSwitcherException::KEY_EXPECTED => self::KEY_LOCAL_REPOSITORIES
                ));
        }

        foreach($devConfig[self::KEY_LOCAL_REPOSITORIES] as $repo)
        {
            if(!isset($repo['package-name'], $repo['path']) || !is_string($repo['package-name']) || !is_string($repo['path'])) {
                throw (new ComposerSwitcherException(
                    'ERROR: Invalid local repository entry in DEV composer config.',
                    ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE
                ))
                    ->setContext(array(
                        ComposerSwitcherException::KEY_FILE_PATH => $this->devFile->getPath(),
                        ComposerSwitcherException::KEY_PACKAGE_NAME => is_string($repo['package-name'] ?? null) ? $repo['package-name'] : null
                    ));
            }

            $packageName = $repo['package-name'];
            $path = $repo['path'];
            $version = $repo['version'] ?? '*';

            // Write to require-dev if the package lives there in the PROD baseline.
            $requireKey = isset($config['require-dev'][$packageName]) ? 'require-dev' : 'require';
            $config[$requireKey][$packageName] = $version;

            // Default wildcard version in the path repository.
            // This means that no version constraint is applied,
            // and the package will always be treated as the latest version,
            // which equals to `dev-main`.
            $repoEntry = array(
                'type' => 'path',
                'url' => $path,
                'options' => array(
                    'symlink' => true
                ),
            );

            // Specific version constraint: This means that the version cannot
            // be inferred from the local package (no `version` field in its
            // `composer.json`), and therefore we need to define the package
            // version explicitly.
            if($version !== '*')
            {
                $versions = array(
                    $packageName => $version
                );

                if(strpos($packageName, '_') !== false)
                {
                    // Some package names use underscores instead of hyphens.
                    // The repository URL may use either, so we need to add
                    // both versions to ensure that Composer can find it.
                    $versions[str_replace('_', '-', $packageName)] = $version;
                }

                $repoEntry['options']['versions'] = $versions;
            }

            if(!isset($config[self::KEY_REPOSITORIES]) || !is_array($config[self::KEY_REPOSITORIES])) {
                $config[self::KEY_REPOSITORIES] = array();
            }

            // Attempt to find existing repository entries and replace
            // the first match with the path entry. Any additional
            // matches (stale VCS duplicates) are removed.
            $found = false;
            foreach ($config[self::KEY_REPOSITORIES] as $i => $repository)
            {
                if (!isset($repository['url'])) {
                    continue;
                }

                if(
                    !$this->urlMatchesPackageName($repository['url'], $packageName)
                    &&
                    // GitHub repository URLs use hyphens instead of underscores.
                    // The package name may use either.
                    !$this->urlMatchesPackageName($repository['url'], str_replace('_', '-', $packageName)))
                {
                    continue;
                }

                if(!$found) {
                    $found = true;
                    $this->console->line1('- UPDATE | [%s] | Overwriting existing repository entry.', $packageName);
                    $config[self::KEY_REPOSITORIES][$i] = $repoEntry;
                } else {
                    $this->console->line1('- PRUNE  | [%s] | Removing duplicate repository entry.', $packageName);
                    unset($config[self::KEY_REPOSITORIES][$i]);
                }
            }

            if($found) {
                $config[self::KEY_REPOSITORIES] = array_values($config[self::KEY_REPOSITORIES]);
            } else {
                $config[self::KEY_REPOSITORIES][] = $repoEntry;
                $this->console->line1('- ADD | [%s] | Adding new repository entry.', $packageName);
            }
        }

        $this->mainFile->putData($config);

        $this->console->newline();

        $this->addMessage(self::MESSAGE_REBUILT_DEV_CONFIG, 'Rebuilt a fresh DEV `composer.json`.');
    }

    /**
     * Checks whether a repository URL contains the package name
     * followed by a valid boundary character (`.`, `/`, or end-of-string).
     * Prevents substring collisions like `application-utils` matching
     * `application-utils-core`.
     */
    private function urlMatchesPackageName(string $url, string $name) : bool
    {
        $pos = stripos($url, $name);

        if($pos === false) {
            return false;
        }

        $afterPos = $pos + strlen($name);

        if($afterPos >= strlen($url)) {
            return true;
        }

        $nextChar = $url[$afterPos];

        return $nextChar === '.' || $nextChar === '/';
    }
}
