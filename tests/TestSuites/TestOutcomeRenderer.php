<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\ComposerCommand;
use Mistralys\ComposerSwitcher\State\ConfigChange;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\State\ConfigChangeSet;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\InstalledState;
use Mistralys\ComposerSwitcher\State\LockStatus;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ScriptedEventContext;
use Mistralys\ComposerSwitcher\Utils\OutcomeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {@see OutcomeRenderer} in isolation: the `[version]`/
 * `[permanent]`/`[discarded]` change-set markers, the planned-command
 * line's dry-run-aware label, file operation formatting, and the
 * `describe()` report layout — all through a {@see ScriptedEventContext}
 * capturing every written line, with no filesystem or switcher
 * involved.
 */
final class TestOutcomeRenderer extends TestCase
{
    // region: _Tests

    public function test_rendersSectionsAndMarkers() : void
    {
        $versionChange = new ConfigChange(array('require', 'acme/one'), '^1.0', '^1.1', ConfigChange::KIND_CHANGED, ConfigChangeOrigin::LocalSwitch);
        $permanentChange = new ConfigChange(array('require', 'acme/two'), '^2.0', null, ConfigChange::KIND_REMOVED, ConfigChangeOrigin::CarriedBack);
        $discardedChange = new ConfigChange(array('repositories', 'local'), array('type' => 'path'), array('type' => 'vcs'), ConfigChange::KIND_CHANGED, ConfigChangeOrigin::Discarded);

        $changes = new ConfigChangeSet(array($versionChange, $discardedChange), array($permanentChange));

        $context = new ScriptedEventContext();
        $renderer = new OutcomeRenderer($context);

        $renderer->renderChangeSet($changes);

        $this->assertTrue($context->hasWrittenLineContaining('composer.json changes:'));
        $this->assertTrue($context->hasWrittenLineContaining('[version]'));
        $this->assertTrue($context->hasWrittenLineContaining('[discarded]'));
        $this->assertTrue($context->hasWrittenLineContaining('Permanent production changes:'));
        $this->assertTrue($context->hasWrittenLineContaining('[permanent]'));
    }

    public function test_rendersPlannedCommandAndOperations() : void
    {
        $command = new ComposerCommand(array('update', 'acme/one'), 'Installing the switched local packages.');
        $operation = new FileOperation(FileOperation::TYPE_WRITE, '/path/composer.json', null, 'Rewriting DEV config.', true);

        $realOutcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array($operation), $command);
        $previewOutcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, true, array(), array($operation), $command);

        $realContext = new ScriptedEventContext();
        (new OutcomeRenderer($realContext))->render($realOutcome);

        $this->assertTrue($realContext->hasWrittenLineContaining('Planned command: composer'));
        $this->assertTrue($realContext->hasWrittenLineContaining('would write'));

        $previewContext = new ScriptedEventContext();
        (new OutcomeRenderer($previewContext))->render($previewOutcome);

        $this->assertTrue($previewContext->hasWrittenLineContaining('Would run: composer'));
    }

    public function test_emptyChangeSetRendersNoConfigSection() : void
    {
        $context = new ScriptedEventContext();
        $renderer = new OutcomeRenderer($context);

        $renderer->renderChangeSet(new ConfigChangeSet(array(), array()));

        $this->assertSame(array(), $context->getWrittenLines());
    }

    public function test_rendersDescriptionWithoutThrowingOnEmptyCollections() : void
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

        $context = new ScriptedEventContext();
        (new OutcomeRenderer($context))->renderDescription($description);

        $this->assertTrue($context->hasWrittenLineContaining('Mode: '));
        $this->assertTrue($context->hasWrittenLineContaining('(none)'));
    }

    // endregion
}
