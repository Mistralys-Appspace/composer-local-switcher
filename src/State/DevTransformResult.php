<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * Immutable result of
 * {@see \Mistralys\ComposerSwitcher\Utils\DevConfigTransformer::apply()}:
 * the resulting DEV-style `composer.json` data, plus a fact per local
 * package recording which version it was aliased to and where that
 * alias came from — the data a later caller turns into
 * `MESSAGE_VERSION_DERIVED` messages.
 *
 * @package Composer Switcher
 * @subpackage State
 */
final class DevTransformResult
{
    /**
     * The alias version came from the local repository entry's
     * explicit `version` override.
     */
    public const SOURCE_OVERRIDE = 'override';

    /**
     * The alias version was derived from the PROD lock's locked
     * version for that package.
     */
    public const SOURCE_LOCKED = 'locked';

    /**
     * No alias could be derived (no override, and the PROD lock has
     * no locked version for that package) — the package falls back to
     * Composer's wildcard `*`.
     */
    public const SOURCE_NONE = 'none';

    /**
     * @param array<string,mixed> $config
     * @param array<int,array{packageName:string,version:string|null,source:string}> $versionDerivations
     */
    public function __construct(
        private readonly array $config,
        private readonly array $versionDerivations
    )
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function getConfig() : array
    {
        return $this->config;
    }

    /**
     * @return array<int,array{packageName:string,version:string|null,source:string}>
     */
    public function getVersionDerivations() : array
    {
        return $this->versionDerivations;
    }
}
