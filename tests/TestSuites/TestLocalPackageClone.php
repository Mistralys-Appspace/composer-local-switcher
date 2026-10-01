<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Tests\TestClasses\LocalPackageClone;
use Mistralys\ComposerSwitcher\Tests\TestClasses\WorkCopy;
use PHPUnit\Framework\TestCase;

/**
 * Tier 1 suite for {@see LocalPackageClone}: every test injects its own
 * cache directory under a fresh {@see WorkCopy::allocate()} path, so none
 * of them ever reads from or writes to the real, shared clone cache under
 * `tests/assets/local-clones/` that Tier 2 suites reuse across concurrent
 * sessions. None of these tests reaches a code path that invokes `git`:
 * the network-clone path is covered by the Tier 2 counterpart,
 * `Mistralys\ComposerSwitcher\IntegrationSuites\TestLocalPackageClone`.
 */
final class TestLocalPackageClone extends TestCase
{
    private ?string $workRoot = null;

    protected function tearDown() : void
    {
        if($this->workRoot !== null) {
            FixtureFileSystem::removeDirectory($this->workRoot);
            $this->workRoot = null;
        }

        parent::tearDown();
    }

    // region: _Tests

    public function test_getCacheDirectoryReturnsInjectedPath() : void
    {
        $injectedPath = $this->allocateWorkPath() . '/injected-cache';

        $clone = new LocalPackageClone($injectedPath);

        $this->assertSame($injectedPath, $clone->getCacheDirectory());
    }

    public function test_getCacheDirectoryDefaultsToSharedCache() : void
    {
        $clone = new LocalPackageClone();

        $this->assertStringEndsWith(
            'tests/assets/local-clones/simple_html_dom',
            $this->normalizePath($clone->getCacheDirectory())
        );
    }

    /**
     * The invalid-directory case (cache directory present, but with no
     * `composer.json` inside) is fully exercised without any network
     * access, so it belongs here in Tier 1 rather than in a Tier 2
     * integration suite.
     */
    public function test_ensureAvailableReportsInvalidDirectoryForInjectedCacheWithoutComposerJson() : void
    {
        $cacheDir = $this->allocateWorkPath() . '/invalid-cache';

        mkdir($cacheDir, 0777, true);
        file_put_contents($cacheDir . '/not-a-composer-file.txt', 'placeholder');

        $clone = new LocalPackageClone($cacheDir);

        $result = $clone->ensureAvailable();

        $this->assertNull($result);
        $this->assertSame(LocalPackageClone::REASON_INVALID_DIRECTORY, $clone->getUnavailableReason());
    }

    /**
     * An already-valid injected cache directory is returned as-is, without
     * `ensureAvailable()` ever needing to reach the git-invoking clone path.
     */
    public function test_ensureAvailableReturnsInjectedValidCache() : void
    {
        $cacheDir = $this->allocateWorkPath() . '/valid-cache';

        mkdir($cacheDir, 0777, true);
        file_put_contents($cacheDir . '/composer.json', '{}');

        $clone = new LocalPackageClone($cacheDir);

        $result = $clone->ensureAvailable();

        $this->assertNotNull($result);
        $this->assertSame(LocalPackageClone::REASON_NONE, $clone->getUnavailableReason());
        $this->assertStringEndsWith('valid-cache', $this->normalizePath((string)$result));
    }

    public function test_ensureAvailablePurgesStalePartialSiblings() : void
    {
        $parentDir = $this->allocateWorkPath();
        $cacheDir = $parentDir . '/valid-cache';

        mkdir($cacheDir, 0777, true);
        file_put_contents($cacheDir . '/composer.json', '{}');

        $stalePartial = $parentDir . '/.partial-valid-cache-stale';
        $freshPartial = $parentDir . '/.partial-valid-cache-fresh';

        mkdir($stalePartial, 0777, true);
        mkdir($freshPartial, 0777, true);

        touch($stalePartial, time() - (LocalPackageClone::PARTIAL_STALE_AFTER_SECONDS + 10));

        $clone = new LocalPackageClone($cacheDir);
        $clone->ensureAvailable();

        $this->assertDirectoryDoesNotExist($stalePartial);
        $this->assertDirectoryExists($freshPartial);
    }

    public function test_getUnavailableReasonIsEmptyBeforeAnyCall() : void
    {
        $clone = new LocalPackageClone($this->allocateWorkPath() . '/unused-cache');

        $this->assertSame(LocalPackageClone::REASON_NONE, $clone->getUnavailableReason());
    }

    // endregion

    // region: Support methods

    /**
     * Allocates a fresh, throwaway directory (created eagerly, unlike
     * {@see WorkCopy::allocate()}'s own lazy semantics) that this test can
     * freely nest an injected cache directory and its `.partial-*` siblings
     * under, removed in {@see self::tearDown()}.
     */
    private function allocateWorkPath() : string
    {
        $workCopy = WorkCopy::allocate(__DIR__ . '/../assets/work-projects');
        $path = $workCopy->getPath();

        mkdir($path, 0777, true);

        $this->workRoot = $path;

        return $path;
    }

    private function normalizePath(string $path) : string
    {
        $segments = array();

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment)
        {
            if ($segment === '.' || $segment === '') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    // endregion
}
