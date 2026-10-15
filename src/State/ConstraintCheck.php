<?php
/**
 * @package Composer Switcher
 * @subpackage State
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\State;

/**
 * The outcome of checking a `version` alias against a package's root
 * `require`/`require-dev` constraint, as computed by
 * {@see \Mistralys\ComposerSwitcher\Utils\VersionConstraintChecker::check()}.
 *
 * @package Composer Switcher
 * @subpackage State
 */
enum ConstraintCheck: string
{
    /**
     * The version satisfies the constraint.
     */
    case Satisfied = 'satisfied';

    /**
     * The version does not satisfy the constraint — both parsed
     * successfully, but `Composer\Semver\Semver::satisfies()` returned
     * `false`.
     */
    case Violated = 'violated';

    /**
     * The version or the constraint could not be parsed by Composer's
     * semver engine (e.g. `not-a-version`), or normalisation failed for
     * a non-dev-alias version. Blocks per the plan's AC-02.
     */
    case Invalid = 'invalid';

    /**
     * No semver engine was available to evaluate the check (the duck-typed
     * `Composer\Semver\Semver`/`VersionParser` classes are not loaded),
     * or the version is a `dev-*` alias whose normalisation failed —
     * Composer accepts alias forms this checker cannot prove malformed,
     * so this never blocks.
     */
    case Unverifiable = 'unverifiable';

    /**
     * Whether this result should block a switch that writes a `version`
     * override — only {@see self::Violated} and {@see self::Invalid} do.
     */
    public function blocks() : bool
    {
        return $this === self::Violated || $this === self::Invalid;
    }
}
