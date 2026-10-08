<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\InstalledState;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Utils\InstalledPackages;
use PHPUnit\Framework\TestCase;

/**
 * Tier 1 suite for {@see InstalledPackages} and
 * {@see InstalledState}: whether a local package is actually
 * installed from a path repository, read from a synthetic
 * `installed.json`, and never thrown as an exception even when that
 * file is missing or malformed.
 *
 * Each test operates against its own throwaway project root under
 * the system temp directory, since {@see InstalledPackages} is
 * unrelated to the `ComposerSwitcherTestCase` fixture-copy flow.
 */
final class TestInstalledPackages extends TestCase
{
    private string $projectRoot;

    protected function setUp() : void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/composer-local-switcher-installed-packages-test-' . uniqid('', true);

        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown() : void
    {
        parent::tearDown();

        FixtureFileSystem::removeDirectory($this->projectRoot);
    }

    // region: _Tests - isInstalledFromPath()

    public function test_isInstalledFromPathDetectsPathTypePackage() : void
    {
        $this->writeInstalledJson('vendor', array(
            'packages' => array(
                array('name' => 'acme/path-package', 'version' => 'dev-main', 'dist' => array('type' => 'path'), 'install-path' => '../../local/path-package'),
                array('name' => 'acme/zip-package', 'version' => '1.0.0', 'dist' => array('type' => 'zip'))
            )
        ));

        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertTrue($installed->exists());
        $this->assertTrue($installed->isInstalledFromPath('acme/path-package'));
        $this->assertFalse($installed->isInstalledFromPath('acme/zip-package'));
    }

    /**
     * A package absent from the `packages` list is a definitively
     * known "not installed from path" (`false`), not an "unknown"
     * state — the file itself was readable and well-formed.
     */
    public function test_isInstalledFromPathReturnsFalseForUnknownPackage() : void
    {
        $this->writeInstalledJson('vendor', array('packages' => array()));

        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertFalse($installed->isInstalledFromPath('acme/never-required'));
    }

    public function test_isInstalledFromPathReturnsNullWhenFileMissing() : void
    {
        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertFalse($installed->exists());
        $this->assertNull($installed->isInstalledFromPath('acme/anything'));
    }

    public function test_isInstalledFromPathReturnsNullWhenFileMalformed() : void
    {
        $installedJsonPath = $this->projectRoot . '/vendor/composer/installed.json';
        mkdir(dirname($installedJsonPath), 0777, true);
        file_put_contents($installedJsonPath, '{not valid json');

        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertTrue($installed->exists());
        $this->assertNull($installed->isInstalledFromPath('acme/anything'));
    }

    // endregion

    // region: _Tests - Custom vendor-dir

    public function test_customVendorDirIsHonoured() : void
    {
        $this->writeInstalledJson('custom-vendor', array(
            'packages' => array(
                array('name' => 'acme/path-package', 'dist' => array('type' => 'path'))
            )
        ));

        $installed = new InstalledPackages($this->projectRoot, array('config' => array('vendor-dir' => 'custom-vendor')));

        $this->assertTrue($installed->exists());
        $this->assertTrue($installed->isInstalledFromPath('acme/path-package'));
    }

    public function test_defaultVendorDirIsUsedWhenConfigKeyAbsent() : void
    {
        $this->writeInstalledJson('vendor', array('packages' => array()));

        $installedDefault = new InstalledPackages($this->projectRoot, array());
        $installedEmptyConfig = new InstalledPackages($this->projectRoot, array('config' => array()));

        $this->assertTrue($installedDefault->exists());
        $this->assertTrue($installedEmptyConfig->exists());
    }

    // endregion

    // region: _Tests - InstalledState::fromInstalledPackages()

    public function test_installedStateMatchesInDevWhenAllPathInstalled() : void
    {
        $this->writeInstalledJson('vendor', array(
            'packages' => array(
                array('name' => 'acme/one', 'dist' => array('type' => 'path')),
                array('name' => 'acme/two', 'dist' => array('type' => 'path'))
            )
        ));

        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertSame(
            InstalledState::Matches,
            InstalledState::fromInstalledPackages(ConfigSwitcher::MODE_DEV, array('acme/one', 'acme/two'), $installed)
        );
    }

    public function test_installedStateMatchesInProdWhenNonePathInstalled() : void
    {
        $this->writeInstalledJson('vendor', array(
            'packages' => array(
                array('name' => 'acme/one', 'dist' => array('type' => 'zip')),
                array('name' => 'acme/two', 'dist' => array('type' => 'zip'))
            )
        ));

        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertSame(
            InstalledState::Matches,
            InstalledState::fromInstalledPackages(ConfigSwitcher::MODE_PROD, array('acme/one', 'acme/two'), $installed)
        );
    }

    /**
     * A mix of path- and non-path-installed packages matches neither
     * mode's expectation, so it reports {@see InstalledState::Pending}
     * regardless of which mode is active.
     */
    public function test_installedStatePendingOnMixedInstallation() : void
    {
        $this->writeInstalledJson('vendor', array(
            'packages' => array(
                array('name' => 'acme/one', 'dist' => array('type' => 'path')),
                array('name' => 'acme/two', 'dist' => array('type' => 'zip'))
            )
        ));

        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertSame(
            InstalledState::Pending,
            InstalledState::fromInstalledPackages(ConfigSwitcher::MODE_DEV, array('acme/one', 'acme/two'), $installed)
        );
        $this->assertSame(
            InstalledState::Pending,
            InstalledState::fromInstalledPackages(ConfigSwitcher::MODE_PROD, array('acme/one', 'acme/two'), $installed)
        );
    }

    /**
     * A missing/malformed `installed.json` yields {@see InstalledState::Unknown}
     * rather than throwing, for either mode.
     */
    public function test_installedStateUnknownWhenInstalledJsonMissing() : void
    {
        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertSame(
            InstalledState::Unknown,
            InstalledState::fromInstalledPackages(ConfigSwitcher::MODE_DEV, array('acme/one'), $installed)
        );
    }

    public function test_installedStateMatchesWithNoLocalPackages() : void
    {
        $installed = new InstalledPackages($this->projectRoot, array());

        $this->assertSame(
            InstalledState::Matches,
            InstalledState::fromInstalledPackages(ConfigSwitcher::MODE_DEV, array(), $installed)
        );
    }

    // endregion

    // region: Support methods

    /**
     * @param array<string,mixed> $data
     */
    private function writeInstalledJson(string $vendorDir, array $data) : void
    {
        $dir = $this->projectRoot . '/' . $vendorDir . '/composer';
        mkdir($dir, 0777, true);

        file_put_contents($dir . '/installed.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    // endregion
}
