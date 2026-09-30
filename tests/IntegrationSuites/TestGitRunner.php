<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\GitRunner;
use PHPUnit\Framework\TestCase;

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

    // endregion
}
