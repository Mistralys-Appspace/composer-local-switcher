<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable value object holding the outcome of comparing
 * `composer.json` against `composer-prod.json`.
 *
 * In DEV mode, `composer.json` has been rewritten with local
 * repository entries, so the comparison is not meaningful —
 * {@see self::isComparable()} reflects this, and {@see self::isInSync()}
 * always returns `false` while it does.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class VerificationResult
{
    /**
     * @param bool $devMode Whether the switcher was in DEV mode when the comparison was requested.
     * @param bool $inSync Whether the compared files were found to be identical. Ignored (treated as not in sync) when `$devMode` is `true`.
     * @param string[] $differences Top-level keys that differ between the two files.
     */
    public function __construct(
        private readonly bool $devMode,
        private readonly bool $inSync,
        private readonly array $differences
    )
    {
    }

    public function isDevMode() : bool
    {
        return $this->devMode;
    }

    /**
     * Whether the comparison result is meaningful. `false` in DEV mode,
     * since `composer.json` has been rewritten for local repositories
     * and can no longer be meaningfully compared to the production baseline.
     */
    public function isComparable() : bool
    {
        return !$this->devMode;
    }

    public function isInSync() : bool
    {
        if(!$this->isComparable()) {
            return false;
        }

        return $this->inSync;
    }

    /**
     * @return string[]
     */
    public function getDifferences() : array
    {
        return $this->differences;
    }

    /**
     * @return array{inSync:bool,differences:string[],devMode:bool}
     */
    public function toArray() : array
    {
        return [
            'inSync' => $this->isInSync(),
            'differences' => $this->differences,
            'devMode' => $this->devMode
        ];
    }
}
