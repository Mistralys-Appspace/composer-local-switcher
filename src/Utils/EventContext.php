<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

/**
 * The single duck-typing site for Composer's event/IO objects.
 *
 * The library has zero runtime dependencies, so it cannot type-hint
 * against `Composer\Script\Event`/`Composer\IO\IOInterface` directly.
 * Every `composerSwitch*` static entry point receives whatever object
 * Composer's `EventDispatcher` passes it (or `null`, when called
 * directly rather than through a Composer script), and hands it to
 * {@see self::fromEvent()} — the only place in the library that probes
 * for `getArguments()`/`getIO()`/`isInteractive()`/`askConfirmation()`/
 * `write()` via `method_exists()`. Everything downstream (
 * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner},
 * {@see \Mistralys\ComposerSwitcher\Utils\OutcomeRenderer}) consumes
 * this typed adapter instead.
 *
 * Not `final`, so a Tier 1 test double (a `ScriptedEventContext`,
 * built in a later work package) can extend it with queued answers.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
class EventContext
{
    /**
     * @param string[] $arguments
     */
    protected function __construct(
        private readonly array $arguments,
        private readonly ?object $io
    )
    {
    }

    /**
     * Builds a context from whatever Composer passed to a script
     * callback. Every probe below is a single `method_exists()` check —
     * this is the only place in the library that duck-types Composer's
     * event/IO objects.
     *
     * Fallbacks: a `null` event yields no arguments and a non-interactive
     * context; an event without a usable `getIO()` result behaves the
     * same way, with {@see self::write()} falling back to a bare `echo`.
     */
    public static function fromEvent(?object $event) : self
    {
        $arguments = array();
        $io = null;

        if($event !== null)
        {
            if(method_exists($event, 'getArguments'))
            {
                $result = $event->getArguments();

                if(is_array($result))
                {
                    $arguments = array_values(array_filter($result, 'is_string'));
                }
            }

            if(method_exists($event, 'getIO'))
            {
                $ioCandidate = $event->getIO();

                if(is_object($ioCandidate))
                {
                    $io = $ioCandidate;
                }
            }
        }

        return new self($arguments, $io);
    }

    /**
     * @return string[]
     */
    public function getArguments() : array
    {
        return $this->arguments;
    }

    public function hasFlag(string $flag) : bool
    {
        return in_array($flag, $this->arguments, true);
    }

    /**
     * Whether the IO is interactive. `false` when there is no IO, or
     * the IO has no `isInteractive()` method — never throws.
     */
    public function isInteractive() : bool
    {
        if($this->io === null || !method_exists($this->io, 'isInteractive'))
        {
            return false;
        }

        return (bool)$this->io->isInteractive();
    }

    /**
     * Asks for confirmation through the IO's `askConfirmation()`.
     * Returns `false` without asking when there is no IO, or the IO has
     * no `askConfirmation()` method — the caller never invokes this on
     * a non-interactive context in practice, since {@see self::isInteractive()}
     * already gates that, but the fallback keeps this method safe to
     * call unconditionally.
     */
    public function confirm(string $question, bool $default) : bool
    {
        if($this->io === null || !method_exists($this->io, 'askConfirmation'))
        {
            return false;
        }

        return (bool)$this->io->askConfirmation($question, $default);
    }

    /**
     * Writes a single line. Delegates to the IO's `write()` when
     * available, so output goes through Composer's own output
     * formatting; falls back to a bare `echo … . PHP_EOL` otherwise,
     * matching the pre-existing static entry points' own behavior.
     */
    public function write(string $line) : void
    {
        if($this->io !== null && method_exists($this->io, 'write'))
        {
            $this->io->write($line);
            return;
        }

        echo $line . PHP_EOL;
    }

    /**
     * Writes a single line carrying one of {@see \Mistralys\ComposerSwitcher\ConfigSwitcher}'s
     * `MESSAGE_*` codes, the edge-layer equivalent of
     * {@see \Mistralys\ComposerSwitcher\State\SwitchMessage} (which
     * pairs text with a code for messages the core produces). The code
     * is rendered as a `[<code>]` prefix, so it stays inspectable in
     * the same written-line stream {@see self::write()} already uses —
     * a test double capturing lines can assert on the code without a
     * second, parallel reporting channel.
     */
    public function writeMessage(int $code, string $text) : void
    {
        $this->write(sprintf('[%d] %s', $code, $text));
    }
}
