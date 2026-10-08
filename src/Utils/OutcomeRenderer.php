<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\State\ConfigChange;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\State\ConfigChangeSet;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\State\SwitchOutcome;

/**
 * Renders a {@see SwitchOutcome} or a {@see SwitchDescription} as
 * human-readable text, through the injected {@see EventContext}'s
 * {@see EventContext::write()} — never a bare `echo`, so output goes
 * through Composer's own IO when one is available.
 *
 * Takes over the rendering {@see \Mistralys\ComposerSwitcher\ConfigSwitcher}'s
 * old `printPreview()`/`formatOperation()` used to own, extended to
 * also render a {@see ConfigChangeSet} as a grouped list, each change
 * marked `[version]` when {@see ConfigChange::isVersionChange()},
 * `[permanent]` when carried back, or `[discarded]` when a DEV edit to
 * a managed entry was reset.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class OutcomeRenderer
{
    public function __construct(
        private readonly EventContext $context
    )
    {
    }

    /**
     * Renders a full outcome: the config changes it produces (or would
     * produce, for a preview), the planned command, the file
     * operations, and the accumulated messages — in that order.
     */
    public function render(SwitchOutcome $outcome) : void
    {
        $this->renderChangeSet($outcome->getConfigChanges());

        $command = $outcome->getComposerCommand();

        if($command !== null)
        {
            $label = $outcome->isDryRun() ? 'Would run' : 'Planned command';

            $this->context->write(sprintf('%s: composer %s (%s)', $label, $command->toShellString(), $command->getReason()));
        }

        foreach($outcome->getOperations() as $operation)
        {
            $this->context->write($this->formatOperation($operation));
        }

        foreach($outcome->getMessageTexts() as $text)
        {
            $this->context->write($text);
        }
    }

    /**
     * Renders a {@see ConfigChangeSet}'s two sections as a grouped list.
     * A no-op (writes nothing) for an empty set.
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
            $this->context->write('composer.json changes:');

            foreach($composerJsonChanges as $change)
            {
                $this->context->write('  ' . $this->formatChange($change));
            }
        }

        $prodConfigChanges = $changes->getProdConfigChanges();

        if(count($prodConfigChanges) > 0)
        {
            $this->context->write('Permanent production changes:');

            foreach($prodConfigChanges as $change)
            {
                $this->context->write('  ' . $this->formatChange($change));
            }
        }
    }

    /**
     * Renders {@see ConfigSwitcher::describe()}'s state-of-the-world
     * snapshot as a human-readable report.
     */
    public function renderDescription(SwitchDescription $description) : void
    {
        $this->context->write('Mode: ' . $description->getMode());
        $this->context->write('Last switch: ' . ($description->getLastSwitchDate() ?? 'never'));
        $this->context->write('Active flag: ' . ($description->getActiveFlag() ?? 'none'));
        $this->context->write('');

        $this->context->write('Files:');

        foreach($description->getFiles() as $file)
        {
            $this->context->write(sprintf(
                '  - %-10s %s [%s%s]',
                $file['label'],
                $file['path'],
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
                    $repo['path'],
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
                $this->context->write('  - ' . $warning);
            }
        }
    }

    /**
     * Formats a single {@see FileOperation} as `would <type>: <source>
     * -> <target> (<reason>)`. Operations with no source (e.g. a plain
     * write) render `-` in its place, keeping the format uniform.
     */
    public function formatOperation(FileOperation $operation) : string
    {
        return sprintf(
            'would %s: %s -> %s (%s)',
            $operation->getType(),
            $operation->getSourcePath() ?? '-',
            $operation->getTargetPath(),
            $operation->getReason()
        );
    }

    private function formatChange(ConfigChange $change) : string
    {
        $markers = array();

        if($change->isVersionChange())
        {
            $markers[] = '[version]';
        }

        if($change->getOrigin() === ConfigChangeOrigin::CarriedBack)
        {
            $markers[] = '[permanent]';
        }
        else if($change->getOrigin() === ConfigChangeOrigin::Discarded)
        {
            $markers[] = '[discarded]';
        }

        return sprintf(
            '%s: %s %s -> %s%s',
            $change->getPathString(),
            $change->getKind(),
            $this->formatValue($change->getBefore()),
            $this->formatValue($change->getAfter()),
            count($markers) > 0 ? ' ' . implode(' ', $markers) : ''
        );
    }

    private function formatValue(mixed $value) : string
    {
        if($value === null)
        {
            return 'null';
        }

        if(is_scalar($value))
        {
            return (string)$value;
        }

        return json_encode($value) ?: '…';
    }
}
