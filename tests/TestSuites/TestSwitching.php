<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\FileOperation;
use Mistralys\ComposerSwitcher\State\SwitchMessage;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;
use Mistralys\ComposerSwitcher\Utils\ConfigFile;
use Mistralys\ComposerSwitcher\Utils\LockFile;

final class TestSwitching extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * No switch has been made yet: The status file does not exist.
     */
    public function test_initialState() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();
        $status = $switcher->getStatus();

        $this->assertFalse($status->exists());
        $this->assertNull($status->getDate());
        $this->assertNull($status->getMode());
        $this->assertFalse($status->isDEV());
        $this->assertFalse($status->isPROD());
        $this->assertEmpty($status->getData());

        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_DEV)->exists());
        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_PROD)->exists());
    }

    /**
     * `switchUpdate()` in the INITIAL state (no switch has ever been
     * run) is the PROD/INITIAL→PROD decision-table row (per this plan's
     * WP-008 "Reshape the switching core" notes) — it reports mode
     * `prod`, has no file effects on `composer-prod.*` (that is a
     * transient DEV-only snapshot under v3), and still creates the
     * status/flag files like any other switch.
     */
    public function test_switchUpdateInInitialStateRoutesToProdToProd() : void
    {
        $switcher = $this->createSwitcher();

        $this->assertFalse($switcher->getStatus()->exists());
        $this->assertFalse($switcher->getProdFile()->exists());

        $outcome = $switcher->switchUpdate();

        $this->assertSame(ConfigSwitcher::MODE_PROD, $outcome->getMode());
        $this->assertFalse($outcome->isDryRun());

        $this->assertTrue($switcher->getStatus()->exists());
        $this->assertTrue($switcher->getStatus()->isPROD());
        $this->assertFalse($switcher->getProdFile()->exists());
    }

    /**
     * No switch has been made yet, so switching to DEV
     * has the following tasks:
     *
     * - Create the status file, marking the mode as DEV.
     * - Snapshot `composer.json`/`.lock` into the transient
     *   `composer-prod.json`/`.lock`.
     * - Rewrite `composer.json`'s repositories for the local packages.
     *
     * Expected file structure after this operation:
     *
     * - `composer.json` (DEV configuration, with DEV repositories)
     * - `composer.lock` (unchanged — it stays the PROD lock throughout
     *   the DEV session; v3 never backs it up to a separate DEV lock)
     * - `composer/composer-prod.json` (snapshot of the original `composer.json`)
     * - `composer/composer-prod.lock` (snapshot of the original `composer.lock`)
     */
    public function test_initialSwitchToDEV() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();

        $switcher->switchToDevelopment();

        $status = $switcher->getStatus();
        $this->assertTrue($status->exists());
        $this->assertNotNull($status->getDate());
        $this->assertEquals(ConfigSwitcher::MODE_DEV, $status->getMode());
        $this->assertTrue($status->isDEV());
        $this->assertFalse($status->isPROD());

        $this->assertTrue($switcher->getProdFile()->exists());
        $this->assertTrue($switcher->getProdFile()->getLockFile()->exists());
        $this->assertTrue($switcher->getMainFile()->getLockFile()->exists());

        $this->assertConfigHasExpectedPaths($switcher);
        $this->assertLockFileIsPROD($switcher->getProdFile());
        $this->assertLockFileIsPROD($switcher->getMainFile());
        $this->assertFlagIsDEV($switcher);
    }

    /**
     * No switch has been made yet: under v3, PROD/INITIAL→PROD has no
     * file effects on `composer-prod.*` at all — that file is a
     * transient DEV-only snapshot now, never a committed-style
     * production baseline. Only the status and flag files are created.
     */
    public function test_initialSwitchToPROD() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();

        $switcher->switchToProduction();

        $status = $switcher->getStatus();
        $this->assertTrue($status->exists());
        $this->assertNotNull($status->getDate());
        $this->assertEquals(ConfigSwitcher::MODE_PROD, $status->getMode());
        $this->assertTrue($status->isPROD());
        $this->assertFalse($status->isDEV());

        $this->assertFalse($switcher->getProdFile()->exists());
        $this->assertFalse($switcher->getProdFile()->getLockFile()->exists());

        $this->assertFlagIsPROD($switcher);
    }

    /**
     * A DEV→PROD switch restores `composer.lock` from the snapshot
     * (`composer-prod.lock`), overwriting whatever the user's own
     * tooling wrote to `composer.lock` while in DEV — v3 has no
     * separate DEV lock backup file to restore from instead.
     */
    public function test_switchDEVToPROD() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        // Simulate the user's own `composer update` producing a new
        // DEV lock file.
        $this->assertNotFalse(file_put_contents(
            $switcher->getMainFile()->getLockFile()->getPath(),
            'DEV'
        ));

        $switcher->switchToProduction();

        $this->assertLockFileIsPROD($switcher->getMainFile());
        $this->assertFlagIsPROD($switcher);
    }

    /**
     * A missing `composer.lock` no longer aborts the switch: the config
     * rewrite, status file, and flag file are all still produced, and
     * {@see ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND} is recorded as
     * a warning rather than the sole side effect of an early return.
     */
    public function test_switchWithoutLockFileCompletes() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getMainFile()->getLockFile()->getPath());

        $switcher->switchToDevelopment();

        $status = $switcher->getStatus();
        $this->assertTrue($status->exists());
        $this->assertTrue($status->isDEV());

        $this->assertFlagIsDEV($switcher);
        $this->assertConfigHasExpectedPaths($switcher);

        $this->assertContains(
            ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * A DEV->PROD switch with no `composer-prod.lock` backup to
     * restore from removes `composer.lock` instead (forcing
     * re-creation via the planned full `update`), and records
     * {@see ConfigSwitcher::MESSAGE_PROD_LOCK_MISSING}.
     *
     * The snapshot lock is legitimately absent only when the main lock
     * was already `Missing` *before* the snapshot was taken — deleting
     * it afterward would instead trip the snapshot-modified tamper
     * check ({@see ConfigSwitcher::MESSAGE_SNAPSHOT_MODIFIED}) and
     * block the switch entirely, which is covered separately.
     */
    public function test_prodSwitchWithoutProdLockDeletesMainLock() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getMainFile()->getLockFile()->getPath());

        $switcher->switchToDevelopment();

        $this->assertFalse($switcher->getProdFile()->getLockFile()->exists());

        $switcher->switchToProduction();

        $this->assertFalse($switcher->getMainFile()->getLockFile()->exists());
        $this->assertFlagIsPROD($switcher);

        $this->assertContains(
            ConfigSwitcher::MESSAGE_PROD_LOCK_MISSING,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * Deleting the snapshot's lock backup after it was taken (rather
     * than before, as in {@see self::test_prodSwitchWithoutProdLockDeletesMainLock()})
     * is detected as a modified snapshot and blocks the switch
     * entirely — `composer.lock` is left untouched rather than being
     * force-deleted.
     */
    public function test_deletingSnapshotLockAfterTheFactBlocksTheSwitch() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $this->assertTrue($switcher->getProdFile()->getLockFile()->exists());
        unlink($switcher->getProdFile()->getLockFile()->getPath());

        $mainLockContentBefore = $switcher->getMainFile()->getLockFile()->getContent();

        $outcome = $switcher->switchToProduction();

        $this->assertTrue($outcome->isBlocked());
        $this->assertSame($mainLockContentBefore, $switcher->getMainFile()->getLockFile()->getContent());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_SNAPSHOT_MODIFIED,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * A `Stale` main lock (content-hash mismatch against the current
     * `composer.json`) blocks a PROD/INITIAL→DEV switch entirely — no
     * snapshot is taken and `composer.json` stays untouched.
     */
    public function test_prodToDevBlockedOnStaleMainLock() : void
    {
        $switcher = $this->createSwitcher();

        file_put_contents(
            $switcher->getMainFile()->getLockFile()->getPath(),
            json_encode(array('content-hash' => 'deliberately-wrong-hash', 'packages' => array()), JSON_THROW_ON_ERROR)
        );

        $mainDataBefore = $switcher->getMainFile()->getData();

        $outcome = $switcher->switchToDevelopment();

        $this->assertTrue($outcome->isBlocked());
        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
        $this->assertFalse($switcher->getProdFile()->exists());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_PROD_LOCK_OUTDATED,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * A `Missing` main lock does not block a PROD/INITIAL→DEV switch —
     * it completes in full and plans a full `update` (no package names)
     * rather than the usual partial one.
     */
    public function test_prodToDevWithMissingLockPlansFullUpdate() : void
    {
        $switcher = $this->createSwitcher();
        unlink($switcher->getMainFile()->getLockFile()->getPath());

        $outcome = $switcher->switchToDevelopment();

        $this->assertFalse($outcome->isBlocked());
        $this->assertNotNull($outcome->getComposerCommand());
        $this->assertSame(array('update'), $outcome->getComposerCommand()->getArguments());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_NO_LOCK_FILE_FOUND,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * A missing production snapshot blocks a DEV→PROD switch with no
     * file effects — distinct from a modified snapshot, which is
     * covered by {@see self::test_deletingSnapshotLockAfterTheFactBlocksTheSwitch()}.
     */
    public function test_devToProdBlockedOnMissingSnapshot() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $switcher->getProdFile()->delete();
        $switcher->getProdFile()->getLockFile()->delete();

        $mainDataBefore = $switcher->getMainFile()->getData();

        $outcome = $switcher->switchToProduction();

        $this->assertTrue($outcome->isBlocked());
        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_SNAPSHOT_MISSING,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * An edit to the production snapshot (`composer-prod.json`) itself
     * blocks a DEV→DEV refresh, with no file effects — the three-way
     * revert can no longer trust a tampered base/target.
     */
    public function test_devRefreshBlockedOnModifiedSnapshot() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $prodConfig = $switcher->getProdFile()->getData();
        $prodConfig['extra']['tampered'] = true;
        $switcher->getProdFile()->putData($prodConfig);

        $mainDataBefore = $switcher->getMainFile()->getData();

        $outcome = $switcher->switchToDevelopment();

        $this->assertTrue($outcome->isBlocked());
        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_SNAPSHOT_MODIFIED,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * A DEV→DEV refresh (here via `switchUpdate()`) with no repository
     * delta must not rewrite `composer.json` at all when the
     * three-way-merged result is structurally identical to what is
     * already on disk — even when that disk content uses non-canonical
     * formatting `ConfigFile::putData()` would never reproduce byte for
     * byte, and even after the user's own `composer require` added a
     * non-managed package directly. No write operation for
     * `composer.json` is recorded, and the manual edit survives.
     */
    public function test_devRefreshWithNoDeltaLeavesComposerJsonByteIdenticalAndKeepsUserEdits() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $config['require']['acme/manually-required'] = '^1.0';

        // Non-canonical formatting: a two-space indent instead of
        // ConfigFile::putData()'s four-space JSON_PRETTY_PRINT output.
        $nonCanonicalJson = str_replace(
            '    ',
            '  ',
            (string)json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        file_put_contents($switcher->getMainFile()->getPath(), $nonCanonicalJson);

        $bytesBefore = file_get_contents($switcher->getMainFile()->getPath());

        $outcome = $switcher->switchUpdate();

        $bytesAfter = file_get_contents($switcher->getMainFile()->getPath());

        $this->assertSame(
            $bytesBefore,
            $bytesAfter,
            'composer.json must stay byte-for-byte unchanged when the refresh has no delta.'
        );

        $mainFilePath = $switcher->getMainFile()->getPath();
        $writeOperations = array_filter(
            $outcome->getOperations(),
            static fn(FileOperation $operation) : bool => $operation->getType() === FileOperation::TYPE_WRITE
                && $operation->getTargetPath() === $mainFilePath
        );
        $this->assertEmpty($writeOperations, 'No write operation must be recorded for composer.json.');

        $configAfter = $switcher->getMainFile()->getData();
        $this->assertSame('^1.0', $configAfter['require']['acme/manually-required'] ?? null);
    }

    /**
     * Cleans up a leftover v2-era `local-repositories.lock` file on
     * every switch, regardless of direction, emitting
     * {@see ConfigSwitcher::MESSAGE_LEGACY_FILES_FOUND} without ever
     * throwing.
     */
    public function test_legacyDevLockFileIsCleanedUpOnAnySwitch() : void
    {
        $switcher = $this->createSwitcher();

        $legacyLockPath = $switcher->getDevFile()->getLockFile()->getPath();
        file_put_contents($legacyLockPath, 'legacy dev lock content');

        $outcome = $switcher->switchToProduction();

        $this->assertFileDoesNotExist($legacyLockPath);
        $this->assertContains(
            ConfigSwitcher::MESSAGE_LEGACY_FILES_FOUND,
            $this->getMessageCodes($switcher)
        );
        $this->assertFalse($outcome->isBlocked());
    }

    /**
     * A committed-style `composer-prod.json` found while in PROD mode
     * (a v2-era leftover) is deleted outright on a PROD/INITIAL→PROD
     * switch — it has no reason to exist outside an active DEV session
     * under v3.
     */
    public function test_legacyCommittedProdConfigIsDeletedOnProdSwitch() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();
        $this->bootstrapProdSnapshot($switcher);

        $this->assertTrue($switcher->getProdFile()->exists());

        $outcome = $switcher->switchToProduction();

        $this->assertFalse($switcher->getProdFile()->exists());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_LEGACY_FILES_FOUND,
            $this->getMessageCodes($switcher)
        );
        $this->assertFalse($outcome->isBlocked());
    }

    /**
     * PROD/INITIAL→PROD plans `install` only when nothing has ever been
     * installed yet (no `vendor/composer/installed.json`); with one
     * already present, it plans no command at all.
     */
    public function test_prodToProdPlansInstallOnlyWhenNothingInstalled() : void
    {
        $switcher = $this->createSwitcher();

        $outcomeWithoutInstalled = $switcher->switchToProduction();
        $this->assertNotNull($outcomeWithoutInstalled->getComposerCommand());
        $this->assertSame(array('install'), $outcomeWithoutInstalled->getComposerCommand()->getArguments());

        $installedJsonPath = $this->testTarget . '/vendor/composer/installed.json';
        mkdir(dirname($installedJsonPath), 0777, true);
        file_put_contents($installedJsonPath, json_encode(array('packages' => array()), JSON_THROW_ON_ERROR));

        $outcomeWithInstalled = $switcher->switchToProduction();
        $this->assertNull($outcomeWithInstalled->getComposerCommand());
        $this->assertContains(
            ConfigSwitcher::MESSAGE_ALREADY_INSTALLED,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * `vendor/composer/installed.json` existing is not, by itself,
     * enough for PROD/INITIAL→PROD to consider the dependencies
     * installed: if it still shows a configured local package
     * installed from a path repository (a stale/orphaned DEV-session
     * install left behind, e.g. by a dry run or a manual flag-file
     * reset), the installed state is `Pending`, not `Matches`, and
     * `install` must still be planned so the mismatch self-heals.
     */
    public function test_prodToProdPlansInstallWhenInstalledJsonShowsStalePathInstall() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $installedJsonPath = $this->testTarget . '/vendor/composer/installed.json';
        mkdir(dirname($installedJsonPath), 0777, true);
        file_put_contents($installedJsonPath, json_encode(array(
            'packages' => array(
                array('name' => 'mistralys/application_framework', 'version' => 'dev-main', 'dist' => array('type' => 'path'))
            )
        ), JSON_THROW_ON_ERROR));

        $outcome = $switcher->switchToProduction();

        $this->assertNotNull($outcome->getComposerCommand());
        $this->assertSame(array('install'), $outcome->getComposerCommand()->getArguments());
        $this->assertNotContains(
            ConfigSwitcher::MESSAGE_ALREADY_INSTALLED,
            $this->getMessageCodes($switcher)
        );
    }

    /**
     * A PROD→PROD switch (`switch_planProdToProd()`) has no file
     * effects at all under v3 — not even when `composer.json` itself
     * has been modified since the previous switch. The reconcile
     * machinery this used to delegate into (two committed, editable
     * copies of the config) no longer exists; `composer.json` is now
     * the single source of truth in PROD mode.
     */
    public function test_prodToProdHasNoFileEffectsEvenWhenMainConfigChanges() : void
    {
        //$this->setKeepWorkFiles();

        $switcher = $this->createSwitcher();
        $switcher->switchToProduction();

        $config = $switcher->getMainFile()->getData();
        $config['require']['php'] = '>=8.0';
        $switcher->getMainFile()->putData($config);

        $mainDataBefore = $switcher->getMainFile()->getData();
        $mainLockContentBefore = $switcher->getMainFile()->getLockFile()->getContent();

        $switcher->switchToProduction();

        $this->assertSame($mainDataBefore, $switcher->getMainFile()->getData());
        $this->assertSame($mainLockContentBefore, $switcher->getMainFile()->getLockFile()->getContent());
        $this->assertFalse($switcher->getProdFile()->exists());
    }

    public function test_specificPackageVersion() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $this->assertConfigHasCustomVersion($switcher, 'mistralys/application-utils-core', '2.3.14');
    }

    /**
     * The hyphen alias in `options.versions` (added alongside the
     * package name for underscore package names, so Composer can
     * resolve either spelling of the repository URL) must never be
     * added for a package name that already uses hyphens throughout —
     * there is no alternate spelling to alias.
     */
    public function test_hyphenAliasOmittedForNonUnderscorePackage() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();

        $versions = $this->findVersionsForPackage($config, 'mistralys/application-utils-core');

        $this->assertSame(
            array('mistralys/application-utils-core' => '2.3.14'),
            $versions
        );
    }

    /**
     * `DevConfigTransformer::apply()` writes the `version` field from
     * a local repository entry into the path repository's
     * `options.versions` alias verbatim — no validation of the version
     * string happens there, so a malformed value is the caller's
     * responsibility. The root `require` constraint, however, is no
     * longer overwritten with that literal value: since the package
     * is already root-required in the PROD baseline (`>=3.1.9`) and an
     * alias was derived (the override), the constraint is kept from
     * PROD — aliasing the symlinked package to the override is what
     * satisfies it, rather than replacing it outright. This
     * characterises that the switcher itself never rejects or
     * silently corrects a malformed override, while also no longer
     * needing to loosen the root constraint to accommodate one.
     */
    public function test_malformedVersionIsWrittenVerbatim() : void
    {
        $malformedVersion = 'not-a-version';

        $devFile = new ConfigFile($this->testTarget . '/composer/local-repositories.json');
        $devConfig = $devFile->getData();

        foreach($devConfig['local-repositories'] as &$repo)
        {
            if($repo['package-name'] === 'mistralys/application-utils') {
                $repo['version'] = $malformedVersion;
            }
        }
        unset($repo);

        $devFile->putData($devConfig);

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();

        $this->assertSame('>=3.1.9', $config['require']['mistralys/application-utils'] ?? null);

        $versions = $this->findVersionsForPackage($config, 'mistralys/application-utils');

        $this->assertSame($malformedVersion, $versions['mistralys/application-utils'] ?? null);
    }

    /**
     * After a DEV switch, the VCS repository entry matching a
     * switched package must be replaced by a single path entry.
     */
    public function test_devSwitchPrunesStaleVCSRepository() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $repos = $config['repositories'];

        foreach($repos as $repository)
        {
            if(!isset($repository['type'], $repository['url'])) {
                continue;
            }

            // No VCS entry should remain for any switched package.
            if($repository['type'] === 'vcs') {
                $this->assertFalse(
                    stripos($repository['url'], 'application-framework') !== false
                    || stripos($repository['url'], 'application-utils-core') !== false
                    || stripos($repository['url'], 'application-utils') !== false,
                    'Stale VCS repository entry found after DEV switch: ' . $repository['url']
                );
            }
        }

        $this->assertConfigHasExpectedPaths($switcher);
    }

    /**
     * When the PROD config has two VCS entries matching the same
     * switched package, the DEV switch must replace the first and
     * remove the second — leaving exactly one path entry.
     */
    public function test_devSwitchPrunesMultipleMatchingRepositories() : void
    {
        // Add a duplicate VCS entry for application-framework.
        $mainFile = new ConfigFile($this->testTarget . '/composer.json');
        $config = $mainFile->getData();
        $config['repositories'][] = array(
            'type' => 'vcs',
            'url' => 'git@github.com:Mistralys/application-framework.git'
        );
        $mainFile->putData($config);

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $repos = $config['repositories'];

        // Count path entries that match application-framework.
        $pathCount = 0;
        $vcsCount = 0;
        foreach($repos as $repository)
        {
            if(!isset($repository['type'], $repository['url'])) {
                continue;
            }

            $matches = stripos($repository['url'], 'application-framework') !== false
                && stripos($repository['url'], 'application-utils') === false;

            if(!$matches) {
                continue;
            }

            if($repository['type'] === 'path') {
                $pathCount++;
            } elseif($repository['type'] === 'vcs') {
                $vcsCount++;
            }
        }

        $this->assertSame(1, $pathCount, 'Expected exactly one path entry for application-framework.');
        $this->assertSame(0, $vcsCount, 'Expected no VCS entries for application-framework after DEV switch.');
    }

    public function test_statusFileStoresCanonicalPaths() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $data = $switcher->getStatus()->getData();

        $this->assertStringNotContainsString('/../', $data['mainFile']);
        $this->assertStringNotContainsString('/../', $data['prodFile']);
        $this->assertStringNotContainsString('/../', $data['devFile']);
    }

    public function test_installGitHooksCopiesResourceExecutable() : void
    {
        $hooksDir = $this->testTarget . '/.git/hooks';
        mkdir($hooksDir, 0777, true);

        $switcher = $this->createSwitcher();
        $result = $switcher->installGitHooks($this->testTarget);

        $this->assertTrue($result);

        $hookFile = $hooksDir . '/pre-commit';
        $this->assertFileExists($hookFile);

        $resourceFile = __DIR__ . '/../../resources/git-hooks/pre-commit';
        $this->assertFileEquals($resourceFile, $hookFile);
        $this->assertTrue(is_executable($hookFile));
    }

    public function test_installGitHooksSkipsWhenGitHooksDirMissing() : void
    {
        $switcher = $this->createSwitcher();
        $result = $switcher->installGitHooks($this->testTarget);

        $this->assertFalse($result);
        $this->assertDirectoryDoesNotExist($this->testTarget . '/.git');
    }

    public function test_fromProjectRootThreePathConvention() : void
    {
        $switcher = ConfigSwitcher::fromProjectRoot($this->testTarget);

        $this->assertSame(
            $this->testTarget . '/composer.json',
            $switcher->getMainFile()->getPath()
        );

        $this->assertSame(
            $this->testTarget . '/composer/composer-prod.json',
            $switcher->getProdFile()->getPath()
        );

        $this->assertSame(
            $this->testTarget . '/composer/local-repositories.json',
            $switcher->getDevFile()->getPath()
        );
    }

    public function test_devSwitchRespectsRequireDevPlacement() : void
    {
        $devPackage = 'mistralys/some-dev-tool';

        // Add a require-dev package to the fixture's composer.json.
        $mainFile = new ConfigFile($this->testTarget . '/composer.json');
        $config = $mainFile->getData();
        $config['require-dev'][$devPackage] = '>=1.0';
        $mainFile->putData($config);

        // Add a matching entry to the dev config.
        $devFile = new ConfigFile($this->testTarget . '/composer/local-repositories.json');
        $devConfig = $devFile->getData();
        $devConfig['local-repositories'][] = array(
            'package-name' => $devPackage,
            'path' => '/path/to/some-dev-tool'
        );
        $devFile->putData($devConfig);

        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $result = $switcher->getMainFile()->getData();

        // The package must remain in require-dev, not be moved to require.
        $this->assertArrayHasKey($devPackage, $result['require-dev']);
        $this->assertArrayNotHasKey($devPackage, $result['require']);
    }

    public function test_devSwitchPreservesNonSwitchedVCSRepository() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $config = $switcher->getMainFile()->getData();
        $repos = $config['repositories'];

        $found = false;
        foreach($repos as $repository)
        {
            if(
                isset($repository['type'], $repository['url'])
                && stripos($repository['url'], 'some-unrelated-library') !== false
            ) {
                $found = true;
                $this->assertSame('vcs', $repository['type'], 'Non-switched VCS entry must retain its type.');
                break;
            }
        }

        $this->assertTrue($found, 'Expected the non-switched VCS entry for some-unrelated-library to survive the DEV switch.');
    }

    // endregion

    // region: Support methods

    /**
     * @return int[]
     */
    private function getMessageCodes(ConfigSwitcher $switcher) : array
    {
        return array_map(
            static fn(SwitchMessage $message) : int => $message->getCode(),
            $switcher->getMessages()
        );
    }

    /**
     * Manually creates the `composer-prod.json`/`.lock` snapshot that
     * `switchToProduction()` no longer produces under v3 (it has no
     * file effects on `composer-prod.*` at all) — for tests whose real
     * subject is a committed-style `composer-prod.*` leftover being
     * recognised and cleaned up as a v2-era legacy artifact (see
     * {@see ConfigSwitcher::switch_cleanLegacyArtifacts()}) rather than
     * being produced by a real DEV session.
     */
    private function bootstrapProdSnapshot(ConfigSwitcher $switcher) : void
    {
        $switcher->getMainFile()->copyTo($switcher->getProdFile());
        $switcher->getMainFile()->getLockFile()->tryCopyTo($switcher->getProdFile()->getLockFile());
    }

    private function assertFlagIsDEV(ConfigSwitcher $switcher) : void
    {
        $this->assertTrue($switcher->getFlagFile(ConfigSwitcher::MODE_DEV)->exists());
        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_PROD)->exists());
    }

    private function assertFlagIsPROD(ConfigSwitcher $switcher) : void
    {
        $this->assertFalse($switcher->getFlagFile(ConfigSwitcher::MODE_DEV)->exists());
        $this->assertTrue($switcher->getFlagFile(ConfigSwitcher::MODE_PROD)->exists());
    }
    
    /**
     * @param ConfigFile|LockFile $target
     * @return void
     */
    private function assertLockFileIsPROD($target) : void
    {
        $this->assertLockFileIs($target, 'PROD');
    }

    /**
     * @param ConfigFile|LockFile $target
     * @return void
     */
    private function assertLockFileIs($target, string $mode) : void
    {
        if($target instanceof ConfigFile) {
            $target = $target->getLockFile();
        }

        $this->assertSame($mode, $target->getContent());
    }

    private function assertConfigHasExpectedPaths(ConfigSwitcher $switcher) : void
    {
        $this->assertConfigHasPath($switcher, '/path/to/application-framework');
        $this->assertConfigHasPath($switcher, '/path/to/application-utils-core');
        $this->assertConfigHasPath($switcher, '/path/to/application-utils');
    }

    private function assertConfigHasPath(ConfigSwitcher $switcher, string $path) : void
    {
        $config = $switcher->getMainFile()->getData();
        $this->assertArrayHasKey('repositories', $config);
        $this->assertIsArray($config['repositories']);

        foreach($config['repositories'] as $repository)
        {
            if(isset($repository['type']) && $repository['type'] === 'path' && $repository['url'] === $path) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('No path repository found for path: ' . $path);
    }

    private function assertConfigHasCustomVersion(ConfigSwitcher $switcher, string $packageName, string $version) : void
    {
        $config = $switcher->getMainFile()->getData();
        $this->assertArrayHasKey('repositories', $config);
        $this->assertIsArray($config['repositories']);

        foreach($config['repositories'] as $repository)
        {
            if(
                isset($repository['type'], $repository['options']['versions'][$packageName])
                && $repository['type'] === 'path'
                && $repository['options']['versions'][$packageName] === $version
            ) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('No version definition found for package name: ' . $packageName);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function findVersionsForPackage(array $config, string $packageName) : array
    {
        $this->assertArrayHasKey('repositories', $config);
        $this->assertIsArray($config['repositories']);

        foreach($config['repositories'] as $repository)
        {
            if(
                isset($repository['type'], $repository['options']['versions'])
                && $repository['type'] === 'path'
                && isset($repository['options']['versions'][$packageName])
            ) {
                return $repository['options']['versions'];
            }
        }

        $this->fail('No versions definition found for package name: ' . $packageName);
    }

    // endregion
}
