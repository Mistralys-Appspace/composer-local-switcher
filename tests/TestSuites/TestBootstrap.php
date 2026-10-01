<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Tier 1 suite proving `tests/bootstrap.php` — the PHPUnit `bootstrap`
 * entry point configured in `phpunit.xml` — purges abandoned work
 * copies without touching ones still within
 * {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy::STALE_AFTER_SECONDS}.
 *
 * The bootstrap script is run as a real subprocess rather than
 * `require`d in-process: it is only ever meant to execute once, at
 * PHPUnit start-up, before any test class is loaded, so exercising it
 * in-process would both run it a second time unexpectedly and prove
 * nothing about its behaviour as an actual PHPUnit `bootstrap` entry.
 */
final class TestBootstrap extends TestCase
{
    /**
     * A work copy directory backdated by one hour — well within
     * {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy::STALE_AFTER_SECONDS}
     * (24 hours) — must still be present after `tests/bootstrap.php`
     * runs, proving the bootstrap-driven purge only removes genuinely
     * abandoned work copies rather than every pre-existing entry.
     */
    public function test_bootstrapDoesNotPurgeEntryYoungerThan24Hours() : void
    {
        $workRoot = dirname(__DIR__) . '/assets/work-projects';
        $probeDir = $workRoot . '/bootstrap-probe-' . uniqid('', true);

        if(!is_dir($workRoot)) {
            mkdir($workRoot, 0777, true);
        }

        mkdir($probeDir, 0777, true);
        touch($probeDir, time() - 3600);

        try {
            $process = new Process(array(PHP_BINARY, dirname(__DIR__) . '/bootstrap.php'), dirname(__DIR__, 2));
            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                sprintf(
                    "Expected tests/bootstrap.php to run without error.\nOutput:\n%s\nError output:\n%s",
                    $process->getOutput(),
                    $process->getErrorOutput()
                )
            );

            $this->assertDirectoryExists(
                $probeDir,
                'Expected a work copy younger than 24 hours to survive the bootstrap-driven purge.'
            );
        } finally {
            FixtureFileSystem::removeDirectory($probeDir);
        }
    }
}
