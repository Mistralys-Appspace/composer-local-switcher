<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;

/**
 * Tier 2 suite proving the library's other core promise under v3: a
 * `switch-prod` restores the published package exactly, and
 * `composer.lock` follows each switch precisely — and, unlike pre-v3
 * releases, each switch completes in a single command with no
 * follow-up `composer install`/`update` needed. There is no longer a
 * separate, committed `composer/local-repositories.lock` DEV-lock
 * backup to assert on: under v3 `composer.lock` always stays the PROD
 * lock throughout a DEV session, and `composer/composer-prod.*` is a
 * transient snapshot deleted again the moment `switch-prod` restores
 * from it.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestRoundTrip extends IntegrationTestCase
{
    private const PACKAGE_NAME = 'mistralys/simple_html_dom';
    private const PROD_CONSTRAINT = '^2.0';

    // region: _Tests

    /**
     * After `composer switch-prod`, the rebuilt `composer.json` carries
     * no `type: path` repository entry, the package's `require`
     * constraint is restored to `^2.0`, and `vendor/mistralys/simple_html_dom`
     * is installed as a real directory again (not a symlink) — the single
     * `switch-prod` command itself completes the install, with no
     * follow-up `composer install`/`update` needed — AC-06, AC-07.
     */
    public function test_prodSwitchRestoresPublishedPackage() : void
    {
        $this->roundTripToProd();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');

        $repositories = $data['repositories'] ?? array();
        $pathRepositories = array_values(array_filter(
            $repositories,
            static fn(array $repo) : bool => ($repo['type'] ?? null) === 'path'
        ));

        $this->assertCount(0, $pathRepositories, 'Expected no `type: path` repository entry after switching back to PROD.');

        $this->assertArrayHasKey(self::PACKAGE_NAME, $data['require'] ?? array());
        $this->assertSame(self::PROD_CONSTRAINT, $data['require'][self::PACKAGE_NAME]);

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;

        $this->assertDirectoryExists($vendorPath);
        $this->assertFalse(is_link($vendorPath), 'Expected the restored vendor package to be a real directory, not a symlink.');
    }

    /**
     * A `composer switch-prod` writes the PROD flag file and removes any
     * DEV flag file left behind by the preceding `switch-dev` — AC-06.
     */
    public function test_flagFilesFollowTheSwitch() : void
    {
        $this->roundTripToProd();

        $this->assertFileExists($this->testTarget . '/composer.json.PROD');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.DEV');
    }

    /**
     * The transient PROD snapshot (`composer/composer-prod.json` and
     * `.lock`) exists while a DEV session is active, and is deleted again
     * once `switch-prod` restores from it — there is no second,
     * permanently committed lock backup under v3 — AC-13.
     */
    public function test_snapshotExistsOnlyDuringDevSession() : void
    {
        $this->bootstrapProd();

        $this->assertFileDoesNotExist($this->testTarget . '/composer/composer-prod.json');
        $this->assertFileDoesNotExist($this->testTarget . '/composer/composer-prod.lock');

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $this->assertFileExists($this->testTarget . '/composer/composer-prod.json');
        $this->assertFileExists($this->testTarget . '/composer/composer-prod.lock');

        $prodSwitch = $this->runSwitch('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), 'Expected switch-prod to succeed.');

        $this->assertFileDoesNotExist($this->testTarget . '/composer/composer-prod.json');
        $this->assertFileDoesNotExist($this->testTarget . '/composer/composer-prod.lock');
    }

    /**
     * After a full PROD->DEV->PROD round trip, the active `composer.lock`
     * is byte-identical to the original PROD lock captured before the
     * round trip began — the restored lock is the exact bytes
     * `switch-dev` snapshotted, not a freshly regenerated one — AC-01
     * (the broader content-hash parity claim is pinned in
     * `TestLockFidelity`).
     */
    public function test_switchRestoresMatchingLockFile() : void
    {
        $this->bootstrapProd();

        $originalLockContent = $this->readFile($this->testTarget . '/composer.lock');

        $this->roundTripToProd();

        $this->assertSame(
            $originalLockContent,
            $this->readFile($this->testTarget . '/composer.lock'),
            'Expected the active lock file to be byte-identical to the original PROD lock after the round trip.'
        );
    }

    /**
     * A second full PROD->DEV->PROD cycle restores exactly the same
     * published state as the first: `composer.lock` is still
     * byte-identical to the original PROD lock, `composer.json` carries
     * no `type: path` repository entry, and the package's `require`
     * constraint is still `^2.0` — proving the round trip is repeatable
     * rather than only correct once — AC-01, AC-06, AC-07.
     */
    public function test_secondRoundTripRestoresIdenticalProdLock() : void
    {
        $this->bootstrapProd();

        $originalLockContent = $this->readFile($this->testTarget . '/composer.lock');

        $this->roundTripToProd();
        $this->roundTripToProd();

        $this->assertSame(
            $originalLockContent,
            $this->readFile($this->testTarget . '/composer.lock'),
            'Expected the active lock file to be byte-identical to the original PROD lock after the second round trip.'
        );

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');

        $repositories = $data['repositories'] ?? array();
        $pathRepositories = array_values(array_filter(
            $repositories,
            static fn(array $repo) : bool => ($repo['type'] ?? null) === 'path'
        ));

        $this->assertCount(0, $pathRepositories, 'Expected no `type: path` repository entry after the second switch-prod.');

        $this->assertArrayHasKey(self::PACKAGE_NAME, $data['require'] ?? array());
        $this->assertSame(self::PROD_CONSTRAINT, $data['require'][self::PACKAGE_NAME]);
    }

    // endregion

    // region: Support methods

    /**
     * Runs a single PROD->DEV->PROD round trip: bootstraps a PROD lock
     * file (if not already bootstrapped), then switches to DEV and back
     * to PROD via {@see self::runSwitch()} — each switch completes in one
     * command under v3, so no following `composer install`/`update` is
     * needed to reach the fully-installed PROD state this method leaves
     * the work copy in.
     *
     * @return void
     */
    private function roundTripToProd() : void
    {
        if(!file_exists($this->testTarget . '/composer.lock')) {
            $this->bootstrapProd();
        }

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), sprintf(
            "switch-dev failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $devSwitch->getOutput(),
            $devSwitch->getErrorOutput()
        ));

        $prodSwitch = $this->runSwitch('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), sprintf(
            "switch-prod failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $prodSwitch->getOutput(),
            $prodSwitch->getErrorOutput()
        ));
    }

    // endregion
}
