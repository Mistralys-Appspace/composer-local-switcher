<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerRunner;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2 counterpart of `Mistralys\ComposerSwitcher\TestSuites\TestComposerRunner`.
 *
 * Every test here shells out to a real Composer binary, so it belongs in the
 * `Integration` testsuite (`composer test-integration`), not the default,
 * offline `Test suites` testsuite (`composer test`). The one case that never
 * invokes a real binary (`isAvailable()` against an unresolvable path) stays
 * in the Tier 1 file.
 */
final class TestComposerRunner extends TestCase
{
    // region: _Tests

    public function test_isAvailableReturnsTrueForRealBinary() : void
    {
        $runner = new ComposerRunner(getcwd());

        $this->assertTrue($runner->isAvailable());
    }

    public function test_runReturnsSeparateStreamsForSuccessfulCommand() : void
    {
        $runner = new ComposerRunner(getcwd());
        $result = $runner->run('--version');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('Composer version', $result->getOutput());
        $this->assertStringNotContainsString('Composer version', $result->getErrorOutput());
        $this->assertTrue($result->containsOutput('Composer version'));
    }

    public function test_runReturnsSeparateStreamsForFailingCommand() : void
    {
        $runner = new ComposerRunner(getcwd());
        $result = $runner->run('this-is-not-a-real-composer-command');

        $this->assertFalse($result->isSuccess());
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertSame('', $result->getOutput());
        $this->assertStringContainsString('not defined', $result->getErrorOutput());
    }

    public function test_runCompletesForLargeOutputCommandWithoutBlocking() : void
    {
        $runner = new ComposerRunner(getcwd());

        // A dry-run `composer update` against this project's own dependency
        // graph produces enough combined output on both streams to exercise
        // both pipes under load, proving the invocation drains
        // non-blockingly rather than deadlocking a hand-rolled two-pipe
        // proc_open() loop.
        $result = $runner->run('update', '--dry-run');

        $this->assertTrue($result->isSuccess());
        $this->assertNotSame('', $result->getErrorOutput());
    }

    // endregion
}
