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
 * discards in favor of the snapshot value. A managed package moved
 * between `require` and `require-dev` during DEV is reported as a
 * single {@see ManagedEntryOverride} with {@see ManagedEntryOverride::isMove()}
 * `true`, never as two separate entries.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class RevertResult
{
    /**
     * @param array<string,mixed> $effectiveConfig
     * @param array<int,ManagedEntryOverride> $overrides
     */
    public function __construct(
        private readonly array $effectiveConfig,
        private readonly array $overrides
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
     * @return array<int,ManagedEntryOverride>
     */
    public function getOverrides() : array
    {
        return $this->overrides;
    }

    /**
     * Legacy four-key array shape, one entry per override — including
     * a move, which is a single override here too (prior versions of
     * this method, backed by `DevConfigTransformer::revertSection()`'s
     * per-section detection, reported a move as two entries; this
     * adapter no longer reproduces that duplication).
     *
     * @deprecated 3.3.0 Use {@see self::getOverrides()} instead.
     * @return array<int,array{section:string,packageName:string,snapshotValue:mixed,currentValue:mixed}>
     */
    public function getOverriddenManagedEntries() : array
    {
        return array_map(
            static fn(ManagedEntryOverride $override) : array => array(
                'section' => $override->getSection(),
                'packageName' => $override->getPackageName(),
                'snapshotValue' => $override->getSnapshotValue(),
                'currentValue' => $override->getCurrentValue()
            ),
            $this->overrides
        );
    }

    public function hasOverriddenManagedEntries() : bool
    {
        return count($this->overrides) > 0;
    }
}
