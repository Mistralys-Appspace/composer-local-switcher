<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Utils\StatusFile;

/**
 * Verifies {@see StatusFile}'s snapshot integrity extension: the new
 * `snapshotHash` and `appliedRepositories` keys round-trip through
 * {@see StatusFile::saveState()}, and both getters tolerate a legacy
 * status file (or a caller that simply omitted them) by returning
 * `null` rather than throwing.
 */
final class TestStatusFile extends ComposerSwitcherTestCase
{
    // region: _Tests

    public function test_saveStateRoundTripsSnapshotHashAndAppliedRepositories() : void
    {
        $switcher = $this->createSwitcher();
        $status = $switcher->getStatus();

        $appliedRepositories = array(
            array('packageName' => 'acme/one', 'path' => '../one', 'versionOverride' => null),
            array('packageName' => 'acme/two', 'path' => '../two', 'versionOverride' => '1.2.3')
        );

        $status->saveState(ConfigSwitcher::MODE_DEV, $switcher, 'deadbeefcafebabe00000000deadbeef', $appliedRepositories);

        $this->assertSame('deadbeefcafebabe00000000deadbeef', $status->getSnapshotHash());
        $this->assertSame($appliedRepositories, $status->getAppliedRepositories());
    }

    /**
     * `saveState()` called the original way (no snapshot hash, no
     * applied repositories — exactly what the existing call site in
     * `ConfigSwitcher::switchTo()` still does) must not write either
     * new key at all, so a subsequent read reports `null` for both.
     */
    public function test_omittedValuesReadAsNull() : void
    {
        $switcher = $this->createSwitcher();
        $status = $switcher->getStatus();

        $status->saveState(ConfigSwitcher::MODE_PROD, $switcher);

        $this->assertNull($status->getSnapshotHash());
        $this->assertNull($status->getAppliedRepositories());
    }

    /**
     * A status file written entirely without the new keys — the
     * exact shape a pre-upgrade status file on disk would have —
     * degrades to `null` for both getters rather than throwing, even
     * though the original (legacy) keys are still present and valid.
     */
    public function test_legacyStatusFileWithoutNewKeysReadsAsNull() : void
    {
        $switcher = $this->createSwitcher();
        $status = $switcher->getStatus();

        $status->putData(array(
            StatusFile::KEY_MODE => ConfigSwitcher::MODE_DEV,
            StatusFile::KEY_DATE => '2026-01-01 00:00:00',
            StatusFile::KEY_MAIN_FILE => '/legacy/composer.json',
            StatusFile::KEY_PROD_FILE => '/legacy/composer/composer-prod.json',
            StatusFile::KEY_DEV_FILE => '/legacy/composer/local-repositories.json',
        ));

        $this->assertNull($status->getSnapshotHash());
        $this->assertNull($status->getAppliedRepositories());
        $this->assertSame(ConfigSwitcher::MODE_DEV, $status->getMode());
    }

    // endregion
}
