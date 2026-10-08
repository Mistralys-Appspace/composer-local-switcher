<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\InstalledState;
use Mistralys\ComposerSwitcher\State\LockStatus;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;

/**
 * Verifies {@see ConfigSwitcher::describe()}: it assembles the full
 * state-of-the-world snapshot (mode, last switch date, per-file
 * records, active flag, {@see \Mistralys\ComposerSwitcher\State\LockStatus},
 * {@see InstalledState}, DEV-only pending production changes, and
 * local repositories with their derived versions) without throwing in
 * any of the three switcher states, and {@see \Mistralys\ComposerSwitcher\State\SwitchDescription::toJSON()}
 * round-trips through `json_decode()` into the same structure as
 * `toArray()`.
 */
final class TestDescribe extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * Before any switch has been made: mode `initial`, no last switch
     * date, the prod config absent, the main config present, neither
     * flag file present, and the three fixture local repositories
     * parsed regardless — `describe()` does not require a switch to
     * have happened to report the dev configuration's contents.
     */
    public function test_describeInInitialState() : void
    {
        $switcher = $this->createSwitcher();

        $description = $switcher->describe();

        $this->assertSame(ConfigSwitcher::MODE_INITIAL, $description->getMode());
        $this->assertNull($description->getLastSwitchDate());
        $this->assertNull($description->getActiveFlag());
        $this->assertFalse($description->hasActiveFlag());
        $this->assertFalse($description->hasWarnings());

        $this->assertFalse($this->findFileRecord($description, 'prod')['exists']);
        $this->assertTrue($this->findFileRecord($description, 'main')['exists']);

        $this->assertCount(3, $description->getLocalRepositories());

        // No switch has been run yet, so there is nothing DEV-specific
        // to report.
        $this->assertNull($description->getPendingProdChanges());
    }

    /**
     * After a DEV switch: mode `dev`, the DEV flag present, the PROD
     * flag absent, and the fresh snapshot reports no pending production
     * changes yet (nothing has been edited since the switch).
     */
    public function test_describeInDevState() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $description = $switcher->describe();

        $this->assertSame(ConfigSwitcher::MODE_DEV, $description->getMode());
        $this->assertNotNull($description->getLastSwitchDate());
        $this->assertSame(ConfigSwitcher::MODE_DEV, $description->getActiveFlag());

        $pendingProdChanges = $description->getPendingProdChanges();
        $this->assertNotNull($pendingProdChanges, 'A fresh DEV switch must be able to compute pending production changes.');
        $this->assertFalse($pendingProdChanges->hasPermanentChanges());
        $this->assertFalse($description->hasPendingProdChanges());
    }

    /**
     * After a PROD switch: mode `prod`, the PROD flag present, and no
     * pending production changes to report (that concept is DEV-only).
     *
     * Under the v3 switching model (this plan's WP-008),
     * `composer-prod.json` is a transient DEV-only snapshot — a
     * PROD/INITIAL→PROD switch has no file effects on it at all, so it
     * no longer exists once in PROD mode.
     */
    public function test_describeInProdState() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $description = $switcher->describe();

        $this->assertSame(ConfigSwitcher::MODE_PROD, $description->getMode());
        $this->assertSame(ConfigSwitcher::MODE_PROD, $description->getActiveFlag());
        $this->assertFalse($this->findFileRecord($description, 'prod')['exists']);
        $this->assertFalse($this->findFileRecord($description, 'prodLock')['exists']);
        $this->assertNull($description->getPendingProdChanges());
    }

    /**
     * Neither the fixture's lock (a plain `PROD`/`DEV` text file, not
     * JSON) nor an `installed.json` exist, so `lockStatus`/`installedState`
     * both degrade to their "cannot be determined" value rather than
     * throwing — `describe()`'s core guarantee.
     */
    public function test_describeReportsUnknownLockAndInstalledStateForNonJsonFixtureLock() : void
    {
        $switcher = $this->createSwitcher();

        $description = $switcher->describe();

        $this->assertSame(LockStatus::Unknown, $description->getLockStatus());
        $this->assertSame(InstalledState::Unknown, $description->getInstalledState());
    }

    /**
     * With the dev config file deleted, `describe()` degrades to an
     * empty repository list plus a warning instead of throwing —
     * the one call agents rely on for diagnosis must never itself
     * fail when the state being diagnosed is broken.
     */
    public function test_describeToleratesMissingDevFile() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getDevFile()->getPath());

        $description = $switcher->describe();

        $this->assertSame(array(), $description->getLocalRepositories());
        $this->assertTrue($description->hasWarnings());
    }

    /**
     * Each `localRepositories` entry's `derivedVersion` is the explicit
     * override when one is configured (`mistralys/application-utils-core`,
     * fixture version `2.3.14`), or `null` when none can be derived —
     * the fixture's lock file is the literal text `PROD`, not JSON, so
     * no locked version is ever available to fall back to.
     */
    public function test_describeReportsDerivedVersionPerPackage() : void
    {
        $switcher = $this->createSwitcher();

        $description = $switcher->describe();

        $withOverride = $this->findLocalRepository($description, 'mistralys/application-utils-core');
        $this->assertSame('2.3.14', $withOverride['derivedVersion']);

        $withoutOverride = $this->findLocalRepository($description, 'mistralys/application_framework');
        $this->assertNull($withoutOverride['derivedVersion']);
    }

    /**
     * A leftover v2-era `local-repositories.lock` surfaces as a warning
     * in the description — `describe()` is read-only, so it reports
     * the legacy artifact rather than cleaning it up itself (that
     * happens on the next real switch).
     */
    public function test_describeSurfacesLegacyDevLockFileAsWarning() : void
    {
        $switcher = $this->createSwitcher();
        file_put_contents($switcher->getDevFile()->getLockFile()->getPath(), 'legacy dev lock content');

        $description = $switcher->describe();

        $this->assertTrue($description->hasWarnings());
        $this->assertTrue($switcher->getDevFile()->getLockFile()->exists(), 'describe() must not clean up the legacy artifact itself.');
    }

    /**
     * An edit to the production snapshot after it was taken degrades
     * `pendingProdChanges` to `null` plus a warning, mirroring
     * `switchTo()`'s own tamper-detection blocker — `describe()` never
     * throws even though the underlying `revert()` call could not run
     * against a trustworthy base.
     */
    public function test_describePendingProdChangesDegradesOnModifiedSnapshot() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $prodConfig = $switcher->getProdFile()->getData();
        $prodConfig['extra']['tampered'] = true;
        $switcher->getProdFile()->putData($prodConfig);

        $description = $switcher->describe();

        $this->assertNull($description->getPendingProdChanges());
        $this->assertTrue($description->hasWarnings());
    }

    /**
     * A non-managed edit made directly to `composer.json` while in DEV
     * mode (simulating a `composer require`) shows up as a pending
     * production change — the same carry-back the real `revert()` +
     * `ConfigDiff` pipeline a DEV→PROD switch uses.
     */
    public function test_describeReportsPendingCarriedBackPackage() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $config['require']['acme/newly-required'] = '^1.0';
        $switcher->getMainFile()->putData($config);

        $description = $switcher->describe();

        $pendingProdChanges = $description->getPendingProdChanges();
        $this->assertNotNull($pendingProdChanges);
        $this->assertTrue($pendingProdChanges->hasPermanentChanges());
        $this->assertTrue($description->hasPendingProdChanges());
        $this->assertContains('acme/newly-required', $pendingProdChanges->getProdChangedPackages());
    }

    /**
     * `describe()->toJSON()` must round-trip through `json_decode()`
     * into the exact same structure as `describe()->toArray()` —
     * the JSON entry point and the programmatic entry point must
     * never diverge.
     */
    public function test_describeJsonRoundTrips() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $description = $switcher->describe();

        $decoded = json_decode($description->toJSON(), true);

        $this->assertSame($description->toArray(), $decoded);
    }

    // endregion

    // region: Support methods

    /**
     * @param SwitchDescription $description
     * @param string $label
     * @return array{label:string,path:string,exists:bool,modifiedDate:string|null}
     */
    private function findFileRecord(SwitchDescription $description, string $label) : array
    {
        foreach($description->getFiles() as $file)
        {
            if($file['label'] === $label) {
                return $file;
            }
        }

        $this->fail('No file record found for label: ' . $label);
    }

    /**
     * @return array{packageName:string,path:string,version:string,derivedVersion:string|null}
     */
    private function findLocalRepository(SwitchDescription $description, string $packageName) : array
    {
        foreach($description->getLocalRepositories() as $repo)
        {
            if($repo['packageName'] === $packageName) {
                return $repo;
            }
        }

        $this->fail('No local repository record found for package: ' . $packageName);
    }

    // endregion
}
