<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use PHPUnit\Framework\Attributes\DataProvider;

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
     * `reconcile()` called before any switch has ever been run (the
     * status file does not exist) is a no-op for every direction,
     * including an explicit one — it reports {@see MODE_INITIAL} and
     * {@see MESSAGE_INITIAL_NOT_RECONCILABLE} rather than throwing or
     * implicitly creating `composer-prod.json`.
     *
     */
    #[DataProvider('provideInitialStateDirections')]
    public function test_initialStateIsNotReconcilable(?string $direction) : void
    {
        $switcher = $this->createSwitcher();

        $prodFile = $switcher->getProdFile();
        $prodLockFile = $prodFile->getLockFile();
        $statusFile = $switcher->getStatus();

        $this->assertFalse($prodFile->exists());
        $this->assertFalse($statusFile->exists());

        $outcome = $switcher->reconcile($direction);

        $this->assertSame(ConfigSwitcher::MODE_INITIAL, $outcome->getMode());
        $this->assertFalse($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_INITIAL_NOT_RECONCILABLE,
            $this->getMessageCodes($outcome)
        );
        $this->assertFalse($prodFile->exists());
        $this->assertFalse($prodLockFile->exists());
        $this->assertFalse($statusFile->exists());
    }

    /**
     * @return array<string,array{0:string|null}>
     */
    public static function provideInitialStateDirections() : array
    {
        return [
            'null (automatic)' => [null],
            'RECONCILE_TO_MAIN' => [ConfigSwitcher::RECONCILE_TO_MAIN],
            'RECONCILE_TO_PROD' => [ConfigSwitcher::RECONCILE_TO_PROD],
        ];
    }

    /**
     * An invalid direction still throws in the INITIAL state — the
     * INITIAL no-op policy only applies to the two valid direction
     * constants (and `null`), never to a bogus value.
     */
    public function test_invalidDirectionThrowsInInitialState() : void
    {
        $switcher = $this->createSwitcher();

        $this->assertFalse($switcher->getStatus()->exists());

        try {
            $switcher->reconcile('sideways');
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch(ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_INVALID_RECONCILE_DIRECTION, $e->getCode());
            $this->assertSame('sideways', $e->getContextValue(ComposerSwitcherException::KEY_DIRECTION));
        }
    }

    /**
     * In PROD mode with `composer-prod.json` deleted, `reconcile()`
     * (automatic direction) and the explicit `RECONCILE_TO_MAIN`
     * direction are both blocked — `composer.json` stays untouched and
     * `composer-prod.json` stays absent.
     *
     */
    #[DataProvider('provideBlockedMissingProdDirections')]
    public function test_missingProdConfigIsBlocked(?string $direction) : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $mainDataBefore = $switcher->getMainFile()->getData();
        $switcher->getProdFile()->delete();

        $outcome = $switcher->reconcile($direction);

        $this->assertFalse($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_PROD_CONFIG_MISSING,
            $this->getMessageCodes($outcome)
        );
        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
        $this->assertFalse($switcher->getProdFile()->exists());
    }

    /**
     * @return array<string,array{0:string|null}>
     */
    public static function provideBlockedMissingProdDirections() : array
    {
        return [
            'null (automatic)' => [null],
            'RECONCILE_TO_MAIN' => [ConfigSwitcher::RECONCILE_TO_MAIN],
        ];
    }

    /**
     * An explicit `RECONCILE_TO_PROD` is the one direction that
     * recovers from a missing `composer-prod.json`: it recreates the
     * file (and its lock, when present) from `composer.json` instead of
     * being blocked.
     */
    public function test_missingProdConfigRecoversWithExplicitDirection() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $mainData = $switcher->getMainFile()->getData();
        $mainLockContent = $switcher->getMainFile()->getLockFile()->getContent();
        $switcher->getProdFile()->delete();

        $outcome = $switcher->reconcile(ConfigSwitcher::RECONCILE_TO_PROD);

        $this->assertTrue($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_BACKED_UP_MAIN_TO_PROD,
            $this->getMessageCodes($outcome)
        );
        $this->assertNotContains(
            ConfigSwitcher::MESSAGE_PROD_CONFIG_MISSING,
            $this->getMessageCodes($outcome)
        );
        $this->assertTrue($switcher->getProdFile()->exists());
        $this->assertSame($mainData, $switcher->getProdFile()->getData());
        $this->assertSame($mainLockContent, $switcher->getProdFile()->getLockFile()->getContent());
    }

    /**
     * The same recovery in dry-run mode reports the copy as a planned
     * operation without creating `composer-prod.json` on disk.
     */
    public function test_missingProdConfigRecoveryDryRunCreatesNoFile() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();
        $switcher->getProdFile()->delete();

        $outcome = $switcher->reconcile(ConfigSwitcher::RECONCILE_TO_PROD, true);

        $this->assertTrue($outcome->isDryRun());
        $this->assertTrue($outcome->hasOperations());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_BACKED_UP_MAIN_TO_PROD,
            $this->getMessageCodes($outcome)
        );
        $this->assertFalse($switcher->getProdFile()->exists());
        $this->assertFalse($switcher->getFileSystem()->isDryRun());
    }

    /**
     * A PROD->PROD switch with `composer-prod.json` deleted does not
     * throw — `switch_case_PROD_PROD()` delegates into the same
     * blocked reconciliation core, so the switch completes and records
     * {@see MESSAGE_PROD_CONFIG_MISSING} alongside the usual PROD-mode
     * message, leaving `composer.json` untouched.
     */
    public function test_switchToProductionWithMissingProdConfigDoesNotThrow() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $mainDataBefore = $switcher->getMainFile()->getData();
        $switcher->getProdFile()->delete();

        $outcome = $switcher->switchToProduction();

        $this->assertContains(
            ConfigSwitcher::MESSAGE_PROD_CONFIG_MISSING,
            $this->getMessageCodes($outcome)
        );
        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
        $this->assertFalse($switcher->getProdFile()->exists());
    }

    /**
     * `switchUpdate()` dispatches to `switchToProduction()` while
     * already in PROD mode, so it must carry the exact same
     * no-throw/no-op guarantee with `composer-prod.json` missing.
     */
    public function test_switchUpdateWithMissingProdConfigDoesNotThrow() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();
        $switcher->getProdFile()->delete();

        $outcome = $switcher->switchUpdate();

        $this->assertContains(
            ConfigSwitcher::MESSAGE_PROD_CONFIG_MISSING,
            $this->getMessageCodes($outcome)
        );
        $this->assertFalse($switcher->getProdFile()->exists());
    }

    /**
     * A throw mid-reconcile (a malformed `composer.json`, surfacing
     * from `verify()`'s `getData()` call) must still restore the file
     * system facade's dry-run flag — `reconcile()`'s `try`/`finally`
     * must cover the entire reconciliation, not just the happy path.
     */
    public function test_dryRunFlagRestoredAfterMidReconcileThrow() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        // Malformed JSON: verify()'s mainFile->getData() call throws
        // ERROR_CANNOT_DECODE_JSON before a direction can be resolved.
        file_put_contents($switcher->getMainFile()->getPath(), '{not valid json');

        try {
            $switcher->reconcile(null, true);
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch(ComposerSwitcherException $e) {
            // Expected.
        }

        $this->assertFalse($switcher->getFileSystem()->isDryRun());

        // A subsequent real reconcile must still be able to write to disk.
        file_put_contents(
            $switcher->getMainFile()->getPath(),
            (string)file_get_contents($switcher->getProdFile()->getPath())
        );
        $this->modifyMainConfig($switcher);
        touch($switcher->getProdFile()->getPath(), time() - 60);
        touch($switcher->getMainFile()->getPath(), time());

        $mainData = $switcher->getMainFile()->getData();

        $switcher->reconcile();

        $this->assertSame($mainData, $switcher->getProdFile()->getData());
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
