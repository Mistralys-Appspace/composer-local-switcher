<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Single choke-point through which every test-harness `git` invocation
 * passes, mirroring {@see ComposerRunner}'s pattern for Composer.
 *
 * Every command is executed via {@see Process}'s array-argument constructor,
 * which avoids the shell entirely. `git` is always resolved through `PATH`
 * rather than an injectable override, because Symfony's {@see Process}
 * only forwards `putenv()` changes for environment variables that already
 * existed at process start — a `PATH` substitution is the one override
 * mechanism guaranteed to work, and {@see \Mistralys\ComposerSwitcher\IntegrationSuites\TestGitHooks::test_skipsWhenGitUnavailable()}
 * relies on exactly that.
 */
class GitRunner
{
    public function __construct(private readonly ?string $workingDirectory = null)
    {
    }

    /**
     * Runs a `git` command in the configured working directory.
     *
     * @param string ...$arguments `git` sub-command and its own arguments,
     *        e.g. `run('add', 'composer.json')`.
     */
    public function run(string ...$arguments): ProcessResult
    {
        $process = new Process(array_merge(array('git'), $arguments), $this->workingDirectory);
        $process->run();

        return new ProcessResult(
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput()
        );
    }

    /**
     * Whether a usable `git` binary can be resolved and executed.
     */
    public function isAvailable(): bool
    {
        try {
            $process = new Process(array('git', '--version'));
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable $e) {
            return false;
        }
    }
}
