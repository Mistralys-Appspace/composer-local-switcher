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
use Mistralys\ComposerSwitcher\State\MessageLevel;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ScriptedEventContext;
use Mistralys\ComposerSwitcher\Utils\ConfigChangeFormatter;
use Mistralys\ComposerSwitcher\Utils\OutcomeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {@see OutcomeRenderer} in isolation: the header convention
 * shared by every lifecycle moment, the `[permanent]`/`[discarded]`
 * change-set markers (with `[version]` folded into the description),
 * message-level filtering (`Detail` hidden unless verbose, each with
 * its own prefix), file operation formatting, and the `describe()`
 * report layout — all through a {@see ScriptedEventContext} capturing
 * every written line, with no filesystem or switcher involved.
 */
final class TestOutcomeRenderer extends TestCase
{
    private const PROJECT_ROOT = '/project';

    // region: _Tests

    public function test_renderPlanWritesHeaderNamingTheTransition() : void
    {
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array());

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderPlan($outcome, false);

        $this->assertTrue($context->hasWrittenLineContaining('Composer Local Switcher — switch to DEV'));
    }

    public function test_renderPlanPreviewStatesNothingWillChange() : void
    {
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, true, array(), array());

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderPlan($outcome, true);

        $this->assertTrue($context->hasWrittenLineContaining('Composer Local Switcher — preview of switch to DEV'));
        $this->assertTrue($context->hasWrittenLineContaining('nothing will be changed'));
    }

    /**
     * A known previous mode names the transition in the header: `PROD →
     * DEV` for a genuine mode change, with {@see ConfigSwitcher::MODE_INITIAL}
     * displaying as `PROD` (see {@see OutcomeRenderer}'s `describeTransition()`
     * docblock for why that mapping holds).
     */
    public function test_renderPlanNamesTheTransitionWhenPreviousModeIsKnown() : void
    {
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array(), null, false, null, ConfigSwitcher::MODE_PROD);

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderPlan($outcome, false);

        $this->assertTrue($context->hasWrittenLineContaining('Composer Local Switcher — switch PROD → DEV'));
    }

    /**
     * {@see ConfigSwitcher::MODE_INITIAL} displays as `PROD` — the very
     * first switch a project ever makes always starts from the
     * PROD-equivalent initial state.
     */
    public function test_renderPlanDisplaysInitialPreviousModeAsProd() : void
    {
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array(), null, false, null, ConfigSwitcher::MODE_INITIAL);

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderPlan($outcome, false);

        $this->assertTrue($context->hasWrittenLineContaining('Composer Local Switcher — switch PROD → DEV'));
    }

    /**
     * A previous mode equal to the target names the header a `refresh`
     * rather than repeating the mode on both sides of an arrow.
     */
    public function test_renderPlanNamesARefreshWhenPreviousModeEqualsTarget() : void
    {
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array(), null, false, null, ConfigSwitcher::MODE_DEV);

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderPlan($outcome, false);

        $this->assertTrue($context->hasWrittenLineContaining('Composer Local Switcher — switch refresh DEV'));
    }

    /**
     * A blocked outcome's header also names the transition, exactly
     * like {@see self::test_renderPlanNamesTheTransitionWhenPreviousModeIsKnown()}'s
     * non-blocked counterpart.
     */
    public function test_renderBlockedNamesTheTransitionWhenPreviousModeIsKnown() : void
    {
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array(), null, true, null, ConfigSwitcher::MODE_PROD);

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderBlocked($outcome);

        $this->assertTrue($context->hasWrittenLineContaining('Composer Local Switcher — switch PROD → DEV blocked, nothing was changed'));
    }

    public function test_renderBlockedCommandStartDoneFailureShareTheHeaderPrefix() : void
    {
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array(), null, true);
        $command = new ComposerCommand(array('update', 'acme/one'), 'Installing the switched local packages.');

        foreach(array(
            fn(OutcomeRenderer $renderer) => $renderer->renderBlocked($outcome),
            fn(OutcomeRenderer $renderer) => $renderer->renderCommandStart($command),
            fn(OutcomeRenderer $renderer) => $renderer->renderDone('DEV'),
            fn(OutcomeRenderer $renderer) => $renderer->renderFailure($command, 1),
        ) as $call)
        {
            $context = new ScriptedEventContext();
            $call($this->createRenderer($context));

            $this->assertTrue(
                $context->hasWrittenLineContaining('Composer Local Switcher — '),
                'Expected the header prefix to be written.'
            );
        }
    }

    public function test_rendersChangeSetWithMarkersAndNoJsonObject() : void
    {
        $versionChange = new ConfigChange(array('require', 'acme/one'), '^1.0', '^1.1', ConfigChange::KIND_CHANGED, ConfigChangeOrigin::LocalSwitch);
        $permanentChange = new ConfigChange(array('require', 'acme/two'), '^2.0', null, ConfigChange::KIND_REMOVED, ConfigChangeOrigin::CarriedBack);
        $discardedChange = new ConfigChange(array('repositories', '0'), array('type' => 'path', 'url' => '/project/packages/foo'), array('type' => 'vcs', 'url' => 'https://example.test/foo.git'), ConfigChange::KIND_CHANGED, ConfigChangeOrigin::Discarded);

        $changes = new ConfigChangeSet(array($versionChange, $discardedChange), array($permanentChange));

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderChangeSet($changes);

        $this->assertTrue($context->hasWrittenLineContaining('composer.json changes (2):'));
        $this->assertTrue($context->hasWrittenLineContaining('require › acme/one'));
        $this->assertFalse($context->hasWrittenLineContaining('[version]'), 'The [version] marker is folded into the description.');
        $this->assertTrue($context->hasWrittenLineContaining('[discarded]'));
        $this->assertTrue($context->hasWrittenLineContaining('Permanent production changes (1):'));
        $this->assertTrue($context->hasWrittenLineContaining('[permanent]'));

        foreach($context->getWrittenLines() as $line)
        {
            $this->assertStringNotContainsString('{', $line, 'No raw JSON object should reach the console.');
        }
    }

    public function test_renderPlanHidesFileOperationsAndDetailMessagesByDefault() : void
    {
        $operation = new FileOperation(FileOperation::TYPE_WRITE, self::PROJECT_ROOT . '/composer.json', null, 'Rewriting DEV config.', true);
        $messages = array(
            new SwitchMessage(182201, 'Using Composer DEV configuration.', MessageLevel::Detail),
            new SwitchMessage(0, 'Switched to DEV mode.', MessageLevel::Info),
        );

        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, $messages, array($operation));

        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderPlan($outcome, false);

        $this->assertFalse($context->hasWrittenLineContaining('[182201]'));
        $this->assertFalse($context->hasWrittenLineContaining('Using Composer DEV configuration.'));
        $this->assertFalse($context->hasWrittenLineContaining('would write'));
        $this->assertTrue($context->hasWrittenLineContaining('Switched to DEV mode.'));
    }

    public function test_renderPlanShowsFileOperationsAndCodedDetailMessagesWhenVerbose() : void
    {
        $operation = new FileOperation(FileOperation::TYPE_WRITE, self::PROJECT_ROOT . '/composer.json', null, 'Rewriting DEV config.', true);
        $messages = array(
            new SwitchMessage(182201, 'Using Composer DEV configuration.', MessageLevel::Detail),
        );

        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, $messages, array($operation));

        $context = new ScriptedEventContext(array(), true, array(), true);
        $this->createRenderer($context)->renderPlan($outcome, false);

        $this->assertTrue($context->hasWrittenLineContaining('[182201] Using Composer DEV configuration.'));
        $this->assertTrue($context->hasWrittenLineContaining('will write'));
        $this->assertTrue($context->hasWrittenLineContaining('./composer.json'), 'The file operation path should be project-relative.');
    }

    /**
     * {@see OutcomeRenderer::formatOperation()}'s preview/real distinction,
     * exercised through `renderPlan()`'s own `$isPreview` flag rather
     * than calling `formatOperation()` directly: a real plan's file
     * operation lines read `will <type>: …`, a preview's read `would
     * <type>: …`.
     */
    public function test_verboseOperationLinesUseWillForRealSwitch() : void
    {
        $operation = new FileOperation(FileOperation::TYPE_WRITE, self::PROJECT_ROOT . '/composer.json', null, 'Rewriting DEV config.', true);
        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array($operation));

        $realContext = new ScriptedEventContext(array(), true, array(), true);
        $this->createRenderer($realContext)->renderPlan($outcome, false);

        $this->assertTrue($realContext->hasWrittenLineContaining('will write'));
        $this->assertFalse($realContext->hasWrittenLineContaining('would write'));

        $previewContext = new ScriptedEventContext(array(), true, array(), true);
        $this->createRenderer($previewContext)->renderPlan($outcome, true);

        $this->assertTrue($previewContext->hasWrittenLineContaining('would write'));
        $this->assertFalse($previewContext->hasWrittenLineContaining('will write'));
    }

    /**
     * A `Detail`-level message with code `0` ({@see \Mistralys\ComposerSwitcher\State\SwitchMessage::hasCode()}
     * treats `0` as "no code", matching the constructor's own default)
     * renders without a `[0]` prefix at `-v` — distinct from a coded
     * `Detail` message, which does carry one (see
     * {@see self::test_renderPlanShowsFileOperationsAndCodedDetailMessagesWhenVerbose()}).
     */
    public function test_detailMessageWithCodeZeroAtVerbose() : void
    {
        $messages = array(
            new SwitchMessage(0, 'Uncoded detail message.', MessageLevel::Detail),
        );

        $outcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, $messages, array());

        $context = new ScriptedEventContext(array(), true, array(), true);
        $this->createRenderer($context)->renderPlan($outcome, false);

        $this->assertTrue($context->hasWrittenLineContaining('Uncoded detail message.'));
        $this->assertFalse($context->hasWrittenLineContaining('[0]'));
    }

    public function test_rendersPlannedCommandLabels() : void
    {
        $command = new ComposerCommand(array('update', 'acme/one'), 'Installing the switched local packages.');

        $realOutcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, false, array(), array(), $command);
        $previewOutcome = new SwitchOutcome(ConfigSwitcher::MODE_DEV, true, array(), array(), $command);

        $realContext = new ScriptedEventContext();
        $this->createRenderer($realContext)->render($realOutcome);

        $this->assertTrue($realContext->hasWrittenLineContaining('Next: composer'));

        $previewContext = new ScriptedEventContext();
        $this->createRenderer($previewContext)->render($previewOutcome);

        $this->assertTrue($previewContext->hasWrittenLineContaining('Would run: composer'));
    }

    public function test_emptyChangeSetRendersNothing() : void
    {
        $context = new ScriptedEventContext();
        $this->createRenderer($context)->renderChangeSet(new ConfigChangeSet(array(), array()));

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
        $this->createRenderer($context)->renderDescription($description);

        $this->assertTrue($context->hasWrittenLineContaining('Composer Local Switcher — status'));
        $this->assertTrue($context->hasWrittenLineContaining('Mode: '));
        $this->assertTrue($context->hasWrittenLineContaining('(none)'));
    }

    // endregion

    private function createRenderer(ScriptedEventContext $context) : OutcomeRenderer
    {
        return new OutcomeRenderer($context, new ConfigChangeFormatter(self::PROJECT_ROOT));
    }
}
