<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use RuntimeException;

/**
 * Tier 1 suite for {@see WorkCopy}: collision-free name allocation,
 * fixture-backed creation, and age-based purging of abandoned work copies.
 *
 * Each test operates against its own throwaway work root under the
 * system temp directory rather than `tests/assets/work-projects/`, since
 * these tests exercise {@see WorkCopy} directly and are unrelated to the
 * `ComposerSwitcherTestCase` fixture-copy flow.
 */
final class TestWorkCopy extends TestCase
{
    /**
     * @var string
     */
    private $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = sys_get_temp_dir() . '/composer-local-switcher-workcopy-test-' . uniqid('', true);

        mkdir($this->workRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FixtureFileSystem::removeDirectory($this->workRoot);
    }

    // region: _Tests

    public function test_allocate_returnsDistinctPaths() : void
    {
        $first = WorkCopy::allocate($this->workRoot);
        $second = WorkCopy::allocate($this->workRoot);

        $this->assertNotSame($first->getPath(), $second->getPath());
    }

    public function test_allocate_pathContainsProcessId() : void
    {
        $workCopy = WorkCopy::allocate($this->workRoot);

        $this->assertStringContainsString((string)getmypid(), $workCopy->getPath());
    }

    public function test_allocate_doesNotCreateTheDirectory() : void
    {
        $workCopy = WorkCopy::allocate($this->workRoot);

        $this->assertDirectoryDoesNotExist($workCopy->getPath());
    }

    public function test_createFromFixture_throwsOnCollision() : void
    {
        $existingPath = $this->workRoot . '/already-there';
        mkdir($existingPath);

        $workCopy = new WorkCopy($existingPath);

        $this->expectException(RuntimeException::class);

        $workCopy->createFromFixture($this->createFixtureDir());
    }

    public function test_createFromFixture_copiesFixtureContents() : void
    {
        $workCopy = WorkCopy::allocate($this->workRoot);

        $workCopy->createFromFixture($this->createFixtureDir());

        $this->assertFileExists($workCopy->getPath() . '/marker.txt');
    }

    public function test_staleAfterSecondsConstant() : void
    {
        // Read via reflection rather than referencing the constant
        // directly, so the comparison is a genuine runtime check instead
        // of a compile-time-constant tautology PHPStan would flag.
        $value = (new ReflectionClassConstant(WorkCopy::class, 'STALE_AFTER_SECONDS'))->getValue();

        $this->assertSame(86400, $value);
    }

    public function test_purgeStale_removesOnlyEntriesOlderThanTheThreshold() : void
    {
        $stale = $this->createBackdatedEntry('stale', WorkCopy::STALE_AFTER_SECONDS + 10);
        $hourOld = $this->createBackdatedEntry('hour-old', 3600);
        $fresh = $this->createBackdatedEntry('fresh', 0);

        $removed = WorkCopy::purgeStale($this->workRoot);

        $this->assertSame(1, $removed);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($hourOld);
        $this->assertDirectoryExists($fresh);
    }

    public function test_purgeStale_unlinksSymlinkWithoutTouchingItsTarget() : void
    {
        $target = $this->workRoot . '/link-target.txt';
        file_put_contents($target, 'kept');

        $staleDir = $this->createBackdatedEntry('stale-with-link', WorkCopy::STALE_AFTER_SECONDS + 10);
        $link = $staleDir . '/link-to-target';
        symlink($target, $link);

        // Re-apply the backdated mtime: creating the symlink just now
        // would otherwise have bumped the containing directory back to
        // "fresh" on some filesystems.
        touch($staleDir, time() - (WorkCopy::STALE_AFTER_SECONDS + 10));

        WorkCopy::purgeStale($this->workRoot);

        $this->assertDirectoryDoesNotExist($staleDir);
        $this->assertFileExists($target);
        $this->assertSame('kept', file_get_contents($target));
    }

    public function test_purgeStale_returnsZeroForNonexistentWorkRoot() : void
    {
        $missingRoot = $this->workRoot . '/does-not-exist';

        $this->assertSame(0, WorkCopy::purgeStale($missingRoot));
    }

    // endregion

    private function createFixtureDir() : string
    {
        $fixtureDir = $this->workRoot . '/fixture-source';

        mkdir($fixtureDir, 0777, true);
        file_put_contents($fixtureDir . '/marker.txt', 'fixture');

        return $fixtureDir;
    }

    private function createBackdatedEntry(string $name, int $ageSeconds) : string
    {
        $path = $this->workRoot . '/' . $name;

        mkdir($path, 0777, true);

        if ($ageSeconds > 0) {
            touch($path, time() - $ageSeconds);
        }

        return $path;
    }
}
