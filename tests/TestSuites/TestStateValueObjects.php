<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\State\ComposerCommand;
use Mistralys\ComposerSwitcher\State\ConfigChange;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\State\ConfigChangeSet;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\InstalledState;
use Mistralys\ComposerSwitcher\State\LockStatus;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
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

        // Defaults for the extended fields, when not supplied at construction time.
        $this->assertNull($outcome->getComposerCommand());
        $this->assertFalse($outcome->isBlocked());
        $this->assertTrue($outcome->getConfigChanges()->isEmpty());
        $this->assertFalse($outcome->requiresConfirmation());
        $this->assertNull($array['composerCommand']);
        $this->assertFalse($array['blocked']);
        $this->assertSame(array('composerJson' => array(), 'prodConfig' => array()), $array['configChanges']);
    }

    public function test_switchOutcome_extendedFieldsGettersAndArray() : void
    {
        $command = new ComposerCommand(array('update', 'acme/local-one'), 'Installing the switched package.');
        $changes = new ConfigChangeSet(
            array(new ConfigChange(array('require', 'acme/local-one'), '*', '2.3.0', ConfigChange::KIND_CHANGED, ConfigChangeOrigin::LocalSwitch)),
            array()
        );

        $outcome = new SwitchOutcome('dev', false, array(), array(), $command, true, $changes);

        $this->assertSame($command, $outcome->getComposerCommand());
        $this->assertTrue($outcome->isBlocked());
        $this->assertSame($changes, $outcome->getConfigChanges());
        $this->assertTrue($outcome->requiresConfirmation());

        $array = $outcome->toArray();
        $this->assertSame($command->toArray(), $array['composerCommand']);
        $this->assertTrue($array['blocked']);
        $this->assertSame($changes->toArray(), $array['configChanges']);
    }

    /**
     * `requiresConfirmation()` is true exactly when the `composerJson`
     * section is non-empty — a `prodConfig`-only change set (e.g.
     * `describe()`'s pending-carry-back view) does not, by itself,
     * require confirmation.
     */
    public function test_switchOutcome_requiresConfirmationOnlyForComposerJsonSection() : void
    {
        $prodOnlyChanges = new ConfigChangeSet(
            array(),
            array(new ConfigChange(array('require', 'acme/carried'), null, '^1.0', ConfigChange::KIND_ADDED, ConfigChangeOrigin::CarriedBack))
        );

        $outcome = new SwitchOutcome('dev', false, array(), array(), null, false, $prodOnlyChanges);

        $this->assertFalse($outcome->requiresConfirmation());
    }

    public function test_switchOutcome_hasSameEffectsAsTrueForIdenticalOutcomes() : void
    {
        $command = new ComposerCommand(array('install'), 'Installing dependencies.');
        $changes = new ConfigChangeSet(array(), array());
        $operations = array(new FileOperation(FileOperation::TYPE_COPY, '/target', '/source', 'Copying.', true));

        $first = new SwitchOutcome('dev', false, array(new SwitchMessage(1, 'first')), $operations, $command, false, $changes);
        $second = new SwitchOutcome('dev', false, array(new SwitchMessage(2, 'second')), $operations, $command, false, $changes);

        $this->assertTrue($first->hasSameEffectsAs($second));
    }

    public function test_switchOutcome_hasSameEffectsAsFalseWhenBlockedDiffers() : void
    {
        $first = new SwitchOutcome('dev', false, array(), array(), null, false);
        $second = new SwitchOutcome('dev', false, array(), array(), null, true);

        $this->assertFalse($first->hasSameEffectsAs($second));
    }

    public function test_switchOutcome_hasSameEffectsAsFalseWhenConfigChangesDiffer() : void
    {
        $first = new SwitchOutcome('dev', false, array(), array(), null, false, new ConfigChangeSet(array(), array()));
        $second = new SwitchOutcome('dev', false, array(), array(), null, false, new ConfigChangeSet(
            array(new ConfigChange(array('extra'), null, array('foo' => 'bar'), ConfigChange::KIND_ADDED, ConfigChangeOrigin::CarriedBack)),
            array()
        ));

        $this->assertFalse($first->hasSameEffectsAs($second));
    }

    public function test_switchOutcome_hasSameEffectsAsFalseWhenCommandDiffers() : void
    {
        $first = new SwitchOutcome('dev', false, array(), array(), new ComposerCommand(array('install'), 'r'));
        $second = new SwitchOutcome('dev', false, array(), array(), new ComposerCommand(array('update'), 'r'));

        $this->assertFalse($first->hasSameEffectsAs($second));
    }

    public function test_switchOutcome_hasSameEffectsAsFalseWhenOperationsDiffer() : void
    {
        $first = new SwitchOutcome('dev', false, array(), array(
            new FileOperation(FileOperation::TYPE_COPY, '/target', '/source', 'Copying.', true)
        ));
        $second = new SwitchOutcome('dev', false, array(), array(
            new FileOperation(FileOperation::TYPE_DELETE, '/target', null, 'Deleting.', true)
        ));

        $this->assertFalse($first->hasSameEffectsAs($second));
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
        $files = array(
            array('label' => 'main', 'path' => '/project/composer.json', 'exists' => true, 'modifiedDate' => '2026-09-30 10:00:00')
        );

        $localRepositories = array(
            array('packageName' => 'mistralys/some-dev-tool', 'path' => '../some-dev-tool', 'version' => '*', 'derivedVersion' => '1.2.3')
        );

        $warnings = array('No lock file found.');

        $pendingProdChanges = new ConfigChangeSet(
            array(),
            array(new ConfigChange(array('require', 'mistralys/some-dev-tool'), null, '^1.0', ConfigChange::KIND_ADDED, ConfigChangeOrigin::CarriedBack))
        );

        $description = new SwitchDescription(
            'dev',
            '2026-09-30 10:00:00',
            $files,
            'dev',
            LockStatus::Fresh,
            InstalledState::Matches,
            $pendingProdChanges,
            $localRepositories,
            $warnings
        );

        $this->assertSame('dev', $description->getMode());
        $this->assertSame('2026-09-30 10:00:00', $description->getLastSwitchDate());
        $this->assertSame($files, $description->getFiles());
        $this->assertSame('dev', $description->getActiveFlag());
        $this->assertTrue($description->hasActiveFlag());
        $this->assertSame(LockStatus::Fresh, $description->getLockStatus());
        $this->assertSame(InstalledState::Matches, $description->getInstalledState());
        $this->assertSame($pendingProdChanges, $description->getPendingProdChanges());
        $this->assertTrue($description->hasPendingProdChanges());
        $this->assertSame($localRepositories, $description->getLocalRepositories());
        $this->assertSame($warnings, $description->getWarnings());
        $this->assertTrue($description->hasWarnings());

        $array = $description->toArray();

        $this->assertSame('dev', $array['mode']);
        $this->assertSame('2026-09-30 10:00:00', $array['lastSwitchDate']);
        $this->assertSame($files, $array['files']);
        $this->assertSame('dev', $array['activeFlag']);
        $this->assertSame('fresh', $array['lockStatus']);
        $this->assertSame('matches', $array['installedState']);
        $this->assertSame($pendingProdChanges->toArray(), $array['pendingProdChanges']);
        $this->assertSame($localRepositories, $array['localRepositories']);
        $this->assertSame($warnings, $array['warnings']);
    }

    public function test_switchDescription_noSwitchYet() : void
    {
        $description = new SwitchDescription(
            null,
            null,
            array(),
            null,
            LockStatus::Missing,
            InstalledState::Unknown,
            null,
            array(),
            array()
        );

        $this->assertNull($description->getMode());
        $this->assertNull($description->getLastSwitchDate());
        $this->assertNull($description->getActiveFlag());
        $this->assertFalse($description->hasActiveFlag());
        $this->assertFalse($description->hasWarnings());
        $this->assertNull($description->getPendingProdChanges());
        $this->assertFalse($description->hasPendingProdChanges());
    }

    /**
     * `toArray()`/`toJSON()` must round-trip cleanly with every
     * collection empty (no files, no local repositories, no warnings)
     * and `pendingProdChanges` `null` — the shape `describe()` produces
     * for a freshly initialized project before any file records have
     * been gathered.
     */
    public function test_switchDescription_toArrayAndJsonWithEmptyCollections() : void
    {
        $description = new SwitchDescription(
            null,
            null,
            array(),
            null,
            LockStatus::Missing,
            InstalledState::Unknown,
            null,
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
        $this->assertNull($array['pendingProdChanges']);

        $json = $description->toJSON();
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($array, $decoded);
    }

    // endregion

    // region: _Tests - LockStatus

    /**
     * Only {@see LockStatus::Stale} signals that an update/install is
     * required — the other three cases (no lock file, a lock that
     * still matches its config, and an undeterminable status) are
     * handled by dedicated recovery paths elsewhere, not by treating
     * them as "needs an update".
     */
    public function test_lockStatus_requiresUpdate() : void
    {
        $this->assertTrue(LockStatus::Stale->requiresUpdate());
        $this->assertFalse(LockStatus::Fresh->requiresUpdate());
        $this->assertFalse(LockStatus::Missing->requiresUpdate());
        $this->assertFalse(LockStatus::Unknown->requiresUpdate());
    }

    // endregion

    // region: _Tests - ComposerCommand

    public function test_composerCommand_gettersAndArray() : void
    {
        $command = new ComposerCommand(
            array('update', '--no-interaction'),
            'Refreshing the lock file after a DEV switch.'
        );

        $this->assertSame(array('update', '--no-interaction'), $command->getArguments());
        $this->assertSame('Refreshing the lock file after a DEV switch.', $command->getReason());
        $this->assertSame(
            array('arguments' => array('update', '--no-interaction'), 'reason' => 'Refreshing the lock file after a DEV switch.'),
            $command->toArray()
        );
    }

    public function test_composerCommand_toArrayFromArrayRoundTrip() : void
    {
        $original = new ComposerCommand(array('install', '--no-dev'), 'Installing PROD dependencies.');

        $restored = ComposerCommand::fromArray($original->toArray());

        $this->assertSame($original->getArguments(), $restored->getArguments());
        $this->assertSame($original->getReason(), $restored->getReason());
    }

    public function test_composerCommand_toShellStringEscapesEachArgument() : void
    {
        $command = new ComposerCommand(
            array('update', 'vendor/with a space', '--with=foo/bar:1.0.0'),
            'Partial update.'
        );

        $this->assertSame(
            implode(' ', array(
                escapeshellarg('update'),
                escapeshellarg('vendor/with a space'),
                escapeshellarg('--with=foo/bar:1.0.0')
            )),
            $command->toShellString()
        );
    }

    public function test_composerCommand_toShellStringWithNoArguments() : void
    {
        $command = new ComposerCommand(array(), 'Nothing to run.');

        $this->assertSame('', $command->toShellString());
    }

    // endregion
}
