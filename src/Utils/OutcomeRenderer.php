<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\State\ComposerCommand;
use Mistralys\ComposerSwitcher\State\ConfigChange;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\State\ConfigChangeSet;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\MessageLevel;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;

/**
 * Renders a {@see SwitchOutcome} or a {@see SwitchDescription} as
 * human-readable text, through the injected {@see EventContext}'s
 * `write()` — never a bare `echo`, so output goes through Composer's
 * own IO when one is available.
 *
 * A stateless layout component: every lifecycle moment
 * (`renderPlan()`, `renderBlocked()`, `renderCommandStart()`,
 * `renderDone()`, `renderFailure()`, `renderDescription()`) takes what
 * it prints as parameters and holds no run state between calls — the
 * caller (e.g. {@see SwitchCommandRunner}) decides *when* each moment
 * happens, this class only decides *how* it looks. Every block shares
 * one header convention: a first line starting with
 * `"Composer Local Switcher — "` (see {@see self::HEADER_PREFIX} and
 * {@see self::writeHeader()}), which is what keeps every block
 * visually identifiable as the switcher's own output.
 *
 * Change descriptions are delegated to the injected
 * {@see ConfigChangeFormatter}, which also supplies
 * {@see ConfigChangeFormatter::displayPath()} for every path this
 * class renders (change entries and file operations alike) — no
 * absolute path inside or adjacent to the project root reaches the
 * console. At default verbosity, file operations and
 * {@see MessageLevel::Detail} messages are suppressed entirely; `-v`
 * (via {@see EventContext::isVerbose()}) reveals both, with `Detail`
 * messages carrying their `[code]` prefix.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class OutcomeRenderer
{
    /**
     * Every block this renderer writes starts with this prefix,
     * followed by a short, moment-specific suffix (e.g. `switch to
     * DEV`, `switch to DEV blocked, nothing was changed`).
     */
    private const HEADER_PREFIX = 'Composer Local Switcher — ';

    public function __construct(
        private readonly EventContext $context,
        private readonly ConfigChangeFormatter $formatter
    )
    {
    }

    /**
     * Thin compatibility wrapper over {@see self::renderPlan()},
     * treating the outcome's own {@see SwitchOutcome::isDryRun()} as
     * the preview flag.
     */
    public function render(SwitchOutcome $outcome) : void
    {
        $this->renderPlan($outcome, $outcome->isDryRun());
    }

    /**
     * Renders a planned (or previewed) switch: a header naming the
     * transition, the config change set, the accumulated messages, and
     * the planned Composer command (if any). File operations are only
     * shown at `-v`.
     */
    public function renderPlan(SwitchOutcome $outcome, bool $isPreview) : void
    {
        $modeLabel = strtoupper($outcome->getMode());

        if($isPreview)
        {
            $this->writeHeader(sprintf('preview of switch to %s (nothing will be changed)', $modeLabel));
        }
        else
        {
            $this->writeHeader(sprintf('switch to %s', $modeLabel));
        }

        $this->renderChangeSet($outcome->getConfigChanges());
        $this->renderMessages($outcome->getMessages());

        $command = $outcome->getComposerCommand();

        if($command !== null)
        {
            $label = $isPreview ? 'Would run' : 'Next';

            $this->context->write(sprintf(
                '%s: composer %s (%s)',
                $label,
                $command->toDisplayString(),
                $this->context->escape($command->getReason())
            ));
        }

        if($this->context->isVerbose())
        {
            foreach($outcome->getOperations() as $operation)
            {
                $this->context->write($this->formatOperation($operation));
            }
        }
    }

    /**
     * Renders a blocked outcome: the header states the transition was
     * blocked and nothing was changed, followed by the messages that
     * explain why (a blocked outcome carries no file effects to show).
     */
    public function renderBlocked(SwitchOutcome $outcome) : void
    {
        $this->writeHeader(sprintf('switch to %s blocked, nothing was changed', strtoupper($outcome->getMode())));

        $this->renderMessages($outcome->getMessages());
    }

    /**
     * Frames the start of a real Composer invocation the switcher is
     * about to run on the caller's behalf.
     */
    public function renderCommandStart(ComposerCommand $command) : void
    {
        $this->writeHeader(sprintf('running `composer %s`', $command->toDisplayString()));
        $this->context->write('  ' . $this->context->escape($command->getReason()));
    }

    /**
     * Frames the successful end of a switch for `$modeLabel` (e.g.
     * `DEV`) — the caller decides the label's casing/wording.
     */
    public function renderDone(string $modeLabel) : void
    {
        $this->writeHeader(sprintf('switch to %s done', $modeLabel));
    }

    /**
     * Frames a failed Composer invocation: the header names the exit
     * code, the body repeats the command and its reason so the user
     * does not need to scroll back up to see what ran.
     */
    public function renderFailure(ComposerCommand $command, int $exitCode) : void
    {
        $this->writeHeader(sprintf('composer command failed (exit code %d)', $exitCode));

        $this->context->write(sprintf(
            '  `composer %s`: %s',
            $command->toDisplayString(),
            $this->context->escape($command->getReason())
        ));
    }

    /**
     * Renders a {@see ConfigChangeSet}'s two sections as a grouped,
     * counted list. A no-op (writes nothing) for an empty set. Each
     * line is {@see ConfigChangeFormatter::describe()}'s human
     * description, with a `[permanent]`/`[discarded]` marker appended
     * for a carried-back/discarded origin — the `[version]` marker is
     * folded into the description itself (a `require`/`require-dev`
     * line already reads as a version change).
     */
    public function renderChangeSet(ConfigChangeSet $changes) : void
    {
        if($changes->isEmpty())
        {
            return;
        }

        $composerJsonChanges = $changes->getComposerJsonChanges();

        if(count($composerJsonChanges) > 0)
        {
            $this->context->write(sprintf('composer.json changes (%d):', count($composerJsonChanges)));

            foreach($composerJsonChanges as $change)
            {
                $this->context->write('  ' . $this->describeChange($change));
            }
        }

        $prodConfigChanges = $changes->getProdConfigChanges();

        if(count($prodConfigChanges) > 0)
        {
            $this->context->write(sprintf('Permanent production changes (%d):', count($prodConfigChanges)));

            foreach($prodConfigChanges as $change)
            {
                $this->context->write('  ' . $this->describeChange($change));
            }
        }
    }

    /**
     * Renders {@see ConfigSwitcher::describe()}'s state-of-the-world
     * snapshot as a human-readable report, under the same header
     * convention as every other block.
     */
    public function renderDescription(SwitchDescription $description) : void
    {
        $this->writeHeader('status');

        $this->context->write('Mode: ' . ($description->getMode() ?? 'none'));
        $this->context->write('Last switch: ' . ($description->getLastSwitchDate() ?? 'never'));
        $this->context->write('Active flag: ' . ($description->getActiveFlag() ?? 'none'));
        $this->context->write('');

        $this->context->write('Files:');

        foreach($description->getFiles() as $file)
        {
            $this->context->write(sprintf(
                '  - %-10s %s [%s%s]',
                $file['label'],
                $this->formatter->displayPath($file['path']),
                $file['exists'] ? 'exists' : 'missing',
                $file['modifiedDate'] !== null ? ', modified ' . $file['modifiedDate'] : ''
            ));
        }

        $this->context->write('');

        $this->context->write('Lock status: ' . $description->getLockStatus()->value);
        $this->context->write('Installed state: ' . $description->getInstalledState()->value);

        $pendingProdChanges = $description->getPendingProdChanges();

        if($pendingProdChanges !== null && $pendingProdChanges->hasPermanentChanges())
        {
            $this->context->write('');
            $this->context->write('Pending production changes:');
            $this->renderChangeSet($pendingProdChanges);
        }

        $this->context->write('');
        $this->context->write('Local repositories:');

        $localRepositories = $description->getLocalRepositories();

        if(empty($localRepositories))
        {
            $this->context->write('  (none)');
        }
        else
        {
            foreach($localRepositories as $repo)
            {
                $this->context->write(sprintf(
                    '  - %s -> %s (%s%s)',
                    $repo['packageName'],
                    $this->formatter->displayPath($repo['path']),
                    $repo['version'],
                    $repo['derivedVersion'] !== null ? ', derived: ' . $repo['derivedVersion'] : ''
                ));
            }
        }

        if($description->hasWarnings())
        {
            $this->context->write('');
            $this->context->write('Warnings:');

            foreach($description->getWarnings() as $warning)
            {
                $this->context->write('  - ' . $this->context->escape($warning));
            }
        }
    }

    /**
     * Formats a single {@see FileOperation} as `would <type>: <source>
     * -> <target> (<reason>)`, both paths rendered through
     * {@see ConfigChangeFormatter::displayPath()}. Operations with no
     * source (e.g. a plain write) render `-` in its place, keeping the
     * format uniform.
     */
    public function formatOperation(FileOperation $operation) : string
    {
        $source = $operation->getSourcePath();

        return sprintf(
            'would %s: %s -> %s (%s)',
            $operation->getType(),
            $source !== null ? $this->formatter->displayPath($source) : '-',
            $this->formatter->displayPath($operation->getTargetPath()),
            $operation->getReason()
        );
    }

    /**
     * Writes every message from an outcome, filtered and formatted by
     * {@see MessageLevel}: a {@see MessageLevel::Detail} message is
     * skipped entirely unless verbose, and only then carries its
     * `[code]` prefix; a {@see MessageLevel::Warning} message is
     * marked with a leading `!`; any other level is written plain.
     * Every message's text passes through {@see EventContext::escape()}
     * first, so a dynamic value containing a literal `<` (e.g. a
     * version constraint like `<2.0`) renders literally through
     * Composer's IO instead of being misread as a style tag.
     *
     * @param SwitchMessage[] $messages
     */
    private function renderMessages(array $messages) : void
    {
        $verbose = $this->context->isVerbose();

        foreach($messages as $message)
        {
            if($message->getLevel() === MessageLevel::Detail)
            {
                if(!$verbose)
                {
                    continue;
                }

                $code = $message->hasCode() ? sprintf('[%d] ', $message->getCode()) : '';

                $this->context->write('  ' . $code . $this->context->escape($message->getText()));
                continue;
            }

            $prefix = $message->getLevel() === MessageLevel::Warning ? '  ! ' : '  ';

            $this->context->write($prefix . $this->context->escape($message->getText()));
        }
    }

    private function describeChange(ConfigChange $change) : string
    {
        $marker = match($change->getOrigin()) {
            ConfigChangeOrigin::CarriedBack => ' [permanent]',
            ConfigChangeOrigin::Discarded => ' [discarded]',
            default => '',
        };

        return $this->context->escape($this->formatter->describe($change)) . $marker;
    }

    /**
     * Writes a header line. Plain {@see EventContext::write()} rather
     * than {@see EventContext::writeStyled()}: the header carries no
     * `<info>`/`<comment>`/`<warning>` tags, and {@see EventContext::writeStyled()}'s
     * `echo` fallback (used when there is no IO) bypasses {@see EventContext::write()}
     * entirely, which a plain {@see EventContext::write()} call avoids.
     */
    private function writeHeader(string $suffix) : void
    {
        $this->context->write(self::HEADER_PREFIX . $suffix);
    }
}
