<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable value object describing a single planned Composer
 * invocation: the argument list (without the `composer` binary
 * itself — the caller decides how to locate and invoke it) and a
 * human-readable reason explaining why this command is being run.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class ComposerCommand
{
    /**
     * @param string[] $arguments The command's arguments, excluding the `composer` binary itself.
     * @param string $reason Human-readable explanation of why this command is being run.
     */
    public function __construct(
        private readonly array $arguments,
        private readonly string $reason
    )
    {
    }

    /**
     * @return string[]
     */
    public function getArguments() : array
    {
        return $this->arguments;
    }

    public function getReason() : string
    {
        return $this->reason;
    }

    /**
     * @return array{arguments:string[],reason:string}
     */
    public function toArray() : array
    {
        return array(
            'arguments' => $this->arguments,
            'reason' => $this->reason
        );
    }

    /**
     * @param array{arguments:string[],reason:string} $data
     */
    public static function fromArray(array $data) : self
    {
        return new self($data['arguments'], $data['reason']);
    }

    /**
     * Renders the argument list as a shell-escaped string (still
     * without the binary), e.g. for a "Run: composer {this}" display
     * message. Each argument is escaped independently via
     * `escapeshellarg()`, so no argument containing whitespace or
     * shell metacharacters can be misinterpreted as a separate token.
     */
    public function toShellString() : string
    {
        return implode(' ', array_map('escapeshellarg', $this->arguments));
    }
}
