<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\State\DevTransformResult;
use Mistralys\ComposerSwitcher\State\LocalRepository;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\DevConfigTransformer;
use Mistralys\ComposerSwitcher\Utils\LockFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tier 1 suite for {@see DevConfigTransformer}: the pure, invertible
 * DEV config transform that replaces the inline logic formerly in
 * `ConfigSwitcher::switch_adjustConfigForDev()`.
 *
 * `apply()` is tested against its alias-derivation, root-constraint
 * and repository replace/prune/append rules; `revert()` against the
 * round-trip invariant and its three carry-back/restore rules
 * (non-managed key edits, non-managed package adds/removes/changes,
 * non-managed repository adds/removes, and managed-entry overrides).
 *
 * Each test builds its {@see LockFile} fixtures against its own
 * throwaway work root under the system temp directory, following
 * `TestLockFile.php`'s established convention — this suite is
 * unrelated to the `ComposerSwitcherTestCase` fixture-copy flow.
 */
final class TestDevConfigTransformer extends TestCase
{
    private string $workRoot;

    protected function setUp() : void
    {
        parent::setUp();

        $this->workRoot = sys_get_temp_dir() . '/composer-local-switcher-dev-transform-test-' . uniqid('', true);

        mkdir($this->workRoot, 0777, true);
    }

    protected function tearDown() : void
    {
        parent::tearDown();

        FixtureFileSystem::removeDirectory($this->workRoot);
    }

    // region: _Tests - apply()

    public function test_applyAliasesToLockedVersion() : void
    {
        $prodConfig = array('name' => 'acme/app', 'require' => array('php' => '>=8.1'));
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $lock = $this->buildLockFile(array('acme/local-one' => '2.3.0'));

        $result = DevConfigTransformer::apply($prodConfig, $repos, $lock);
        $config = $result->getConfig();

        $this->assertSame(
            '2.3.0',
            $this->findVersionsMap($config, 'acme/local-one')['acme/local-one'] ?? null
        );

        $derivations = $result->getVersionDerivations();
        $this->assertSame('acme/local-one', $derivations[0]['packageName']);
        $this->assertSame('2.3.0', $derivations[0]['version']);
        $this->assertSame(DevTransformResult::SOURCE_LOCKED, $derivations[0]['source']);
    }

    public function test_applyPrefersOverride() : void
    {
        $prodConfig = array('name' => 'acme/app', 'require' => array('php' => '>=8.1'));
        $repos = array(new LocalRepository('acme/local-one', '../local-one', '9.9.9'));
        $lock = $this->buildLockFile(array('acme/local-one' => '1.0.0'));

        $result = DevConfigTransformer::apply($prodConfig, $repos, $lock);

        $this->assertSame(
            '9.9.9',
            $this->findVersionsMap($result->getConfig(), 'acme/local-one')['acme/local-one'] ?? null
        );
        $this->assertSame(DevTransformResult::SOURCE_OVERRIDE, $result->getVersionDerivations()[0]['source']);
    }

    /**
     * When the package is already root-required in PROD and an alias
     * was derived, the require constraint is kept from PROD rather
     * than replaced — aliasing in the repository entry is what
     * satisfies the constraint.
     */
    public function test_applyKeepsProdConstraintWhenAliased() : void
    {
        $prodConfig = array('name' => 'acme/app', 'require' => array('acme/local-one' => '^2.0'));
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $lock = $this->buildLockFile(array('acme/local-one' => '2.5.3'));

        $config = DevConfigTransformer::apply($prodConfig, $repos, $lock)->getConfig();

        $this->assertSame('^2.0', $config['require']['acme/local-one'] ?? null);
        $this->assertSame(
            '2.5.3',
            $this->findVersionsMap($config, 'acme/local-one')['acme/local-one'] ?? null
        );
    }

    public function test_applyFallsBackToWildcardWithoutVersions() : void
    {
        $prodConfig = array('name' => 'acme/app', 'require' => array('php' => '>=8.1'));
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $lock = $this->buildLockFile(array());

        $result = DevConfigTransformer::apply($prodConfig, $repos, $lock);
        $config = $result->getConfig();

        $this->assertSame('*', $config['require']['acme/local-one'] ?? null);

        $entry = $this->findRepositoryEntry($config, 'acme/local-one');
        $this->assertArrayNotHasKey('versions', $entry['options']);

        $this->assertSame(DevTransformResult::SOURCE_NONE, $result->getVersionDerivations()[0]['source']);
    }

    public function test_applyRespectsRequireDevPlacement() : void
    {
        $prodConfig = array(
            'name' => 'acme/app',
            'require' => array('php' => '>=8.1'),
            'require-dev' => array('acme/local-dev' => '^1.0')
        );
        $repos = array(new LocalRepository('acme/local-dev', '../local-dev'));
        $lock = $this->buildLockFile(array('acme/local-dev' => '1.2.0'));

        $config = DevConfigTransformer::apply($prodConfig, $repos, $lock)->getConfig();

        $this->assertArrayNotHasKey('acme/local-dev', $config['require']);
        $this->assertSame('^1.0', $config['require-dev']['acme/local-dev'] ?? null);
    }

    public function test_applyReplacePruneAppendRepositories() : void
    {
        $prodConfig = array(
            'name' => 'acme/app',
            'require' => array('php' => '>=8.1'),
            'repositories' => array(
                array('type' => 'vcs', 'url' => 'git@github.com:acme/local-one.git'),
                array('type' => 'vcs', 'url' => 'git@github.com:acme/local-one.git'),
                array('type' => 'vcs', 'url' => 'git@github.com:acme/unrelated.git')
            )
        );
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $lock = $this->buildLockFile(array());

        $config = DevConfigTransformer::apply($prodConfig, $repos, $lock)->getConfig();

        $pathCount = 0;
        $vcsForLocalOne = 0;
        $unrelatedStillPresent = false;

        foreach($config['repositories'] as $repository) {
            if($repository['type'] === 'path' && $repository['url'] === '../local-one') {
                $pathCount++;
            }
            if($repository['type'] === 'vcs' && str_contains($repository['url'], 'local-one')) {
                $vcsForLocalOne++;
            }
            if($repository['url'] === 'git@github.com:acme/unrelated.git') {
                $unrelatedStillPresent = true;
            }
        }

        $this->assertSame(1, $pathCount, 'Exactly one path entry must remain for the switched package.');
        $this->assertSame(0, $vcsForLocalOne, 'Both duplicate VCS entries must be gone.');
        $this->assertTrue($unrelatedStillPresent, 'The unrelated repository entry must be untouched.');
    }

    public function test_applyUnderscoreHyphenAlias() : void
    {
        $prodConfig = array('name' => 'acme/app', 'require' => array('php' => '>=8.1'));
        $repos = array(new LocalRepository('acme/my_package', '../my-package', '1.0.0'));
        $lock = $this->buildLockFile(array());

        $config = DevConfigTransformer::apply($prodConfig, $repos, $lock)->getConfig();

        $versions = $this->findVersionsMap($config, 'acme/my_package');

        $this->assertSame(
            array('acme/my_package' => '1.0.0', 'acme/my-package' => '1.0.0'),
            $versions
        );
    }

    // endregion

    // region: _Tests - revert()

    /**
     * `revert(apply(P, repos, lock), P, repos, lock)` is value-equal
     * to `P` across a representative config table. `assertEquals()`
     * (not `assertSame()`) is used deliberately: the three-way merge
     * reconstructs associative sections key-by-key, so key order is
     * not guaranteed to match `P`'s own — only content equality is
     * the actual invariant.
     *
     * @param array<string,mixed> $snapshotConfig
     * @param LocalRepository[] $repos
     * @param array<string,string> $lockedVersions
     */
    #[DataProvider('provideRepresentativeConfigs')]
    public function test_revertIsInverseOfApply(array $snapshotConfig, array $repos, array $lockedVersions) : void
    {
        $lock = $this->buildLockFile($lockedVersions);

        $base = DevConfigTransformer::apply($snapshotConfig, $repos, $lock)->getConfig();
        $result = DevConfigTransformer::revert($base, $snapshotConfig, $repos, $lock);

        $this->assertEquals($snapshotConfig, $result->getEffectiveConfig());
        $this->assertFalse($result->hasOverriddenManagedEntries());
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:LocalRepository[],2:array<string,string>}>
     */
    public static function provideRepresentativeConfigs() : array
    {
        return array(
            'root-required local package, unrelated repo, extra/minimum-stability' => array(
                array(
                    'name' => 'acme/app',
                    'require' => array('php' => '>=8.1', 'acme/local-one' => '^2.0'),
                    'require-dev' => array('phpunit/phpunit' => '^10.0'),
                    'repositories' => array(
                        array('type' => 'vcs', 'url' => 'git@github.com:acme/unrelated.git')
                    ),
                    'extra' => array('foo' => 'bar'),
                    'minimum-stability' => 'dev'
                ),
                array(new LocalRepository('acme/local-one', '../local-one')),
                array('acme/local-one' => '2.3.0')
            ),
            'require-dev-only local package with version override, no repositories' => array(
                array(
                    'name' => 'acme/app2',
                    'require' => array('php' => '>=8.1'),
                    'require-dev' => array('acme/local-dev' => '^1.0', 'phpunit/phpunit' => '^10.0')
                ),
                array(new LocalRepository('acme/local-dev', '../local-dev', '9.9.9')),
                array()
            ),
            'no local packages at all' => array(
                array(
                    'name' => 'acme/app3',
                    'require' => array('php' => '>=8.1'),
                    'require-dev' => array(),
                    'repositories' => array(),
                    'extra' => array()
                ),
                array(),
                array()
            )
        );
    }

    public function test_revertCarriesBackAddedRemovedAndChangedPackages() : void
    {
        [$snapshotConfig, $repos, $lock] = $this->buildBaselineOne();
        $base = DevConfigTransformer::apply($snapshotConfig, $repos, $lock)->getConfig();

        $current = $base;
        $current['require']['php'] = '>=8.2';
        $current['require']['acme/new-dep'] = '^1.0';
        unset($current['require-dev']['phpunit/phpunit']);

        $result = DevConfigTransformer::revert($current, $snapshotConfig, $repos, $lock);
        $effective = $result->getEffectiveConfig();

        $this->assertSame('>=8.2', $effective['require']['php'] ?? null);
        $this->assertSame('^1.0', $effective['require']['acme/new-dep'] ?? null);
        $this->assertArrayNotHasKey('phpunit/phpunit', $effective['require-dev']);

        // The managed package is restored to the snapshot's value, untouched.
        $this->assertSame('^2.0', $effective['require']['acme/local-one'] ?? null);
        $this->assertFalse($result->hasOverriddenManagedEntries());
    }

    public function test_revertCarriesBackNonManagedKeyEdits() : void
    {
        [$snapshotConfig, $repos, $lock] = $this->buildBaselineOne();
        $base = DevConfigTransformer::apply($snapshotConfig, $repos, $lock)->getConfig();

        $current = $base;
        $current['extra'] = array('foo' => 'baz', 'new' => 'qux');
        $current['scripts'] = array('post-install-cmd' => 'echo hi');

        $effective = DevConfigTransformer::revert($current, $snapshotConfig, $repos, $lock)->getEffectiveConfig();

        $this->assertSame(array('foo' => 'baz', 'new' => 'qux'), $effective['extra'] ?? null);
        $this->assertSame(array('post-install-cmd' => 'echo hi'), $effective['scripts'] ?? null);
        $this->assertSame('acme/app', $effective['name'] ?? null);
    }

    public function test_revertCarriesBackRepositoryAdditionsAndRemovals() : void
    {
        [$snapshotConfig, $repos, $lock] = $this->buildBaselineOne();
        $base = DevConfigTransformer::apply($snapshotConfig, $repos, $lock)->getConfig();

        $current = $base;
        $current['repositories'] = array_values(array_filter(
            $current['repositories'],
            static fn(array $entry) : bool => $entry['url'] !== 'git@github.com:acme/unrelated.git'
        ));
        $current['repositories'][] = array('type' => 'vcs', 'url' => 'git@github.com:acme/newly-added.git');

        $effective = DevConfigTransformer::revert($current, $snapshotConfig, $repos, $lock)->getEffectiveConfig();

        $urls = array_column($effective['repositories'], 'url');

        $this->assertNotContains('git@github.com:acme/unrelated.git', $urls, 'The user-removed entry must stay removed.');
        $this->assertContains('git@github.com:acme/newly-added.git', $urls, 'The user-added entry must be carried back.');

        // The managed path entry never appears in the revert's repositories diff.
        foreach($effective['repositories'] as $entry) {
            $this->assertNotSame('../local-one', $entry['url'] ?? null);
        }
    }

    public function test_revertRestoresManagedEntriesAndReportsOverrides() : void
    {
        [$snapshotConfig, $repos, $lock] = $this->buildBaselineOne();
        $base = DevConfigTransformer::apply($snapshotConfig, $repos, $lock)->getConfig();

        $current = $base;
        $current['require']['acme/local-one'] = '^3.0';

        $result = DevConfigTransformer::revert($current, $snapshotConfig, $repos, $lock);

        $this->assertSame('^2.0', $result->getEffectiveConfig()['require']['acme/local-one'] ?? null);
        $this->assertTrue($result->hasOverriddenManagedEntries());

        $overrides = $result->getOverriddenManagedEntries();
        $this->assertSame('require', $overrides[0]['section']);
        $this->assertSame('acme/local-one', $overrides[0]['packageName']);
        $this->assertSame('^2.0', $overrides[0]['snapshotValue']);
        $this->assertSame('^3.0', $overrides[0]['currentValue']);
    }

    public function test_revertResultCarriesEffectiveConfigAndOverridesOnly() : void
    {
        [$snapshotConfig, $repos, $lock] = $this->buildBaselineOne();
        $base = DevConfigTransformer::apply($snapshotConfig, $repos, $lock)->getConfig();

        $unedited = DevConfigTransformer::revert($base, $snapshotConfig, $repos, $lock);
        $this->assertSame(array(), $unedited->getOverriddenManagedEntries());
        $this->assertFalse($unedited->hasOverriddenManagedEntries());

        $edited = $base;
        $edited['require']['acme/local-one'] = '^9.0';
        $withOverride = DevConfigTransformer::revert($edited, $snapshotConfig, $repos, $lock);

        $this->assertCount(1, $withOverride->getOverriddenManagedEntries());
        $this->assertTrue($withOverride->hasOverriddenManagedEntries());
        $this->assertArrayHasKey('require', $withOverride->getEffectiveConfig());
    }

    // endregion

    // region: Support methods

    /**
     * @return array{0:array<string,mixed>,1:LocalRepository[],2:LockFile}
     */
    private function buildBaselineOne() : array
    {
        $snapshotConfig = array(
            'name' => 'acme/app',
            'require' => array('php' => '>=8.1', 'acme/local-one' => '^2.0'),
            'require-dev' => array('phpunit/phpunit' => '^10.0'),
            'repositories' => array(
                array('type' => 'vcs', 'url' => 'git@github.com:acme/unrelated.git')
            ),
            'extra' => array('foo' => 'bar')
        );
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $lock = $this->buildLockFile(array('acme/local-one' => '2.3.0'));

        return array($snapshotConfig, $repos, $lock);
    }

    /**
     * @param array<string,string> $lockedVersions
     */
    private function buildLockFile(array $lockedVersions) : LockFile
    {
        $configFile = new ConfigFile($this->workRoot . '/' . uniqid('composer_', true) . '.json');
        $configFile->putData(array('name' => 'acme/prod-placeholder'));

        $packages = array();
        foreach($lockedVersions as $packageName => $version) {
            $packages[] = array('name' => $packageName, 'version' => $version);
        }

        file_put_contents(
            $configFile->getLockFile()->getPath(),
            json_encode(array('packages' => $packages), JSON_THROW_ON_ERROR)
        );

        return $configFile->getLockFile();
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,string>
     */
    private function findVersionsMap(array $config, string $packageName) : array
    {
        return $this->findRepositoryEntry($config, $packageName)['options']['versions'] ?? array();
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function findRepositoryEntry(array $config, string $packageName) : array
    {
        foreach($config['repositories'] ?? array() as $repository) {
            if(($repository['url'] ?? null) === $this->pathForPackage($packageName)) {
                return $repository;
            }
        }

        $this->fail('No repository entry found for package: ' . $packageName);
    }

    private function pathForPackage(string $packageName) : string
    {
        return match($packageName) {
            'acme/local-one' => '../local-one',
            'acme/local-dev' => '../local-dev',
            'acme/my_package' => '../my-package',
            default => '../' . $packageName
        };
    }

    // endregion
}
