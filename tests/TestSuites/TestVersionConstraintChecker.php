<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\State\ConstraintCheck;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ThrowingVersionParser;
use Mistralys\ComposerSwitcher\Utils\VersionConstraintChecker;
use PHPUnit\Framework\TestCase;

/**
 * Tier 1 suite for {@see VersionConstraintChecker}, using the real
 * `composer/semver` dev dependency (default constructor arguments)
 * for the engine-present cases, and {@see ThrowingVersionParser} /
 * a non-existent class name to force the `Invalid`/`Unverifiable`
 * paths deterministically, without depending on the real parser's
 * actual leniency for malformed input.
 */
final class TestVersionConstraintChecker extends TestCase
{
    public function test_satisfiedVersionIsSatisfied() : void
    {
        $checker = new VersionConstraintChecker();

        $this->assertSame(ConstraintCheck::Satisfied, $checker->check('3.0.0', '^3.0.0'));
    }

    public function test_violatingVersionIsViolated() : void
    {
        $checker = new VersionConstraintChecker();

        $this->assertSame(ConstraintCheck::Violated, $checker->check('1.0.4', '^3.0.0'));
    }

    public function test_malformedNonDevVersionIsInvalid() : void
    {
        $checker = new VersionConstraintChecker();

        $this->assertSame(ConstraintCheck::Invalid, $checker->check('not-a-version', '^3.0.0'));
    }

    public function test_malformedConstraintIsInvalid() : void
    {
        $checker = new VersionConstraintChecker();

        $this->assertSame(ConstraintCheck::Invalid, $checker->check('3.0.0', 'not-a-constraint'));
    }

    public function test_throwingParserMakesNonDevVersionInvalid() : void
    {
        $checker = new VersionConstraintChecker(parserClass: ThrowingVersionParser::class);

        $this->assertSame(ConstraintCheck::Invalid, $checker->check('1.2.3', '^3.0.0'));
    }

    public function test_missingSemverClassIsUnverifiable() : void
    {
        $checker = new VersionConstraintChecker(semverClass: 'Composer\\Semver\\DoesNotExist');

        $this->assertSame(ConstraintCheck::Unverifiable, $checker->check('3.0.0', '^3.0.0'));
    }

    public function test_missingParserClassIsUnverifiable() : void
    {
        $checker = new VersionConstraintChecker(parserClass: 'Composer\\Semver\\DoesNotExist');

        $this->assertSame(ConstraintCheck::Unverifiable, $checker->check('3.0.0', '^3.0.0'));
    }

    /**
     * Covers the plan's highest-risk detail: a `dev-*` alias must never
     * become `Invalid` with the real engine, even though its raw form
     * is not a plain semver string.
     */
    public function test_devAliasIsNotInvalidWithRealEngine() : void
    {
        $checker = new VersionConstraintChecker();

        $this->assertNotSame(ConstraintCheck::Invalid, $checker->check('dev-main', '^3.0.0'));
        $this->assertNotSame(ConstraintCheck::Invalid, $checker->check('3.0.x-dev', '^3.0.0'));
    }

    /**
     * With a parser whose `normalize()` always throws, a `dev-*` alias
     * must degrade to `Unverifiable`, not `Invalid` — the dev-alias
     * rule in {@see VersionConstraintChecker::check()} is the only
     * thing distinguishing this from {@see self::test_throwingParserMakesNonDevVersionInvalid()}.
     */
    public function test_throwingParserMakesDevAliasUnverifiable() : void
    {
        $checker = new VersionConstraintChecker(parserClass: ThrowingVersionParser::class);

        $this->assertSame(ConstraintCheck::Unverifiable, $checker->check('dev-main', '^3.0.0'));
        $this->assertSame(ConstraintCheck::Unverifiable, $checker->check('3.0.X-DEV', '^3.0.0'));
    }
}
