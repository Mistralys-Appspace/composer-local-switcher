<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use FilesystemIterator;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ProcessResult;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;
use Mistralys\ComposerSwitcher\Tests\TestClasses\LocalPackageClone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

/**
 * Minimal {@see IntegrationTestCase} subclass used to drive a second,
 * independent test lifecycle (`setUp()` / `tearDown()`) via reflection
 * from within {@see TestDevSwitch::test_workDirectoryIsFullyRemoved()}.
 *
 * A work copy that has been switched to DEV and updated needs to go
 * through a real `tearDown()` call to prove the symlinked `vendor/`
 * package does not orphan the work directory — but that `tearDown()` can
 * only be observed for its filesystem effect *after* it has run, which
 * requires a second, independently controlled test instance rather than
 * `$this` (whose own `tearDown()` runs after the enclosing test method,
 * too late to assert against).
 *
 * Not a runnable test case itself: only ever instantiated and driven
 * manually from {@see TestDevSwitch}.
 */
final class DevSwitchWorkDirectoryHarness extends IntegrationTestCase
{
    /**
     * Placeholder test method required by PHPUnit's TestCase constructor.
     * Never executed by the runner directly.
     */
    public function test_placeholder() : void
    {
        $this->expectNotToPerformAssertions();
    }

    public function getWorkCopyPath() : string
    {
        return $this->testTarget;
    }

    public function bootstrapProdPublic() : void
    {
        $this->bootstrapProd();
    }

    public function runComposerPublic(string ...$arguments) : ProcessResult
    {
        return $this->runComposer(...$arguments);
    }
}

/**
 * Tier 2 suite validating the library's core promise: a DEV switch
 * produces a Composer-resolvable `path` repository whose `vendor/` entry
 * is a real symlink into the local clone, and edits made in the clone are
 * immediately visible through it.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestDevSwitch extends IntegrationTestCase
{
    private const PACKAGE_NAME = 'mistralys/simple_html_dom';

    // region: _Tests

    /**
     * After `composer switch-dev`, the rebuilt `composer.json` carries
     * exactly one `repositories` entry for the package: a `path` repo with
     * symlinking enabled, its `url` equal to the resolved local clone
     * path, and the `require` constraint relaxed to `*` (the DEV switch
     * always uses a wildcard version when the local repository entry
     * carries no explicit `version`) — AC-05.
     */
    public function test_devSwitchGeneratesPathRepository() : void
    {
        $this->switchToDev();

        $data = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $clonePath = $this->resolveClonePath();

        $repositories = $data[ConfigSwitcher::KEY_REPOSITORIES] ?? array();
        $matching = array_values(array_filter(
            $repositories,
            static fn(array $repo) : bool => ($repo['url'] ?? null) === $clonePath
        ));

        $this->assertCount(1, $matching, 'Expected exactly one repository entry pointing at the local clone.');

        $repository = $matching[0];

        $this->assertSame('path', $repository['type']);
        $this->assertTrue($repository['options']['symlink'] ?? false);
        $this->assertSame($clonePath, $repository['url']);

        $this->assertArrayHasKey(self::PACKAGE_NAME, $data['require'] ?? array());
        $this->assertSame('*', $data['require'][self::PACKAGE_NAME]);
    }

    /**
     * A `composer switch-dev` writes the DEV flag file and the switcher's
     * own status file (reporting mode `dev`), and never leaves a PROD flag
     * file behind — AC-05.
     */
    public function test_devSwitchWritesStateArtefacts() : void
    {
        $this->switchToDev();

        $this->assertFileExists($this->testTarget . '/composer.json.DEV');
        $this->assertFileExists($this->testTarget . '/composer/local-repositories.status');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.PROD');

        $status = $this->decodeJsonFile($this->testTarget . '/composer/local-repositories.status');

        $this->assertSame(ConfigSwitcher::MODE_DEV, $status['mode'] ?? null);
    }

    /**
     * A real `composer update` run against the DEV-switched work copy
     * installs the package as a genuine filesystem symlink, resolving
     * into the cached local clone directory rather than a copy — AC-03.
     */
    public function test_devUpdateCreatesVendorSymlink() : void
    {
        $this->switchToDev();
        $this->updateDependencies();

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;
        $clonePath = $this->resolveClonePath();

        $this->assertTrue(is_link($vendorPath), 'Expected the installed vendor package to be a symlink.');

        $resolved = realpath($vendorPath);

        $this->assertNotFalse($resolved, 'Expected the vendor symlink to resolve to a real path.');
        $this->assertStringStartsWith($clonePath, $resolved);
    }

    /**
     * A file written directly into the cloned package is immediately
     * readable through the work copy's `vendor/` symlink — proving the
     * DEV switch wires up a live filesystem link, not a snapshot copy —
     * AC-04. The marker is removed again before the test ends, since the
     * clone directory is a cache shared across the whole Tier 2 run (and
     * potentially concurrent test sessions).
     */
    public function test_cloneEditIsVisibleThroughVendorPath() : void
    {
        $this->switchToDev();
        $this->updateDependencies();

        $clonePath = $this->resolveClonePath();
        $markerName = 'WP010-MARKER-' . uniqid('', true) . '.tmp';
        $markerContent = 'composer-local-switcher WP-010 marker: ' . $markerName;

        $clonePathMarker = $clonePath . '/' . $markerName;
        $vendorPathMarker = $this->testTarget . '/vendor/' . self::PACKAGE_NAME . '/' . $markerName;

        try {
            $this->assertNotFalse(
                file_put_contents($clonePathMarker, $markerContent),
                'Failed to write the marker file into the local clone.'
            );

            $this->assertFileExists($vendorPathMarker, 'Expected the marker to be visible through the vendor symlink.');
            $this->assertSame($markerContent, file_get_contents($vendorPathMarker));
        } finally {
            if(is_file($clonePathMarker)) {
                unlink($clonePathMarker);
            }
        }

        $this->assertFileDoesNotExist($clonePathMarker, 'The marker must not survive past the end of the test.');
    }

    /**
     * Tearing down a DEV work copy whose `vendor/` holds a symlinked
     * package removes the work directory in full (no orphan left behind
     * by a failed `rmdir()` on the symlink), and leaves the cached local
     * clone untouched — AC-15.
     */
    public function test_workDirectoryIsFullyRemoved() : void
    {
        $harness = new DevSwitchWorkDirectoryHarness('test_placeholder');

        $setUp = new ReflectionMethod(IntegrationTestCase::class, 'setUp');
        $setUp->invoke($harness);

        $harness->bootstrapProdPublic();

        $switchResult = $harness->runComposerPublic('switch-dev');
        $this->assertTrue($switchResult->isSuccess(), 'Expected switch-dev to succeed in the harness work copy.');

        $updateResult = $harness->runComposerPublic('update');
        $this->assertTrue($updateResult->isSuccess(), 'Expected composer update to succeed in the harness work copy.');

        $workCopyPath = $harness->getWorkCopyPath();
        $vendorPath = $workCopyPath . '/vendor/' . self::PACKAGE_NAME;

        $this->assertTrue(is_link($vendorPath), 'Expected the harness work copy to have a symlinked vendor package before teardown.');

        $clonePath = $this->resolveClonePath();
        $fileCountBefore = $this->countCloneEntries($clonePath);

        $tearDown = new ReflectionMethod(ComposerSwitcherTestCase::class, 'tearDown');
        $tearDown->invoke($harness);

        $this->assertDirectoryDoesNotExist($workCopyPath, 'Expected the harness work directory to be fully removed after teardown.');

        $fileCountAfter = $this->countCloneEntries($clonePath);

        $this->assertSame($fileCountBefore, $fileCountAfter, 'Expected the cached local clone to be unaffected by teardown of the work copy.');
    }

    // endregion

    // region: Support methods

    private function resolveClonePath() : string
    {
        $clone = new LocalPackageClone();
        $path = $clone->ensureAvailable();

        if($path === null) {
            $this->fail('Expected the local package clone to be available (it was already required by setUp()).');
        }

        return $path;
    }

    /**
     * Counts filesystem entries (files and directories) directly under
     * the clone directory, recursively, without following symlinks —
     * used to prove the shared clone cache is unaffected by an unrelated
     * work copy's teardown.
     *
     * @param string $clonePath
     * @return int
     */
    private function countCloneEntries(string $clonePath) : int
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($clonePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;

        foreach($items as $item) {
            $count++;
        }

        return $count;
    }

    // endregion
}
