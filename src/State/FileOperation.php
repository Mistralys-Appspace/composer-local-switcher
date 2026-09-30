<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable value object describing a single file operation
 * performed (or planned, in dry-run mode) during a switch —
 * a copy, a write, or a deletion.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class FileOperation
{
    public const TYPE_COPY = 'copy';
    public const TYPE_WRITE = 'write';
    public const TYPE_DELETE = 'delete';

    public function __construct(
        private readonly string $type,
        private readonly string $targetPath,
        private readonly ?string $sourcePath,
        private readonly string $reason,
        private readonly bool $applied
    )
    {
    }

    public function getType() : string
    {
        return $this->type;
    }

    public function getTargetPath() : string
    {
        return $this->targetPath;
    }

    public function getSourcePath() : ?string
    {
        return $this->sourcePath;
    }

    public function getReason() : string
    {
        return $this->reason;
    }

    /**
     * Whether the operation was actually applied to the filesystem.
     * `false` when the operation was only planned (e.g. dry-run mode).
     */
    public function isApplied() : bool
    {
        return $this->applied;
    }

    /**
     * @return array{type:string,target:string,source:string|null,reason:string,applied:bool}
     */
    public function toArray() : array
    {
        return [
            'type' => $this->type,
            'target' => $this->targetPath,
            'source' => $this->sourcePath,
            'reason' => $this->reason,
            'applied' => $this->applied
        ];
    }
}
