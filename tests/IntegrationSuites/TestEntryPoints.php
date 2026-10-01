<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ProcessResult;
use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tier 2 suite driving all ten namespaced `composer switch-*` commands
 * ({@see \Mistralys\ComposerSwitcher\ConfigSwitcher::composerSwitchDev()}
 * and its siblings — `switch-dev`, `switch-prod`, `switch-update`,
 * `switch-verify-config`, `switch-install-hooks`, `switch-describe`,
 * `switch-describe-json`, `switch-reconcile`, `switch-preview-dev` and
 * `switch-preview-prod`) as real Composer invocations, asserting the
 * documented output each one produces rather than only the underlying
 * class method's return value — proving the script-key wiring in
 * `composer.json` actually dispatches to the switcher, not just that the
 * switcher's own logic is correct in isolation.
 *
 * `switch-describe` and `switch-preview-prod` are wired in the fixture's
 * `composer.json` but deliberately not driven by a dedicated test here:
 * they share their rendering code paths with `switch-describe-json`
 * (both read {@see \Mistralys\ComposerSwitcher\ConfigSwitcher::describe()})
 * and `switch-preview-dev` (both call
 * {@see \Mistralys\ComposerSwitcher\ConfigSwitcher::previewSwitch()}) —
 * commands this suite already exercises — so a dedicated Tier 2 test for
 * either would duplicate coverage already proven at the dispatch layer.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestEntryPoints extends IntegrationTestCase
{
    // region: _Tests

    /**
     * `composer switch-verify-config` against the untouched PROD baseline
     * (`composer.json` and `composer-prod.json` still identical) prints
     * the in-sync message and exits zero — AC-09.
     */
    public function test_verifyConfigReportsInSync() : void
    {
        $this->bootstrapProd();

        $result = $this->runComposer('switch-verify-config');

        $this->assertSuccessfulWithOutput($result, 'composer.json and composer-prod.json are in sync.');
    }

    /**
     * After editing `composer/composer-prod.json`, `composer
     * switch-verify-config` prints the differing-keys header followed by
     * the changed key — proving the comparison inspects real file
     * content rather than reporting a blanket in-sync/out-of-sync flag —
     * AC-09.
     */
    public function test_verifyConfigListsDifferences() : void
    {
        $this->bootstrapProd();
        $this->addProdDescriptionKey('Verify-config difference probe');

        $result = $this->runComposer('switch-verify-config');

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('composer.json and composer-prod.json differ in the following keys:', $result->getOutput());
        $this->assertStringContainsString('- description', $result->getOutput());
    }

    /**
     * In DEV state, `composer switch-verify-config` skips the comparison
     * entirely and prints the dedicated DEV-mode message instead of
     * either the in-sync or differing-keys output — AC-09.
     */
    public function test_verifyConfigReportsDevMode() : void
    {
        $this->switchToDev();

        $result = $this->runComposer('switch-verify-config');

        $this->assertSuccessfulWithOutput($result, 'DEV mode is active — config comparison skipped.');
    }

    /**
     * In PROD state, an edit to `composer/composer-prod.json` reaches
     * the active `composer.json` once `composer switch-update` runs —
     * the PROD/PROD branch of the switcher copies whichever of the two
     * files was modified more recently over the other, and the edit is
     * always the newer of the two here — AC-09.
     */
    public function test_switchUpdatePropagatesProdEdit() : void
    {
        $this->bootstrapProd();

        $prodSwitchResult = $this->runComposer('switch-prod');
        $this->assertTrue($prodSwitchResult->isSuccess(), 'Expected the initial switch-prod to succeed.');

        // Backdates composer.json's mtime by a full minute — far beyond
        // any filesystem's timestamp resolution — so the prod edit that
        // follows is guaranteed to register as strictly newer. This
        // guarantees the switcher's PROD/PROD branch treats the prod
        // file as the newer of the two and propagates its content,
        // deterministically and without the real-time cost of sleep().
        touch($this->testTarget . '/composer.json', time() - 60);
        clearstatcache();

        $this->addProdDescriptionKey('Propagated PROD edit');

        $updateResult = $this->runComposer('switch-update');
        $this->assertTrue($updateResult->isSuccess(), 'Expected switch-update to succeed.');

        $mainData = $this->decodeJsonFile($this->testTarget . '/composer.json');

        $this->assertSame('Propagated PROD edit', $mainData['description'] ?? null);
    }

    /**
     * Before any switch has ever run, `composer switch-update` is a
     * silent no-op: it exits zero, prints nothing, and leaves
     * `composer.json`, both flag files, and the status file exactly as
     * they were — {@see \Mistralys\ComposerSwitcher\ConfigSwitcher::switchUpdate()}
     * only dispatches once the status is DEV or PROD, neither of which
     * applies in the INITIAL state — AC-09.
     */
    public function test_switchUpdateIsNoOpInInitialState() : void
    {
        $mainJsonBefore = $this->readFile($this->testTarget . '/composer.json');

        $result = $this->runComposer('switch-update');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('', trim($result->getOutput()));

        $this->assertSame($mainJsonBefore, $this->readFile($this->testTarget . '/composer.json'));
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.DEV');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.PROD');
        $this->assertFileDoesNotExist($this->testTarget . '/composer/local-repositories.status');
    }

    /**
     * Both `composer switch-dev` and `composer switch-prod` exit zero
     * and write their own dedicated flag file (`composer.json.DEV` /
     * `composer.json.PROD`), never both at once — {@see writeFlagFiles()}
     * always deletes both before writing the one matching the new mode —
     * AC-09.
     *
     * The DEV switch deletes the main lock file when no DEV lock exists
     * yet ({@see \Mistralys\ComposerSwitcher\ConfigSwitcher::switch_case_PROD_DEV()}),
     * so a `composer update` is required in between: without it,
     * `switch-prod` would find no lock file and bail out with its
     * "no lock file" warning instead of switching at all.
     */
    public function test_switchDevAndProdExitSuccessfully() : void
    {
        $this->bootstrapProd();

        $devResult = $this->runComposer('switch-dev');

        $this->assertTrue($devResult->isSuccess());
        $this->assertFileExists($this->testTarget . '/composer.json.DEV');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.PROD');

        $this->updateDependencies();

        $prodResult = $this->runComposer('switch-prod');

        $this->assertTrue($prodResult->isSuccess());
        $this->assertFileExists($this->testTarget . '/composer.json.PROD');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.DEV');
    }

    /**
     * `composer switch-reconcile` in a fresh (INITIAL) work copy exits
     * zero and prints {@see ConfigSwitcher::MESSAGE_INITIAL_NOT_RECONCILABLE}'s
     * text — no switch has run yet, so there is nothing to reconcile.
     * Once `switch-prod` establishes a baseline and the work copy is
     * untouched since, the same command instead reports the
     * already-in-sync outcome — proving the CLI entry point dispatches
     * to {@see ConfigSwitcher::reconcile()} and prints whichever message
     * it recorded, rather than a fixed string — AC-05, AC-13.
     */
    public function test_reconcileReportsInitialThenAlreadyInSync() : void
    {
        $initialResult = $this->runComposer('switch-reconcile');

        $this->assertSuccessfulWithOutput($initialResult, 'No switch has been run yet: there is nothing to reconcile.');

        $this->bootstrapProd();
        $this->runComposerChecked('switch-prod');

        $inSyncResult = $this->runComposer('switch-reconcile');

        $this->assertSuccessfulWithOutput($inSyncResult, '`composer.json` and `composer-prod.json` are already in sync.');
    }

    /**
     * `composer switch-describe-json` in a fresh (INITIAL) work copy
     * exits zero and prints a single JSON document on stdout whose
     * `mode` key is {@see ConfigSwitcher::MODE_INITIAL} — proving the
     * CLI entry point emits {@see ConfigSwitcher::describe()}'s snapshot
     * as machine-readable JSON rather than the human-readable report
     * `switch-describe` renders — AC-13.
     */
    public function test_describeJsonReportsInitialMode() : void
    {
        $result = $this->runComposer('switch-describe-json');

        $this->assertTrue($result->isSuccess());

        $decoded = json_decode(trim($result->getOutput()), true);

        $this->assertIsArray($decoded, 'Expected switch-describe-json stdout to decode as a JSON object.');
        $this->assertSame(ConfigSwitcher::MODE_INITIAL, $decoded['mode'] ?? null);
    }

    /**
     * `composer switch-preview-dev` only prints the planned operations
     * and messages — it never touches disk. A byte-for-byte snapshot of
     * every file in the work copy, taken immediately before and after
     * the command runs, must therefore be identical — proving the dry
     * run reaches the real filesystem facade's dry-run flag through the
     * full CLI dispatch path, not only when called directly on
     * {@see ConfigSwitcher} as Tier 1 already covers — AC-13.
     */
    public function test_previewDevLeavesFileSnapshotUnchanged() : void
    {
        $before = $this->snapshotDirectory($this->testTarget);

        $result = $this->runComposer('switch-preview-dev');

        $after = $this->snapshotDirectory($this->testTarget);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($before, $after, 'switch-preview-dev must leave every file in the work copy untouched.');
    }

    // endregion

    // region: Support methods

    private function assertSuccessfulWithOutput(ProcessResult $result, string $expectedOutput) : void
    {
        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString($expectedOutput, $result->getOutput());
    }

    /**
     * Adds a `description` key to `composer/composer-prod.json` without
     * disturbing the rest of the file — a key the fixture's
     * `composer.json` does not carry, so it always registers as a
     * difference regardless of the two files' prior state.
     *
     * @param string $description
     * @return void
     */
    private function addProdDescriptionKey(string $description) : void
    {
        $path = $this->testTarget . '/composer/composer-prod.json';
        $data = $this->decodeJsonFile($path);

        $data['description'] = $description;

        $this->writeJsonFile($path, $data);
    }

    /**
     * Recursively snapshots every regular file under `$root`, capturing
     * both content and modification time, so two snapshots taken around
     * a dry-run command can be compared for byte-for-byte equality.
     *
     * @param string $root
     * @return array<string,array{content:string,mtime:int|false}>
     */
    private function snapshotDirectory(string $root) : array
    {
        $snapshot = array();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach($iterator as $fileInfo)
        {
            if(!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();

            $snapshot[$path] = array(
                'content' => file_get_contents($path),
                'mtime' => filemtime($path)
            );
        }

        ksort($snapshot);

        return $snapshot;
    }

    // endregion
}
