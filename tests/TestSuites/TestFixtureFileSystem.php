<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tier 1 suite for {@see FixtureFileSystem}: the dangling-symlink-aware
 * `pathExists()` predicate and the collision-safe `copyDirectory()`.
 *
 * Each test operates against its own throwaway work root under the
 * system temp directory, since these tests exercise
 * {@see FixtureFileSystem} directly and are unrelated to the
 * `ComposerSwitcherTestCase` fixture-copy flow.
 */
final class TestFixtureFileSystem extends TestCase
{
    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = sys_get_temp_dir() . '/composer-local-switcher-fixturefs-test-' . uniqid('', true);

        mkdir($this->workRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FixtureFileSystem::removeDirectory($this->workRoot);
    }

    // region: _Tests

    public function test_pathExistsDetectsDirectoryFileAndDanglingSymlink() : void
    {
        $directory = $this->workRoot . '/a-directory';
        $file = $this->workRoot . '/a-file.txt';
        $danglingSymlink = $this->workRoot . '/a-dangling-symlink';
        $missing = $this->workRoot . '/does-not-exist';

        mkdir($directory);
        file_put_contents($file, 'content');
        symlink($this->workRoot . '/no-such-target', $danglingSymlink);

        $this->assertTrue(FixtureFileSystem::pathExists($directory));
        $this->assertTrue(FixtureFileSystem::pathExists($file));
        $this->assertTrue(FixtureFileSystem::pathExists($danglingSymlink));
        $this->assertFalse(FixtureFileSystem::pathExists($missing));
    }

    public function test_copyDirectoryThrowsOnExistingDirectory() : void
    {
        $source = $this->createSourceTree();
        $destination = $this->workRoot . '/destination';

        mkdir($destination);
        file_put_contents($destination . '/pre-existing.txt', 'untouched');

        $this->expectException(RuntimeException::class);

        try {
            FixtureFileSystem::copyDirectory($source, $destination);
        } finally {
            $this->assertFileExists($destination . '/pre-existing.txt');
            $this->assertSame('untouched', file_get_contents($destination . '/pre-existing.txt'));
        }
    }

    public function test_copyDirectoryThrowsOnExistingFile() : void
    {
        $source = $this->createSourceTree();
        $destination = $this->workRoot . '/destination-file';

        file_put_contents($destination, 'untouched');

        $this->expectException(RuntimeException::class);

        try {
            FixtureFileSystem::copyDirectory($source, $destination);
        } finally {
            $this->assertFileExists($destination);
            $this->assertSame('untouched', file_get_contents($destination));
        }
    }

    public function test_copyDirectoryThrowsOnExistingSymlink() : void
    {
        $source = $this->createSourceTree();
        $linkTarget = $this->workRoot . '/link-target.txt';
        $destination = $this->workRoot . '/destination-symlink';

        file_put_contents($linkTarget, 'untouched');
        symlink($linkTarget, $destination);

        $this->expectException(RuntimeException::class);

        try {
            FixtureFileSystem::copyDirectory($source, $destination);
        } finally {
            $this->assertTrue(is_link($destination));
            $this->assertSame('untouched', file_get_contents($linkTarget));
        }
    }

    public function test_copyDirectoryCopiesNestedTree() : void
    {
        $source = $this->createSourceTree();
        $destination = $this->workRoot . '/fresh-destination';

        FixtureFileSystem::copyDirectory($source, $destination);

        $this->assertFileExists($destination . '/root.txt');
        $this->assertSame('root-content', file_get_contents($destination . '/root.txt'));

        $this->assertDirectoryExists($destination . '/nested');
        $this->assertFileExists($destination . '/nested/child.txt');
        $this->assertSame('child-content', file_get_contents($destination . '/nested/child.txt'));
    }

    // endregion

    /**
     * Creates a small nested source tree (a top-level file plus a
     * subdirectory containing another file) to copy in the
     * `copyDirectory()` tests.
     */
    private function createSourceTree() : string
    {
        $source = $this->workRoot . '/source';

        mkdir($source . '/nested', 0777, true);
        file_put_contents($source . '/root.txt', 'root-content');
        file_put_contents($source . '/nested/child.txt', 'child-content');

        return $source;
    }
}
