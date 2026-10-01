<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

/**
 * Immutable value object holding the outcome of a single process
 * invocation (e.g. {@see ComposerRunner::run()} or {@see GitRunner::run()}):
 * the exit code and the stdout/stderr streams, captured separately and
 * correctly attributed to each other.
 */
class ProcessResult
{
    public function __construct(
        private readonly int $exitCode,
        private readonly string $output,
        private readonly string $errorOutput
    )
    {
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Whether the given string appears anywhere in stdout or stderr.
     *
     * @param string $needle
     * @return bool
     */
    public function containsOutput(string $needle): bool
    {
        return str_contains($this->output, $needle) || str_contains($this->errorOutput, $needle);
    }
}
