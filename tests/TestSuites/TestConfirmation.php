<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ScriptedEventContext;
use Mistralys\ComposerSwitcher\Utils\ComposerProcess;
use Mistralys\ComposerSwitcher\Utils\ConfigChangeFormatter;
use Mistralys\ComposerSwitcher\Utils\OutcomeRenderer;
use Mistralys\ComposerSwitcher\Utils\SwitchCommandRunner;

/**
 * Drives {@see SwitchCommandRunner}'s full confirmation sequence
 * (Tier 1) via {@see ScriptedEventContext} and the fake `composer`
 * binary: the printed-before-any-write guarantee, the interactive
 * default (`[permanent]`/`[discarded]` → "no", routine → "yes"),
 * the no-prompt-when-unchanged shortcut, the non-interactive `--yes`
 * requirement, and the "shown equals applied" second-preview check,
 * including that an irrelevant input edit made mid-prompt does not
 * abort the switch while a relevant one does.
 */
final class TestConfirmation extends ComposerSwitcherTestCase
{
    private ?string $recordFile = null;

    protected function setUp() : void
    {
        parent::setUp();

        $this->recordFile = $this->assetsFolder . '/work-projects/fake-composer-record-' . uniqid('', true) . '.json';

        putenv('FAKE_COMPOSER_RECORD_FILE=' . $this->recordFile);
        putenv('FAKE_COMPOSER_EXIT_CODE=0');
    }

    protected function tearDown() : void
    {
        if($this->recordFile !== null && file_exists($this->recordFile)) {
            unlink($this->recordFile);
        }

        putenv('FAKE_COMPOSER_RECORD_FILE');
        putenv('FAKE_COMPOSER_EXIT_CODE');

        parent::tearDown();
    }

    // region: _Tests

    public function test_changesPrintedBeforeAnyWrite() : void
    {
        $before = $this->readMainFile();
        $diskUnchangedAtPrompt = null;

        [$runner, $context] = $this->createRunnerWithContext(array(), true, array(true));
        $context->setOnConfirmCallback(function() use (&$diskUnchangedAtPrompt, $before) {
            $diskUnchangedAtPrompt = $this->readMainFile() === $before;
        });

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertTrue($context->wasConfirmCalled());
        $this->assertTrue($diskUnchangedAtPrompt);
        $this->assertTrue($context->hasWrittenLineContaining('composer.json changes ('));
    }

    public function test_interactiveYesAppliesAndRuns() : void
    {
        $runner = $this->createRunner(array(), true, array(true));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertTrue($this->createSwitcher()->getStatus()->exists());
        $this->assertFileExists($this->recordFile);
    }

    public function test_interactiveNoCancelsWithoutWrites() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array(), true, array(false));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertFalse($this->createSwitcher()->getStatus()->exists());
        $this->assertFileDoesNotExist($this->recordFile);
        $this->assertTrue($context->hasWrittenMessageCode(ConfigSwitcher::MESSAGE_SWITCH_CANCELLED));
    }

    /**
     * A DEV-time `composer require` carried back into production on
     * `switch-prod` is a permanent change — the prompt must default to
     * "no" for it.
     */
    public function test_defaultNoWithPermanentOrDiscardedChanges() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $config['require']['acme/newly-required'] = '^1.0';
        $switcher->getMainFile()->putData($config);

        [$runner, $context] = $this->createRunnerWithContext(array(), true, array(true));

        $runner->runSwitch(ConfigSwitcher::MODE_PROD);

        $calls = $context->getConfirmCalls();
        $this->assertNotEmpty($calls);
        $this->assertFalse($calls[0]['default']);
    }

    /**
     * A first PROD/INITIAL->DEV switch also prunes the old VCS
     * repository entries it replaces, which {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::makeOriginClassifier()}
     * classifies as {@see \Mistralys\ComposerSwitcher\State\ConfigChangeOrigin::LocalSwitch}
     * (the same URL match the pruning itself uses) — so the first
     * switch is a routine one and the prompt defaults to "yes". A
     * DEV->DEV refresh after only a version-override edit is routine
     * as well: the require section change for the edited package is
     * {@see \Mistralys\ComposerSwitcher\State\ConfigChangeOrigin::LocalSwitch}.
     */
    public function test_defaultYesForRoutineChanges() : void
    {
        [$firstRunner, $firstContext] = $this->createRunnerWithContext(array(), true, array(true));
        $firstRunner->runSwitch(ConfigSwitcher::MODE_DEV);

        $firstCalls = $firstContext->getConfirmCalls();
        $this->assertNotEmpty($firstCalls);
        $this->assertTrue($firstCalls[0]['default']);

        $devFilePath = $this->testTarget . '/composer/local-repositories.json';
        $devConfig = json_decode((string)file_get_contents($devFilePath), true);
        $devConfig['local-repositories'][0]['version'] = '9.9.9';
        file_put_contents($devFilePath, json_encode($devConfig));

        [$runner, $context] = $this->createRunnerWithContext(array(), true, array(true));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $calls = $context->getConfirmCalls();
        $this->assertNotEmpty($calls);
        $this->assertTrue($calls[0]['default']);
    }

    /**
     * A PROD/INITIAL->PROD switch has no `composer.json` change, so it
     * never prompts — even interactively — yet still plans and
     * executes its command (`install`, since nothing is installed in
     * the fixture).
     */
    public function test_noPromptWhenComposerJsonUnchanged() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array(), true, array());

        $runner->runSwitch(ConfigSwitcher::MODE_PROD);

        $this->assertFalse($context->wasConfirmCalled());
        $this->assertFileExists($this->recordFile);
    }

    public function test_nonInteractiveWithoutYesThrowsAndWritesNothing() : void
    {
        $runner = $this->createRunner(array(), false);

        try {
            $runner->runSwitch(ConfigSwitcher::MODE_DEV);
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_CONFIRMATION_REQUIRED, $e->getCode());
        }

        $this->assertFalse($this->createSwitcher()->getStatus()->exists());
        $this->assertFileDoesNotExist($this->recordFile);
    }

    public function test_nonInteractiveWithYesApplies() : void
    {
        $runner = $this->createRunner(array('--yes'), false);

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertTrue($this->createSwitcher()->getStatus()->exists());
        $this->assertFileExists($this->recordFile);
    }

    public function test_nonInteractiveWithoutYesProceedsWhenComposerJsonUnchanged() : void
    {
        $runner = $this->createRunner(array(), false);

        $runner->runSwitch(ConfigSwitcher::MODE_PROD);

        $this->assertTrue($this->createSwitcher()->getStatus()->exists());
        $this->assertFileExists($this->recordFile);
    }

    public function test_yesStillPrintsChanges() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array('--yes'), true, array());

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertFalse($context->wasConfirmCalled());
        $this->assertTrue($context->hasWrittenLineContaining('composer.json changes ('));
        // The first (pre-confirmation) render is of the dry-run preview
        // outcome, so OutcomeRenderer labels its command line "Would
        // run:"; the real execution is announced separately via
        // MESSAGE_COMPOSER_COMMAND's "Running `composer ...`" line.
        $this->assertTrue($context->hasWrittenLineContaining('Would run: composer'));
        $this->assertTrue($context->hasWrittenLineContaining('Running `composer'));
    }

    /**
     * A plain new key added straight to `composer.json` mid-prompt is
     * not a reliable way to pin this case for a PROD->DEV switch: it
     * gets carried through unchanged by `DevConfigTransformer::apply()`
     * (which uses the just-re-read `composer.json` as its own base),
     * so it appears identically in the second preview's own before/
     * after diff and produces no visible change there. Editing
     * `local-repositories.json` instead — the file the planner's own
     * `apply()` step actually transforms — changes what the second
     * preview's `require` section looks like relative to the first.
     */
    public function test_inputsChangedDuringPromptAborts() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array(), true, array(true));

        $devFilePath = $this->testTarget . '/composer/local-repositories.json';

        $context->setOnConfirmCallback(function() use ($devFilePath) {
            $devConfig = json_decode((string)file_get_contents($devFilePath), true);
            $devConfig['local-repositories'][0]['version'] = '9.9.9';
            file_put_contents($devFilePath, json_encode($devConfig));
        });

        try {
            $runner->runSwitch(ConfigSwitcher::MODE_DEV);
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_INPUTS_CHANGED, $e->getCode());
        }

        $this->assertFalse($this->createSwitcher()->getStatus()->exists());
        $this->assertFileDoesNotExist($this->recordFile);
    }

    /**
     * Reformatting `local-repositories.json` (different whitespace,
     * same data) mid-prompt must not be mistaken for a relevant input
     * change — the second preview reparses the same packages/paths and
     * produces the same change set, so the switch proceeds.
     */
    public function test_irrelevantInputChangeDuringPromptDoesNotAbort() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array(), true, array(true));

        $devFilePath = $this->testTarget . '/composer/local-repositories.json';

        $context->setOnConfirmCallback(function() use ($devFilePath) {
            $decoded = json_decode((string)file_get_contents($devFilePath), true);
            file_put_contents($devFilePath, json_encode($decoded));
        });

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertTrue($this->createSwitcher()->getStatus()->exists());
        $this->assertFileExists($this->recordFile);
    }

    /**
     * The "shown equals applied" property that the runner's
     * second-preview check guards: a preview taken immediately before
     * a real switch, from a separate switcher instance pointed at the
     * same files, reports exactly the change set the real switch then
     * applies.
     */
    public function test_appliedChangeSetEqualsPreviewed() : void
    {
        $previewed = $this->createSwitcher()->previewSwitch(ConfigSwitcher::MODE_DEV);

        $outcome = $this->createSwitcher()->switchTo(ConfigSwitcher::MODE_DEV);

        $this->assertSame(
            $previewed->getConfigChanges()->toArray(),
            $outcome->getConfigChanges()->toArray()
        );
    }

    /**
     * A real switch's messages reach the console exactly once, through
     * the runner's injected context — never also via the switcher's
     * own legacy echo channel ({@see ConfigSwitcher::displayMessages()}),
     * which {@see SwitchCommandRunner::runSwitch()} disables for the
     * duration of its own real `switchTo()` call. A verbose context is
     * used so the (otherwise `-v`-only) `Detail`-level bookkeeping
     * messages this fixture's DEV switch produces are actually
     * rendered, making "printed exactly once" a meaningful check rather
     * than "printed nowhere".
     */
    public function test_realSwitchMessagesPrintedOnce() : void
    {
        $switcher = $this->createSwitcher()->setWriteToConsole(false);
        $context = new ScriptedEventContext(array('--yes'), true, array(), true);
        $process = new ComposerProcess(__DIR__ . '/../assets/fake-composer/composer.php');
        $renderer = new OutcomeRenderer($context, new ConfigChangeFormatter($this->testTarget));
        $runner = new SwitchCommandRunner($context, $switcher, $process, $renderer);

        ob_start();
        $runner->runSwitch(ConfigSwitcher::MODE_DEV);
        $output = ob_get_clean();

        $this->assertSame(
            '',
            $output,
            'The switcher\'s legacy echo channel must print nothing during a runner-driven switch.'
        );

        $messages = $switcher->getMessages();
        $this->assertNotEmpty($messages, 'The DEV switch is expected to record at least one message.');

        foreach($messages as $message)
        {
            $occurrences = 0;

            foreach($context->getWrittenLines() as $line)
            {
                if(str_contains($line, $message->getText()))
                {
                    $occurrences++;
                }
            }

            $this->assertSame(
                1,
                $occurrences,
                sprintf('Message "%s" must be written through the context exactly once.', $message->getText())
            );
        }

        $this->assertTrue($switcher->isDisplayMessagesEnabled());
    }

    /**
     * {@see SwitchCommandRunner::runSwitch()} restores the switcher's
     * display-messages setting in a `finally` block, so it ends up
     * unchanged even when the Composer command it runs afterward fails
     * and the method exits via an exception.
     */
    public function test_runSwitchRestoresDisplayMessagesSetting() : void
    {
        putenv('FAKE_COMPOSER_EXIT_CODE=5');

        $switcher = $this->createSwitcher()->setWriteToConsole(false);
        $this->assertTrue($switcher->isDisplayMessagesEnabled());

        $context = new ScriptedEventContext(array('--yes'), true, array());
        $process = new ComposerProcess(__DIR__ . '/../assets/fake-composer/composer.php');
        $renderer = new OutcomeRenderer($context, new ConfigChangeFormatter($this->testTarget));
        $runner = new SwitchCommandRunner($context, $switcher, $process, $renderer);

        try {
            $runner->runSwitch(ConfigSwitcher::MODE_DEV);
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch (ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_COMPOSER_COMMAND_FAILED, $e->getCode());
        }

        $this->assertTrue($switcher->isDisplayMessagesEnabled());
    }

    /**
     * The first preview's {@see ConfigSwitcher::MESSAGE_DRY_RUN_ACTIVE}
     * message ("Dry run: no files will actually be changed.") is never
     * shown by a runner-driven switch — at default verbosity it is
     * already a {@see \Mistralys\ComposerSwitcher\State\MessageLevel::Detail}
     * message, and the preview header itself already says "(nothing
     * will be changed)", so the notice would only be redundant noise.
     */
    public function test_runnerHidesDryRunMessage() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array('--yes'), true, array());

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertFalse($context->hasWrittenLineContaining('Dry run: no files will actually be changed.'));
    }

    public function test_noInstallStillConfirms() : void
    {
        [$runner, $context] = $this->createRunnerWithContext(array('--no-install'), true, array(true));

        $runner->runSwitch(ConfigSwitcher::MODE_DEV);

        $this->assertTrue($context->wasConfirmCalled());
        $this->assertFileDoesNotExist($this->recordFile);
    }

    /**
     * A direct API call to `switchTo()` never prompts — there is no
     * `EventContext` or `SwitchCommandRunner` involved at all. API
     * consumers inspect `getConfigChanges()`/`requiresConfirmation()`
     * and decide for themselves.
     */
    public function test_switchToNeverPrompts() : void
    {
        $switcher = $this->createSwitcher();

        $outcome = $switcher->switchTo(ConfigSwitcher::MODE_DEV);

        $this->assertTrue($outcome->requiresConfirmation());
        $this->assertTrue($switcher->getStatus()->exists());
    }

    // endregion

    // region: Support methods

    private function readMainFile() : string
    {
        return (string)file_get_contents($this->testTarget . '/composer.json');
    }

    /**
     * @param string[] $arguments
     * @param bool[] $queuedAnswers
     */
    private function createRunner(array $arguments, bool $interactive = true, array $queuedAnswers = array()) : SwitchCommandRunner
    {
        [$runner] = $this->createRunnerWithContext($arguments, $interactive, $queuedAnswers);

        return $runner;
    }

    /**
     * @param string[] $arguments
     * @param bool[] $queuedAnswers
     * @return array{0:SwitchCommandRunner,1:ScriptedEventContext}
     */
    private function createRunnerWithContext(array $arguments, bool $interactive = true, array $queuedAnswers = array()) : array
    {
        $context = new ScriptedEventContext($arguments, $interactive, $queuedAnswers);
        $switcher = $this->createSwitcher();
        $process = new ComposerProcess(__DIR__ . '/../assets/fake-composer/composer.php');
        $renderer = new OutcomeRenderer($context, new ConfigChangeFormatter($this->testTarget));

        return array(new SwitchCommandRunner($context, $switcher, $process, $renderer), $context);
    }

    // endregion
}
