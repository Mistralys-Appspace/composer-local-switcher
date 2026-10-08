<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Single choke-point through which every Tier 2 Composer invocation passes.
 *
 * Every command is executed via {@see Process}'s array-argument constructor,
 * which avoids the shell entirely and drains stdout/stderr non-blockingly,
 * so a large-output command (e.g. a bootstrap `composer update`) completes
 * instead of deadlocking a hand-rolled `proc_open()` two-pipe loop.
 */
class ComposerRunner
{
    public function __construct(private readonly string $workingDirectory)
    {
    }

    /**
     * Runs a Composer command in the configured working directory.
     * `--no-interaction` is always inserted — before the first `--`
     * separator when one is present (e.g. `run('switch-dev', '--',
     * '--yes')`, which must become `switch-dev --no-interaction --
     * --yes`, not `switch-dev -- --yes --no-interaction`: everything
     * after `--` is a script argument the switcher's own
     * `EventContext::getArguments()` reads, not a Composer global flag,
     * so appending `--no-interaction` there would hand it to the
     * script instead of Composer), or appended at the end otherwise.
     *
     * `--no-progress` is deliberately not appended here: it is rejected
     * by several commands (e.g. `show`) that don't declare it, and it
     * has nothing to suppress in the first place — Composer only ever
     * renders a progress bar against an interactive TTY, and every
     * invocation here runs through a {@see Process} pipe instead.
     *
     * @param string ...$arguments Composer sub-command and its own arguments,
     *        e.g. `run('update', 'some/package')`.
     */
    public function run(string ...$arguments): ProcessResult
    {
        $separatorIndex = array_search('--', $arguments, true);

        if($separatorIndex !== false) {
            array_splice($arguments, $separatorIndex, 0, array('--no-interaction'));
        } else {
            $arguments[] = '--no-interaction';
        }

        $command = array_merge(array($this->resolveBinary()), $arguments);

        $process = new Process($command, $this->workingDirectory);
        $process->run();

        return new ProcessResult(
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput()
        );
    }

    /**
     * Whether a usable Composer binary can be resolved and executed.
     */
    public function isAvailable(): bool
    {
        try {
            $process = new Process(array($this->resolveBinary(), '--version'));
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Resolves the Composer binary to invoke: the `COMPOSER_BINARY`
     * environment variable when set and non-empty, `composer` on the
     * `PATH` otherwise.
     */
    private function resolveBinary(): string
    {
        $envBinary = getenv('COMPOSER_BINARY');

        if (is_string($envBinary) && $envBinary !== '') {
            return $envBinary;
        }

        return 'composer';
    }
}
