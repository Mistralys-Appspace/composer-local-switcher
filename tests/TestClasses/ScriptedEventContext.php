<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use Mistralys\ComposerSwitcher\State\MessageLevel;
use Mistralys\ComposerSwitcher\Utils\EventContext;

/**
 * A Tier 1 test double for {@see EventContext}, driving
 * {@see \Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner} without
 * any Composer event/IO object: queued `confirm()` answers (recording
 * every question/default asked, so a test can assert on the prompt
 * text and its default independently of the answer), an optional
 * in-prompt callback (fired from inside `confirm()`, letting a test
 * edit a file "while the user is being asked" — the
 * applied-equals-confirmed property this plan's second preview
 * guards against), every `write()`/`writeMessage()`/`writeStyled()`
 * line captured in order instead of echoed (a styled line is captured
 * with its style tags stripped, reusing {@see EventContext::stripStyleTags()}
 * so the stripping logic is not duplicated), and every `writeMessage()`
 * call's `(code, text, level)` captured separately as structured data —
 * no recording hook was added to production {@see EventContext} for
 * this; this double simply overrides the public, non-`final`
 * `writeMessage()`/`writeStyled()`.
 *
 * Extends {@see EventContext} (not final, exactly for this purpose)
 * rather than implementing a separate interface, so it can be passed
 * anywhere an `EventContext` is expected with no Composer-class
 * dependency anywhere in the test suite.
 */
final class ScriptedEventContext extends EventContext
{
    /**
     * @var array<int,array{question:string,default:bool,answer:bool}>
     */
    private array $confirmCalls = array();

    /**
     * @var string[]
     */
    private array $writtenLines = array();

    /**
     * @var array<int,array{code:int,text:string,level:MessageLevel}>
     */
    private array $writtenMessages = array();

    /**
     * @var bool[]
     */
    private array $queuedAnswers;

    /**
     * @var callable|null
     */
    private $onConfirmCallback = null;

    /**
     * @param string[] $arguments
     * @param bool[] $queuedAnswers Answers returned by successive
     *        `confirm()` calls, in order. When exhausted, `confirm()`
     *        falls back to the `$default` it was called with.
     * @param bool $verbose Overrides {@see EventContext::isVerbose()} —
     *        lets a test drive the `[code]`-prefix gating in
     *        {@see EventContext::writeMessage()} without a duck-typed IO.
     */
    public function __construct(
        array $arguments = array(),
        private readonly bool $interactive = true,
        array $queuedAnswers = array(),
        private readonly bool $verbose = false
    )
    {
        parent::__construct($arguments, null);

        $this->queuedAnswers = $queuedAnswers;
    }

    public function isInteractive() : bool
    {
        return $this->interactive;
    }

    public function isVerbose() : bool
    {
        return $this->verbose;
    }

    public function confirm(string $question, bool $default) : bool
    {
        if($this->onConfirmCallback !== null)
        {
            ($this->onConfirmCallback)();
        }

        $answer = count($this->queuedAnswers) > 0 ? array_shift($this->queuedAnswers) : $default;

        $this->confirmCalls[] = array(
            'question' => $question,
            'default' => $default,
            'answer' => $answer,
        );

        return $answer;
    }

    public function write(string $line) : void
    {
        $this->writtenLines[] = $line;
    }

    /**
     * Captures a styled line the same way {@see self::write()} captures
     * a plain one, with its style tags stripped via the inherited
     * {@see EventContext::stripStyleTags()} — matching the real
     * `echo` fallback's stripping behavior instead of falling through
     * to an uncaptured `echo`.
     */
    public function writeStyled(string $line) : void
    {
        $this->writtenLines[] = $this->stripStyleTags($line);
    }

    /**
     * Records `(code, text, level)` as structured data, then delegates
     * to {@see EventContext::writeMessage()} so the line still reaches
     * {@see self::write()} (and therefore {@see self::getWrittenLines()})
     * exactly as it would for a real IO.
     */
    public function writeMessage(int $code, string $text, MessageLevel $level = MessageLevel::Info) : void
    {
        $this->writtenMessages[] = array('code' => $code, 'text' => $text, 'level' => $level);

        parent::writeMessage($code, $text, $level);
    }

    /**
     * Registers a callback fired from inside the next (and every
     * subsequent) `confirm()` call, before the queued answer is
     * returned — used to simulate a file edit happening "while the
     * user is being asked".
     */
    public function setOnConfirmCallback(?callable $callback) : void
    {
        $this->onConfirmCallback = $callback;
    }

    /**
     * @return array<int,array{question:string,default:bool,answer:bool}>
     */
    public function getConfirmCalls() : array
    {
        return $this->confirmCalls;
    }

    public function wasConfirmCalled() : bool
    {
        return count($this->confirmCalls) > 0;
    }

    /**
     * @return string[]
     */
    public function getWrittenLines() : array
    {
        return $this->writtenLines;
    }

    /**
     * Whether any captured written line contains `$needle` — a
     * convenience for assertions that do not care which exact line
     * carried the text.
     */
    public function hasWrittenLineContaining(string $needle) : bool
    {
        foreach($this->writtenLines as $line)
        {
            if(str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `writeMessage()` was called with `$code` at any point —
     * a stable assertion channel that does not depend on the `[code]`
     * prefix's verbosity gating, unlike inspecting written lines.
     */
    public function hasWrittenMessageCode(int $code) : bool
    {
        foreach($this->writtenMessages as $message)
        {
            if($message['code'] === $code)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,array{code:int,text:string,level:MessageLevel}>
     */
    public function getWrittenMessages() : array
    {
        return $this->writtenMessages;
    }
}
