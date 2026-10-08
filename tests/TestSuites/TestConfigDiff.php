<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\State\ConfigChange;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\State\ConfigChangeSet;
use Mistralys\ComposerSwitcher\State\LocalRepository;
use Mistralys\ComposerSwitcher\Tests\TestClasses\FixtureFileSystem;
use Mistralys\ComposerSwitcher\Utils\ConfigDiff;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\DevConfigTransformer;
use Mistralys\ComposerSwitcher\Utils\LockFile;
use PHPUnit\Framework\TestCase;

/**
 * Tier 1 suite for {@see ConfigDiff}, {@see ConfigChange} and
 * {@see ConfigChangeSet}: the structural diff engine and immutable
 * change-record types a preview, a confirmation prompt and
 * `describe()` all read from the same source.
 *
 * {@see DevConfigTransformer::makeOriginClassifier()} (the real
 * classifier these types are built to consume) is exercised directly
 * here rather than a stub, since attaching the correct
 * {@see ConfigChangeOrigin} per change is itself part of this WP's
 * acceptance criteria — a stub classifier would test nothing about
 * whether the two integrate correctly.
 *
 * This value object/differ pairing carries no filesystem or process
 * dependencies, so it is tested directly against {@see TestCase}.
 */
final class TestConfigDiff extends TestCase
{
    private string $workRoot;

    protected function setUp() : void
    {
        parent::setUp();

        $this->workRoot = sys_get_temp_dir() . '/composer-local-switcher-config-diff-test-' . uniqid('', true);

        mkdir($this->workRoot, 0777, true);
    }

    protected function tearDown() : void
    {
        parent::tearDown();

        FixtureFileSystem::removeDirectory($this->workRoot);
    }

    // region: _Tests - ConfigDiff::between()

    public function test_leafPathChangeForNestedAssociativeStructure() : void
    {
        $before = array('require' => array('php' => '>=8.1'));
        $after = array('require' => array('php' => '>=8.2'));

        $changes = ConfigDiff::between($before, $after, $this->alwaysCarriedBackClassifier());

        $this->assertCount(1, $changes);
        $this->assertSame(array('require', 'php'), $changes[0]->getPath());
        $this->assertSame('>=8.1', $changes[0]->getBefore());
        $this->assertSame('>=8.2', $changes[0]->getAfter());
        $this->assertSame(ConfigChange::KIND_CHANGED, $changes[0]->getKind());
    }

    public function test_addedAndRemovedLeafKeys() : void
    {
        $before = array('require' => array('acme/old' => '^1.0'));
        $after = array('require' => array('acme/new' => '^2.0'));

        $changes = ConfigDiff::between($before, $after, $this->alwaysCarriedBackClassifier());

        $kinds = array_map(static fn(ConfigChange $c) : string => $c->getKind(), $changes);
        sort($kinds);

        $this->assertSame(array(ConfigChange::KIND_ADDED, ConfigChange::KIND_REMOVED), $kinds);
    }

    public function test_addedTopLevelKey() : void
    {
        $before = array('name' => 'acme/app');
        $after = array('name' => 'acme/app', 'extra' => array('foo' => 'bar'));

        $changes = ConfigDiff::between($before, $after, $this->alwaysCarriedBackClassifier());

        $this->assertCount(1, $changes);
        $this->assertSame(array('extra'), $changes[0]->getPath());
        $this->assertSame(ConfigChange::KIND_ADDED, $changes[0]->getKind());
        $this->assertNull($changes[0]->getBefore());
        $this->assertSame(array('foo' => 'bar'), $changes[0]->getAfter());
    }

    public function test_removedTopLevelKey() : void
    {
        $before = array('name' => 'acme/app', 'extra' => array('foo' => 'bar'));
        $after = array('name' => 'acme/app');

        $changes = ConfigDiff::between($before, $after, $this->alwaysCarriedBackClassifier());

        $this->assertCount(1, $changes);
        $this->assertSame(ConfigChange::KIND_REMOVED, $changes[0]->getKind());
        $this->assertSame(array('foo' => 'bar'), $changes[0]->getBefore());
        $this->assertNull($changes[0]->getAfter());
    }

    public function test_identicalConfigsProduceNoChanges() : void
    {
        $config = array('name' => 'acme/app', 'require' => array('php' => '>=8.1'));

        $changes = ConfigDiff::between($config, $config, $this->alwaysCarriedBackClassifier());

        $this->assertSame(array(), $changes);
    }

    /**
     * List entries (`repositories`) are compared item by item, by
     * value: an entry with no value-equal match becomes a whole-item
     * Added or Removed change, never a Changed one.
     */
    public function test_repositoriesListComparedByValueAsAddedOrRemoved() : void
    {
        $before = array('repositories' => array(
            array('type' => 'vcs', 'url' => 'git@github.com:acme/kept.git'),
            array('type' => 'vcs', 'url' => 'git@github.com:acme/removed.git')
        ));
        $after = array('repositories' => array(
            array('type' => 'vcs', 'url' => 'git@github.com:acme/kept.git'),
            array('type' => 'vcs', 'url' => 'git@github.com:acme/added.git')
        ));

        $changes = ConfigDiff::between($before, $after, $this->alwaysCarriedBackClassifier());

        $this->assertCount(2, $changes);

        $kinds = array_map(static fn(ConfigChange $c) : string => $c->getKind(), $changes);
        sort($kinds);
        $this->assertSame(array(ConfigChange::KIND_ADDED, ConfigChange::KIND_REMOVED), $kinds);

        foreach($changes as $change) {
            if($change->getKind() === ConfigChange::KIND_ADDED) {
                $this->assertSame('git@github.com:acme/added.git', $change->getAfter()['url']);
            } else {
                $this->assertSame('git@github.com:acme/removed.git', $change->getBefore()['url']);
            }
        }
    }

    public function test_identicalRepositoriesListProducesNoChanges() : void
    {
        $repositories = array(
            array('type' => 'vcs', 'url' => 'git@github.com:acme/one.git')
        );

        $changes = ConfigDiff::between(
            array('repositories' => $repositories),
            array('repositories' => $repositories),
            $this->alwaysCarriedBackClassifier()
        );

        $this->assertSame(array(), $changes);
    }

    // endregion

    // region: _Tests - ConfigChange::isVersionChange()

    public function test_isVersionChangeTrueForRequirePath() : void
    {
        $changes = ConfigDiff::between(
            array('require' => array('acme/pkg' => '^1.0')),
            array('require' => array('acme/pkg' => '^2.0')),
            $this->alwaysCarriedBackClassifier()
        );

        $this->assertTrue($changes[0]->isVersionChange());
    }

    public function test_isVersionChangeTrueForRequireDevPath() : void
    {
        $changes = ConfigDiff::between(
            array('require-dev' => array('acme/pkg' => '^1.0')),
            array('require-dev' => array('acme/pkg' => '^2.0')),
            $this->alwaysCarriedBackClassifier()
        );

        $this->assertTrue($changes[0]->isVersionChange());
    }

    public function test_isVersionChangeFalseForNonVersionTopLevelKey() : void
    {
        $changes = ConfigDiff::between(
            array('extra' => array('foo' => 'bar')),
            array('extra' => array('foo' => 'baz')),
            $this->alwaysCarriedBackClassifier()
        );

        $this->assertFalse($changes[0]->isVersionChange());
    }

    public function test_isVersionChangeTrueForRepositoryEntryCarryingVersionsAlias() : void
    {
        $changes = ConfigDiff::between(
            array('repositories' => array()),
            array('repositories' => array(
                array('type' => 'path', 'url' => '../local-one', 'options' => array('symlink' => true, 'versions' => array('acme/local-one' => '1.2.3')))
            )),
            $this->alwaysCarriedBackClassifier()
        );

        $this->assertTrue($changes[0]->isVersionChange());
    }

    public function test_isVersionChangeFalseForRepositoryEntryWithoutVersionsAlias() : void
    {
        $changes = ConfigDiff::between(
            array('repositories' => array()),
            array('repositories' => array(
                array('type' => 'vcs', 'url' => 'git@github.com:acme/plain.git')
            )),
            $this->alwaysCarriedBackClassifier()
        );

        $this->assertFalse($changes[0]->isVersionChange());
    }

    // endregion

    // region: _Tests - Origin classification (via DevConfigTransformer::makeOriginClassifier())

    public function test_managedPackageChangeIsLocalSwitchOrigin() : void
    {
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $classifier = DevConfigTransformer::makeOriginClassifier($repos);

        $changes = ConfigDiff::between(
            array('require' => array('acme/local-one' => '*')),
            array('require' => array('acme/local-one' => '2.3.0')),
            $classifier
        );

        $this->assertSame(ConfigChangeOrigin::LocalSwitch, $changes[0]->getOrigin());
    }

    public function test_nonManagedPackageChangeIsCarriedBackOrigin() : void
    {
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $classifier = DevConfigTransformer::makeOriginClassifier($repos);

        $changes = ConfigDiff::between(
            array('require' => array('php' => '>=8.1')),
            array('require' => array('php' => '>=8.2')),
            $classifier
        );

        $this->assertSame(ConfigChangeOrigin::CarriedBack, $changes[0]->getOrigin());
    }

    public function test_managedPathRepositoryEntryIsLocalSwitchOrigin() : void
    {
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));
        $classifier = DevConfigTransformer::makeOriginClassifier($repos);

        $changes = ConfigDiff::between(
            array('repositories' => array()),
            array('repositories' => array(
                array('type' => 'path', 'url' => '../local-one', 'options' => array('symlink' => true))
            )),
            $classifier
        );

        $this->assertSame(ConfigChangeOrigin::LocalSwitch, $changes[0]->getOrigin());
    }

    public function test_overriddenManagedPackageIsDiscardedOrigin() : void
    {
        [$snapshotConfig, $repos, $lock] = $this->buildBaselineWithOverride();
        $base = DevConfigTransformer::apply($snapshotConfig, $repos, $lock)->getConfig();

        $current = $base;
        $current['require']['acme/local-one'] = '^9.0';

        $revertResult = DevConfigTransformer::revert($current, $snapshotConfig, $repos, $lock);
        $classifier = DevConfigTransformer::makeOriginClassifier($repos, $revertResult);

        $changes = ConfigDiff::between($current, $revertResult->getEffectiveConfig(), $classifier);

        $packageChange = $this->findChangeForPackage($changes, 'acme/local-one');
        $this->assertSame(ConfigChangeOrigin::Discarded, $packageChange->getOrigin());
    }

    // endregion

    // region: _Tests - ConfigChangeSet

    public function test_emptySetReportsIsEmpty() : void
    {
        $set = new ConfigChangeSet(array(), array());

        $this->assertTrue($set->isEmpty());
        $this->assertFalse($set->hasPermanentChanges());
        $this->assertSame(array(), $set->getProdChangedKeys());
        $this->assertSame(array(), $set->getProdChangedPackages());
    }

    public function test_prodChangedKeysAndPackagesDerivedFromProdConfigSection() : void
    {
        $prodConfig = ConfigDiff::between(
            array('require' => array('php' => '>=8.1'), 'extra' => array('foo' => 'bar')),
            array('require' => array('php' => '>=8.1', 'acme/new' => '^1.0'), 'extra' => array('foo' => 'baz')),
            $this->alwaysCarriedBackClassifier()
        );

        $set = new ConfigChangeSet(array(), $prodConfig);

        $this->assertFalse($set->isEmpty());
        $this->assertTrue($set->hasPermanentChanges());

        $keys = $set->getProdChangedKeys();
        sort($keys);
        $this->assertSame(array('extra', 'require'), $keys);

        $this->assertSame(array('acme/new'), $set->getProdChangedPackages());
    }

    public function test_composerJsonSectionDoesNotAffectProdChangedViews() : void
    {
        $composerJson = ConfigDiff::between(
            array('require' => array()),
            array('require' => array('acme/local-one' => '*')),
            $this->alwaysCarriedBackClassifier()
        );

        $set = new ConfigChangeSet($composerJson, array());

        $this->assertFalse($set->isEmpty());
        $this->assertFalse($set->hasPermanentChanges());
        $this->assertSame(array(), $set->getProdChangedKeys());
        $this->assertSame(array(), $set->getProdChangedPackages());
    }

    // endregion

    // region: Support methods

    private function alwaysCarriedBackClassifier() : callable
    {
        return static fn(array $path, mixed $before, mixed $after) : ConfigChangeOrigin => ConfigChangeOrigin::CarriedBack;
    }

    /**
     * @param ConfigChange[] $changes
     */
    private function findChangeForPackage(array $changes, string $packageName) : ConfigChange
    {
        foreach($changes as $change) {
            $path = $change->getPath();

            if(($path[1] ?? null) === $packageName) {
                return $change;
            }
        }

        $this->fail('No change found for package: ' . $packageName);
    }

    /**
     * @return array{0:array<string,mixed>,1:LocalRepository[],2:LockFile}
     */
    private function buildBaselineWithOverride() : array
    {
        $configFile = new ConfigFile($this->workRoot . '/composer.json');
        $configFile->putData(array('name' => 'acme/prod-placeholder'));
        file_put_contents($configFile->getLockFile()->getPath(), json_encode(array('packages' => array())));

        $snapshotConfig = array(
            'name' => 'acme/app',
            'require' => array('php' => '>=8.1', 'acme/local-one' => '^2.0')
        );
        $repos = array(new LocalRepository('acme/local-one', '../local-one'));

        return array($snapshotConfig, $repos, $configFile->getLockFile());
    }

    // endregion
}
