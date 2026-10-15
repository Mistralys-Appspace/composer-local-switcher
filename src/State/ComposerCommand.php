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

    /**
     * Renders the argument list as a human-readable string, e.g.
     * `update vendor/pkg` for a console line meant to be read, not
     * copy-pasted into a shell — unlike {@see self::toShellString()},
     * a plain argument is left bare; only an argument containing
     * whitespace or a shell metacharacter is wrapped in single quotes,
     * so the common case (`update acme/local-one`) stays free of the
     * visual noise `escapeshellarg()` would add to every argument.
     */
    public function toDisplayString() : string
    {
        return implode(' ', array_map(
            function(string $argument) : string
            {
                return self::needsQuoting($argument) ? "'" . $argument . "'" : $argument;
            },
            $this->arguments
        ));
    }

    /**
     * Whether `$argument` contains whitespace or a shell metacharacter
     * and therefore needs quoting in {@see self::toDisplayString()} to
     * read unambiguously as a single token. An empty string also needs
     * quoting, since a bare empty argument would otherwise be invisible
     * in the joined output.
     */
    private static function needsQuoting(string $argument) : bool
    {
        return $argument === '' || preg_match('/[\s;&|<>()$`"\'\\\\*?\[\]{}~#!]/', $argument) === 1;
    }
}
