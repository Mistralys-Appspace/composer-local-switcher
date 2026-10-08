<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable result of
 * {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::revert()}:
 * the three-way-merged `effectiveConfig` (the DEV config with every
 * DEV-time edit to a non-managed key/package/repository carried back,
 * and every managed (local package) entry restored to its snapshot
 * value), plus the list of managed entries the user edited anyway —
 * the overrides {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::revert()}
 * discards in favor of the snapshot value.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class RevertResult
{
    /**
     * @param array<string,mixed> $effectiveConfig
     * @param array<int,array{section:string,packageName:string,snapshotValue:mixed,currentValue:mixed}> $overriddenManagedEntries
     */
    public function __construct(
        private readonly array $effectiveConfig,
        private readonly array $overriddenManagedEntries
    )
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function getEffectiveConfig() : array
    {
        return $this->effectiveConfig;
    }

    /**
     * @return array<int,array{section:string,packageName:string,snapshotValue:mixed,currentValue:mixed}>
     */
    public function getOverriddenManagedEntries() : array
    {
        return $this->overriddenManagedEntries;
    }

    public function hasOverriddenManagedEntries() : bool
    {
        return count($this->overriddenManagedEntries) > 0;
    }
}
