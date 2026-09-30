<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use FilesystemIterator;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\GitRunner;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

/**
 * Tier 2 counterpart of `Mistralys\ComposerSwitcher\TestSuites\TestComposerRunner`,
 * proving {@see GitRunner} in isolation against a real `git` binary.
 *
 * Every test here shells out to a real `git` binary, so it belongs in the
 * `Integration` testsuite (`composer test-integration`), not the default,
 * offline `Test suites` testsuite (`composer test`).
 */
final class TestGitRunner extends TestCase
{
    private ?string $workCopy = null;

    protected function tearDown() : void
    {
        if($this->workCopy !== null) {
            FixtureFileSystem::removeDirectory($this->workCopy);
            $this->workCopy = null;
        }

        parent::tearDown();
    }

    // region: _Tests

    public function test_isAvailableReturnsTrueForRealBinary() : void
    {
        $runner = new GitRunner();

        $this->assertTrue($runner->isAvailable());
    }

    public function test_runReturnsStdoutForSuccessfulCommand() : void
    {
        $runner = new GitRunner();
        $result = $runner->run('--version');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('git version', $result->getOutput());
    }

    public function test_runReturnsNonZeroAndStderrForFailingCommand() : void
    {
        $workCopy = $this->createEmptyGitWorkCopy();
        $runner = new GitRunner($workCopy);

        $result = $runner->run('rev-parse', '--verify', 'no-such-ref');

        $this->assertFalse($result->isSuccess());
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertNotSame('', $result->getErrorOutput());
    }

    public function test_runWithNoArgumentsReturnsNonZeroWithUsageOutput() : void
    {
        $runner = new GitRunner();

        $result = $runner->run();

        $this->assertFalse($result->isSuccess());
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertTrue(
            $result->containsOutput('usage') || $result->containsOutput('Usage'),
            'Expected a bare "git" invocation to print usage information to stdout or stderr.'
        );
    }

    public function test_runWithMissingWorkingDirectoryThrows() : void
    {
        $missingDir = sys_get_temp_dir() . '/composer-switcher-git-runner-missing-' . uniqid('', true);
        $runner = new GitRunner($missingDir);

        $this->expectException(ProcessRuntimeException::class);

        $runner->run('status');
    }

    /**
     * A read-only working directory prevents `git` from writing new
     * loose objects under `.git/objects/` (and similar), so the
     * command should fail with a non-zero exit code without the
     * process itself throwing. The `.git/` internals must be made
     * read-only recursively — chmod'ing only the top-level directory
     * leaves already-existing subdirectories (like `.git/`) writable,
     * since permissions are not inherited retroactively.
     *
     * Gracefully skipped where `chmod()` has no real effect on
     * write permissions (e.g. when running as root, where the
     * superuser bypasses filesystem permission checks).
     */
    public function test_runInReadOnlyWorkingDirectoryReturnsNonZero() : void
    {
        $workCopy = $this->createEmptyGitWorkCopy();

        $this->chmodRecursive($workCopy, 0555, 0444);

        try {
            $probeFile = $workCopy . '/write-probe.tmp';
            $writable = @file_put_contents($probeFile, 'probe') !== false;

            if($writable) {
                @unlink($probeFile);
                $this->markTestSkipped('chmod() had no effect on write permissions in this environment (e.g. running as root).');
            }

            $runner = new GitRunner($workCopy);
            $result = $runner->run('commit', '--allow-empty', '-m', 'test');

            $this->assertFalse($result->isSuccess());
            $this->assertNotSame(0, $result->getExitCode());
        } finally {
            $this->chmodRecursive($workCopy, 0755, 0644);
        }
    }

    // endregion

    // region: Support methods

    /**
     * Creates a throwaway, `git init`-ed work copy for tests that need a
     * real repository to run a failing `git` command against.
     */
    private function createEmptyGitWorkCopy() : string
    {
        $dir = sys_get_temp_dir() . '/composer-switcher-git-runner-' . uniqid('', true);

        if(!mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail(sprintf('Failed to create throwaway git work copy: %s', $dir));
        }

        $this->workCopy = $dir;

        $initResult = (new GitRunner($dir))->run('init');

        $this->assertTrue($initResult->isSuccess(), 'Expected "git init" to succeed in the throwaway work copy.');

        return $dir;
    }

    /**
     * Recursively chmods every entry under `$dir` (including `$dir`
     * itself), applying `$dirMode` to directories and `$fileMode` to
     * everything else. Used to make an entire `.git`-containing work
     * copy read-only (or to restore it afterward), since a chmod on
     * the top-level directory alone leaves already-existing
     * subdirectories at their original permissions.
     */
    private function chmodRecursive(string $dir, int $dirMode, int $fileMode) : void
    {
        chmod($dir, $dirMode);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach($items as $item) {
            chmod($item->getPathname(), $item->isDir() ? $dirMode : $fileMode);
        }
    }

    // endregion
}
