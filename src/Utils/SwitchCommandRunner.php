<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\ComposerCommand;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\State\ConfigChangeSet;
use Mistralys\ComposerSwitcher\State\MessageLevel;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;

/**
 * The only place in the library that executes Composer or prompts the
 * user — every `composerSwitch*`/`composerSwitchPreview*` static
 * entry point builds one of these (with an {@see EventContext}, the
 * target {@see ConfigSwitcher}, a {@see ComposerProcess} and an
 * {@see OutcomeRenderer}) and delegates to it immediately.
 *
 * `switchTo()`/`previewSwitch()` themselves never prompt or execute
 * anything — "plan in the core, execute at the edge" — so a library
 * consumer can inspect a {@see SwitchOutcome} and decide for itself,
 * while this class is what `composer switch-dev`/`-prod`/`-update`
 * actually run.
 *
 * Two output-related responsibilities belong here, not in
 * {@see ConfigSwitcher}: first, {@see self::runSwitch()} disables the
 * switcher's own legacy echo channel (see {@see ConfigSwitcher::setDisplayMessages()})
 * for the duration of its own real {@see ConfigSwitcher::switchTo()}
 * call only — restored in a `finally` block immediately after that
 * single call returns or throws — so the switcher (an injected
 * collaborator the caller owns) is never left permanently mutated;
 * every message instead reaches the console exactly once, through the
 * injected {@see OutcomeRenderer}. Second, {@see self::runCommand()}
 * frames the Composer invocation it executes with
 * {@see OutcomeRenderer::renderCommandStart()} and either
 * {@see OutcomeRenderer::renderDone()} or {@see OutcomeRenderer::renderFailure()},
 * and — on a non-zero exit — prints {@see ConfigSwitcher::MESSAGE_COMPOSER_RECOVERY}
 * naming the current mode and the two recovery commands before
 * throwing; this is guidance only, not an automatic rollback (see that
 * method's docblock for why).
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class SwitchCommandRunner
{
    public function __construct(
        private readonly EventContext $context,
        private readonly ConfigSwitcher $switcher,
        private readonly ComposerProcess $process,
        private readonly OutcomeRenderer $renderer
    )
    {
    }

    /**
     * Runs the full confirmation sequence for a switch to `$mode`:
     *
     * 1. Preview (the first one). A blocked outcome is rendered and
     *    stops the sequence with {@see ComposerSwitcherException::ERROR_SWITCH_BLOCKED}.
     * 2. Render the change set and the planned command — always, with
     *    or without `--yes`.
     * 3. When the outcome does not {@see SwitchOutcome::requiresConfirmation()}
     *    (no `composer.json` change), skip straight to step 5.
     * 4. Confirm, see {@see self::confirmSwitch()}.
     * 5. Preview again; when it does not {@see SwitchOutcome::hasSameEffectsAs()}
     *    the first preview, throw {@see ComposerSwitcherException::ERROR_INPUTS_CHANGED}
     *    and write nothing. This still runs when step 4 was skipped —
     *    a pure dry run that costs one extra in-memory switch.
     * 6. Run the real switch, then execute its command (unless
     *    `--no-install`).
     *
     * A nested run (`COMPOSER_SWITCHER_NESTED` set — this process was
     * itself launched by the switcher) no-ops before step 1.
     *
     * Step 6's real {@see ConfigSwitcher::switchTo()} call is wrapped
     * with the switcher's own auto-display temporarily disabled (see
     * {@see ConfigSwitcher::setDisplayMessages()}/{@see ConfigSwitcher::isDisplayMessagesEnabled()}),
     * restored in a `finally` immediately afterward — the switcher's
     * legacy echo channel would otherwise print every message a second
     * time, duplicating what this method's own {@see OutcomeRenderer}
     * already renders.
     */
    public function runSwitch(string $mode) : void
    {
        if($this->isNestedRun())
        {
            $this->emitNestedRunSkipped();
            return;
        }

        $first = $this->switcher->previewSwitch($mode);

        $this->renderPlanned($first, false);

        if($first->isBlocked())
        {
            throw new ComposerSwitcherException(
                'ERROR: The switch is blocked by a precondition (see the messages above).',
                ComposerSwitcherException::ERROR_SWITCH_BLOCKED
            );
        }

        if($first->requiresConfirmation() && !$this->confirmSwitch($first))
        {
            return;
        }

        $second = $this->switcher->previewSwitch($mode);

        if(!$second->hasSameEffectsAs($first))
        {
            throw new ComposerSwitcherException(
                'ERROR: The planned switch changed between the preview and the confirmation; nothing was written. Please re-run the switch.',
                ComposerSwitcherException::ERROR_INPUTS_CHANGED
            );
        }

        $previousDisplayMessages = $this->switcher->isDisplayMessagesEnabled();
        $this->switcher->setDisplayMessages(false);

        try
        {
            $outcome = $this->switcher->switchTo($mode);
        }
        finally
        {
            $this->switcher->setDisplayMessages($previousDisplayMessages);
        }

        $this->runCommand($outcome);
    }

    /**
     * Runs `switch-update`: dispatches to {@see self::runSwitch()} for
     * the mode the switcher is already in (DEV stays DEV — a refresh —
     * PROD/INITIAL stays PROD), mirroring {@see ConfigSwitcher::switchUpdate()}'s
     * own dispatch. The nested-run guard and full confirmation
     * sequence apply exactly as they do for `switch-dev`/`switch-prod`.
     */
    public function runUpdate() : void
    {
        $mode = $this->switcher->getStatus()->isDEV() ? ConfigSwitcher::MODE_DEV : ConfigSwitcher::MODE_PROD;

        $this->runSwitch($mode);
    }

    /**
     * Previews a switch to `$mode`: renders the outcome a real switch
     * would produce and never touches disk or executes anything. Not
     * gated by the nested-run guard — a preview has no side effects to
     * guard against recursion for.
     */
    public function runPreview(string $mode) : void
    {
        $outcome = $this->switcher->previewSwitch($mode);

        $this->renderPlanned($outcome, true);
    }

    /**
     * Renders a {@see SwitchOutcome} for one of this class's two
     * planning moments — step 2 of {@see self::runSwitch()}'s sequence,
     * or the whole of {@see self::runPreview()} — delegating to
     * {@see OutcomeRenderer::renderBlocked()} when the outcome is
     * blocked, or {@see OutcomeRenderer::renderPlan()} with the given
     * `$isPreview` flag otherwise. `$isPreview` is the caller's own
     * moment (a true preview vs. a real switch's pre-confirmation plan)
     * — deliberately not {@see SwitchOutcome::isDryRun()}, which
     * {@see OutcomeRenderer::render()} conflates with it and which this
     * method exists to avoid (see that method's `@deprecated` note).
     */
    private function renderPlanned(SwitchOutcome $outcome, bool $isPreview) : void
    {
        if($outcome->isBlocked())
        {
            $this->renderer->renderBlocked($outcome);
            return;
        }

        $this->renderer->renderPlan($outcome, $isPreview);
    }

    /**
     * Step 4 of {@see self::runSwitch()}'s sequence:
     *
     * - `--yes` was given: confirmed, no prompt.
     * - Interactive context: ask, defaulting to "no" when the change
     *   set has a permanent or discarded entry, "yes" otherwise.
     * - Non-interactive without `--yes`: print the requirement, throw
     *   {@see ComposerSwitcherException::ERROR_CONFIRMATION_REQUIRED}.
     * - Declined: print the cancellation, return `false` (the caller
     *   returns normally — exit 0, nothing written).
     */
    private function confirmSwitch(SwitchOutcome $outcome) : bool
    {
        if($this->context->hasFlag('--yes'))
        {
            return true;
        }

        if(!$this->context->isInteractive())
        {
            $this->context->writeMessage(
                ConfigSwitcher::MESSAGE_CONFIRMATION_REQUIRED,
                'ERROR: Confirmation required — re-run with `-- --yes` to apply non-interactively.'
            );

            throw new ComposerSwitcherException(
                'ERROR: Confirmation required — re-run with `-- --yes` to apply non-interactively.',
                ComposerSwitcherException::ERROR_CONFIRMATION_REQUIRED
            );
        }

        $command = $outcome->getComposerCommand();

        $question = $command !== null
            ? sprintf('Apply these changes and run `composer %s`?', $command->toDisplayString())
            : 'Apply these changes?';

        $default = !$this->hasPermanentOrDiscardedChanges($outcome->getConfigChanges());

        if($this->context->confirm($question, $default))
        {
            return true;
        }

        $this->context->writeMessage(ConfigSwitcher::MESSAGE_SWITCH_CANCELLED, 'Switch cancelled; nothing was written.');

        return false;
    }

    /**
     * Step 6: executes the outcome's planned command (if any), unless
     * `--no-install` was given — in which case the command is printed
     * instead of run. `--with-dependencies` is appended for a partial
     * update (see {@see self::isPartialUpdateCommand()}), and
     * `--no-interaction` is appended whenever the parent context is
     * non-interactive, so the child process never blocks on a prompt
     * the parent could not have answered either.
     *
     * Every human-facing command line (the `--no-install` notice, the
     * exception message) renders the command via
     * {@see ComposerCommand::toDisplayString()} — unquoted unless an
     * argument actually needs it — while the exception's
     * {@see ComposerSwitcherException::KEY_COMMAND} context value keeps
     * {@see ComposerCommand::toShellString()}'s shell-escaped form, for
     * a programmatic consumer that might copy-paste or re-run it.
     *
     * One footer rule covers every path through this method: a switch
     * ends with {@see OutcomeRenderer::renderDone()} whether there was
     * no command to run at all, `--no-install` skipped it, or it ran
     * and exited `0`. The real invocation (when one happens) is framed
     * by {@see OutcomeRenderer::renderCommandStart()} and either
     * `renderDone()` or {@see OutcomeRenderer::renderFailure()}. The
     * legacy {@see ConfigSwitcher::MESSAGE_COMPOSER_COMMAND} announcement
     * ("Running `composer ...`: reason") is no longer written here —
     * `renderCommandStart()` already names the command and its reason,
     * and writing both duplicated the same information on two adjacent
     * lines; the constant itself is kept for backward compatibility
     * (see its own docblock). On a non-zero exit,
     * {@see ConfigSwitcher::MESSAGE_COMPOSER_RECOVERY} is printed next,
     * naming the current mode and the two recovery commands (`composer
     * switch-update`, `composer switch-prod`) — guidance only: the
     * switch itself is deliberately *not* rolled back, since Composer
     * may already have written a new lock file by the time it fails,
     * and an automatic restore would be a second, unconfirmed state
     * transition (see the plan's "Rejected Approaches").
     *
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_COMPOSER_COMMAND_FAILED}
     */
    private function runCommand(SwitchOutcome $outcome) : void
    {
        $command = $outcome->getComposerCommand();

        if($command === null)
        {
            $this->renderer->renderDone(strtoupper($outcome->getMode()));
            return;
        }

        if($this->context->hasFlag('--no-install'))
        {
            $this->context->writeMessage(ConfigSwitcher::MESSAGE_COMPOSER_SKIPPED, sprintf(
                'Run `composer %s` to finish: %s',
                $command->toDisplayString(),
                $command->getReason()
            ));

            $this->renderer->renderDone(strtoupper($outcome->getMode()));

            return;
        }

        $arguments = $command->getArguments();

        if($this->context->hasFlag('--with-dependencies') && $this->isPartialUpdateCommand($command))
        {
            $arguments[] = '--with-dependencies';
        }

        if(!$this->context->isInteractive())
        {
            $arguments[] = '--no-interaction';
        }

        $finalCommand = new ComposerCommand($arguments, $command->getReason());

        $this->renderer->renderCommandStart($finalCommand);

        $exitCode = $this->process->run($finalCommand, $this->getWorkingDir());

        if($exitCode !== 0)
        {
            $this->renderer->renderFailure($finalCommand, $exitCode);

            $this->context->writeMessage(
                ConfigSwitcher::MESSAGE_COMPOSER_RECOVERY,
                sprintf(
                    'WARNING: The switch to %s did not finish — the Composer command above failed, so `composer.json` may not match what is installed. Fix the underlying issue, then run `composer switch-update` to retry, or `composer switch-prod` to fall back to production.',
                    strtoupper($outcome->getMode())
                ),
                MessageLevel::Warning
            );

            throw (new ComposerSwitcherException(
                sprintf('ERROR: `composer %s` failed with exit code %d.', $finalCommand->toDisplayString(), $exitCode),
                ComposerSwitcherException::ERROR_COMPOSER_COMMAND_FAILED
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_COMMAND => $finalCommand->toShellString(),
                    ComposerSwitcherException::KEY_EXIT_CODE => $exitCode
                ));
        }

        $this->renderer->renderDone(strtoupper($outcome->getMode()));
    }

    /**
     * Whether `$command` is a partial `update` (specific package names,
     * as opposed to a bare full `update` or an `install`) — the only
     * shape `--with-dependencies` is meaningful to forward to, since a
     * full `update` already resolves every dependency.
     */
    private function isPartialUpdateCommand(ComposerCommand $command) : bool
    {
        $arguments = $command->getArguments();

        return ($arguments[0] ?? null) === 'update' && count($arguments) > 1;
    }

    /**
     * Whether `$changes` has a `prodConfig` entry (a permanent change)
     * or a `composerJson` entry whose origin is {@see ConfigChangeOrigin::CarriedBack}
     * (also rendered with a trailing `[permanent]` marker by
     * {@see OutcomeRenderer}'s private `describeChange()`) or
     * {@see ConfigChangeOrigin::Discarded} (rendered `[discarded]` the
     * same way) — the condition the
     * interactive confirmation prompt defaults to "no" for. Matching
     * the renderer's own origin check here, rather than only
     * {@see ConfigChangeSet::hasPermanentChanges()}'s `prodConfig`
     * section, keeps the prompt's default in lockstep with what the
     * user is actually shown: a `composerJson` entry carried back from
     * a DEV edit is labelled `[permanent]` even before it reaches the
     * `prodConfig` section.
     */
    private function hasPermanentOrDiscardedChanges(ConfigChangeSet $changes) : bool
    {
        if($changes->hasPermanentChanges())
        {
            return true;
        }

        foreach($changes->getComposerJsonChanges() as $change)
        {
            if($change->getOrigin() === ConfigChangeOrigin::CarriedBack || $change->getOrigin() === ConfigChangeOrigin::Discarded)
            {
                return true;
            }
        }

        return false;
    }

    private function isNestedRun() : bool
    {
        return getenv('COMPOSER_SWITCHER_NESTED') !== false;
    }

    private function emitNestedRunSkipped() : void
    {
        $this->context->writeMessage(
            ConfigSwitcher::MESSAGE_NESTED_RUN_SKIPPED,
            'Skipping switch: this process was launched by the switcher itself (COMPOSER_SWITCHER_NESTED is set).'
        );
    }

    private function getWorkingDir() : string
    {
        return $this->switcher->getProjectRoot();
    }
}
