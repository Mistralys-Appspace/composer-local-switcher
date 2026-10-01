<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
use Mistralys\ComposerSwitcher\State\VerificationResult;
use PHPUnit\Framework\TestCase;

/**
 * These value objects carry no filesystem or process dependencies,
 * so they are tested directly against {@see TestCase} rather than
 * the fixture-driven {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase}.
 */
final class TestStateValueObjects extends TestCase
{
    // region: _Tests - SwitchMessage

    public function test_switchMessage_gettersAndArray() : void
    {
        $message = new SwitchMessage(182201, 'WARNING: No lock file found.');

        $this->assertSame(182201, $message->getCode());
        $this->assertSame('WARNING: No lock file found.', $message->getText());
        $this->assertSame('WARNING: No lock file found.', (string)$message);
        $this->assertSame(
            array('code' => 182201, 'text' => 'WARNING: No lock file found.'),
            $message->toArray()
        );
    }

    public function test_switchMessage_hasCode() : void
    {
        $withCode = new SwitchMessage(1, 'Has a code');
        $withoutCode = new SwitchMessage(0, 'Has no code');

        $this->assertTrue($withCode->hasCode());
        $this->assertFalse($withoutCode->hasCode());
    }

    /**
     * {@see SwitchMessage::hasCode()} treats any non-positive code —
     * not just the documented `0` default/unset value — as "no code".
     * A negative code is not a value any throw site in the library
     * ever constructs, but the getter's own `> 0` comparison should
     * still be verified against it directly.
     */
    public function test_switchMessage_hasCodeWithNegativeCode() : void
    {
        $negative = new SwitchMessage(-1, 'Has a negative code');

        $this->assertFalse($negative->hasCode());
        $this->assertSame(-1, $negative->getCode());
    }

    // endregion

    // region: _Tests - FileOperation

    public function test_fileOperation_gettersAndArray() : void
    {
        $operation = new FileOperation(
            FileOperation::TYPE_COPY,
            '/project/composer/composer-prod.json',
            '/project/composer.json',
            'Backing up the modified composer.json.',
            true
        );

        $this->assertSame(FileOperation::TYPE_COPY, $operation->getType());
        $this->assertSame('/project/composer/composer-prod.json', $operation->getTargetPath());
        $this->assertSame('/project/composer.json', $operation->getSourcePath());
        $this->assertSame('Backing up the modified composer.json.', $operation->getReason());
        $this->assertTrue($operation->isApplied());

        $this->assertSame(
            array(
                'type' => FileOperation::TYPE_COPY,
                'target' => '/project/composer/composer-prod.json',
                'source' => '/project/composer.json',
                'reason' => 'Backing up the modified composer.json.',
                'applied' => true
            ),
            $operation->toArray()
        );
    }

    public function test_fileOperation_nullSourcePath() : void
    {
        $operation = new FileOperation(
            FileOperation::TYPE_DELETE,
            '/project/composer.json.PROD',
            null,
            'Clearing stale flag files.',
            false
        );

        $this->assertNull($operation->getSourcePath());
        $this->assertFalse($operation->isApplied());
        $this->assertNull($operation->toArray()['source']);
    }

    // endregion

    // region: _Tests - VerificationResult

    public function test_verificationResult_inSyncOutsideDevMode() : void
    {
        $result = new VerificationResult(false, true, array());

        $this->assertFalse($result->isDevMode());
        $this->assertTrue($result->isComparable());
        $this->assertTrue($result->isInSync());
        $this->assertSame(array(), $result->getDifferences());
        $this->assertSame(
            array('inSync' => true, 'differences' => array(), 'devMode' => false),
            $result->toArray()
        );
    }

    public function test_verificationResult_differencesOutsideDevMode() : void
    {
        $result = new VerificationResult(false, false, array('require', 'require-dev'));

        $this->assertFalse($result->isInSync());
        $this->assertSame(array('require', 'require-dev'), $result->getDifferences());
    }

    /**
     * Matches today's DEV-mode behavior: comparison is not
     * meaningful, so `isInSync()` always reports `false`,
     * regardless of what `$inSync` was constructed with.
     */
    public function test_verificationResult_devModeForcesNotInSync() : void
    {
        $result = new VerificationResult(true, true, array());

        $this->assertTrue($result->isDevMode());
        $this->assertFalse($result->isComparable());
        $this->assertFalse($result->isInSync());
    }

    public function test_verificationResult_toArrayAlwaysIncludesDevModeKey() : void
    {
        $devResult = new VerificationResult(true, false, array());
        $prodResult = new VerificationResult(false, true, array());

        $this->assertTrue($devResult->toArray()['devMode']);
        $this->assertFalse($prodResult->toArray()['devMode']);
    }

    // endregion

    // region: _Tests - SwitchOutcome

    public function test_switchOutcome_gettersAndArray() : void
    {
        $messages = array(
            new SwitchMessage(0, 'Using Composer DEV configuration.'),
            new SwitchMessage(182202, 'Run `composer update` to create a DEV lock file.')
        );

        $operations = array(
            new FileOperation(FileOperation::TYPE_COPY, '/target/composer.lock', '/source/composer.lock', 'Restoring lock file.', true)
        );

        $outcome = new SwitchOutcome('dev', false, $messages, $operations);

        $this->assertSame('dev', $outcome->getMode());
        $this->assertFalse($outcome->isDryRun());
        $this->assertSame($messages, $outcome->getMessages());
        $this->assertSame(
            array('Using Composer DEV configuration.', 'Run `composer update` to create a DEV lock file.'),
            $outcome->getMessageTexts()
        );
        $this->assertSame($operations, $outcome->getOperations());
        $this->assertTrue($outcome->hasOperations());

        $array = $outcome->toArray();

        $this->assertSame('dev', $array['mode']);
        $this->assertFalse($array['dryRun']);
        $this->assertSame($messages[0]->toArray(), $array['messages'][0]);
        $this->assertSame($operations[0]->toArray(), $array['operations'][0]);
    }

    public function test_switchOutcome_noOperations() : void
    {
        $outcome = new SwitchOutcome('prod', true, array(), array());

        $this->assertFalse($outcome->hasOperations());
        $this->assertSame(array(), $outcome->getMessageTexts());
        $this->assertTrue($outcome->isDryRun());
    }

    /**
     * `toArray()` with no messages and no operations must still
     * produce the full key set, with both collections as empty
     * arrays rather than, say, being omitted or `null`.
     */
    public function test_switchOutcome_toArrayWithNoMessagesOrOperations() : void
    {
        $outcome = new SwitchOutcome('initial', false, array(), array());

        $array = $outcome->toArray();

        $this->assertSame('initial', $array['mode']);
        $this->assertFalse($array['dryRun']);
        $this->assertSame(array(), $array['messages']);
        $this->assertSame(array(), $array['operations']);
    }

    // endregion

    // region: _Tests - SwitchDescription

    public function test_switchDescription_gettersAndArray() : void
    {
        $verification = new VerificationResult(false, true, array());

        $files = array(
            array('label' => 'main', 'path' => '/project/composer.json', 'exists' => true, 'modifiedDate' => '2026-09-30 10:00:00')
        );

        $localRepositories = array(
            array('packageName' => 'mistralys/some-dev-tool', 'path' => '../some-dev-tool', 'version' => '*')
        );

        $warnings = array('No lock file found.');

        $description = new SwitchDescription(
            'dev',
            '2026-09-30 10:00:00',
            $files,
            'dev',
            $verification,
            $localRepositories,
            $warnings
        );

        $this->assertSame('dev', $description->getMode());
        $this->assertSame('2026-09-30 10:00:00', $description->getLastSwitchDate());
        $this->assertSame($files, $description->getFiles());
        $this->assertSame('dev', $description->getActiveFlag());
        $this->assertTrue($description->hasActiveFlag());
        $this->assertSame($verification, $description->getVerification());
        $this->assertSame($localRepositories, $description->getLocalRepositories());
        $this->assertSame($warnings, $description->getWarnings());
        $this->assertTrue($description->hasWarnings());

        $array = $description->toArray();

        $this->assertSame('dev', $array['mode']);
        $this->assertSame('2026-09-30 10:00:00', $array['lastSwitchDate']);
        $this->assertSame($files, $array['files']);
        $this->assertSame('dev', $array['activeFlag']);
        $this->assertSame($verification->toArray(), $array['verification']);
        $this->assertSame($localRepositories, $array['localRepositories']);
        $this->assertSame($warnings, $array['warnings']);
    }

    public function test_switchDescription_noSwitchYet() : void
    {
        $verification = new VerificationResult(false, false, array());

        $description = new SwitchDescription(
            null,
            null,
            array(),
            null,
            $verification,
            array(),
            array()
        );

        $this->assertNull($description->getMode());
        $this->assertNull($description->getLastSwitchDate());
        $this->assertNull($description->getActiveFlag());
        $this->assertFalse($description->hasActiveFlag());
        $this->assertFalse($description->hasWarnings());
    }

    /**
     * `toArray()`/`toJSON()` must round-trip cleanly with every
     * collection empty (no files, no local repositories, no warnings)
     * — the shape `describe()` produces for a freshly initialized
     * project before any file records have been gathered.
     */
    public function test_switchDescription_toArrayAndJsonWithEmptyCollections() : void
    {
        $verification = new VerificationResult(false, true, array());

        $description = new SwitchDescription(
            null,
            null,
            array(),
            null,
            $verification,
            array(),
            array()
        );

        $array = $description->toArray();

        $this->assertSame(array(), $array['files']);
        $this->assertSame(array(), $array['localRepositories']);
        $this->assertSame(array(), $array['warnings']);
        $this->assertNull($array['mode']);
        $this->assertNull($array['lastSwitchDate']);
        $this->assertNull($array['activeFlag']);

        $json = $description->toJSON();
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($array, $decoded);
    }

    // endregion
}
