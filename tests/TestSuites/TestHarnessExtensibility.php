<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use RuntimeException;

/**
 * Minimal `ComposerSwitcherTestCase` subclass used to prove that
 * overriding {@see ComposerSwitcherTestCase::getFixtureSourceDir()}
 * changes the fixture directory `setUp()` copies from.
 *
 * Not a runnable test case itself: its `setUp()`/`tearDown()` are
 * invoked directly via reflection from
 * {@see TestHarnessExtensibility::test_fixtureSourceSeamIsOverridable()}.
 */
final class OverriddenFixtureSourceHarness extends ComposerSwitcherTestCase
{
    /**
     * Placeholder test method required by PHPUnit's TestCase constructor.
     * Never executed by the runner directly.
     */
    public function test_placeholder() : void
    {
        $this->expectNotToPerformAssertions();
    }

    protected function getFixtureSourceDir() : string
    {
        return 'integration-project';
    }

    public function getTestSource() : string
    {
        return $this->testSource;
    }

    public function getTestTarget() : string
    {
        return $this->testTarget;
    }
}

/**
 * Covers the Tier 1 harness extensibility seam and the teardown
 * fix for symlinked directories in `ComposerSwitcherTestCase`.
 *
 * @see ComposerSwitcherTestCase
 */
final class TestHarnessExtensibility extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * A subclass overriding {@see getFixtureSourceDir()} causes
     * `setUp()` to copy from the overridden fixture directory
     * instead of the default `test-project`.
     */
    public function test_fixtureSourceSeamIsOverridable() : void
    {
        $harness = new OverriddenFixtureSourceHarness('test_placeholder');

        $setUp = new \ReflectionMethod(ComposerSwitcherTestCase::class, 'setUp');
        $setUp->invoke($harness);

        try {
            $this->assertStringEndsWith('/integration-project', $harness->getTestSource());
            $this->assertDirectoryExists($harness->getTestTarget());
        } finally {
            FixtureFileSystem::removeDirectory($harness->getTestTarget());
        }
    }

    /**
     * `removeDirectory()` fully removes a work directory containing
     * a symlink to a directory, leaving no orphaned directory behind.
     */
    public function test_removeDirectoryHandlesSymlinkedDirectory() : void
    {
        $linkTargetDir = $this->assetsFolder . '/work-projects/symlink-target-' . uniqid();
        mkdir($linkTargetDir, 0777, true);
        file_put_contents($linkTargetDir . '/file.txt', 'content');

        $symlinkPath = $this->testTarget . '/vendor';
        symlink($linkTargetDir, $symlinkPath);

        $this->assertTrue(is_link($symlinkPath));

        FixtureFileSystem::removeDirectory($this->testTarget);

        $this->assertDirectoryDoesNotExist($this->testTarget);

        // The symlink's target must remain untouched: the fix must not
        // follow symlinks, only unlink the link itself.
        $this->assertDirectoryExists($linkTargetDir);
        $this->assertFileExists($linkTargetDir . '/file.txt');

        // Clean up the target directory manually since it lives
        // outside of $this->testTarget and won't be torn down
        // automatically.
        unlink($linkTargetDir . '/file.txt');
        rmdir($linkTargetDir);

        // tearDown() will remove $this->testTarget again via its WorkCopy;
        // FixtureFileSystem::removeDirectory() already no-ops on a
        // non-existent directory (see its is_dir() guard), so no further
        // cleanup is required here.
    }

    /**
     * {@see FixtureFileSystem::copyDirectory()} refuses to copy into an
     * already-existing destination, treating it as a collision rather
     * than silently merging into it.
     */
    public function test_copyDirectoryRefusesExistingTarget() : void
    {
        $this->expectException(RuntimeException::class);

        FixtureFileSystem::copyDirectory($this->testSource, $this->testTarget);
    }

    // endregion
}
