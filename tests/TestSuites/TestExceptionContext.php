<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use ErrorException;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;

/**
 * Verifies that {@see ComposerSwitcherException} carries a structured
 * context payload (in addition to the free-form message) at every
 * throw site exercised here, and that `ConfigFile::getData()`'s read
 * failure carries a real error code rather than the implicit `0`.
 */
final class TestExceptionContext extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * `switchTo()` with an unrecognized mode throws
     * `ERROR_INVALID_SWITCH_MODE` with the offending mode and the
     * expected set of valid modes in its context.
     */
    public function test_invalidModeContext() : void
    {
        $switcher = $this->createSwitcher();

        try {
            $switcher->switchTo('bogus');
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_INVALID_SWITCH_MODE, $e->getCode());
            $this->assertSame('bogus', $e->getContextValue(ComposerSwitcherException::KEY_MODE));
            $this->assertSame(
                array(ConfigSwitcher::MODE_DEV, ConfigSwitcher::MODE_PROD),
                $e->getContextValue(ComposerSwitcherException::KEY_EXPECTED)
            );
        }
    }

    /**
     * Deleting the DEV config (`local-repositories.json`) and then
     * switching to DEV throws `ERROR_DEV_FILE_MISSING` with the
     * missing file's path in its context.
     */
    public function test_missingDevFileContext() : void
    {
        $switcher = $this->createSwitcher();
        $devFilePath = $switcher->getDevFile()->getPath();
        unlink($devFilePath);

        try {
            $switcher->switchToDevelopment();
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_DEV_FILE_MISSING, $e->getCode());
            $this->assertSame($devFilePath, $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH));
        }
    }

    /**
     * A DEV config without a `local-repositories` key throws
     * `ERROR_INVALID_JSON_STRUCTURE` with the file's path and the
     * expected (missing) key in its context.
     */
    public function test_invalidJsonStructureContext() : void
    {
        $switcher = $this->createSwitcher();
        $devFile = $switcher->getDevFile();
        $devFile->putData(array());

        try {
            $switcher->switchToDevelopment();
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE, $e->getCode());
            $this->assertSame($devFile->getPath(), $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH));
            $this->assertSame(
                ConfigSwitcher::KEY_LOCAL_REPOSITORIES,
                $e->getContextValue(ComposerSwitcherException::KEY_EXPECTED)
            );
        }
    }

    /**
     * Copying a file into a read-only directory throws
     * `ERROR_CANNOT_COPY_FILE` with both the source and target paths,
     * and the captured native error, in its context — gracefully
     * skipped where `chmod()` has no real effect on write permissions
     * (e.g. running as root).
     */
    public function test_copyFailureContext() : void
    {
        $sourcePath = $this->testTarget . '/composer.json';
        $targetDir = $this->testTarget . '/unwritable-target';
        mkdir($targetDir, 0755);
        $targetPath = $targetDir . '/copy-destination.json';

        chmod($targetDir, 0555);

        try {
            $writable = @file_put_contents($targetPath, 'probe') !== false;

            if($writable) {
                @unlink($targetPath);
                $this->markTestSkipped('chmod() had no effect on write permissions in this environment (e.g. running as root).');
            }

            $source = new ConfigFile($sourcePath);
            $target = new ConfigFile($targetPath);

            try {
                $source->copyTo($target);
                $this->fail('Expected a ComposerSwitcherException to be thrown.');
            } catch (ComposerSwitcherException $e) {
                $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_COPY_FILE, $e->getCode());
                $this->assertSame($sourcePath, $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH));
                $this->assertSame($targetPath, $e->getContextValue(ComposerSwitcherException::KEY_TARGET_PATH));

                $nativeError = $e->getContextValue(ComposerSwitcherException::KEY_NATIVE_ERROR);
                $this->assertIsString($nativeError);
                $this->assertNotSame('', $nativeError);

                $previous = $e->getPrevious();
                $this->assertInstanceOf(ErrorException::class, $previous);
                $this->assertSame($nativeError, $previous->getMessage());
            }
        } finally {
            chmod($targetDir, 0755);
        }
    }

    /**
     * `ConfigFile::getData()` on an unreadable file throws
     * `ERROR_CANNOT_READ_FILE` (182108), not the implicit code `0`
     * that a bare `Exception` (or an uncoded throw) would carry, and
     * chains the captured native error as `getPrevious()`.
     */
    public function test_readFailureCarriesErrorCode() : void
    {
        $path = $this->testTarget . '/does-not-exist.json';
        $configFile = new ConfigFile($path);

        try {
            $configFile->getData();
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_READ_FILE, $e->getCode());
            $this->assertNotSame(0, $e->getCode());

            $nativeError = $e->getContextValue(ComposerSwitcherException::KEY_NATIVE_ERROR);
            $this->assertIsString($nativeError);
            $this->assertNotSame('', $nativeError);

            $previous = $e->getPrevious();
            $this->assertInstanceOf(ErrorException::class, $previous);
            $this->assertSame($nativeError, $previous->getMessage());
        }
    }

    /**
     * `FileSystem::write()` and `FileSystem::delete()` failures carry
     * the file path, the captured native error under `KEY_NATIVE_ERROR`,
     * and chain that same error as `getPrevious()` — gracefully skipped
     * where `chmod()` has no real effect on write permissions (e.g.
     * running as root).
     */
    public function test_writeAndDeleteFailureContext() : void
    {
        $targetDir = $this->testTarget . '/unwritable-write-delete';
        mkdir($targetDir, 0755);

        $writeTargetPath = $targetDir . '/write-target.json';
        $deleteTargetPath = $targetDir . '/delete-target.json';
        file_put_contents($deleteTargetPath, 'content');

        chmod($targetDir, 0555);

        try {
            $writable = @file_put_contents($writeTargetPath, 'probe') !== false;

            if($writable) {
                @unlink($writeTargetPath);
                $this->markTestSkipped('chmod() had no effect on write permissions in this environment (e.g. running as root).');
            }

            $fileSystem = $this->createSwitcher()->getDevFile()->getFileSystem();

            try {
                $fileSystem->write($writeTargetPath, 'content');
                $this->fail('Expected a ComposerSwitcherException to be thrown.');
            } catch (ComposerSwitcherException $e) {
                $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_WRITE_FILE, $e->getCode());
                $this->assertSame($writeTargetPath, $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH));

                $nativeError = $e->getContextValue(ComposerSwitcherException::KEY_NATIVE_ERROR);
                $this->assertIsString($nativeError);
                $this->assertNotSame('', $nativeError);

                $previous = $e->getPrevious();
                $this->assertInstanceOf(ErrorException::class, $previous);
                $this->assertSame($nativeError, $previous->getMessage());
            }

            try {
                $fileSystem->delete($deleteTargetPath);
                $this->fail('Expected a ComposerSwitcherException to be thrown.');
            } catch (ComposerSwitcherException $e) {
                $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_DELETE_FILE, $e->getCode());
                $this->assertSame($deleteTargetPath, $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH));

                $nativeError = $e->getContextValue(ComposerSwitcherException::KEY_NATIVE_ERROR);
                $this->assertIsString($nativeError);
                $this->assertNotSame('', $nativeError);

                $previous = $e->getPrevious();
                $this->assertInstanceOf(ErrorException::class, $previous);
                $this->assertSame($nativeError, $previous->getMessage());
            }
        } finally {
            chmod($targetDir, 0755);
        }
    }

    // endregion
}
