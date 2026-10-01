<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\SwitchDescription;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;

/**
 * Verifies {@see ConfigSwitcher::describe()}: it assembles the full
 * state-of-the-world snapshot (mode, last switch date, per-file
 * records, active flag, verification result, and local repositories)
 * without throwing in any of the three switcher states, and
 * {@see \Mistralys\ComposerSwitcher\State\SwitchDescription::toJSON()}
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
    }

    /**
     * After a DEV switch: mode `dev`, the DEV flag present, the PROD
     * flag absent, and the verification result reporting DEV mode
     * (not meaningfully comparable).
     */
    public function test_describeInDevState() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $description = $switcher->describe();

        $this->assertSame(ConfigSwitcher::MODE_DEV, $description->getMode());
        $this->assertNotNull($description->getLastSwitchDate());
        $this->assertSame(ConfigSwitcher::MODE_DEV, $description->getActiveFlag());
        $this->assertTrue($description->getVerification()->isDevMode());
    }

    /**
     * After a PROD switch: mode `prod`, the PROD flag present, the
     * verification result reporting in-sync, and the prod lock file
     * present on disk.
     */
    public function test_describeInProdState() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $description = $switcher->describe();

        $this->assertSame(ConfigSwitcher::MODE_PROD, $description->getMode());
        $this->assertSame(ConfigSwitcher::MODE_PROD, $description->getActiveFlag());
        $this->assertTrue($description->getVerification()->isInSync());
        $this->assertTrue($this->findFileRecord($description, 'prodLock')['exists']);
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

    // endregion
}
