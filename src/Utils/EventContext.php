<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\State\MessageLevel;

/**
 * The single duck-typing site for Composer's event/IO objects.
 *
 * The library has zero runtime dependencies, so it cannot type-hint
 * against `Composer\Script\Event`/`Composer\IO\IOInterface` directly.
 * Every `composerSwitch*` static entry point receives whatever object
 * Composer's `EventDispatcher` passes it (or `null`, when called
 * directly rather than through a Composer script), and hands it to
 * {@see self::fromEvent()} — the only place in the library that probes
 * for `getArguments()`/`getIO()`/`isInteractive()`/`isVerbose()`/
 * `askConfirmation()`/`write()` via `method_exists()`. Everything downstream (
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
     * Whether the IO reports increased verbosity (e.g. `-v`/`-vv`/`-vvv`).
     * Duck-types the IO's `isVerbose()`, exactly like {@see self::isInteractive()}
     * duck-types `isInteractive()`. `false` when there is no IO, or the
     * IO has no `isVerbose()` method — never throws. Gates the `[code]`
     * prefix in {@see self::writeMessage()}, and is available to callers
     * that want to gate their own output the same way.
     */
    public function isVerbose() : bool
    {
        if($this->io === null || !method_exists($this->io, 'isVerbose'))
        {
            return false;
        }

        return (bool)$this->io->isVerbose();
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
     *
     * A line passed here is not run through Composer's tag formatter
     * on purpose — plain, unstyled text never risks a stray `<` being
     * misread as a formatter tag. A caller that wants `<info>`-style
     * tags honored, or a dynamic value that might contain a literal
     * `<`, should use {@see self::writeStyled()} / {@see self::escape()}
     * instead.
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
     * Writes a single line that may carry Composer's `<info>`/
     * `<comment>`/`<warning>` style tags. Delegates to the IO's
     * `write()` exactly like {@see self::write()} — Composer's own IO
     * resolves the tags into terminal styling — but the `echo`
     * fallback strips exactly those three tag names first, since a
     * bare `echo` has no formatter to interpret them and would
     * otherwise print the raw markup.
     */
    public function writeStyled(string $line) : void
    {
        if($this->io !== null && method_exists($this->io, 'write'))
        {
            $this->io->write($line);
            return;
        }

        echo $this->stripStyleTags($line) . PHP_EOL;
    }

    /**
     * Escapes a dynamic value so it renders literally when later passed
     * through {@see self::write()} / {@see self::writeStyled()} to
     * Composer's IO — necessary for any value that might contain a
     * literal `<` (e.g. a version constraint like `<2.0`), which
     * Composer's formatter would otherwise try to read as the start of
     * a style tag. A no-op (returns `$text` unchanged) when there is no
     * IO, since the `echo` fallback never interprets tags in the first
     * place.
     */
    public function escape(string $text) : string
    {
        if($this->io === null)
        {
            return $text;
        }

        return str_replace('<', '\\<', $text);
    }

    /**
     * Writes a single line carrying one of {@see \Mistralys\ComposerSwitcher\ConfigSwitcher}'s
     * `MESSAGE_*` codes, the edge-layer equivalent of
     * {@see \Mistralys\ComposerSwitcher\State\SwitchMessage} (which
     * pairs text with a code for messages the core produces). The code
     * is only rendered as a `[<code>] ` prefix when {@see self::isVerbose()}
     * — at default verbosity the line reads as plain human text, with
     * no machine-code noise. `$level` carries no behavior here (this
     * class never filters by it); it exists so a test double can
     * record `(code, text, level)` as data without a dedicated
     * recording hook in production code.
     */
    public function writeMessage(int $code, string $text, MessageLevel $level = MessageLevel::Info) : void
    {
        if($this->isVerbose())
        {
            $this->write(sprintf('[%d] %s', $code, $text));
            return;
        }

        $this->write($text);
    }

    /**
     * Strips `<info>`, `<comment>` and `<warning>` tags (opening and
     * closing) from `$line` — the exact tag set {@see self::writeStyled()}
     * supports, used only by its `echo` fallback.
     */
    private function stripStyleTags(string $line) : string
    {
        $pattern = '#</?(?:info|comment|warning)>#';

        return preg_replace($pattern, '', $line) ?? $line;
    }
}
