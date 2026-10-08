<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;

/**
 * Tier 2 counterpart of the Tier 1 `TestConfirmation` suite: proves the
 * confirmation sequence end to end against a real, non-interactive
 * Composer invocation (every Tier 2 switch call is non-interactive by
 * construction, since it runs through a {@see \Symfony\Component\Process\Process}
 * pipe rather than an attached TTY).
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestConfirmation extends IntegrationTestCase
{
    private const PACKAGE_NAME = 'mistralys/simple_html_dom';

    // region: _Tests

    /**
     * A non-interactive `switch-dev` run without `--yes` exits non-zero,
     * leaves every file in the work copy untouched, and names `--yes` in
     * its output — AC-23.
     */
    public function test_nonInteractiveSwitchDevWithoutYesExitsNonZeroAndLeavesFilesUntouched() : void
    {
        $this->bootstrapProd();

        $mainJsonBefore = $this->readFile($this->testTarget . '/composer.json');
        $mainLockBefore = $this->readFile($this->testTarget . '/composer.lock');

        $result = $this->runComposer('switch-dev');

        $this->assertNotSame(0, $result->getExitCode(), 'Expected switch-dev without --yes to exit non-zero.');
        $this->assertTrue(
            $result->containsOutput('--yes'),
            sprintf(
                "Expected the output to name --yes.\nOutput:\n%s\nError output:\n%s",
                $result->getOutput(),
                $result->getErrorOutput()
            )
        );

        $this->assertSame($mainJsonBefore, $this->readFile($this->testTarget . '/composer.json'), 'Expected composer.json to be untouched.');
        $this->assertSame($mainLockBefore, $this->readFile($this->testTarget . '/composer.lock'), 'Expected composer.lock to be untouched.');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.DEV');
    }

    /**
     * A non-interactive `switch-dev -- --yes` completes in a single
     * command: the switched package is installed, with no follow-up
     * command needed — AC-11, AC-23.
     */
    public function test_switchDevWithYesCompletesInOneCommand() : void
    {
        $this->bootstrapProd();

        $result = $this->runSwitch('switch-dev');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-dev -- --yes failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;
        $this->assertTrue(is_link($vendorPath), 'Expected the switched package to already be installed as a symlink.');
    }

    /**
     * After a `composer require` made in DEV, `switch-prod -- --yes`
     * prints the permanent carry-back section — the change set's
     * `prodConfig` entry for the newly required package, rendered with
     * its `[permanent]` marker — before applying — AC-21.
     */
    public function test_switchProdShowsPermanentCarryBackSection() : void
    {
        $this->switchToDev();

        $requireResult = $this->runComposer('require', 'psr/log:^3.0', '--no-interaction');
        $this->assertTrue($requireResult->isSuccess(), 'Expected composer require to succeed.');

        $result = $this->runSwitch('switch-prod');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-prod -- --yes failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertTrue($result->containsOutput('psr/log'), 'Expected the carried-back package to be named in the output.');
        $this->assertTrue($result->containsOutput('[permanent]'), 'Expected the permanent-change marker to appear in the output.');
    }

    // endregion
}
