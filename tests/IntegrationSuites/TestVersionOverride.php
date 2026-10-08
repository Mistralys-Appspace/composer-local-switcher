<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;

/**
 * Tier 2 suite proving the `version` override property in
 * `local-repositories.json` pins the switched package to a configured
 * version Composer would not otherwise infer, including the
 * underscore-to-hyphen alias branch {@see ConfigSwitcher} adds for
 * package names that contain an underscore.
 *
 * The inferred value (`dev-master`, since the cloned package's own
 * `composer.json` carries no `version` field) and the configured
 * override (`2.0.0`) must differ for these assertions to prove which one
 * Composer actually used, rather than merely restating a default.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestVersionOverride extends IntegrationTestCase
{
    private const PACKAGE_NAME = 'mistralys/simple_html_dom';
    private const PACKAGE_NAME_HYPHENATED = 'mistralys/simple-html-dom';

    // region: _Tests

    /**
     * With no `version` key in `local-repositories.json` (the fixture's
     * default), `switch-dev` still derives a version for the generated
     * repository entry's `options.versions` — from the PROD lock's own
     * locked version for the package, under this plan's "derive DEV
     * package versions from the production lock" rule — rather than
     * leaving Composer to infer one on its own (`dev-master`), which is
     * the pre-v3 behavior this replaces. A real `composer show` reports
     * exactly that derived version — AC-02, AC-08.
     */
    public function test_wildcardVersionDerivesFromProdLock() : void
    {
        $this->bootstrapProd();

        $lockedVersion = $this->getLockedVersion();
        $this->assertNotNull($lockedVersion, 'Expected the PROD lock to carry a resolved version for the fixture package.');

        $switchResult = $this->runSwitch('switch-dev');
        $this->assertTrue($switchResult->isSuccess(), sprintf(
            "switch-dev failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $switchResult->getOutput(),
            $switchResult->getErrorOutput()
        ));

        $repository = $this->getPackageRepositoryEntry();
        $versions = $repository['options']['versions'] ?? array();

        $this->assertArrayHasKey(self::PACKAGE_NAME, $versions);
        $this->assertSame($lockedVersion, $versions[self::PACKAGE_NAME]);

        $showResult = $this->runComposer('show', self::PACKAGE_NAME);

        $this->assertTrue($showResult->isSuccess());
        $this->assertStringContainsString($lockedVersion, $showResult->getOutput());
        $this->assertStringNotContainsString('dev-master', $showResult->getOutput());
    }

    /**
     * With `"version": "2.1.0"` configured — deliberately different
     * from both the PROD-locked version (`2.0.0`, see
     * {@see self::test_wildcardVersionDerivesFromProdLock()}) and what
     * Composer would infer on its own (`dev-master`) — the generated
     * repository entry's `options.versions` pins the package to the
     * configured override, and a real `composer show` reports `2.1.0`:
     * proving the explicit override wins over both of the other two
     * sources the switcher could otherwise have used. The override must
     * still satisfy the PROD `require` constraint (`^2.0`), which
     * `DevConfigTransformer::apply()` keeps unchanged for an
     * already-root-required package — a value outside that range (e.g.
     * `9.9.9`) would make the switch's own planned `composer update`
     * fail with a constraint conflict, which is not what this test is
     * about — AC-08.
     */
    public function test_explicitVersionPinsResolvedPackage() : void
    {
        $this->bootstrapProd();

        $lockedVersion = $this->getLockedVersion();
        $this->assertNotSame('2.1.0', $lockedVersion, 'The override and the PROD-locked version must differ for this test to prove anything.');

        $this->setLocalRepositoryVersionOverride('2.1.0');

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $repository = $this->getPackageRepositoryEntry();
        $versions = $repository['options']['versions'] ?? array();

        $this->assertArrayHasKey(self::PACKAGE_NAME, $versions);
        $this->assertSame('2.1.0', $versions[self::PACKAGE_NAME]);

        $showResult = $this->runComposer('show', self::PACKAGE_NAME);

        $this->assertTrue($showResult->isSuccess());
        $this->assertStringContainsString('2.1.0', $showResult->getOutput());
        $this->assertStringNotContainsString('dev-master', $showResult->getOutput());

        if($lockedVersion !== null) {
            $this->assertStringNotContainsString($lockedVersion, $showResult->getOutput());
        }
    }

    /**
     * Because the fixture package's name (`mistralys/simple_html_dom`)
     * contains an underscore, an explicit `version` override also adds
     * the hyphen-normalised alias (`mistralys/simple-html-dom`) to
     * `options.versions`, pinned to the same version — Composer's
     * `path` repository resolver matches on either spelling, and GitHub
     * repository URLs conventionally use hyphens even when the package
     * name itself uses underscores — AC-08.
     */
    public function test_underscoreNameGetsHyphenAlias() : void
    {
        $this->setLocalRepositoryVersionOverride('2.0.0');
        $this->switchToDev();

        $repository = $this->getPackageRepositoryEntry();
        $versions = $repository['options']['versions'] ?? array();

        $this->assertArrayHasKey(self::PACKAGE_NAME_HYPHENATED, $versions);
        $this->assertSame('2.0.0', $versions[self::PACKAGE_NAME_HYPHENATED]);
    }

    /**
     * With `"version": "not-a-version"` configured — a value Composer
     * cannot parse as a constraint — `switch-dev` still rewrites
     * `composer.json` (the switcher writes the override into the
     * generated repository entry without validating it, and the file
     * write happens before the planned command runs — "plan in the
     * core, execute at the edge"), but the single command `switch-dev`
     * then runs itself (`composer update <package>`) fails with a
     * non-zero exit, and the entry point's own exit code surfaces that
     * failure directly — there is no separate `composer update` step
     * under v3 to fail independently. Recovery from this failure —
     * switching back to PROD afterwards — is covered by
     * {@see self::test_switchProdRecoversAfterRejectedVersion()}, so
     * this test deliberately stops at the failed switch rather than
     * asserting further — AC-08.
     */
    public function test_malformedVersionFailsComposerUpdate() : void
    {
        $this->setLocalRepositoryVersionOverride('not-a-version');
        $this->bootstrapProd();

        $switchResult = $this->runSwitch('switch-dev');

        $this->assertNotSame(0, $switchResult->getExitCode(), 'Expected switch-dev to fail with a malformed version override.');
        $this->assertTrue(
            $switchResult->containsOutput('not-a-version'),
            sprintf(
                "Expected the malformed version to be named in the command output.\nOutput:\n%s\nError output:\n%s",
                $switchResult->getOutput(),
                $switchResult->getErrorOutput()
            )
        );
    }

    /**
     * A malformed `version` override makes `switch-dev`'s own planned
     * `composer update` fail (see
     * {@see self::test_malformedVersionFailsComposerUpdate()}) — but
     * under v3 `composer.lock` always stays the PROD lock throughout a
     * DEV session (it is never deleted or rewritten by the switch
     * itself), so the failed update simply leaves it exactly as
     * {@see self::bootstrapProd()} produced it; there is no "missing
     * DEV lock" state to recover from the way there was in pre-v3
     * releases. What recovery still needs to undo is `composer.json`'s
     * rewrite (which did happen, before the failing command ran) —
     * `switch-prod` restores both `composer.json` and `composer.lock`
     * from the transient PROD snapshot taken when `switch-dev` first
     * ran — AC-13.
     *
     * The recovery step is driven through {@see ConfigSwitcher} directly
     * rather than a real `composer switch-prod` invocation: the malformed
     * `version` override was written verbatim into `composer.json`'s
     * `require` section by the preceding DEV switch (by design — see
     * {@see self::test_malformedVersionIsWrittenVerbatim()} in
     * `TestSwitching.php`), and Composer's own root-package loader
     * eagerly parses every `require` entry as a version constraint
     * before running *any* command or script — including a custom one
     * like `switch-prod` — so the Composer binary itself refuses to run
     * at all while that value is in place. That eager validation is a
     * property of the Composer CLI, not of this library, so it would
     * make every command fail identically; exercising the switcher's own
     * recovery logic directly is what actually isolates the behavior
     * under test.
     */
    public function test_switchProdRecoversAfterRejectedVersion() : void
    {
        $this->setLocalRepositoryVersionOverride('not-a-version');
        $this->bootstrapProd();

        $originalLockContent = $this->readFile($this->testTarget . '/composer.lock');

        $switchResult = $this->runSwitch('switch-dev');
        $this->assertNotSame(0, $switchResult->getExitCode(), 'Expected switch-dev to fail with a malformed version override.');

        $this->assertFileExists(
            $this->testTarget . '/composer.lock',
            'Expected composer.lock to still exist — it stays the PROD lock throughout a DEV session under v3, even when the planned command fails.'
        );
        $this->assertSame(
            $originalLockContent,
            $this->readFile($this->testTarget . '/composer.lock'),
            'Expected composer.lock to be untouched by the failed switch-dev command.'
        );

        $prodData = $this->decodeJsonFile($this->testTarget . '/composer/composer-prod.json');
        $prodLockContent = $this->readFile($this->testTarget . '/composer/composer-prod.lock');

        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $mainData = $this->decodeJsonFile($this->testTarget . '/composer.json');

        // assertEquals rather than assertSame: the restore rebuilds the
        // config structurally (via the three-way revert), which does not
        // guarantee the original key order — only that every key/value
        // pair matches the PROD baseline.
        $this->assertEquals($prodData, $mainData, 'Expected composer.json to be restored to the PROD baseline.');

        $this->assertFileExists($this->testTarget . '/composer.lock', 'Expected switch-prod to restore the PROD composer.lock backup.');
        $this->assertSame(
            $prodLockContent,
            $this->readFile($this->testTarget . '/composer.lock'),
            'Expected the restored composer.lock to match the PROD lock backup.'
        );
    }

    // endregion

    // region: Support methods

    /**
     * Reads the PROD `composer.lock` and returns the resolved version
     * Composer locked the fixture package to, or `null` if the lock
     * cannot be read or does not name the package.
     */
    private function getLockedVersion() : ?string
    {
        $lockData = $this->decodeJsonFile($this->testTarget . '/composer.lock');

        foreach(($lockData['packages'] ?? array()) as $package)
        {
            if(($package['name'] ?? null) === self::PACKAGE_NAME) {
                return $package['version'] ?? null;
            }
        }

        return null;
    }

    /**
     * Reads the DEV `composer.json` and returns the single `repositories`
     * entry generated for the fixture package.
     *
     * @return array<string,mixed>
     */
    private function getPackageRepositoryEntry() : array
    {
        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');

        $repositories = $data[ConfigSwitcher::KEY_REPOSITORIES] ?? array();

        foreach($repositories as $repository)
        {
            if(($repository['type'] ?? null) === 'path') {
                return $repository;
            }
        }

        $this->fail('Expected a "path" repository entry for the fixture package in composer.json.');
    }

    // endregion
}
