<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;
use Mistralys\ComposerSwitcher\Utils\ComposerContentHash;

/**
 * Tier 2 suite proving lock-file fidelity against a real Composer
 * binary: {@see ComposerContentHash}'s own hash algorithm matches
 * Composer's, a PROD->DEV->PROD round trip restores `composer.lock`
 * byte-identically, a DEV switch's lock differs from the PROD lock
 * only in the switched package, a switched package with no explicit
 * `version` override still installs pinned to the PROD-locked version
 * (not a loose wildcard), and a stale main lock blocks `switch-dev`
 * with every file left untouched.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestLockFidelity extends IntegrationTestCase
{
    private const PACKAGE_NAME = 'mistralys/simple_html_dom';

    // region: _Tests

    /**
     * {@see ComposerContentHash::fromConfigData()} produces exactly the
     * same hash Composer's own `Locker` records in `composer.lock`'s
     * `content-hash` key, for both the PROD config (before any switch)
     * and the DEV config (after `switch-dev`) — proving this library's
     * mirror of Composer's algorithm stays correct against a real
     * binary, not just the fixed Tier 1 vectors — AC-01.
     */
    public function test_contentHashParityInProdAndDev() : void
    {
        $this->bootstrapProd();

        $prodConfigData = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $prodLockData = $this->decodeJsonFile($this->testTarget . '/composer.lock');

        $this->assertSame(
            $prodLockData['content-hash'] ?? null,
            ComposerContentHash::fromConfigData($prodConfigData),
            'Expected the PROD content hash to match Composer\'s own Locker output.'
        );

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $devConfigData = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $devLockData = $this->decodeJsonFile($this->testTarget . '/composer.lock');

        $this->assertSame(
            $devLockData['content-hash'] ?? null,
            ComposerContentHash::fromConfigData($devConfigData),
            'Expected the DEV content hash to match Composer\'s own Locker output.'
        );
    }

    /**
     * A full PROD->DEV->PROD round trip (each switch completing in one
     * command) leaves `composer.lock` byte-identical to the original
     * PROD lock captured before the round trip began — AC-13.
     */
    public function test_roundTripRestoresByteIdenticalProdLock() : void
    {
        $this->bootstrapProd();

        $originalLock = $this->readFile($this->testTarget . '/composer.lock');

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $prodSwitch = $this->runSwitch('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), 'Expected switch-prod to succeed.');

        $this->assertSame(
            $originalLock,
            $this->readFile($this->testTarget . '/composer.lock'),
            'Expected composer.lock to be byte-identical to the original PROD lock after the round trip.'
        );
    }

    /**
     * After `switch-dev`, every lock entry for a package other than the
     * one switched locally stays identical to the PROD lock — only the
     * switched package's own entry (and the lock's `content-hash`) may
     * differ — proving the DEV switch does not perturb the dependency
     * graph of anything it does not manage — AC-13.
     */
    public function test_devLockDiffersOnlyInSwitchedPackages() : void
    {
        $this->bootstrapProd();

        $prodLockData = $this->decodeJsonFile($this->testTarget . '/composer.lock');
        $prodPackagesByName = $this->indexPackagesByName($prodLockData);

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $devLockData = $this->decodeJsonFile($this->testTarget . '/composer.lock');
        $devPackagesByName = $this->indexPackagesByName($devLockData);

        $this->assertSame(
            array_keys($prodPackagesByName),
            array_keys($devPackagesByName),
            'Expected the DEV lock to name exactly the same set of packages as the PROD lock.'
        );

        foreach($prodPackagesByName as $name => $prodPackage)
        {
            if($name === self::PACKAGE_NAME) {
                continue;
            }

            $this->assertSame(
                $prodPackage,
                $devPackagesByName[$name],
                sprintf('Expected lock entry for [%s] to be unchanged by the DEV switch.', $name)
            );
        }

        $this->assertNotSame(
            $prodPackagesByName[self::PACKAGE_NAME],
            $devPackagesByName[self::PACKAGE_NAME],
            'Expected the switched package\'s own lock entry to differ (it is now a path/symlink install).'
        );
    }

    /**
     * With no `version` key configured in `local-repositories.json` (the
     * fixture's default), the switched package still installs pinned to
     * the PROD-locked version — derived from the PROD lock rather than
     * left to Composer's own inference — and `vendor/mistralys/simple_html_dom`
     * is a real symlink, not a copy — AC-02.
     */
    public function test_switchedPackageAliasedToLockedVersionWithoutPin() : void
    {
        $this->bootstrapProd();

        $lockedVersion = $this->getLockedVersion($this->decodeJsonFile($this->testTarget . '/composer.lock'));
        $this->assertNotNull($lockedVersion, 'Expected the PROD lock to carry a resolved version for the fixture package.');

        $devConfig = $this->decodeJsonFile($this->testTarget . '/composer/local-repositories.json');
        $this->assertArrayNotHasKey('version', $devConfig['local-repositories'][0] ?? array());

        $devSwitch = $this->runSwitch('switch-dev');
        $this->assertTrue($devSwitch->isSuccess(), 'Expected switch-dev to succeed.');

        $installedData = $this->decodeJsonFile($this->testTarget . '/vendor/composer/installed.json');
        $installedPackage = $this->findInstalledPackage($installedData, self::PACKAGE_NAME);

        $this->assertNotNull($installedPackage, 'Expected the switched package to be present in installed.json.');
        $this->assertSame($lockedVersion, $installedPackage['version'] ?? null);

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;
        $this->assertTrue(is_link($vendorPath), 'Expected the switched package to be installed as a symlink.');
    }

    /**
     * A main lock whose `content-hash` no longer matches its config (a
     * stale lock, per {@see \Mistralys\ComposerSwitcher\State\LockStatus::Stale})
     * blocks `switch-dev` entirely: the command exits non-zero, and
     * every file in the work copy is left byte-for-byte untouched —
     * AC-06, AC-11.
     */
    public function test_staleProdLockBlocksSwitchDev() : void
    {
        $this->bootstrapProd();

        $mainJsonBefore = $this->readFile($this->testTarget . '/composer.json');
        $mainLockBefore = $this->readFile($this->testTarget . '/composer.lock');

        $lockData = $this->decodeJsonFile($this->testTarget . '/composer.lock');
        $lockData['content-hash'] = 'not-a-real-content-hash';
        $this->writeJsonFile($this->testTarget . '/composer.lock', $lockData);

        $corruptedLock = $this->readFile($this->testTarget . '/composer.lock');

        $result = $this->runSwitch('switch-dev');

        $this->assertNotSame(0, $result->getExitCode(), 'Expected switch-dev to be blocked by a stale main lock.');

        $this->assertSame($mainJsonBefore, $this->readFile($this->testTarget . '/composer.json'), 'Expected composer.json to be untouched by the blocked switch.');
        $this->assertSame($corruptedLock, $this->readFile($this->testTarget . '/composer.lock'), 'Expected composer.lock to be untouched by the blocked switch.');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.DEV');

        // Restore the lock so tearDown()'s cleanup (and any later assertion
        // in this same test run) does not have to reason about the
        // corrupted state.
        $this->assertNotFalse(file_put_contents($this->testTarget . '/composer.lock', $mainLockBefore));
    }

    // endregion

    // region: Support methods

    /**
     * @param array<string,mixed> $lockData
     * @return array<string,array<string,mixed>>
     */
    private function indexPackagesByName(array $lockData) : array
    {
        $indexed = array();

        foreach(($lockData['packages'] ?? array()) as $package) {
            if(isset($package['name']) && is_string($package['name'])) {
                $indexed[$package['name']] = $package;
            }
        }

        ksort($indexed);

        return $indexed;
    }

    /**
     * @param array<string,mixed> $lockData
     */
    private function getLockedVersion(array $lockData) : ?string
    {
        $package = $this->indexPackagesByName($lockData)[self::PACKAGE_NAME] ?? null;

        return is_array($package) ? ($package['version'] ?? null) : null;
    }

    /**
     * @param array<string,mixed> $installedData
     * @return array<string,mixed>|null
     */
    private function findInstalledPackage(array $installedData, string $packageName) : ?array
    {
        $packages = $installedData['packages'] ?? $installedData;

        foreach($packages as $package) {
            if(is_array($package) && ($package['name'] ?? null) === $packageName) {
                return $package;
            }
        }

        return null;
    }

    // endregion
}
