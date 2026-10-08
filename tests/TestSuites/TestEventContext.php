<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use PHPUnit\Framework\TestCase;
use Mistralys\ComposerSwitcher\Utils\EventContext;

/**
 * Verifies {@see EventContext}'s adapter behaviour in isolation: the
 * `null`-event and no-IO fallbacks, reading arguments/flags from a
 * duck-typed event, and delegating interactivity/confirmation/writing
 * to a duck-typed IO — all via anonymous classes exposing exactly the
 * methods {@see EventContext::fromEvent()} probes for, with no
 * Composer class anywhere in this file (per this plan's "duck-typing
 * confined to one adapter" rule).
 */
final class TestEventContext extends TestCase
{
    // region: _Tests

    public function test_nullEventHasNoArgumentsAndIsNonInteractive() : void
    {
        $context = EventContext::fromEvent(null);

        $this->assertSame(array(), $context->getArguments());
        $this->assertFalse($context->hasFlag('--yes'));
        $this->assertFalse($context->isInteractive());
        $this->assertFalse($context->confirm('Apply?', true));
    }

    public function test_readsArgumentsAndFlagsFromEvent() : void
    {
        $event = new class {
            /**
             * @return string[]
             */
            public function getArguments() : array
            {
                return array('--yes', '--no-install');
            }
        };

        $context = EventContext::fromEvent($event);

        $this->assertSame(array('--yes', '--no-install'), $context->getArguments());
        $this->assertTrue($context->hasFlag('--yes'));
        $this->assertTrue($context->hasFlag('--no-install'));
        $this->assertFalse($context->hasFlag('--with-dependencies'));
    }

    /**
     * A non-string entry in the event's `getArguments()` result (a
     * theoretical but unenforced possibility, since the probed method
     * is untyped from this adapter's point of view) is filtered out
     * rather than crashing the adapter.
     */
    public function test_nonStringArgumentsAreFiltered() : void
    {
        $event = new class {
            /**
             * @return array<int,mixed>
             */
            public function getArguments() : array
            {
                return array('--yes', 42, null, '--no-install');
            }
        };

        $context = EventContext::fromEvent($event);

        $this->assertSame(array('--yes', '--no-install'), $context->getArguments());
    }

    public function test_interactivityAndConfirmDelegateToIo() : void
    {
        $io = new class {
            /**
             * @var array<int,array{question:string,default:bool}>
             */
            public array $confirmCalls = array();

            public function isInteractive() : bool
            {
                return true;
            }

            public function askConfirmation(string $question, bool $default) : bool
            {
                $this->confirmCalls[] = array('question' => $question, 'default' => $default);

                return true;
            }

            public function write(string $line) : void
            {
            }
        };

        $event = new class ($io) {
            public function __construct(private readonly object $io)
            {
            }

            /**
             * @return string[]
             */
            public function getArguments() : array
            {
                return array();
            }

            public function getIO() : object
            {
                return $this->io;
            }
        };

        $context = EventContext::fromEvent($event);

        $this->assertTrue($context->isInteractive());
        $this->assertTrue($context->confirm('Apply these changes?', false));

        $this->assertCount(1, $io->confirmCalls);
        $this->assertSame('Apply these changes?', $io->confirmCalls[0]['question']);
        $this->assertFalse($io->confirmCalls[0]['default']);
    }

    public function test_eventWithoutIoEchoesAndIsNonInteractive() : void
    {
        $event = new class {
            /**
             * @return string[]
             */
            public function getArguments() : array
            {
                return array('--yes');
            }
        };

        $context = EventContext::fromEvent($event);

        $this->assertFalse($context->isInteractive());
        $this->assertFalse($context->confirm('Apply?', true));

        ob_start();
        $context->write('Hello from the fallback.');
        $output = ob_get_clean();

        $this->assertSame('Hello from the fallback.' . PHP_EOL, $output);
    }

    /**
     * `confirm()` is a plain IO delegation with its own fallback — it
     * does not itself gate on `isInteractive()` (that gating is the
     * caller's responsibility, documented on {@see EventContext::confirm()}).
     * This test pins the fallback half of that contract: an IO that
     * reports non-interactive but has no `askConfirmation()` method at
     * all still yields `false` from `confirm()`, same as a missing IO.
     */
    public function test_confirmFallsBackToFalseWhenIoHasNoAskConfirmation() : void
    {
        $io = new class {
            public function isInteractive() : bool
            {
                return false;
            }

            public function write(string $line) : void
            {
            }
        };

        $event = new class ($io) {
            public function __construct(private readonly object $io)
            {
            }

            /**
             * @return string[]
             */
            public function getArguments() : array
            {
                return array();
            }

            public function getIO() : object
            {
                return $this->io;
            }
        };

        $context = EventContext::fromEvent($event);

        $this->assertFalse($context->isInteractive());
        $this->assertFalse($context->confirm('Apply?', true));
    }

    public function test_ioWriteIsUsedWhenAvailable() : void
    {
        $io = new class {
            /**
             * @var string[]
             */
            public array $written = array();

            public function isInteractive() : bool
            {
                return true;
            }

            public function askConfirmation(string $question, bool $default) : bool
            {
                return true;
            }

            public function write(string $line) : void
            {
                $this->written[] = $line;
            }
        };

        $event = new class ($io) {
            public function __construct(private readonly object $io)
            {
            }

            /**
             * @return string[]
             */
            public function getArguments() : array
            {
                return array();
            }

            public function getIO() : object
            {
                return $this->io;
            }
        };

        $context = EventContext::fromEvent($event);
        $context->write('Routed through IO.');

        $this->assertSame(array('Routed through IO.'), $io->written);
    }

    public function test_writeMessagePrefixesCode() : void
    {
        $context = EventContext::fromEvent(null);

        ob_start();
        $context->writeMessage(182224, 'Skipping switch.');
        $output = ob_get_clean();

        $this->assertSame('[182224] Skipping switch.' . PHP_EOL, $output);
    }

    // endregion
}
