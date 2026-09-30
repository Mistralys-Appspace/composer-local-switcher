<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
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

        $this->expectException(ComposerSwitcherException::class);
        $this->expectExceptionCode(ComposerSwitcherException::ERROR_CANNOT_READ_FILE);

        $fs->read($this->workRoot . '/missing.json');
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
}
