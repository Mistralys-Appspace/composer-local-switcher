<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
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

    public function test_specificPackageVersion() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $this->assertConfigHasCustomVersion($switcher, 'mistralys/application-utils-core', '2.3.14');
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
            'url' => 'git@github.com:Mistralys/application-framework-mirror.git'
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

        $this->assertTrue($result['inSync']);
        $this->assertEmpty($result['differences']);
        $this->assertArrayNotHasKey('devMode', $result);
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

        $this->assertFalse($result['inSync']);
        $this->assertContains('require', $result['differences']);
    }

    public function test_verifyWarnsInDevMode() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $result = $switcher->verify();

        $this->assertFalse($result['inSync']);
        $this->assertEmpty($result['differences']);
        $this->assertTrue($result['devMode']);
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
        $devFile = new ConfigFile($this->testTarget . '/composer/dev-config.json');
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


    // endregion

    // region: Support methods

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

    // endregion
}
