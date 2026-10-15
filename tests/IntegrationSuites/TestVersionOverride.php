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
     * `9.9.9`) would now block the switch outright before any write
     * (see {@see self::test_conflictingVersionBlocksSwitch()}), which
     * is not what this test is about — AC-08.
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
     * With `"version": "not-a-version"` configured — a value Composer's
     * own semver engine cannot parse — `switch-dev` is now blocked by
     * the core planner's pre-write guard ({@see ConfigSwitcher::MESSAGE_VERSION_OVERRIDE_CONFLICT})
     * before any file is written: `composer.json` stays byte-identical
     * to its pre-switch content, and no `composer/composer-prod.json`
     * snapshot is taken. This replaces the pre-v3-guard behavior where
     * the malformed value was written verbatim and only then made the
     * planned `composer update` fail mid-switch — AC-08.
     */
    public function test_malformedVersionBlocksSwitch() : void
    {
        $this->setLocalRepositoryVersionOverride('not-a-version');
        $this->bootstrapProd();

        $mainContentBefore = $this->readFile($this->testTarget . '/composer.json');

        $switchResult = $this->runSwitch('switch-dev');

        $this->assertNotSame(0, $switchResult->getExitCode(), 'Expected switch-dev to be blocked by a malformed version override.');
        $this->assertTrue(
            $switchResult->containsOutput('not-a-version'),
            sprintf(
                "Expected the malformed version to be named in the command output.\nOutput:\n%s\nError output:\n%s",
                $switchResult->getOutput(),
                $switchResult->getErrorOutput()
            )
        );
        $this->assertSame(
            $mainContentBefore,
            $this->readFile($this->testTarget . '/composer.json'),
            'Expected composer.json to be untouched by the blocked switch-dev.'
        );
        $this->assertFileDoesNotExist(
            $this->testTarget . '/composer/composer-prod.json',
            'Expected no PROD snapshot to be taken by a blocked switch.'
        );
    }

    /**
     * With `"version": "9.9.9"` configured — a value Composer's semver
     * engine parses fine but which does not satisfy the package's root
     * constraint (`^2.0`) — `switch-dev` is blocked the same way as a
     * malformed override: no file is written, and no PROD snapshot is
     * taken — AC-08.
     */
    public function test_conflictingVersionBlocksSwitch() : void
    {
        $this->setLocalRepositoryVersionOverride('9.9.9');
        $this->bootstrapProd();

        $mainContentBefore = $this->readFile($this->testTarget . '/composer.json');

        $switchResult = $this->runSwitch('switch-dev');

        $this->assertNotSame(0, $switchResult->getExitCode(), 'Expected switch-dev to be blocked by a conflicting version override.');
        $this->assertTrue(
            $switchResult->containsOutput('9.9.9'),
            sprintf(
                "Expected the conflicting version to be named in the command output.\nOutput:\n%s\nError output:\n%s",
                $switchResult->getOutput(),
                $switchResult->getErrorOutput()
            )
        );
        $this->assertSame(
            $mainContentBefore,
            $this->readFile($this->testTarget . '/composer.json'),
            'Expected composer.json to be untouched by the blocked switch-dev.'
        );
        $this->assertFileDoesNotExist(
            $this->testTarget . '/composer/composer-prod.json',
            'Expected no PROD snapshot to be taken by a blocked switch.'
        );
    }

    /**
     * A blocked `switch-dev` (see {@see self::test_malformedVersionBlocksSwitch()})
     * leaves nothing to recover from — `composer.json` was never
     * touched, so there is no PROD snapshot to restore from and no
     * need to switch back to PROD first. Once the rejected pin is
     * removed from `local-repositories.json`, a plain `switch-dev`
     * retry succeeds normally — AC-13.
     */
    public function test_switchDevSucceedsAfterRemovingRejectedPin() : void
    {
        $this->setLocalRepositoryVersionOverride('not-a-version');
        $this->bootstrapProd();

        $mainContentBefore = $this->readFile($this->testTarget . '/composer.json');

        $blockedResult = $this->runSwitch('switch-dev');
        $this->assertNotSame(0, $blockedResult->getExitCode(), 'Expected switch-dev to be blocked by a malformed version override.');

        $this->assertSame(
            $mainContentBefore,
            $this->readFile($this->testTarget . '/composer.json'),
            'Expected composer.json to be untouched by the blocked switch-dev.'
        );
        $this->assertFileDoesNotExist(
            $this->testTarget . '/composer/composer-prod.json',
            'Expected no PROD snapshot to be taken by a blocked switch.'
        );

        $this->setLocalRepositoryVersionOverride(null);

        $devSwitch = $this->runSwitch('switch-dev');

        $this->assertTrue($devSwitch->isSuccess(), sprintf(
            "Expected switch-dev to succeed once the rejected pin was removed.\nOutput:\n%s\nError output:\n%s",
            $devSwitch->getOutput(),
            $devSwitch->getErrorOutput()
        ));

        $this->assertFileExists(
            $this->testTarget . '/composer/composer-prod.json',
            'Expected the successful switch-dev to take its usual PROD snapshot.'
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
