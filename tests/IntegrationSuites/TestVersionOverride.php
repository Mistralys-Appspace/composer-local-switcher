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
     * default), the generated repository entry carries no
     * `options.versions` key at all, and Composer reports the package as
     * `dev-master` — the value it infers on its own, absent an explicit
     * version constraint — AC-08.
     */
    public function test_wildcardVersionOmitsVersionsOption() : void
    {
        $this->switchToDev();
        $this->updateDependencies();

        $repository = $this->getPackageRepositoryEntry();

        $this->assertArrayNotHasKey('versions', $repository['options'] ?? array());

        $showResult = $this->runComposer('show', self::PACKAGE_NAME);

        $this->assertTrue($showResult->isSuccess());
        $this->assertStringContainsString('dev-master', $showResult->getOutput());
    }

    /**
     * With `"version": "2.0.0"` configured, the generated repository
     * entry's `options.versions` pins the package to that version, and a
     * real `composer show` reports `2.0.0` rather than the `dev-master`
     * Composer would otherwise infer — AC-08.
     */
    public function test_explicitVersionPinsResolvedPackage() : void
    {
        $this->setLocalRepositoryVersion('2.0.0');
        $this->switchToDev();
        $this->updateDependencies();

        $repository = $this->getPackageRepositoryEntry();
        $versions = $repository['options']['versions'] ?? array();

        $this->assertArrayHasKey(self::PACKAGE_NAME, $versions);
        $this->assertSame('2.0.0', $versions[self::PACKAGE_NAME]);

        $showResult = $this->runComposer('show', self::PACKAGE_NAME);

        $this->assertTrue($showResult->isSuccess());
        $this->assertStringContainsString('2.0.0', $showResult->getOutput());
        $this->assertStringNotContainsString('dev-master', $showResult->getOutput());
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
        $this->setLocalRepositoryVersion('2.0.0');
        $this->switchToDev();
        $this->updateDependencies();

        $repository = $this->getPackageRepositoryEntry();
        $versions = $repository['options']['versions'] ?? array();

        $this->assertArrayHasKey(self::PACKAGE_NAME_HYPHENATED, $versions);
        $this->assertSame('2.0.0', $versions[self::PACKAGE_NAME_HYPHENATED]);
    }

    /**
     * With `"version": "not-a-version"` configured — a value Composer
     * cannot parse as a constraint — `switch-dev` itself still succeeds
     * (the switcher writes the override into the generated repository
     * entry without validating it), but the following `composer update`
     * fails with a non-zero exit and names the malformed value in its
     * output. Recovery from this failure — switching back to PROD
     * afterwards — is covered by
     * {@see self::test_switchProdRecoversAfterRejectedVersion()}, so this
     * test deliberately stops at the failed update rather than asserting
     * further — AC-08.
     */
    public function test_malformedVersionFailsComposerUpdate() : void
    {
        $this->setLocalRepositoryVersion('not-a-version');
        $this->switchToDev();

        $updateResult = $this->runComposer('update');

        $this->assertNotSame(0, $updateResult->getExitCode(), 'Expected composer update to fail with a malformed version override.');
        $this->assertTrue(
            $updateResult->containsOutput('not-a-version'),
            sprintf(
                "Expected the malformed version to be named in the command output.\nOutput:\n%s\nError output:\n%s",
                $updateResult->getOutput(),
                $updateResult->getErrorOutput()
            )
        );
    }

    /**
     * A malformed `version` override makes `composer update` fail in DEV
     * (see {@see self::test_malformedVersionFailsComposerUpdate()}),
     * leaving no DEV `composer.lock` behind. `switch-prod` used to bail
     * out entirely in this situation — the missing-lock early return in
     * `ConfigSwitcher::switchTo()` aborted before restoring anything,
     * leaving the project stuck in a broken DEV state. It now completes
     * the switch and restores both the PROD `composer.json` and the PROD
     * `composer.lock` backup created when `switch-dev` first ran — AC-13.
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
        $this->setLocalRepositoryVersion('not-a-version');
        $this->switchToDev();

        $updateResult = $this->runComposer('update');
        $this->assertNotSame(0, $updateResult->getExitCode(), 'Expected composer update to fail with a malformed version override.');

        $this->assertFileDoesNotExist(
            $this->testTarget . '/composer.lock',
            'Expected the failed composer update to leave no DEV lock file behind.'
        );

        $prodData = $this->decodeJsonFile($this->testTarget . '/composer/composer-prod.json');
        $prodLockContent = $this->readFile($this->testTarget . '/composer/composer-prod.lock');

        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $mainData = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertSame($prodData, $mainData, 'Expected composer.json to be restored to the PROD baseline.');

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
