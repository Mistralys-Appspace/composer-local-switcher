<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\State\LockStatus;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Utils\ComposerContentHash;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

/**
 * Tier 1 suite for {@see \Mistralys\ComposerSwitcher\Utils\LockFile}'s
 * reader extension: {@see \Mistralys\ComposerSwitcher\Utils\LockFile::getContentHash()},
 * {@see \Mistralys\ComposerSwitcher\Utils\LockFile::getLockStatus()}
 * and {@see \Mistralys\ComposerSwitcher\Utils\LockFile::getLockedVersion()} —
 * all read through {@see FileSystem}, never throw, and never cache.
 *
 * Each test operates against its own throwaway work root under the
 * system temp directory, since these tests exercise {@see \Mistralys\ComposerSwitcher\Utils\LockFile}
 * directly and are unrelated to the `ComposerSwitcherTestCase`
 * fixture-copy flow.
 */
final class TestLockFile extends TestCase
{
    private string $workRoot;

    protected function setUp() : void
    {
        parent::setUp();

        $this->workRoot = sys_get_temp_dir() . '/composer-local-switcher-lockfile-test-' . uniqid('', true);

        mkdir($this->workRoot, 0777, true);
    }

    protected function tearDown() : void
    {
        parent::tearDown();

        FixtureFileSystem::removeDirectory($this->workRoot);
    }

    // region: _Tests - getContentHash()

    public function test_getContentHashReturnsHashFromValidLock() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test', 'require' => array('php' => '>=8.1')));
        $this->writeLock($configFile, array('content-hash' => 'abc123'));

        $this->assertSame('abc123', $configFile->getLockFile()->getContentHash());
    }

    public function test_getContentHashNullWhenLockMissing() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));

        $this->assertNull($configFile->getLockFile()->getContentHash());
    }

    /**
     * The Tier 1 fixture lock (`tests/assets/test-project/composer.lock`)
     * is the literal text `PROD`/`DEV`, not JSON — this must degrade
     * to `null`, never throw.
     */
    public function test_getContentHashNullWhenLockIsNotJson() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));
        file_put_contents($configFile->getLockFile()->getPath(), 'PROD');

        $this->assertNull($configFile->getLockFile()->getContentHash());
    }

    public function test_getContentHashNullWhenHashKeyAbsent() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));
        $this->writeLock($configFile, array('packages' => array()));

        $this->assertNull($configFile->getLockFile()->getContentHash());
    }

    // endregion

    // region: _Tests - getLockStatus()

    public function test_getLockStatusMissingWhenNoLockFile() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));

        $this->assertSame(LockStatus::Missing, $configFile->getLockFile()->getLockStatus());
    }

    public function test_getLockStatusFreshWhenHashesMatch() : void
    {
        $configData = array('name' => 'acme/test', 'require' => array('php' => '>=8.1'));
        $configFile = $this->writeConfig($configData);
        $this->writeLock($configFile, array('content-hash' => ComposerContentHash::fromConfigData($configData)));

        $this->assertSame(LockStatus::Fresh, $configFile->getLockFile()->getLockStatus());
    }

    public function test_getLockStatusStaleWhenHashesMismatch() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test', 'require' => array('php' => '>=8.1')));
        $this->writeLock($configFile, array('content-hash' => 'does-not-match'));

        $this->assertSame(LockStatus::Stale, $configFile->getLockFile()->getLockStatus());
    }

    public function test_getLockStatusUnknownWhenLockIsNotJson() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));
        file_put_contents($configFile->getLockFile()->getPath(), 'PROD');

        $this->assertSame(LockStatus::Unknown, $configFile->getLockFile()->getLockStatus());
    }

    public function test_getLockStatusUnknownWhenHashKeyAbsent() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));
        $this->writeLock($configFile, array('packages' => array()));

        $this->assertSame(LockStatus::Unknown, $configFile->getLockFile()->getLockStatus());
    }

    public function test_getLockStatusUnknownWhenConfigIsMalformed() : void
    {
        $configFile = new ConfigFile($this->workRoot . '/composer.json');
        file_put_contents($configFile->getPath(), '{not valid json');
        $this->writeLock($configFile, array('content-hash' => 'anything'));

        $this->assertSame(LockStatus::Unknown, $configFile->getLockFile()->getLockStatus());
    }

    /**
     * `getLockStatus()` must never cache: once the config changes
     * (and the lock is refreshed to match), a Stale status becomes
     * Fresh without needing a new `LockFile` instance.
     */
    public function test_getLockStatusIsReDerivedAfterConfigChanges() : void
    {
        $configData = array('name' => 'acme/test', 'require' => array('php' => '>=8.1'));
        $configFile = $this->writeConfig($configData);
        $this->writeLock($configFile, array('content-hash' => ComposerContentHash::fromConfigData($configData)));

        $lockFile = $configFile->getLockFile();

        $this->assertSame(LockStatus::Fresh, $lockFile->getLockStatus());

        // The config changes (a new require constraint) without the
        // lock being refreshed to match — now stale.
        $configData['require']['php'] = '>=8.2';
        $configFile->putData($configData);

        $this->assertSame(LockStatus::Stale, $lockFile->getLockStatus());

        // The lock is refreshed to match the new config — fresh again.
        $this->writeLock($configFile, array('content-hash' => ComposerContentHash::fromConfigData($configData)));

        $this->assertSame(LockStatus::Fresh, $lockFile->getLockStatus());
    }

    // endregion

    // region: _Tests - getLockedVersion()

    public function test_getLockedVersionReadsFromPackages() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));
        $this->writeLock($configFile, array(
            'packages' => array(
                array('name' => 'acme/one', 'version' => '1.2.3')
            )
        ));

        $this->assertSame('1.2.3', $configFile->getLockFile()->getLockedVersion('acme/one'));
    }

    public function test_getLockedVersionReadsFromPackagesDev() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));
        $this->writeLock($configFile, array(
            'packages' => array(),
            'packages-dev' => array(
                array('name' => 'acme/dev-only', 'version' => '2.0.0')
            )
        ));

        $this->assertSame('2.0.0', $configFile->getLockFile()->getLockedVersion('acme/dev-only'));
    }

    public function test_getLockedVersionNullForUnknownPackage() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));
        $this->writeLock($configFile, array('packages' => array(
            array('name' => 'acme/one', 'version' => '1.2.3')
        )));

        $this->assertNull($configFile->getLockFile()->getLockedVersion('acme/never-locked'));
    }

    public function test_getLockedVersionNullWhenLockUnreadable() : void
    {
        $configFile = $this->writeConfig(array('name' => 'acme/test'));

        $this->assertNull($configFile->getLockFile()->getLockedVersion('acme/one'));
    }

    // endregion

    // region: _Tests - Dry-run overlay

    /**
     * All three new readers consult the dry-run overlay first, exactly
     * like {@see \Mistralys\ComposerSwitcher\Utils\FileSystem::exists()}/{@see \Mistralys\ComposerSwitcher\Utils\FileSystem::read()} —
     * a pending, never-flushed-to-disk overlay write is observed
     * without the lock file actually existing on disk.
     */
    public function test_allThreeReadersObserveTheDryRunOverlay() : void
    {
        $configData = array('name' => 'acme/test', 'require' => array('php' => '>=8.1'));
        $configFile = $this->writeConfig($configData);
        $lockFile = $configFile->getLockFile();

        $fileSystem = new FileSystem();
        $configFile->setFileSystem($fileSystem);
        $fileSystem->setDryRun(true);

        $fileSystem->write(
            $lockFile->getPath(),
            json_encode(array(
                'content-hash' => ComposerContentHash::fromConfigData($configData),
                'packages' => array(
                    array('name' => 'acme/overlay-only', 'version' => '9.9.9')
                )
            ), JSON_THROW_ON_ERROR)
        );

        // Nothing was ever written to disk.
        $this->assertFileDoesNotExist($lockFile->getPath());

        $this->assertSame(ComposerContentHash::fromConfigData($configData), $lockFile->getContentHash());
        $this->assertSame(LockStatus::Fresh, $lockFile->getLockStatus());
        $this->assertSame('9.9.9', $lockFile->getLockedVersion('acme/overlay-only'));
    }

    // endregion

    // region: Support methods

    /**
     * @param array<string,mixed> $configData
     */
    private function writeConfig(array $configData) : ConfigFile
    {
        $configFile = new ConfigFile($this->workRoot . '/composer.json');
        $configFile->putData($configData);

        return $configFile;
    }

    /**
     * @param array<string,mixed> $lockData
     */
    private function writeLock(ConfigFile $configFile, array $lockData) : void
    {
        file_put_contents($configFile->getLockFile()->getPath(), json_encode($lockData, JSON_THROW_ON_ERROR));
    }

    // endregion
}
