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
 * `MESSAGE_VERSION_DERIVED` messages — and a fact per `SOURCE_OVERRIDE`
 * alias that violates (or could not be parsed against) the package's
 * root `require`/`require-dev` constraint, which a later caller turns
 * into `MESSAGE_VERSION_OVERRIDE_CONFLICT` messages and uses to block
 * the switch before any write.
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
     * @param array<int,array{packageName:string,version:string,constraint:string,section:string,check:ConstraintCheck,lockedVersion:string|null}> $versionConflicts
     */
    public function __construct(
        private readonly array $config,
        private readonly array $versionDerivations,
        private readonly array $versionConflicts = array()
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

    /**
     * Every `SOURCE_OVERRIDE` alias whose {@see ConstraintCheck}
     * {@see ConstraintCheck::blocks()} against the package's root
     * `require`/`require-dev` constraint — empty when every override
     * is satisfied, unverifiable, or there simply is no root constraint
     * to check against. A `SOURCE_LOCKED` alias is never checked: it
     * was derived from the PROD lock itself, so it cannot conflict with
     * the constraint that produced it.
     *
     * @return array<int,array{packageName:string,version:string,constraint:string,section:string,check:ConstraintCheck,lockedVersion:string|null}>
     */
    public function getVersionConflicts() : array
    {
        return $this->versionConflicts;
    }

    public function hasVersionConflicts() : bool
    {
        return count($this->versionConflicts) > 0;
    }
}
