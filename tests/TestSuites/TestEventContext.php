<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use PHPUnit\Framework\TestCase;
use Mistralys\ComposerSwitcher\State\MessageLevel;
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

    /**
     * At default verbosity (no IO at all, in this case), `writeMessage()`
     * writes plain text — no `[code]` prefix.
     */
    public function test_writeMessageOmitsCodeAtDefaultVerbosity() : void
    {
        $context = EventContext::fromEvent(null);

        ob_start();
        $context->writeMessage(182224, 'Skipping switch.');
        $output = ob_get_clean();

        $this->assertSame('Skipping switch.' . PHP_EOL, $output);
    }

    /**
     * An IO reporting `isVerbose() === true` makes `writeMessage()`
     * render the `[code]` prefix.
     */
    public function test_writeMessagePrefixesCodeWhenVerbose() : void
    {
        $io = new class {
            public function isVerbose() : bool
            {
                return true;
            }

            public function write(string $line) : void
            {
                echo $line . PHP_EOL;
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

        ob_start();
        $context->writeMessage(182224, 'Skipping switch.');
        $output = ob_get_clean();

        $this->assertSame('[182224] Skipping switch.' . PHP_EOL, $output);
    }

    /**
     * `$level` carries no behavior in {@see EventContext} itself (no
     * filtering, no styling) — passing a non-default level still
     * writes the same text, with or without the code prefix.
     */
    public function test_writeMessageAcceptsExplicitLevelWithNoBehaviorChange() : void
    {
        $context = EventContext::fromEvent(null);

        ob_start();
        $context->writeMessage(182201, 'A warning-level message.', MessageLevel::Warning);
        $output = ob_get_clean();

        $this->assertSame('A warning-level message.' . PHP_EOL, $output);
    }

    public function test_isVerboseFalseWhenNoIo() : void
    {
        $context = EventContext::fromEvent(null);

        $this->assertFalse($context->isVerbose());
    }

    public function test_isVerboseFalseWhenIoLacksIsVerbose() : void
    {
        $io = new class {
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

        $this->assertFalse($context->isVerbose());
    }

    public function test_isVerboseReflectsIo() : void
    {
        $io = new class {
            public function isVerbose() : bool
            {
                return true;
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

        $this->assertTrue($context->isVerbose());
    }

    /**
     * `escape()` is an identity function when there is no IO — the
     * `echo` fallback never interprets tags, so nothing needs escaping.
     */
    public function test_escapeIsIdentityWithNoIo() : void
    {
        $context = EventContext::fromEvent(null);

        $this->assertSame('<2.0', $context->escape('<2.0'));
    }

    /**
     * With an IO present, `escape()` renders a literal `<` so a dynamic
     * value (e.g. a version constraint) survives Composer's tag
     * formatter unchanged — proven here by actually routing it through
     * an anonymous IO that mimics Symfony's `OutputFormatter::escape()`
     * un-escaping convention (`\<` -> `<`).
     */
    public function test_escapedValueRendersLiterallyThroughIo() : void
    {
        $io = new class {
            /**
             * @var string[]
             */
            public array $written = array();

            public function write(string $line) : void
            {
                // Mimics Symfony's OutputFormatter: an escaped "\<" is
                // unescaped back to a literal "<" once the formatter
                // has finished looking for (and not finding) tags.
                $this->written[] = str_replace('\\<', '<', $line);
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

        $context->write(sprintf('Derived version: %s', $context->escape('<2.0')));

        $this->assertSame(array('Derived version: <2.0'), $io->written);
    }

    /**
     * No `recordMessage()` (or other recording) method exists on
     * {@see EventContext} — the test double records `writeMessage()`
     * calls by overriding the public, non-`final` method itself,
     * exactly as the class's own docblock documents, with no
     * test-only seam added to production code.
     */
    public function test_noRecordingHookExistsOnEventContext() : void
    {
        $reflection = new \ReflectionClass(EventContext::class);

        foreach($reflection->getMethods() as $method)
        {
            $this->assertStringNotContainsStringIgnoringCase(
                'record',
                $method->getName(),
                sprintf('Did not expect a recording method on EventContext, found "%s".', $method->getName())
            );
        }
    }

    public function test_writeStyledKeepsTagsWhenIoIsPresent() : void
    {
        $io = new class {
            /**
             * @var string[]
             */
            public array $written = array();

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
        $context->writeStyled('<info>Using Composer DEV configuration.</info>');

        $this->assertSame(array('<info>Using Composer DEV configuration.</info>'), $io->written);
    }

    public function test_writeStyledStripsTagsInEchoFallback() : void
    {
        $context = EventContext::fromEvent(null);

        ob_start();
        $context->writeStyled('<info>Info</info> <comment>Comment</comment> <warning>Warning</warning> plain');
        $output = ob_get_clean();

        $this->assertSame('Info Comment Warning plain' . PHP_EOL, $output);
    }

    // endregion
}
