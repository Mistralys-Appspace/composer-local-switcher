<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;

/**
 * Tier 2 suite proving DEV-time edits to non-managed `composer.json`
 * entries survive a refresh and are carried back permanently into
 * production on `switch-prod` — the three-way revert's core promise —
 * against a real Composer binary.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestCarryBack extends IntegrationTestCase
{
    private const NEW_PACKAGE_NAME = 'psr/log';
    private const NEW_PACKAGE_CONSTRAINT = '^3.0';

    // region: _Tests

    /**
     * A real `composer require` run in DEV adds a new, non-managed
     * package. A subsequent `switch-dev` refresh keeps that edit (it is
     * not a managed entry, so the three-way revert carries it through
     * rather than resetting it), and the following `switch-prod` carries
     * it back permanently: the package is present in the restored PROD
     * `composer.json`'s `require` section, and is actually installed in
     * `vendor/` afterward — AC-14.
     */
    public function test_requireInDevSurvivesRefreshAndSwitchProd() : void
    {
        $this->switchToDev();

        $requireResult = $this->runComposer('require', self::NEW_PACKAGE_NAME . ':' . self::NEW_PACKAGE_CONSTRAINT, '--no-interaction');
        $this->assertTrue($requireResult->isSuccess(), sprintf(
            "composer require failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $requireResult->getOutput(),
            $requireResult->getErrorOutput()
        ));

        $afterRequire = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertArrayHasKey(self::NEW_PACKAGE_NAME, $afterRequire['require'] ?? array());

        $refreshResult = $this->runSwitch('switch-dev');
        $this->assertTrue($refreshResult->isSuccess(), 'Expected the DEV refresh to succeed.');

        $afterRefresh = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertArrayHasKey(
            self::NEW_PACKAGE_NAME,
            $afterRefresh['require'] ?? array(),
            'Expected the DEV-time require to survive the refresh.'
        );

        $prodSwitch = $this->runSwitch('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), 'Expected switch-prod to succeed.');

        $prodData = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertArrayHasKey(
            self::NEW_PACKAGE_NAME,
            $prodData['require'] ?? array(),
            'Expected the DEV-time require to be carried back into the restored PROD composer.json.'
        );

        $vendorPath = $this->testTarget . '/vendor/' . self::NEW_PACKAGE_NAME;
        $this->assertDirectoryExists($vendorPath, 'Expected the carried-back package to be installed after switch-prod.');
    }

    /**
     * A real `composer remove` of the carried-back package, run while
     * still in DEV, removes it from `composer.json`'s `require` section
     * and is itself carried back on `switch-prod`: the restored PROD
     * `composer.json` no longer names the package at all — AC-14.
     */
    public function test_removeInDevIsCarriedBack() : void
    {
        $this->switchToDev();

        $requireResult = $this->runComposer('require', self::NEW_PACKAGE_NAME . ':' . self::NEW_PACKAGE_CONSTRAINT, '--no-interaction');
        $this->assertTrue($requireResult->isSuccess(), 'Expected composer require to succeed.');

        $removeResult = $this->runComposer('remove', self::NEW_PACKAGE_NAME, '--no-interaction');
        $this->assertTrue($removeResult->isSuccess(), 'Expected composer remove to succeed.');

        $afterRemove = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertArrayNotHasKey(self::NEW_PACKAGE_NAME, $afterRemove['require'] ?? array());

        $prodSwitch = $this->runSwitch('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), 'Expected switch-prod to succeed.');

        $prodData = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertArrayNotHasKey(
            self::NEW_PACKAGE_NAME,
            $prodData['require'] ?? array(),
            'Expected the DEV-time removal to be carried back into the restored PROD composer.json.'
        );
    }

    /**
     * Carrying back a DEV-time `composer require` of an unrelated
     * package does not disturb any other PROD-locked version: every
     * other package's locked version after `switch-prod` is identical
     * to what it was immediately after the original `bootstrapProd()` —
     * AC-14.
     */
    public function test_otherProdVersionsUnchangedAfterCarryBack() : void
    {
        $this->bootstrapProd();

        $originalLockedVersions = $this->getLockedVersionsExcept(
            $this->decodeJsonFile($this->testTarget . '/composer.lock'),
            self::NEW_PACKAGE_NAME
        );

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $requireResult = $this->runComposer('require', self::NEW_PACKAGE_NAME . ':' . self::NEW_PACKAGE_CONSTRAINT, '--no-interaction');
        $this->assertTrue($requireResult->isSuccess(), 'Expected composer require to succeed.');

        $prodSwitch = $this->runSwitch('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), 'Expected switch-prod to succeed.');

        $restoredLockedVersions = $this->getLockedVersionsExcept(
            $this->decodeJsonFile($this->testTarget . '/composer.lock'),
            self::NEW_PACKAGE_NAME
        );

        foreach($originalLockedVersions as $name => $version)
        {
            $this->assertArrayHasKey($name, $restoredLockedVersions, sprintf('Expected package [%s] to still be locked after carry-back.', $name));
            $this->assertSame($version, $restoredLockedVersions[$name], sprintf('Expected package [%s]\'s locked version to be unchanged after carry-back.', $name));
        }
    }

    // endregion

    // region: Support methods

    /**
     * @param array<string,mixed> $lockData
     * @return array<string,string>
     */
    private function getLockedVersionsExcept(array $lockData, string $excludedPackageName) : array
    {
        $versions = array();

        foreach(($lockData['packages'] ?? array()) as $package)
        {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if(is_string($name) && is_string($version) && $name !== $excludedPackageName) {
                $versions[$name] = $version;
            }
        }

        return $versions;
    }

    // endregion
}
