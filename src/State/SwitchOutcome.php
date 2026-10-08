<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable value object summarizing the result of a single
 * switch operation (`switchTo()`, `previewSwitch()`):
 * the target mode, whether it was a dry run, the messages and file
 * operations produced along the way, the single {@see ComposerCommand}
 * (if any) the caller should run next, whether the switch was
 * blocked, and the {@see ConfigChangeSet} describing what changed.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class SwitchOutcome
{
    /**
     * @param string $mode
     * @param bool $dryRun
     * @param SwitchMessage[] $messages
     * @param FileOperation[] $operations
     * @param ComposerCommand|null $composerCommand The single Composer command planned for this outcome, or `null` when none is needed.
     * @param bool $blocked Whether this switch was blocked by a precondition — a blocked outcome carries no file effects.
     * @param ConfigChangeSet|null $configChanges The config changes this switch produces, or `null` to default to an empty set (e.g. a legacy/blocked outcome with nothing to diff).
     */
    public function __construct(
        private readonly string $mode,
        private readonly bool $dryRun,
        private readonly array $messages,
        private readonly array $operations,
        private readonly ?ComposerCommand $composerCommand = null,
        private readonly bool $blocked = false,
        private readonly ?ConfigChangeSet $configChanges = null
    )
    {
    }

    public function getMode() : string
    {
        return $this->mode;
    }

    /**
     * Whether the operation only planned file changes without
     * applying them.
     */
    public function isDryRun() : bool
    {
        return $this->dryRun;
    }

    /**
     * The single Composer command planned for this outcome, or `null`
     * when none is needed (e.g. the installed state already matches).
     */
    public function getComposerCommand() : ?ComposerCommand
    {
        return $this->composerCommand;
    }

    /**
     * Whether this switch was blocked by a precondition. A blocked
     * outcome carries no file effects — only messages.
     */
    public function isBlocked() : bool
    {
        return $this->blocked;
    }

    /**
     * The config changes this switch produces. Defaults to an empty
     * set when none was supplied at construction time.
     */
    public function getConfigChanges() : ConfigChangeSet
    {
        return $this->configChanges ?? new ConfigChangeSet(array(), array());
    }

    /**
     * Whether applying this outcome requires the user's confirmation —
     * true exactly when the `composerJson` section of its config
     * changes is non-empty (i.e. `composer.json` itself would be
     * rewritten).
     */
    public function requiresConfirmation() : bool
    {
        return count($this->getConfigChanges()->getComposerJsonChanges()) > 0;
    }

    /**
     * Compares this outcome against another for the "shown equals
     * applied" check: `isBlocked()`, the config changes'
     * {@see ConfigChangeSet::toArray()}, the planned command's
     * {@see ComposerCommand::toArray()} (or both being `null`), and
     * every file operation's type/source/target. Messages are
     * deliberately not compared — a message's wording can differ
     * (e.g. a derived-version fact) without the actual effect of the
     * switch having changed.
     */
    public function hasSameEffectsAs(self $other) : bool
    {
        if($this->blocked !== $other->blocked) {
            return false;
        }

        if($this->getConfigChanges()->toArray() !== $other->getConfigChanges()->toArray()) {
            return false;
        }

        if($this->composerCommand?->toArray() !== $other->composerCommand?->toArray()) {
            return false;
        }

        return self::operationsMatch($this->operations, $other->operations);
    }

    /**
     * @param FileOperation[] $a
     * @param FileOperation[] $b
     */
    private static function operationsMatch(array $a, array $b) : bool
    {
        if(count($a) !== count($b)) {
            return false;
        }

        foreach($a as $i => $operation) {
            $other = $b[$i] ?? null;

            if($other === null) {
                return false;
            }

            if(
                $operation->getType() !== $other->getType()
                || $operation->getSourcePath() !== $other->getSourcePath()
                || $operation->getTargetPath() !== $other->getTargetPath()
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return SwitchMessage[]
     */
    public function getMessages() : array
    {
        return $this->messages;
    }

    /**
     * @return string[]
     */
    public function getMessageTexts() : array
    {
        return array_map(
            static fn(SwitchMessage $message) : string => $message->getText(),
            $this->messages
        );
    }

    /**
     * @return FileOperation[]
     */
    public function getOperations() : array
    {
        return $this->operations;
    }

    public function hasOperations() : bool
    {
        return count($this->operations) > 0;
    }

    /**
     * @return array{
     *     mode: string,
     *     dryRun: bool,
     *     messages: array<int,array{code:int,text:string}>,
     *     operations: array<int,array{type:string,target:string,source:string|null,reason:string,applied:bool}>,
     *     composerCommand: array{arguments:string[],reason:string}|null,
     *     blocked: bool,
     *     configChanges: array{composerJson:array<int,array<string,mixed>>,prodConfig:array<int,array<string,mixed>>}
     * }
     */
    public function toArray() : array
    {
        return [
            'mode' => $this->mode,
            'dryRun' => $this->dryRun,
            'messages' => array_map(
                static fn(SwitchMessage $message) : array => $message->toArray(),
                $this->messages
            ),
            'operations' => array_map(
                static fn(FileOperation $operation) : array => $operation->toArray(),
                $this->operations
            ),
            'composerCommand' => $this->composerCommand?->toArray(),
            'blocked' => $this->blocked,
            'configChanges' => $this->getConfigChanges()->toArray()
        ];
    }
}
