<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\LockFile;

final class TestSwitching extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * No switch has been made yet: The status file does not exist.
     */
    public function test_initialState() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();
        $status = $switcher->getStatus();

        $this->assertFalse($status->exists());
        $this->assertNull($status->getDate());
        $this->assertNull($status->getMode());
        $this->assertFalse($status->isDEV());
        $this->assertFalse($status->isPROD());
        $this->assertEmpty($status->getData());

        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_DEV)->exists());
        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_PROD)->exists());
    }

    /**
     * No switch has been made yet, so switching to DEV
     * has the following tasks:
     *
     * - Create the status file, marking the mode as DEV.
     * - Create the `composer-prod.json` file (copy from `composer.json`).
     * - Create the `composer-prod.lock` file (copy from `composer.lock`).
     * - Update the `composer.json` file repositories.
     *
     * Expected file structure after this operation:
     *
     * - `composer.json` (DEV configuration, with DEV repositories)
     * - `composer.lock` (NONE, as the DEV repositories have not been installed)
     * - `composer/composer-prod.json` (copy of the original `composer.json`)
     * - `composer/composer-prod.lock` (copy of the original `composer.lock`)
     */
    public function test_initialSwitchToDEV() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();

        $switcher->switchToDevelopment();

        $status = $switcher->getStatus();
        $this->assertTrue($status->exists());
        $this->assertNotNull($status->getDate());
        $this->assertEquals(ConfigSwitcher::MODE_DEV, $status->getMode());
        $this->assertTrue($status->isDEV());
        $this->assertFalse($status->isPROD());

        $this->assertTrue($switcher->getProdFile()->exists());
        $this->assertTrue($switcher->getProdFile()->getLockFile()->exists());
        $this->assertFalse($switcher->getMainFile()->getLockFile()->exists());

        $this->assertConfigHasExpectedPaths($switcher);
        $this->assertLockFileIsPROD($switcher->getProdFile());
        $this->assertFlagIsDEV($switcher);
    }

    /**
     * No switch has been made yet, so switching to PROD
     * must only create the missing production files.
     */
    public function test_initialSwitchToPROD() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();

        $switcher->switchToProduction();

        $status = $switcher->getStatus();
        $this->assertTrue($status->exists());
        $this->assertNotNull($status->getDate());
        $this->assertEquals(ConfigSwitcher::MODE_PROD, $status->getMode());
        $this->assertTrue($status->isPROD());
        $this->assertFalse($status->isDEV());

        // The main composer.json file has been copied to the
        // production file, and the lock file has been copied.
        $this->assertTrue($switcher->getProdFile()->exists());
        $this->assertTrue($switcher->getProdFile()->getLockFile()->exists());

        $this->assertLockFileIsPROD($switcher->getMainFile());
        $this->assertFlagIsPROD($switcher);
    }

    public function test_switchDEVToPROD() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        // Simulate the user creating the DEV lock file
        $this->assertNotFalse(file_put_contents(
            $switcher->getMainFile()->getLockFile()->getPath(),
            'DEV'
        ));

        $switcher->switchToProduction();

        $this->assertLockFileIsPROD($switcher->getMainFile());
        $this->assertLockFileIsDEV($switcher->getDevFile());
        $this->assertFlagIsPROD($switcher);
    }

    /**
     * A missing `composer.lock` no longer aborts the switch: the config
     * rewrite, status file, and flag file are all still produced, and
     * {@see ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND} is recorded as
     * a warning rather than the sole side effect of an early return.
     */
    public function test_switchWithoutLockFileCompletes() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getMainFile()->getLockFile()->getPath());

        $switcher->switchToDevelopment();

        $status = $switcher->getStatus();
        $this->assertTrue($status->exists());
        $this->assertTrue($status->isDEV());

        $this->assertFlagIsDEV($switcher);
        $this->assertConfigHasExpectedPaths($switcher);

        $this->assertContains(
            ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * A DEV->PROD switch with no `composer-prod.lock` backup to restore
     * from used to throw `ERROR_CANNOT_COPY_FILE` from the unconditional
     * `copyTo()` call in `switch_case_DEV_PROD()`. It now removes the
     * stale DEV `composer.lock` instead (mirroring the equivalent
     * missing-lock handling in `switch_case_PROD_DEV()`) and records
     * {@see ConfigSwitcher::MESSAGE_PROD_LOCK_MISSING}.
     */
    public function test_prodSwitchWithoutProdLockDeletesMainLock() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        // Simulate the user creating the DEV lock file.
        $this->assertNotFalse(file_put_contents(
            $switcher->getMainFile()->getLockFile()->getPath(),
            'DEV'
        ));

        // Simulate a missing production lock backup.
        $prodLockFile = $switcher->getProdFile()->getLockFile();
        $this->assertTrue($prodLockFile->exists());
        unlink($prodLockFile->getPath());

        $switcher->switchToProduction();

        $this->assertFalse($switcher->getMainFile()->getLockFile()->exists());
        $this->assertFlagIsPROD($switcher);

        $this->assertContains(
            ConfigSwitcher::MESSAGE_PROD_LOCK_MISSING,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * `BaseFile::tryCopyTo()` used to require both the source AND the
     * target to already exist, so the lock backup in
     * `switch_case_PROD_PROD()` silently did nothing whenever
     * `composer-prod.lock` had not been created yet. It now only
     * requires the source to exist, so a missing target is created.
     *
     * Since `switch_case_PROD_PROD()` now delegates to the content-first
     * `reconcile()` core, the two files must actually differ in content
     * (not just modification time) for the backup branch to trigger —
     * see {@see \Mistralys\ComposerSwitcher\ConfigSwitcher::reconcile()}.
     */
    public function test_tryCopyToCreatesMissingTarget() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $prodLockFile = $switcher->getProdFile()->getLockFile();
        $this->assertTrue($prodLockFile->exists());

        // Simulate a missing production lock backup, with the main
        // composer.json modified more recently than composer-prod.json
        // and actually different in content, so that
        // switch_case_PROD_PROD()'s backup branch triggers.
        unlink($prodLockFile->getPath());
        $this->assertFalse($prodLockFile->exists());

        $config = $switcher->getMainFile()->getData();
        $config['require']['php'] = '>=8.0';
        $switcher->getMainFile()->putData($config);

        touch($switcher->getProdFile()->getPath(), time() - 60);
        touch($switcher->getMainFile()->getPath());

        $switcher->switchToProduction();

        $this->assertTrue($prodLockFile->exists());
        $this->assertSame(
            file_get_contents($switcher->getMainFile()->getLockFile()->getPath()),
            file_get_contents($prodLockFile->getPath())
        );
    }

    public function test_specificPackageVersion() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $this->assertConfigHasCustomVersion($switcher, 'mistralys/application-utils-core', '2.3.14');
    }

    /**
     * The hyphen alias in `options.versions` (added alongside the
     * package name for underscore package names, so Composer can
     * resolve either spelling of the repository URL) must never be
     * added for a package name that already uses hyphens throughout —
     * there is no alternate spelling to alias.
     */
    public function test_hyphenAliasOmittedForNonUnderscorePackage() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();

        $versions = $this->findVersionsForPackage($config, 'mistralys/application-utils-core');

        $this->assertSame(
            array('mistralys/application-utils-core' => '2.3.14'),
            $versions
        );
    }

    /**
     * `ConfigSwitcher` writes the `version` field from a local
     * repository entry into `composer.json` verbatim — it is a
     * deliberate boundary that no validation of the version string
     * happens here. A malformed value is the caller's responsibility;
     * this characterises that the switcher itself never rejects or
     * silently corrects one.
     */
    public function test_malformedVersionIsWrittenVerbatim() : void
    {
        $malformedVersion = 'not-a-version';

        $devFile = new ConfigFile($this->testTarget . '/composer/local-repositories.json');
        $devConfig = $devFile->getData();

        foreach($devConfig['local-repositories'] as &$repo)
        {
            if($repo['package-name'] === 'mistralys/application-utils') {
                $repo['version'] = $malformedVersion;
            }
        }
        unset($repo);

        $devFile->putData($devConfig);

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();

        $this->assertSame($malformedVersion, $config['require']['mistralys/application-utils'] ?? null);

        $versions = $this->findVersionsForPackage($config, 'mistralys/application-utils');

        $this->assertSame($malformedVersion, $versions['mistralys/application-utils'] ?? null);
    }

    /**
     * After a DEV switch, the VCS repository entry matching a
     * switched package must be replaced by a single path entry.
     */
    public function test_devSwitchPrunesStaleVCSRepository() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $repos = $config['repositories'];

        foreach($repos as $repository)
        {
            if(!isset($repository['type'], $repository['url'])) {
                continue;
            }

            // No VCS entry should remain for any switched package.
            if($repository['type'] === 'vcs') {
                $this->assertFalse(
                    stripos($repository['url'], 'application-framework') !== false
                    || stripos($repository['url'], 'application-utils-core') !== false
                    || stripos($repository['url'], 'application-utils') !== false,
                    'Stale VCS repository entry found after DEV switch: ' . $repository['url']
                );
            }
        }

        $this->assertConfigHasExpectedPaths($switcher);
    }

    /**
     * When the PROD config has two VCS entries matching the same
     * switched package, the DEV switch must replace the first and
     * remove the second — leaving exactly one path entry.
     */
    public function test_devSwitchPrunesMultipleMatchingRepositories() : void
    {
        // Add a duplicate VCS entry for application-framework.
        $mainFile = new ConfigFile($this->testTarget . '/composer.json');
        $config = $mainFile->getData();
        $config['repositories'][] = array(
            'type' => 'vcs',
            'url' => 'git@github.com:Mistralys/application-framework.git'
        );
        $mainFile->putData($config);

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $repos = $config['repositories'];

        // Count path entries that match application-framework.
        $pathCount = 0;
        $vcsCount = 0;
        foreach($repos as $repository)
        {
            if(!isset($repository['type'], $repository['url'])) {
                continue;
            }

            $matches = stripos($repository['url'], 'application-framework') !== false
                && stripos($repository['url'], 'application-utils') === false;

            if(!$matches) {
                continue;
            }

            if($repository['type'] === 'path') {
                $pathCount++;
            } elseif($repository['type'] === 'vcs') {
                $vcsCount++;
            }
        }

        $this->assertSame(1, $pathCount, 'Expected exactly one path entry for application-framework.');
        $this->assertSame(0, $vcsCount, 'Expected no VCS entries for application-framework after DEV switch.');
    }

    public function test_verifyReturnsInSyncWhenIdentical() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $result = $switcher->verify();

        $this->assertTrue($result->isInSync());
        $this->assertEmpty($result->getDifferences());
        $this->assertFalse($result->isDevMode());
        $this->assertTrue($result->isComparable());
    }

    public function test_verifyReturnsOutOfSyncWhenDifferent() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        // Modify the main composer.json to create a difference.
        $config = $switcher->getMainFile()->getData();
        $config['require']['php'] = '>=8.0';
        $switcher->getMainFile()->putData($config);

        $result = $switcher->verify();

        $this->assertFalse($result->isInSync());
        $this->assertContains('require', $result->getDifferences());
    }

    public function test_verifyWarnsInDevMode() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $result = $switcher->verify();

        $this->assertFalse($result->isInSync());
        $this->assertEmpty($result->getDifferences());
        $this->assertTrue($result->isDevMode());
        $this->assertFalse($result->isComparable());
    }

    public function test_statusFileStoresCanonicalPaths() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $data = $switcher->getStatus()->getData();

        $this->assertStringNotContainsString('/../', $data['mainFile']);
        $this->assertStringNotContainsString('/../', $data['prodFile']);
        $this->assertStringNotContainsString('/../', $data['devFile']);
    }

    public function test_installGitHooksCopiesResourceExecutable() : void
    {
        $hooksDir = $this->testTarget . '/.git/hooks';
        mkdir($hooksDir, 0777, true);

        $switcher = $this->createSwitcher();
        $result = $switcher->installGitHooks($this->testTarget);

        $this->assertTrue($result);

        $hookFile = $hooksDir . '/pre-commit';
        $this->assertFileExists($hookFile);

        $resourceFile = __DIR__ . '/../../resources/git-hooks/pre-commit';
        $this->assertFileEquals($resourceFile, $hookFile);
        $this->assertTrue(is_executable($hookFile));
    }

    public function test_installGitHooksSkipsWhenGitHooksDirMissing() : void
    {
        $switcher = $this->createSwitcher();
        $result = $switcher->installGitHooks($this->testTarget);

        $this->assertFalse($result);
        $this->assertDirectoryDoesNotExist($this->testTarget . '/.git');
    }

    public function test_fromProjectRootThreePathConvention() : void
    {
        $switcher = ConfigSwitcher::fromProjectRoot($this->testTarget);

        $this->assertSame(
            $this->testTarget . '/composer.json',
            $switcher->getMainFile()->getPath()
        );

        $this->assertSame(
            $this->testTarget . '/composer/composer-prod.json',
            $switcher->getProdFile()->getPath()
        );

        $this->assertSame(
            $this->testTarget . '/composer/local-repositories.json',
            $switcher->getDevFile()->getPath()
        );
    }

    public function test_devSwitchRespectsRequireDevPlacement() : void
    {
        $devPackage = 'mistralys/some-dev-tool';

        // Add a require-dev package to the fixture's composer.json.
        $mainFile = new ConfigFile($this->testTarget . '/composer.json');
        $config = $mainFile->getData();
        $config['require-dev'][$devPackage] = '>=1.0';
        $mainFile->putData($config);

        // Add a matching entry to the dev config.
        $devFile = new ConfigFile($this->testTarget . '/composer/local-repositories.json');
        $devConfig = $devFile->getData();
        $devConfig['local-repositories'][] = array(
            'package-name' => $devPackage,
            'path' => '/path/to/some-dev-tool'
        );
        $devFile->putData($devConfig);

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $result = $switcher->getMainFile()->getData();

        // The package must remain in require-dev, not be moved to require.
        $this->assertArrayHasKey($devPackage, $result['require-dev']);
        $this->assertArrayNotHasKey($devPackage, $result['require']);
    }

    public function test_devSwitchPreservesNonSwitchedVCSRepository() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $repos = $config['repositories'];

        $found = false;
        foreach($repos as $repository)
        {
            if(
                isset($repository['type'], $repository['url'])
                && stripos($repository['url'], 'some-unrelated-library') !== false
            ) {
                $found = true;
                $this->assertSame('vcs', $repository['type'], 'Non-switched VCS entry must retain its type.');
                break;
            }
        }

        $this->assertTrue($found, 'Expected the non-switched VCS entry for some-unrelated-library to survive the DEV switch.');
    }

    // endregion

    // region: Support methods

    /**
     * @return int[]
     */
    private function getMessageCodes(ConfigSwitcher $switcher) : array
    {
        return array_map(
            static fn(SwitchMessage $message) : int => $message->getCode(),
            $switcher->getMessages()
        );
    }

    private function assertFlagIsDEV(ConfigSwitcher $switcher) : void
    {
        $this->assertTrue($switcher->getFlagFile(ConfigSwitcher::MODE_DEV)->exists());
        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_PROD)->exists());
    }

    private function assertFlagIsPROD(ConfigSwitcher $switcher) : void
    {
        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_DEV)->exists());
        $this->assertTrue($switcher->getFlagFile(ConfigSwitcher::MODE_PROD)->exists());
    }
    
    /**
     * @param ConfigFile|LockFile $target
     * @return void
     */
    private function assertLockFileIsDEV($target) : void
    {
        $this->assertLockFileIs($target, 'DEV');
    }

    /**
     * @param ConfigFile|LockFile $target
     * @return void
     */
    private function assertLockFileIsPROD($target) : void
    {
        $this->assertLockFileIs($target, 'PROD');
    }

    /**
     * @param ConfigFile|LockFile $target
     * @return void
     */
    private function assertLockFileIs($target, string $mode) : void
    {
        if($target instanceof ConfigFile) {
            $target = $target->getLockFile();
        }

        $this->assertSame($mode, $target->getContent());
    }

    private function assertConfigHasExpectedPaths(ConfigSwitcher $switcher) : void
    {
        $this->assertConfigHasPath($switcher, '/path/to/application-framework');
        $this->assertConfigHasPath($switcher, '/path/to/application-utils-core');
        $this->assertConfigHasPath($switcher, '/path/to/application-utils');
    }

    private function assertConfigHasPath(ConfigSwitcher $switcher, string $path) : void
    {
        $config = $switcher->getMainFile()->getData();
        $this->assertArrayHasKey('repositories', $config);
        $this->assertIsArray($config['repositories']);

        foreach($config['repositories'] as $repository)
        {
            if(isset($repository['type']) && $repository['type'] === 'path' && $repository['url'] === $path) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('No path repository found for path: ' . $path);
    }

    private function assertConfigHasCustomVersion(ConfigSwitcher $switcher, string $packageName, string $version) : void
    {
        $config = $switcher->getMainFile()->getData();
        $this->assertArrayHasKey('repositories', $config);
        $this->assertIsArray($config['repositories']);

        foreach($config['repositories'] as $repository)
        {
            if(
                isset($repository['type'], $repository['options']['versions'][$packageName])
                && $repository['type'] === 'path'
                && $repository['options']['versions'][$packageName] === $version
            ) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('No version definition found for package name: ' . $packageName);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function findVersionsForPackage(array $config, string $packageName) : array
    {
        $this->assertArrayHasKey('repositories', $config);
        $this->assertIsArray($config['repositories']);

        foreach($config['repositories'] as $repository)
        {
            if(
                isset($repository['type'], $repository['options']['versions'])
                && $repository['type'] === 'path'
                && isset($repository['options']['versions'][$packageName])
            ) {
                return $repository['options']['versions'];
            }
        }

        $this->fail('No versions definition found for package name: ' . $packageName);
    }

    // endregion
}
