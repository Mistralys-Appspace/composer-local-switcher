<?php
/**
 * @package Composer Switcher
 * @subackage Core
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher;

use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\ConsoleWriter;
use Mistralys\ComposerSwitcher\Utils\FlagFile;
use Mistralys\ComposerSwitcher\Utils\StatusFile;

/**
 * @package Composer Switcher
 * @subackage Core
 */
class ConfigSwitcher
{
    public const MODE_DEV = 'dev';
    public const MODE_PROD = 'prod';

    public const MESSAGE_NO_LOCK_FILE_FOUND = 182201;
    public const MESSAGE_CREATE_NEW_LOCK_FILE = 182202;
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
     * @var bool
     */
    private $displayMessages = true;

    /**
     * @var bool Whether to create a flag file alongside the main composer.json file.
     */
    private $flagFileEnabled = true;

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

        if(!empty($result['devMode'])) {
            echo 'DEV mode is active — config comparison skipped.' . PHP_EOL;
            return;
        }

        if($result['inSync']) {
            echo 'composer.json and composer-prod.json are in sync.' . PHP_EOL;
            return;
        }

        echo 'composer.json and composer-prod.json differ in the following keys:' . PHP_EOL;
        foreach($result['differences'] as $key) {
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
     * @param ConfigFile $mainFile The main `composer.json` file.
     * @param ConfigFile $prodFile The production `composer-prod.json` file.
     * @param ConfigFile $devConfig The development configuration file, containing the list of local repositories.
     */
    public function __construct(ConfigFile $mainFile, ConfigFile $prodFile, ConfigFile $devConfig)
    {
        $this->prodFile = $prodFile;
        $this->devFile = $devConfig;
        $this->mainFile = $mainFile;
        $this->statusFile = new StatusFile(str_replace('.json', '.status', $devConfig->getPath()));
        $this->console = new ConsoleWriter();
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
     * Returns an array with keys:
     * - `inSync` (bool) — whether the files have identical normalized content.
     * - `differences` (string[]) — top-level keys that differ.
     * - `devMode` (bool) — present and true when called in DEV mode (comparison skipped).
     *
     * @return array{inSync: bool, differences: string[], devMode?: bool}
     */
    public function verify() : array
    {
        if($this->statusFile->isDEV()) {
            return array(
                'inSync' => false,
                'differences' => array(),
                'devMode' => true
            );
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

        return array(
            'inSync' => empty($differences),
            'differences' => $differences
        );
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
     * @return void
     */
    public function switchUpdate() : void
    {
        // NOTE: A simple ELSE would not have been enough here,
        // as the status may be INITIAL, in which case neither
        // condition would be true.

        if($this->getStatus()->isDEV()) {
            $this->switchToDevelopment();
        } else if($this->getStatus()->isPROD()) {
            $this->switchToProduction();
        }
    }

    public function switchToDevelopment() : void
    {
        $this->switchTo(self::MODE_DEV);
    }

    public function switchToProduction() : void
    {
        $this->switchTo(self::MODE_PROD);
    }

    /**
     * Switches to the target mode.
     *
     * @param string $mode
     * @return void
     *
     * @see self::MODE_PROD
     * @see self::MODE_DEV
     */
    public function switchTo(string $mode) : void
    {
        $this->requireValidMode($mode);

        $this->console->header('Switching to %s composer config', strtoupper($mode));

        if(!$this->mainFile->getLockFile()->exists())
        {
            $this->addMessage(
                'WARNING: No lock file found. Please run `composer update` after switching the config.',
                self::MESSAGE_NO_LOCK_FILE_FOUND
            );

            $this->autoDisplayMessages();
            return;
        }

        $this->switch_copyLockFiles($mode);

        $this->statusFile->saveState($mode, $this);

        $this->writeFlagFiles();

        $this->autoDisplayMessages();
    }

    private function requireValidMode(string $mode) : void
    {
        $mode = strtolower($mode);

        if(in_array($mode, array(self::MODE_DEV, self::MODE_PROD), true)) {
            return;
        }

        throw new ComposerSwitcherException(
            sprintf(
                'Invalid switch mode. Allowed modes are: %s',
                implode(', ', array(self::MODE_DEV, self::MODE_PROD)),
            ),
            ComposerSwitcherException::ERROR_INVALID_SWITCH_MODE
        );
    }

    public function getFlagFile(string $mode) : FlagFile
    {
        $this->requireValidMode($mode);

        return new FlagFile($this, $mode);
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
        $this->addMessage('Using Composer DEV configuration.');

        $this->switch_adjustConfigForDev();
    }

    private function switch_case_PROD_PROD() : void
    {
        $this->console->line1('Ignoring switch, already in PROD mode.');
        $this->addMessage('Using Composer PROD configuration.');

        $mainModified = $this->mainFile->requireModifiedDate();
        $prodModified = $this->prodFile->requireModifiedDate();

        if($mainModified > $prodModified)
        {
            $this->addMessage('Backing up the modified `composer.json`.');
            $this->mainFile->copyTo($this->prodFile);
            $this->mainFile->getLockFile()->tryCopyTo($this->prodFile->getLockFile());
        }
        else if($mainModified < $prodModified)
        {
            $this->addMessage('Updating `composer.json` with changes.');
            $this->prodFile->copyTo($this->mainFile);
            $this->prodFile->getLockFile()->tryCopyTo($this->mainFile->getLockFile());
        }
    }

    private function switch_case_DEV_PROD() : void
    {
        $this->console->line1('Switching from DEV to PROD...');
        $this->addMessage('Using Composer PROD configuration.');

        $mainLockFile = $this->mainFile->getLockFile();

        // Back up the DEV lock file if present
        if($mainLockFile->exists()) {
            $mainLockFile->copyTo($this->devFile->getLockFile());
        }

        // Restore the PROD files
        $this->prodFile->copyTo($this->mainFile);
        $this->prodFile->getLockFile()->copyTo($this->mainFile->getLockFile());

        $this->addMessage('Run `composer install` to use the production dependencies.');
    }

    private function switch_case_PROD_DEV() : void
    {
        $this->console->line1('Switching from PROD to DEV...');
        $this->addMessage('Using Composer DEV configuration.');

        $prodLockFile = $this->mainFile->getLockFile();

        // Back up the PROD lock file if present
        if($prodLockFile->exists()) {
            $prodLockFile->copyTo($this->prodFile->getLockFile());
        }

        if($this->devFile->getLockFile()->exists())
        {
            $this->devFile->getLockFile()->copyTo($this->mainFile->getLockFile());

            $this->addMessage('Run `composer install` to use the development dependencies.');
        }
        else if(!$this->devFile->getLockFile()->exists())
        {
            // Force re-creation of the lock file
            $this->mainFile->getLockFile()->delete();

            $this->addMessage(
                'Run `composer update` to create a DEV lock file.',
                self::MESSAGE_CREATE_NEW_LOCK_FILE
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
        if(!empty($this->messages)) {
            echo PHP_EOL;
            foreach($this->messages as $message) {
                echo $message . PHP_EOL;
            }
            echo PHP_EOL;
        }

        return $this;
    }

    /**
     * @var string[]
     */
    private $messages = array();

    private function addMessage(string $message, ...$args) : void
    {
        $this->messages[] = sprintf($message, ...$args);
    }

    /**
     * @return string[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    private function switch_adjustConfigForDev() : void
    {
        $config = $this->prodFile->getData();

        if(!$this->devFile->exists()) {
            throw new ComposerSwitcherException(
                'ERROR: The DEV composer config file does not exist.',
                ComposerSwitcherException::ERROR_DEV_FILE_MISSING
            );
        }

        $devConfig = $this->devFile->getData();

        $this->console->line1('Adjusting config for DEV...');

        if(!isset($devConfig[self::KEY_LOCAL_REPOSITORIES]) || !is_array($devConfig[self::KEY_LOCAL_REPOSITORIES])) {
            throw new ComposerSwitcherException(
                sprintf(
                    'ERROR: The DEV composer config does not contain the [%s] key, or it is not an array.',
                    self::KEY_LOCAL_REPOSITORIES
                ),
                ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE
            );
        }

        foreach($devConfig[self::KEY_LOCAL_REPOSITORIES] as $repo)
        {
            if(!isset($repo['package-name'], $repo['path']) || !is_string($repo['package-name']) || !is_string($repo['path'])) {
                throw new ComposerSwitcherException(
                    'ERROR: Invalid local repository entry in DEV composer config.',
                    ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE
                );
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
                    stripos($repository['url'], $packageName) === false
                    &&
                    // GitHub repository URLs use hyphens instead of underscores.
                    // The package name may use either.
                    stripos($repository['url'], str_replace('_', '-', $packageName)) === false)
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

        $this->addMessage('Rebuilt a fresh DEV `composer.json`.');
    }

}
