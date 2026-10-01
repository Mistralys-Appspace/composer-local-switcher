<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use ErrorException;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\MarkerErrorRecorder;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

/**
 * Tier 1 suite for {@see FileSystem}: the single choke-point every
 * file mutation in the library passes through, in both real and
 * dry-run mode.
 *
 * Each test operates against its own throwaway work root under the
 * system temp directory, since these tests exercise {@see FileSystem}
 * directly and are unrelated to the `ComposerSwitcherTestCase`
 * fixture-copy flow.
 */
final class TestFileSystem extends TestCase
{
    /**
     * @var string
     */
    private $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = sys_get_temp_dir() . '/composer-local-switcher-filesystem-test-' . uniqid('', true);

        mkdir($this->workRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FixtureFileSystem::removeDirectory($this->workRoot);
    }

    // region: _Tests - Real mode

    public function test_realMode_writeCreatesFileAndRecordsOperation() : void
    {
        $fs = new FileSystem();
        $path = $this->workRoot . '/composer.json';

        $fs->write($path, '{"name":"test"}', 'Writing test data.');

        $this->assertFileExists($path);
        $this->assertSame('{"name":"test"}', file_get_contents($path));

        $operations = $fs->getOperations();
        $this->assertCount(1, $operations);

        $operation = $operations[0];
        $this->assertSame(FileOperation::TYPE_WRITE, $operation->getType());
        $this->assertSame($path, $operation->getTargetPath());
        $this->assertSame('Writing test data.', $operation->getReason());
        $this->assertTrue($operation->isApplied());
    }

    public function test_realMode_copyDuplicatesContentAndRecordsOperation() : void
    {
        $fs = new FileSystem();
        $source = $this->workRoot . '/source.json';
        $target = $this->workRoot . '/target.json';

        file_put_contents($source, 'source-content');

        $fs->copy($source, $target, 'Backing up source.');

        $this->assertFileExists($target);
        $this->assertSame('source-content', file_get_contents($target));

        $operation = $fs->getOperations()[0];
        $this->assertSame(FileOperation::TYPE_COPY, $operation->getType());
        $this->assertSame($target, $operation->getTargetPath());
        $this->assertSame($source, $operation->getSourcePath());
        $this->assertTrue($operation->isApplied());
    }

    public function test_realMode_deleteRemovesFileAndRecordsOperation() : void
    {
        $fs = new FileSystem();
        $path = $this->workRoot . '/to-delete.json';

        file_put_contents($path, 'content');

        $fs->delete($path, 'Clearing stale file.');

        $this->assertFileDoesNotExist($path);

        $operation = $fs->getOperations()[0];
        $this->assertSame(FileOperation::TYPE_DELETE, $operation->getType());
        $this->assertTrue($operation->isApplied());
    }

    public function test_realMode_deleteMissingFileIsNoOp() : void
    {
        $fs = new FileSystem();
        $path = $this->workRoot . '/does-not-exist.json';

        $fs->delete($path, 'Clearing a file that was never there.');

        $this->assertEmpty($fs->getOperations());
    }

    public function test_realMode_readMissingFileThrows() : void
    {
        $fs = new FileSystem();
        $path = $this->workRoot . '/missing.json';

        try {
            $fs->read($path);
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_READ_FILE, $e->getCode());

            $nativeError = $e->getContextValue(ComposerSwitcherException::KEY_NATIVE_ERROR);
            $this->assertIsString($nativeError);
            $this->assertNotSame('', $nativeError);

            $previous = $e->getPrevious();
            $this->assertInstanceOf(ErrorException::class, $previous);
            $this->assertSame($nativeError, $previous->getMessage());
        }
    }

    /**
     * Installs a Composer-style error handler — one that throws an
     * {@see ErrorException} for any native warning, exactly like
     * Composer's own `ErrorHandler::handle()` — around a real-mode
     * `read()`, `write()`, `copy()` and `delete()` failure, and
     * asserts every one of them still surfaces as the documented
     * {@see ComposerSwitcherException}, never as the raw
     * `\ErrorException` the installed handler would otherwise throw.
     */
    public function test_realMode_failuresRaiseNoNativeWarning() : void
    {
        set_error_handler(static function(int $severity, string $message, string $file = '', int $line = 0) : bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            // read() on a missing file.
            try {
                (new FileSystem())->read($this->workRoot . '/no-such-file.json');
                $this->fail('Expected a ComposerSwitcherException to be thrown.');
            } catch (ComposerSwitcherException $e) {
                $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_READ_FILE, $e->getCode());
            }

            // write() into a directory that does not exist.
            try {
                (new FileSystem())->write($this->workRoot . '/no-such-dir/file.json', 'content');
                $this->fail('Expected a ComposerSwitcherException to be thrown.');
            } catch (ComposerSwitcherException $e) {
                $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_WRITE_FILE, $e->getCode());
            }

            // copy() into a directory that does not exist.
            $source = $this->workRoot . '/copy-source.json';
            file_put_contents($source, 'source-content');

            try {
                (new FileSystem())->copy($source, $this->workRoot . '/no-such-dir/copy-target.json');
                $this->fail('Expected a ComposerSwitcherException to be thrown.');
            } catch (ComposerSwitcherException $e) {
                $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_COPY_FILE, $e->getCode());
            }

            // delete() of a file inside a read-only directory —
            // gracefully skipped where chmod() has no real effect on
            // write permissions (e.g. running as root).
            $readOnlyDir = $this->workRoot . '/read-only-dir';
            mkdir($readOnlyDir, 0755);

            $protectedFile = $readOnlyDir . '/protected.json';
            $probeFile = $readOnlyDir . '/probe.json';
            file_put_contents($protectedFile, 'content');
            file_put_contents($probeFile, 'probe');

            chmod($readOnlyDir, 0555);

            try {
                // The installed handler throws on any warning
                // regardless of the `@` operator, so writability is
                // probed by catching that throw rather than relying
                // on error suppression.
                try {
                    $writable = unlink($probeFile);
                } catch (ErrorException $probeError) {
                    $writable = false;
                }

                if($writable) {
                    chmod($readOnlyDir, 0755);
                    $this->markTestSkipped('chmod() had no effect on write permissions in this environment (e.g. running as root).');
                }

                try {
                    (new FileSystem())->delete($protectedFile);
                    $this->fail('Expected a ComposerSwitcherException to be thrown.');
                } catch (ComposerSwitcherException $e) {
                    $this->assertSame(ComposerSwitcherException::ERROR_CANNOT_DELETE_FILE, $e->getCode());
                }
            } finally {
                chmod($readOnlyDir, 0755);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Proves that {@see FileSystem}'s internal native-error capture
     * restores whatever error handler was active beforehand, and that
     * the previously installed handler never observes the native
     * warning {@see FileSystem} captured for itself.
     */
    public function test_realMode_errorHandlerRestoredAfterFailure() : void
    {
        $fs = new FileSystem();

        // A dedicated recorder object (rather than a by-reference
        // local variable) is used because PHPStan cannot see that
        // `trigger_error()` below invokes the closure, and would
        // otherwise flag the later `assertSame()` as comparing
        // against a provably-empty array.
        $marker = new MarkerErrorRecorder();

        set_error_handler($marker->asHandler());

        try {
            try {
                $fs->read($this->workRoot . '/missing.json');
                $this->fail('Expected a ComposerSwitcherException to be thrown.');
            } catch (ComposerSwitcherException $e) {
                // Expected — assertions on the exception itself are
                // covered by other tests in this suite.
            }

            $this->assertEmpty(
                $marker->getMessages(),
                'The previously installed error handler must not observe the native warning FileSystem captured for itself.'
            );

            trigger_error('marker-probe', E_USER_WARNING);

            $this->assertSame(
                array('marker-probe'),
                $marker->getMessages(),
                'The previously installed error handler must be restored after FileSystem captures its own native warning.'
            );
        } finally {
            restore_error_handler();
        }
    }

    public function test_realMode_clearOperationsEmptiesTheLog() : void
    {
        $fs = new FileSystem();
        $fs->write($this->workRoot . '/a.json', 'a');

        $this->assertNotEmpty($fs->getOperations());

        $fs->clearOperations();

        $this->assertEmpty($fs->getOperations());
    }

    // endregion

    // region: _Tests - Dry-run mode

    public function test_dryRun_writeNeverTouchesDisk() : void
    {
        $fs = new FileSystem();
        $fs->setDryRun(true);

        $path = $this->workRoot . '/pending.json';

        $fs->write($path, 'pending-content', 'Writing a preview change.');

        $this->assertFileDoesNotExist($path);
        $this->assertTrue($fs->exists($path));
        $this->assertSame('pending-content', $fs->read($path));

        $operation = $fs->getOperations()[0];
        $this->assertSame(FileOperation::TYPE_WRITE, $operation->getType());
        $this->assertFalse($operation->isApplied());
    }

    public function test_dryRun_copyResolvesSourceThroughPendingWrite() : void
    {
        $fs = new FileSystem();
        $fs->setDryRun(true);

        $source = $this->workRoot . '/source.json';
        $target = $this->workRoot . '/target.json';

        // The source has never existed on disk — it only exists as
        // a pending overlay write.
        $fs->write($source, 'pending-source-content', 'Writing a preview source.');
        $fs->copy($source, $target, 'Copying the pending source.');

        $this->assertFileDoesNotExist($source);
        $this->assertFileDoesNotExist($target);
        $this->assertSame('pending-source-content', $fs->read($target));

        $copyOperation = $fs->getOperations()[1];
        $this->assertSame(FileOperation::TYPE_COPY, $copyOperation->getType());
        $this->assertFalse($copyOperation->isApplied());
    }

    public function test_dryRun_deleteNeverTouchesDiskAndOverlaysExistence() : void
    {
        $fs = new FileSystem();
        $path = $this->workRoot . '/present.json';
        file_put_contents($path, 'content');

        $fs->setDryRun(true);
        $fs->delete($path, 'Previewing a deletion.');

        $this->assertFileExists($path);
        $this->assertFalse($fs->exists($path));

        $this->expectException(ComposerSwitcherException::class);
        $this->expectExceptionCode(ComposerSwitcherException::ERROR_CANNOT_READ_FILE);

        $fs->read($path);
    }

    public function test_dryRun_existsFallsBackToDiskWhenNoOverlayEntry() : void
    {
        $fs = new FileSystem();
        $path = $this->workRoot . '/on-disk.json';
        file_put_contents($path, 'content');

        $fs->setDryRun(true);

        $this->assertTrue($fs->exists($path));
        $this->assertSame('content', $fs->read($path));
    }

    public function test_dryRun_modifiedTimeReflectsOverlayState() : void
    {
        $fs = new FileSystem();
        $fs->setDryRun(true);

        $pendingPath = $this->workRoot . '/pending.json';
        $fs->write($pendingPath, 'content', 'Preview write.');
        $this->assertNotNull($fs->modifiedTime($pendingPath));

        $deletedPath = $this->workRoot . '/deleted.json';
        file_put_contents($deletedPath, 'content');
        $fs->delete($deletedPath, 'Preview delete.');
        $this->assertNull($fs->modifiedTime($deletedPath));
    }

    public function test_dryRun_disablingClearsThePendingOverlay() : void
    {
        $fs = new FileSystem();
        $fs->setDryRun(true);

        $path = $this->workRoot . '/pending.json';
        $fs->write($path, 'pending-content', 'Preview write.');
        $this->assertTrue($fs->exists($path));

        $fs->setDryRun(false);

        $this->assertFalse($fs->exists($path));
        $this->assertFalse($fs->isDryRun());
    }

    // endregion

    // region: _Tests - Facade propagation

    /**
     * {@see ConfigFile::setFileSystem()} propagates the given facade to
     * its eagerly-constructed {@see \Mistralys\ComposerSwitcher\Utils\LockFile},
     * since the lock file is created in the constructor, before a
     * shared facade can be injected — so keeping it in sync is the
     * override's explicit responsibility.
     */
    public function test_configFileLockFileSharesFacade() : void
    {
        $configFile = new ConfigFile($this->workRoot . '/composer.json');
        $fileSystem = new FileSystem();

        $configFile->setFileSystem($fileSystem);

        $this->assertSame($fileSystem, $configFile->getFileSystem());
        $this->assertSame($fileSystem, $configFile->getLockFile()->getFileSystem());
    }

    /**
     * {@see ConfigSwitcher}'s constructor propagates its single shared
     * {@see FileSystem} facade to every config file it owns, and —
     * transitively, via {@see ConfigFile::setFileSystem()} — to each of
     * their lock files too.
     */
    public function test_switcherPropagatesFacadeToAllLockFiles() : void
    {
        $switcher = new ConfigSwitcher(
            new ConfigFile($this->workRoot . '/composer.json'),
            new ConfigFile($this->workRoot . '/composer/composer-prod.json'),
            new ConfigFile($this->workRoot . '/composer/local-repositories.json')
        );

        $fileSystem = $switcher->getFileSystem();

        $this->assertSame($fileSystem, $switcher->getMainFile()->getFileSystem());
        $this->assertSame($fileSystem, $switcher->getProdFile()->getFileSystem());
        $this->assertSame($fileSystem, $switcher->getDevFile()->getFileSystem());

        $this->assertSame($fileSystem, $switcher->getMainFile()->getLockFile()->getFileSystem());
        $this->assertSame($fileSystem, $switcher->getProdFile()->getLockFile()->getFileSystem());
        $this->assertSame($fileSystem, $switcher->getDevFile()->getLockFile()->getFileSystem());
    }

    // endregion
}
