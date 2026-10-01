<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use Closure;

/**
 * Records the messages of every native PHP error/warning handed to
 * its {@see self::asHandler()} closure.
 *
 * Used by {@see \Mistralys\ComposerSwitcher\TestSuites\TestFileSystem}
 * to prove that a previously installed error handler is restored after
 * {@see \Mistralys\ComposerSwitcher\Utils\FileSystem} captures its own
 * native warning. A dedicated object (rather than a by-reference local
 * variable captured in a closure) is used so static analysis can't
 * prove the later assertion on the recorded messages false — it has
 * no way to see that `trigger_error()` invokes the closure.
 */
final class MarkerErrorRecorder
{
    /**
     * @var string[]
     */
    private array $messages = [];

    public function asHandler() : Closure
    {
        return function(int $severity, string $message) : bool {
            $this->messages[] = $message;
            return true;
        };
    }

    /**
     * @return string[]
     */
    public function getMessages() : array
    {
        return $this->messages;
    }
}
