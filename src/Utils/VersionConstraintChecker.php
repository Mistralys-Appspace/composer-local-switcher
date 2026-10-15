<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use Mistralys\ComposerSwitcher\State\ConstraintCheck;
use UnexpectedValueException;

/**
 * Checks whether a `version` alias (as written into a path repository's
 * `require`/`require-dev` entry by {@see DevConfigTransformer}) satisfies
 * a package's root constraint, using Composer's own semver engine.
 *
 * This is a deliberate second, narrowly scoped duck-typing site alongside
 * {@see EventContext} (which duck-types Composer's event/IO objects):
 * the library has zero runtime dependencies, so it cannot type-hint
 * against `Composer\Semver\Semver`/`Composer\Semver\VersionParser`
 * directly. Both class names are constructor arguments — defaulting to
 * the real Composer classes — purely so tests can exercise the
 * {@see ConstraintCheck::Unverifiable} path with a non-existent class
 * name, and the normalisation-failure path with a throwing stub parser
 * ({@see \Mistralys\ComposerSwitcher\Tests\TestClasses\ThrowingVersionParser}).
 * Every real invocation of the switcher runs inside a Composer script
 * process, where `Composer\Semver\*` is always loaded, so this never
 * needs a runtime dependency to hold in practice.
 *
 * **Evaluation order:** {@see self::check()} instantiates `$parserClass`
 * and calls `normalize(string $version)` on the version first, then
 * `parseConstraints(string $constraint)` on the constraint, then
 * `Semver::satisfies()`. `normalize()` is the only parser method the
 * `dev-*` alias rule depends on.
 *
 * **`dev-*` alias rule:** a version is a *dev alias* when, case-insensitively,
 * it starts with `dev-` or ends with `-dev` (e.g. `dev-main`, `3.0.x-dev`).
 * If `normalize()` throws `UnexpectedValueException` for a dev alias, the
 * result is {@see ConstraintCheck::Unverifiable} (never a block) — Composer
 * accepts alias forms this checker cannot prove malformed, and a false
 * block would be worse than today's behavior. If `normalize()` throws for
 * any other version (e.g. `not-a-version`), or `parseConstraints()` throws
 * for the constraint, the result is {@see ConstraintCheck::Invalid}. A dev
 * alias that normalises successfully is evaluated normally and can be
 * {@see ConstraintCheck::Satisfied} or {@see ConstraintCheck::Violated}.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
class VersionConstraintChecker
{
    public function __construct(
        private readonly string $semverClass = 'Composer\\Semver\\Semver',
        private readonly string $parserClass = 'Composer\\Semver\\VersionParser'
    )
    {
    }

    /**
     * Checks whether `$version` satisfies `$constraint`, using the
     * semver engine named by the constructor arguments when it is
     * loaded. See the class docblock for the full evaluation order and
     * the `dev-*` alias rule.
     */
    public function check(string $version, string $constraint) : ConstraintCheck
    {
        if(!class_exists($this->semverClass) || !class_exists($this->parserClass)) {
            return ConstraintCheck::Unverifiable;
        }

        $parser = new $this->parserClass();

        try {
            $normalizedVersion = $parser->normalize($version);
        } catch(UnexpectedValueException) {
            return $this->isDevAlias($version) ? ConstraintCheck::Unverifiable : ConstraintCheck::Invalid;
        }

        try {
            $parser->parseConstraints($constraint);
        } catch(UnexpectedValueException) {
            return ConstraintCheck::Invalid;
        }

        $semverClass = $this->semverClass;

        return $semverClass::satisfies($normalizedVersion, $constraint)
            ? ConstraintCheck::Satisfied
            : ConstraintCheck::Violated;
    }

    /**
     * Whether `$version` is a `dev-*` alias — case-insensitively starts
     * with `dev-` or ends with `-dev` (e.g. `dev-main`, `3.0.x-dev`).
     * The single definition of this rule, consumed only by
     * {@see self::check()} when `normalize()` throws.
     */
    private function isDevAlias(string $version) : bool
    {
        $lower = strtolower($version);

        return str_starts_with($lower, 'dev-') || str_ends_with($lower, '-dev');
    }
}
