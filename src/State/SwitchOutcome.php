<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable value object summarizing the result of a single
 * switch operation (`switchTo()`, `reconcile()`, `previewSwitch()`):
 * the target mode, whether it was a dry run, and the messages and
 * file operations produced along the way.
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
     */
    public function __construct(
        private readonly string $mode,
        private readonly bool $dryRun,
        private readonly array $messages,
        private readonly array $operations
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
     *     operations: array<int,array{type:string,target:string,source:string|null,reason:string,applied:bool}>
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
            )
        ];
    }
}
