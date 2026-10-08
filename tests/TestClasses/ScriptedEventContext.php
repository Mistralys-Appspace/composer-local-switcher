<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

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
 * guards against), and every `write()`/`writeMessage()` line captured
 * in order instead of echoed.
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
     */
    public function __construct(array $arguments = array(), private readonly bool $interactive = true, array $queuedAnswers = array())
    {
        parent::__construct($arguments, null);

        $this->queuedAnswers = $queuedAnswers;
    }

    public function isInteractive() : bool
    {
        return $this->interactive;
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
}
