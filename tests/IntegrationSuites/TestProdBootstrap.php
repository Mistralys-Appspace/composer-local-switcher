<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;

/**
 * Tier 2 suite validating the integration fixture itself and the PROD
 * baseline {@see IntegrationTestCase::bootstrapProd()} establishes: every
 * other Tier 2 suite starts from the state this suite asserts, so a
 * regression here would otherwise surface as a confusing failure in an
 * unrelated downstream suite instead of here, at the source.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestProdBootstrap extends IntegrationTestCase
{
    private const SWITCH_SCRIPT_KEYS = array(
        'switch-dev',
        'switch-prod',
        'switch-update',
        'switch-install-hooks',
    );

    // region: _Tests

    /**
     * The work copy is a valid v3 three-path project: `composer.json` and
     * `composer/local-repositories.json` exist, `composer.json` maps all
     * four `switch-*` script keys, neither placeholder survived
     * {@see IntegrationTestCase::setUp()}'s substitution, and no committed
     * `composer/composer-prod.json` snapshot exists — under v3 that file is
     * only ever a transient snapshot the switcher itself creates.
     */
    public function test_fixtureIsValidThreePathProject() : void
    {
        $this->assertFileExists($this->testTarget . '/composer.json');
        $this->assertFileExists($this->testTarget . '/composer/local-repositories.json');
        $this->assertFileDoesNotExist($this->testTarget . '/composer/composer-prod.json');

        $mainData = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $scripts = $mainData['scripts'] ?? array();

        foreach(self::SWITCH_SCRIPT_KEYS as $key) {
            $this->assertArrayHasKey($key, $scripts, sprintf('Expected scripts key "%s" to be present.', $key));
        }

        $mainJson = $this->readFile($this->testTarget . '/composer.json');

        $this->assertStringNotContainsString('__LIBRARY_SRC_PATH__', $mainJson);
        $this->assertStringNotContainsString('__LOCAL_CLONE_PATH__', $mainJson);
    }

    /**
     * The committed fixture under `tests/assets/integration-project/` — not
     * the work copy — still carries both placeholders verbatim, and
     * `composer.json` leaks no machine-specific absolute path in their
     * place: the placeholder-bearing value decodes to exactly the
     * placeholder string. There is no committed `composer-prod.json` to
     * carry a placeholder copy of its own.
     */
    public function test_committedFixtureCarriesPlaceholders() : void
    {
        $committedMainJson = $this->readFile($this->assetsFolder . '/integration-project/composer.json');
        $committedLocalRepositoriesJson = $this->readFile($this->assetsFolder . '/integration-project/composer/local-repositories.json');

        $this->assertStringContainsString('__LIBRARY_SRC_PATH__', $committedMainJson);
        $this->assertStringContainsString('__LOCAL_CLONE_PATH__', $committedLocalRepositoriesJson);

        $this->assertFileDoesNotExist($this->assetsFolder . '/integration-project/composer/composer-prod.json');

        $committedMain = $this->decodeJsonFile($this->assetsFolder . '/integration-project/composer.json');
        $committedLocalRepositories = $this->decodeJsonFile($this->assetsFolder . '/integration-project/composer/local-repositories.json');

        // Decoding each placeholder-bearing value to exactly the placeholder
        // string (rather than merely string-containing it) proves no
        // machine-specific absolute path rides alongside it in the same field.
        $this->assertSame('__LIBRARY_SRC_PATH__', $committedMain['autoload']['classmap'][0]);
        $this->assertSame('__LOCAL_CLONE_PATH__', $committedLocalRepositories['local-repositories'][0]['path']);
    }

    /**
     * The committed fixture's `composer.json` scripts block carries none
     * of the retired `switch-reconcile`/`switch-verify-config` entry
     * points this plan removes.
     */
    public function test_fixtureScriptsHaveNoRetiredEntryPoints() : void
    {
        $committedMain = $this->decodeJsonFile($this->assetsFolder . '/integration-project/composer.json');
        $scripts = $committedMain['scripts'] ?? array();

        $this->assertArrayNotHasKey('switch-reconcile', $scripts);
        $this->assertArrayNotHasKey('switch-verify-config', $scripts);
    }

    /**
     * A real `composer update` in the bootstrapped work copy exits zero,
     * produces a valid `composer.lock` naming the package, and installs it
     * into `vendor/` as a real copy rather than a symlink — the DEV switch
     * path uses symlinks, so a real copy here is what distinguishes the
     * PROD baseline from a DEV-switched state.
     */
    public function test_prodUpdateInstallsRealPackage() : void
    {
        $this->bootstrapProd();

        $this->assertFileExists($this->testTarget . '/composer.lock');

        $lockData = $this->decodeJsonFile($this->testTarget . '/composer.lock');
        $packageNames = array_column($lockData['packages'] ?? array(), 'name');

        $this->assertContains('mistralys/simple_html_dom', $packageNames);

        $vendorPath = $this->testTarget . '/vendor/mistralys/simple_html_dom';

        $this->assertDirectoryExists($vendorPath);
        $this->assertFalse(is_link($vendorPath), 'Expected the installed vendor package to be a real copy, not a symlink.');
    }

    /**
     * The bootstrap `composer update`'s {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\ProcessResult}
     * keeps stdout and stderr separate and correctly attributed for both a
     * successful and a deliberately failing invocation, and both complete
     * rather than blocking — installing a real package produces enough
     * combined output on both streams to exercise the non-blocking drain
     * under load, not just against a trivial `--version` call.
     */
    public function test_composerRunnerSeparatesStreams() : void
    {
        $successResult = $this->runComposer('update');

        $this->assertTrue($successResult->isSuccess());
        $this->assertSame(0, $successResult->getExitCode());
        $this->assertNotSame('', $successResult->getErrorOutput());
        $this->assertStringNotContainsString('not defined', $successResult->getErrorOutput());

        $failureResult = $this->runComposer('this-is-not-a-real-composer-command');

        $this->assertFalse($failureResult->isSuccess());
        $this->assertNotSame(0, $failureResult->getExitCode());
        $this->assertSame('', $failureResult->getOutput());
        $this->assertStringContainsString('not defined', $failureResult->getErrorOutput());
    }

    /**
     * The bootstrap `composer update` is a PROD-shaped baseline only: none
     * of the switcher's own state artefacts — the DEV/PROD flag files or the
     * local-repositories status file — exist until the first `switch-dev` or
     * `switch-prod` call writes them.
     */
    public function test_noStateArtefactsBeforeFirstSwitch() : void
    {
        $this->bootstrapProd();

        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.DEV');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.PROD');
        $this->assertFileDoesNotExist($this->testTarget . '/composer/local-repositories.status');
    }

    // endregion

    // region: Support methods

    // endregion
}
