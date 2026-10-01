<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;

/**
 * Tier 2 suite proving the library's other core promise: a `switch-prod`
 * restores the published package exactly, and the lock file follows each
 * switch precisely — including the documented, deliberately non-no-op
 * behaviour of the `composer install` that follows it.
 *
 * A switch restores the *lock file*, not `vendor/` itself, which is why
 * `README.md` instructs the user to run `composer install` after switching:
 * these tests assert that a pending operation is reported (and completed),
 * not that installation is a no-op — an AC asserting "nothing to install"
 * here would contradict the documented behaviour.
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
     * After `composer switch-prod` followed by `composer update`, the
     * rebuilt `composer.json` carries no `type: path` repository entry, the
     * package's `require` constraint is restored to `^2.0`, and
     * `vendor/mistralys/simple_html_dom` is installed as a real directory
     * again (not a symlink) — AC-06.
     */
    public function test_prodSwitchRestoresPublishedPackage() : void
    {
        $this->roundTripToProd();
        $this->updateInProd();

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
     * After a full round trip (PROD bootstrap, switch to DEV with an
     * update, then back to PROD), both `composer/composer-prod.lock` and
     * `composer/local-repositories.lock` exist — one backed up on the
     * initial PROD bootstrap, the other backed up when leaving DEV — and
     * the two differ, since they capture the dependency graph of two
     * different modes — AC-07.
     */
    public function test_bothLockFilesExistAndDiffer() : void
    {
        $this->roundTripToProd();

        $prodLockPath = $this->testTarget . '/composer/composer-prod.lock';
        $localRepositoriesLockPath = $this->testTarget . '/composer/local-repositories.lock';

        $this->assertFileExists($prodLockPath);
        $this->assertFileExists($localRepositoriesLockPath);

        $this->assertNotSame(
            file_get_contents($prodLockPath),
            file_get_contents($localRepositoriesLockPath),
            'Expected the PROD and DEV saved lock files to differ.'
        );
    }

    /**
     * The active `composer.lock` is byte-identical to the saved lock file
     * for the current mode after each switch in a full round trip: the
     * restored PROD lock matches `composer/composer-prod.lock` after
     * `switch-prod`, and — once a DEV lock has actually been backed up by a
     * prior visit to DEV — the restored DEV lock matches
     * `composer/local-repositories.lock` after a second `switch-dev` —
     * AC-07.
     */
    public function test_switchRestoresMatchingLockFile() : void
    {
        $this->roundTripToProd();

        $mainLockPath = $this->testTarget . '/composer.lock';
        $prodLockPath = $this->testTarget . '/composer/composer-prod.lock';
        $localRepositoriesLockPath = $this->testTarget . '/composer/local-repositories.lock';

        $this->assertSame(
            file_get_contents($prodLockPath),
            file_get_contents($mainLockPath),
            'Expected the active lock file to be byte-identical to the saved PROD lock after switch-prod.'
        );

        // A second switch-dev now finds a DEV lock backed up by the round
        // trip above (written by switch-prod's DEV -> PROD transition), so
        // it restores it instead of deleting the lock for a fresh update.
        $secondDevSwitch = $this->runComposerChecked('switch-dev');
        $this->assertTrue($secondDevSwitch->isSuccess(), 'Expected the second switch-dev to succeed.');

        $this->assertSame(
            file_get_contents($localRepositoriesLockPath),
            file_get_contents($mainLockPath),
            'Expected the active lock file to be byte-identical to the saved DEV lock after the second switch-dev.'
        );
    }

    /**
     * `composer install --dry-run` run immediately after `switch-prod`
     * reports a pending operation on the package (a downgrade back to the
     * published version) rather than "Nothing to install" — a switch
     * restores the lock file, not `vendor/`, which is exactly the
     * documented reason `README.md` instructs the user to run
     * `composer install` next. A following real `composer install` then
     * exits zero and leaves a real directory in `vendor/` — AC-07.
     */
    public function test_installCompletesTheTransition() : void
    {
        $this->roundTripToProd();

        $dryRunResult = $this->runComposer('install', '--dry-run');

        $this->assertTrue($dryRunResult->isSuccess(), sprintf(
            "composer install --dry-run failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $dryRunResult->getOutput(),
            $dryRunResult->getErrorOutput()
        ));

        $this->assertFalse(
            $dryRunResult->containsOutput('Nothing to install, update or remove'),
            'Expected install --dry-run to report a pending operation, not a no-op, immediately after switch-prod.'
        );

        $installResult = $this->runComposer('install');

        $this->assertTrue($installResult->isSuccess(), sprintf(
            "composer install failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $installResult->getOutput(),
            $installResult->getErrorOutput()
        ));

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;

        $this->assertDirectoryExists($vendorPath);
        $this->assertFalse(is_link($vendorPath), 'Expected the installed vendor package to be a real directory, not a symlink.');
    }

    /**
     * A second full PROD→DEV→PROD cycle restores exactly the same
     * published state as the first: after `switch-dev` → `update` →
     * `switch-prod` → `update` → a second `switch-dev` → `install` →
     * a second `switch-prod`, `composer.lock` is byte-identical to
     * `composer/composer-prod.lock`, `composer.json` carries no `type:
     * path` repository entry, and the package's `require` constraint is
     * still `^2.0` — proving the round trip is repeatable rather than
     * only correct once — AC-06, AC-07.
     */
    public function test_secondRoundTripRestoresIdenticalProdLock() : void
    {
        $this->roundTripToProd();
        $this->updateInProd();

        $secondDevSwitch = $this->runComposerChecked('switch-dev');
        $this->assertTrue($secondDevSwitch->isSuccess(), 'Expected the second switch-dev to succeed.');

        $installResult = $this->runComposerChecked('install');
        $this->assertTrue($installResult->isSuccess(), 'Expected composer install to succeed in DEV mode.');

        $secondProdSwitch = $this->runComposerChecked('switch-prod');
        $this->assertTrue($secondProdSwitch->isSuccess(), 'Expected the second switch-prod to succeed.');

        $mainLockPath = $this->testTarget . '/composer.lock';
        $prodLockPath = $this->testTarget . '/composer/composer-prod.lock';

        $this->assertSame(
            file_get_contents($prodLockPath),
            file_get_contents($mainLockPath),
            'Expected the active lock file to be byte-identical to the saved PROD lock after the second switch-prod.'
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
     * Runs the full round trip every test in this suite needs before it
     * can assert on restored PROD state: bootstraps a PROD lock file,
     * switches to DEV and updates (producing a real DEV lock and a
     * symlinked `vendor/` entry), then switches back to PROD — leaving the
     * work copy exactly where `switch-prod` leaves it, without a following
     * `composer install` or `update` (the transition-completion tests need
     * that intermediate, not-yet-installed state).
     *
     * @return void
     */
    private function roundTripToProd() : void
    {
        $this->bootstrapProd();

        $devSwitch = $this->runComposerChecked('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $updateResult = $this->runComposerChecked('update');
        $this->assertTrue($updateResult->isSuccess(), 'Expected composer update to succeed in DEV mode.');

        $prodSwitch = $this->runComposerChecked('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), 'Expected switch-prod to succeed.');
    }

    /**
     * Runs `composer update` in the work copy after `switch-prod`,
     * completing the transition back to the published package.
     *
     * @return void
     */
    private function updateInProd() : void
    {
        $result = $this->runComposerChecked('update');

        $this->assertTrue($result->isSuccess(), 'Expected composer update to succeed in PROD mode.');
    }

    // endregion
}
