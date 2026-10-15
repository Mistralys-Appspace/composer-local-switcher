<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Tests\TestClasses;

use UnexpectedValueException;

/**
 * A stub duck-typed replacement for `Composer\Semver\VersionParser`,
 * whose {@see self::normalize()} always throws `UnexpectedValueException`
 * — regardless of input — so that
 * {@see \Mistralys\ComposerSwitcher\Utils\VersionConstraintChecker::check()}'s
 * normalisation-failure paths (both the `Invalid` result for a non-dev
 * version, and the `Unverifiable` result for a `dev-*` alias) are
 * deterministic in tests instead of depending on the real parser's
 * actual leniency.
 *
 * Passed as the `$parserClass` constructor argument to
 * {@see \Mistralys\ComposerSwitcher\Utils\VersionConstraintChecker}.
 * `normalize()` is the only parser method the `dev-*` alias rule
 * depends on, and the only one `check()` ever reaches when it throws —
 * {@see self::parseConstraints()} exists only to keep this stub
 * duck-type-complete and is never invoked in that scenario.
 */
final class ThrowingVersionParser
{
    /**
     * @throws UnexpectedValueException Always.
     */
    public function normalize(string $version) : string
    {
        throw new UnexpectedValueException("Stub parser: cannot normalize '$version'.");
    }

    /**
     * @throws UnexpectedValueException Always — present only for
     *         duck-type completeness; {@see \Mistralys\ComposerSwitcher\Utils\VersionConstraintChecker::check()}
     *         never reaches this call when {@see self::normalize()} has
     *         already thrown.
     */
    public function parseConstraints(string $constraint) : mixed
    {
        throw new UnexpectedValueException("Stub parser: cannot parse constraints '$constraint'.");
    }
}
