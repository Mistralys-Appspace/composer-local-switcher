<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\MessageLevel;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;

/**
 * Verifies that every switch message carries an explicit,
 * non-zero {@see SwitchMessage} code, and that the text-only
 * accessors ({@see ConfigSwitcher::getMessageTexts()} and
 * {@see ConfigSwitcher::displayMessages()}) remain byte-identical
 * to the pre-refactor `string[]`-based behavior.
 */
final class TestMessages extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * A missing lock file no longer aborts the switch — the switch
     * still completes in full (snapshot, config rewrite, status file,
     * flag file), recording {@see ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND}
     * as a warning among the switch's other messages (not necessarily
     * first, since {@see ConfigSwitcher::MESSAGE_USING_DEV_CONFIG} is
     * recorded before the lock status is even checked).
     */
    public function test_missingLockFileMessageCarriesCode() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getMainFile()->getLockFile()->getPath());

        $switcher->switchToDevelopment();

        $this->assertContains(
            ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * Every message produced across the initial switch, both
     * cross-mode switches, and the same-mode PROD refresh must
     * carry a non-zero code — i.e. no `addMessage()` call site
     * in any of these code paths omits a code.
     */
    public function test_allMessagesCarryNonZeroCodes() : void
    {
        $initial = $this->createSwitcher();
        $initial->switchToDevelopment();
        $this->assertAllCodesNonZero($initial->getMessages());

        $devToProd = $this->createSwitcher();
        $devToProd->switchToDevelopment();
        // Simulate the user creating a DEV lock file, so the PROD
        // switch below does not hit the "no lock file" early return.
        $this->assertNotFalse(file_put_contents(
            $devToProd->getMainFile()->getLockFile()->getPath(),
            'DEV'
        ));
        $devToProd->switchToProduction();
        $this->assertAllCodesNonZero($devToProd->getMessages());

        $prodToDev = $this->createSwitcher();
        $prodToDev->switchToProduction();
        $prodToDev->switchToDevelopment();
        $this->assertAllCodesNonZero($prodToDev->getMessages());

        $prodToProd = $this->createSwitcher();
        $prodToProd->switchToProduction();
        // Force the "backing up the modified composer.json" branch
        // by making the main file newer than the production copy.
        touch($prodToProd->getMainFile()->getPath(), time() + 60);
        $prodToProd->switchToProduction();
        $this->assertAllCodesNonZero($prodToProd->getMessages());
    }

    /**
     * {@see ConfigSwitcher::MESSAGE_USING_DEV_CONFIG} is a bookkeeping
     * message, so this plan assigns it {@see MessageLevel::Detail}.
     */
    public function test_bookkeepingMessageHasDetailLevel() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $this->assertSame(
            MessageLevel::Detail,
            $this->getMessageByCode($switcher, ConfigSwitcher::MESSAGE_USING_DEV_CONFIG)->getLevel()
        );
    }

    /**
     * {@see ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND} previously
     * carried the free-form `WARNING:` text prefix; this plan assigns
     * it {@see MessageLevel::Warning} as data, while leaving its
     * `getText()` value exactly as it was (API compatibility).
     */
    public function test_previouslyWarningPrefixedMessageHasWarningLevel() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getMainFile()->getLockFile()->getPath());

        $switcher->switchToDevelopment();

        $message = $this->getMessageByCode($switcher, ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND);

        $this->assertSame(MessageLevel::Warning, $message->getLevel());
        $this->assertSame(
            'WARNING: No lock file found. Please run `composer update` after switching the config.',
            $message->getText()
        );
    }

    /**
     * Every ordinary message not covered by the bookkeeping (`Detail`)
     * or previously-`WARNING:`-prefixed (`Warning`) rules defaults to
     * {@see MessageLevel::Info} — {@see ConfigSwitcher::MESSAGE_LEGACY_FILES_FOUND}
     * is one such case.
     */
    public function test_ordinaryMessageHasInfoLevel() : void
    {
        $switcher = $this->createSwitcher();

        // A leftover v2-era `local-repositories.lock` file, cleaned up
        // on any switch — see TestSwitching::test_legacyDevLockFileIsCleanedUpOnAnySwitch().
        file_put_contents($switcher->getDevFile()->getLockFile()->getPath(), 'legacy dev lock content');

        $switcher->switchToProduction();

        $this->assertSame(
            MessageLevel::Info,
            $this->getMessageByCode($switcher, ConfigSwitcher::MESSAGE_LEGACY_FILES_FOUND)->getLevel()
        );
    }

    public function test_messageTextsMatchLegacyStrings() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();
        $this->assertNotFalse(file_put_contents(
            $switcher->getMainFile()->getLockFile()->getPath(),
            'DEV'
        ));

        $switcher->switchToProduction();

        $this->assertSame(
            array('Using Composer PROD configuration.'),
            $switcher->getMessageTexts()
        );
    }

    public function test_displayMessagesOutputUnchanged() : void
    {
        $switcher = $this->createSwitcher()->setWriteToConsole(false);

        $switcher->switchToDevelopment();
        $this->assertNotFalse(file_put_contents(
            $switcher->getMainFile()->getLockFile()->getPath(),
            'DEV'
        ));

        ob_start();
        $switcher->switchToProduction();
        $output = ob_get_clean();

        $this->assertSame(
            PHP_EOL
            . 'Using Composer PROD configuration.' . PHP_EOL
            . PHP_EOL,
            $output
        );
    }

    /**
     * `setDisplayMessages(false)` must suppress the automatic console
     * output a switch would otherwise print, while `getMessages()`
     * still reports every accumulated message — disabling display does
     * not also discard the messages themselves.
     */
    public function test_setDisplayMessagesFalseSuppressesOutput() : void
    {
        $switcher = $this->createSwitcher()
            ->setWriteToConsole(false)
            ->setDisplayMessages(false);

        ob_start();
        $switcher->switchToDevelopment();
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertNotEmpty($switcher->getMessages());
    }

    /**
     * {@see ConfigSwitcher::isDisplayMessagesEnabled()} defaults to
     * `true` (the switcher's own display setting), and reflects
     * whatever {@see ConfigSwitcher::setDisplayMessages()} was last
     * called with — the getter this plan adds to match the
     * pre-existing setter.
     */
    public function test_displayMessagesGetterReflectsSetter() : void
    {
        $switcher = $this->createSwitcher();

        $this->assertTrue($switcher->isDisplayMessagesEnabled());

        $switcher->setDisplayMessages(false);
        $this->assertFalse($switcher->isDisplayMessagesEnabled());

        $switcher->setDisplayMessages(true);
        $this->assertTrue($switcher->isDisplayMessagesEnabled());
    }

    /**
     * `displayMessages()` called directly against an empty message log
     * (no switch performed yet) must print nothing — not even the
     * blank-line padding the non-empty case wraps its output in.
     */
    public function test_displayMessagesWithEmptyLogPrintsNothing() : void
    {
        $switcher = $this->createSwitcher();

        $this->assertEmpty($switcher->getMessages());

        ob_start();
        $switcher->displayMessages();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    // endregion

    // region: Support methods

    /**
     * None of the message codes retired by this plan's switching-core
     * reshape (182202, 182205-182208, 182210-182212, 182215, 182216 —
     * the pre-v3 reconciliation/verification message family) may be
     * reintroduced as a `ConfigSwitcher::MESSAGE_*` constant value. A
     * reflection scan over the class's own constants, rather than a
     * fixed list of constant names, catches a retired number being
     * silently reused under a new name just as readily as the old one
     * reappearing.
     */
    public function test_retiredMessageCodesAreAbsent() : void
    {
        $retiredCodes = array(182202, 182205, 182206, 182207, 182208, 182210, 182211, 182212, 182215, 182216);

        $reflection = new \ReflectionClass(ConfigSwitcher::class);
        $constants = $reflection->getConstants();

        $messageCodes = array();
        foreach($constants as $name => $value)
        {
            if(str_starts_with($name, 'MESSAGE_') && is_int($value)) {
                $messageCodes[] = $value;
            }
        }

        foreach($retiredCodes as $retiredCode)
        {
            $this->assertNotContains(
                $retiredCode,
                $messageCodes,
                sprintf('Retired message code [%d] must not be reused by any ConfigSwitcher::MESSAGE_* constant.', $retiredCode)
            );
        }
    }

    /**
     * @param SwitchMessage[] $messages
     * @return void
     */
    private function assertAllCodesNonZero(array $messages) : void
    {
        $this->assertNotEmpty($messages);

        foreach($messages as $message)
        {
            $this->assertGreaterThan(
                0,
                $message->getCode(),
                sprintf('Message "%s" does not carry a positive code.', $message->getText())
            );
        }
    }

    /**
     * @return int[]
     */
    private function getMessageCodes(ConfigSwitcher $switcher) : array
    {
        return array_map(
            static fn(SwitchMessage $message) : int => $message->getCode(),
            $switcher->getMessages()
        );
    }

    /**
     * The first recorded message carrying the given code. Fails the
     * test (rather than returning `null`) when no such message exists,
     * since every caller uses this to assert on that message's level.
     */
    private function getMessageByCode(ConfigSwitcher $switcher, int $code) : SwitchMessage
    {
        foreach($switcher->getMessages() as $message)
        {
            if($message->getCode() === $code) {
                return $message;
            }
        }

        $this->fail(sprintf('No message with code [%d] was recorded.', $code));
    }

    // endregion
}
