<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;

/**
 * Verifies {@see ConfigSwitcher::reconcile()}: content (via
 * {@see ConfigSwitcher::verify()}) decides whether to act, modification
 * time decides which direction to copy in, and `switch_case_PROD_PROD()`
 * delegates into the same reconciliation core rather than comparing
 * `filemtime()` on its own.
 */
final class TestReconcile extends ComposerSwitcherTestCase
{
    // region: _Tests

    public function test_noOperationsWhenInSync() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        // Identical content, but different modification times.
        touch($switcher->getMainFile()->getPath(), time() + 60);

        $outcome = $switcher->reconcile();

        $this->assertFalse($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_ALREADY_IN_SYNC,
            $this->getMessageCodes($outcome)
        );
    }

    public function test_backsUpMainToProdWhenMainNewer() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $this->modifyMainConfig($switcher);
        touch($switcher->getProdFile()->getPath(), time() - 60);
        touch($switcher->getMainFile()->getPath(), time());

        $mainData = $switcher->getMainFile()->getData();
        $mainLockContent = $switcher->getMainFile()->getLockFile()->getContent();

        $outcome = $switcher->reconcile();

        $this->assertTrue($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_BACKED_UP_MAIN_TO_PROD,
            $this->getMessageCodes($outcome)
        );
        $this->assertSame($mainData, $switcher->getProdFile()->getData());
        $this->assertSame($mainLockContent, $switcher->getProdFile()->getLockFile()->getContent());
    }

    public function test_restoresProdToMainWhenProdNewer() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $this->modifyProdConfig($switcher);
        touch($switcher->getMainFile()->getPath(), time() - 60);
        touch($switcher->getProdFile()->getPath(), time());

        $prodData = $switcher->getProdFile()->getData();
        $prodLockContent = $switcher->getProdFile()->getLockFile()->getContent();

        $outcome = $switcher->reconcile();

        $this->assertTrue($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_RESTORED_PROD_TO_MAIN,
            $this->getMessageCodes($outcome)
        );
        $this->assertSame($prodData, $switcher->getMainFile()->getData());
        $this->assertSame($prodLockContent, $switcher->getMainFile()->getLockFile()->getContent());
    }

    public function test_ambiguousWhenTimestampsEqual() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $this->modifyMainConfig($switcher);

        $sameTime = time();
        touch($switcher->getMainFile()->getPath(), $sameTime);
        touch($switcher->getProdFile()->getPath(), $sameTime);

        $mainDataBefore = $switcher->getMainFile()->getData();
        $prodDataBefore = $switcher->getProdFile()->getData();

        $outcome = $switcher->reconcile();

        $this->assertFalse($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_RECONCILE_AMBIGUOUS,
            $this->getMessageCodes($outcome)
        );
        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
        $this->assertSame($prodDataBefore, $switcher->getProdFile()->getData());
    }

    public function test_explicitDirectionResolvesAmbiguity() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $this->modifyMainConfig($switcher);

        $sameTime = time();
        touch($switcher->getMainFile()->getPath(), $sameTime);
        touch($switcher->getProdFile()->getPath(), $sameTime);

        $mainData = $switcher->getMainFile()->getData();

        $outcome = $switcher->reconcile(ConfigSwitcher::RECONCILE_TO_PROD);

        $this->assertTrue($outcome->hasOperations());
        $this->assertNotContains(
            ConfigSwitcher::MESSAGE_RECONCILE_AMBIGUOUS,
            $this->getMessageCodes($outcome)
        );
        $this->assertContains(
            ConfigSwitcher::MESSAGE_BACKED_UP_MAIN_TO_PROD,
            $this->getMessageCodes($outcome)
        );
        $this->assertSame($mainData, $switcher->getProdFile()->getData());
    }

    public function test_invalidDirectionThrows() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        try {
            $switcher->reconcile('sideways');
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch(ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION, $e->getCode());
            $this->assertSame('sideways', $e->getContextValue(ComposerSwitcherException::KEY_DIRECTION));
            $this->assertSame('sideways', $e->getContext()[ComposerSwitcherException::KEY_DIRECTION] ?? null);
        }
    }

    public function test_devModeIsNotReconcilable() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $mainDataBefore = $switcher->getMainFile()->getData();

        $outcome = $switcher->reconcile();

        $this->assertFalse($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_DEV_MODE_NOT_RECONCILABLE,
            $this->getMessageCodes($outcome)
        );
        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
    }

    /**
     * Two switchers, seeded with an identical starting state: one is
     * driven through `switchToProduction()` (PROD -> PROD, i.e.
     * `switch_case_PROD_PROD()`), the other calls `reconcile()`
     * directly. Both must produce identical file effects, since
     * `switch_case_PROD_PROD()` delegates into the same reconciliation
     * core rather than running its own `filemtime()` comparison.
     */
    public function test_prodToProdSwitchMatchesReconcile() : void
    {
        $viaSwitch = $this->createSwitcher();
        $viaSwitch->switchToProduction();
        $this->modifyMainConfig($viaSwitch);
        touch($viaSwitch->getProdFile()->getPath(), time() - 60);
        touch($viaSwitch->getMainFile()->getPath(), time());

        $viaReconcile = $this->createSecondSwitcher();
        $viaReconcile->switchToProduction();
        $this->modifyMainConfig($viaReconcile);
        touch($viaReconcile->getProdFile()->getPath(), time() - 60);
        touch($viaReconcile->getMainFile()->getPath(), time());

        // Drive the two switchers through the two different call paths
        // from this identical starting state.
        $viaSwitch->switchToProduction();
        $viaReconcile->reconcile();

        $this->assertSame(
            $viaSwitch->getProdFile()->getData(),
            $viaReconcile->getProdFile()->getData()
        );
        $this->assertSame(
            $viaSwitch->getMainFile()->getData(),
            $viaReconcile->getMainFile()->getData()
        );
        $this->assertSame(
            $viaSwitch->getProdFile()->getLockFile()->getContent(),
            $viaReconcile->getProdFile()->getLockFile()->getContent()
        );
    }

    // endregion

    // region: Support methods

    /**
     * @var WorkCopy[]
     */
    private array $extraWorkCopies = array();

    protected function tearDown() : void
    {
        foreach($this->extraWorkCopies as $workCopy) {
            $workCopy->remove();
        }

        $this->extraWorkCopies = array();

        parent::tearDown();
    }

    /**
     * Creates a second switcher against its own, independent work copy
     * of the same fixture, so a starting state can be reproduced
     * identically for two separate switchers within a single test.
     */
    private function createSecondSwitcher() : ConfigSwitcher
    {
        $workCopy = WorkCopy::allocate($this->assetsFolder . '/work-projects');
        $workCopy->createFromFixture($this->testSource);

        $this->extraWorkCopies[] = $workCopy;

        $target = $workCopy->getPath();

        return (new ConfigSwitcher(
            new ConfigFile($target . '/composer.json'),
            new ConfigFile($target . '/composer/composer-prod.json'),
            new ConfigFile($target . '/composer/local-repositories.json')
        ))
            ->setWriteToConsole(true);
    }

    private function modifyMainConfig(ConfigSwitcher $switcher) : void
    {
        $config = $switcher->getMainFile()->getData();
        $config['require']['php'] = '>=8.0';
        $switcher->getMainFile()->putData($config);
    }

    private function modifyProdConfig(ConfigSwitcher $switcher) : void
    {
        $config = $switcher->getProdFile()->getData();
        $config['require']['php'] = '>=8.1';
        $switcher->getProdFile()->putData($config);
    }

    /**
     * @return int[]
     */
    private function getMessageCodes(SwitchOutcome $outcome) : array
    {
        return array_map(
            static fn($message) : int => $message->getCode(),
            $outcome->getMessages()
        );
    }

    // endregion
}
