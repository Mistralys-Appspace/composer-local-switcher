<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
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
     * A missing lock file no longer aborts the switch after the first
     * message — the switch now completes in full (config rewrite,
     * status file, flag file), so {@see ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND}
     * is the first of several messages rather than the only one.
     */
    public function test_missingLockFileMessageCarriesCode() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getMainFile()->getLockFile()->getPath());

        $switcher->switchToDevelopment();

        $messages = $switcher->getMessages();

        $this->assertNotEmpty($messages);
        $this->assertSame(ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND, $messages[0]->getCode());
    }

    /**
     * The very first switch to DEV mode is routed through the same
     * "PROD -> DEV" code path (there is no established mode yet), and
     * since no DEV lock file has ever been created, it forces the main
     * lock file to be deleted so `composer update` can recreate it,
     * emitting {@see ConfigSwitcher::MESSAGE_CREATE_NEW_LOCK_FILE}.
     */
    public function test_devLockRecreationMessageCarriesCode() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $codes = $this->getMessageCodes($switcher);

        $this->assertContains(ConfigSwitcher::MESSAGE_CREATE_NEW_LOCK_FILE, $codes);
        $this->assertFalse($switcher->getMainFile()->getLockFile()->exists());
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
            array(
                'Using Composer PROD configuration.',
                'Run `composer install` to use the production dependencies.'
            ),
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
            . 'Run `composer install` to use the production dependencies.' . PHP_EOL
            . PHP_EOL,
            $output
        );
    }

    // endregion

    // region: Support methods

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

    // endregion
}
