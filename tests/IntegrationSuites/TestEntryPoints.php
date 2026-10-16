<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tier 2 suite driving the namespaced `composer switch-*` commands
 * ({@see \Mistralys\ComposerSwitcher\ConfigSwitcher::composerSwitchDev()}
 * and its siblings — `switch-dev`, `switch-prod`, `switch-update`,
 * `switch-install-hooks`, `switch-describe`, `switch-describe-json` and
 * `switch-preview-dev`) as real Composer invocations, asserting the
 * documented output each one produces rather than only the underlying
 * class method's return value — proving the script-key wiring in
 * `composer.json` actually dispatches to the switcher, not just that the
 * switcher's own logic is correct in isolation.
 *
 * `switch-verify-config` and `switch-reconcile` were removed, along with
 * the rest of the PROD reconciliation surface, by this plan's WP-010 —
 * the single source of truth model has no two-committed-copies drift to
 * reconcile, and this suite accordingly carries no reconcile/verify case.
 *
 * Under v3, every switch command completes its planned Composer step
 * itself in one invocation — there is no longer a separate `composer
 * update`/`install` step needed between `switch-dev` and `switch-prod`,
 * and `switch-update` always dispatches (writing status/flag files even
 * from the INITIAL state), so the pre-v3 "silent no-op" assumption this
 * suite used to assert no longer holds.
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
    private const PACKAGE_NAME = 'mistralys/simple_html_dom';

    // region: _Tests

    /**
     * `composer switch-dev -- --yes` completes in a single command: no
     * follow-up `composer update`/`install` is needed for the switched
     * package to actually be installed as a symlink — the planned
     * command ran as part of the switch itself — AC-11.
     */
    public function test_switchDevCompletesInSingleCommand() : void
    {
        $this->bootstrapProd();

        $result = $this->runSwitch('switch-dev');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-dev failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertFileExists($this->testTarget . '/composer.json.DEV');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.PROD');

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;

        $this->assertTrue(is_link($vendorPath), 'Expected the switched package to already be installed as a symlink with no follow-up command.');
    }

    /**
     * `composer switch-prod -- --yes`, run right after a DEV switch,
     * likewise completes in a single command: the published package is
     * already reinstalled as a real directory, with no follow-up
     * `composer install`/`update` needed — AC-11.
     */
    public function test_switchProdCompletesInSingleCommand() : void
    {
        $this->bootstrapProd();

        $devResult = $this->runSwitch('switch-dev');
        $this->assertTrue($devResult->isSuccess(), 'Expected switch-dev to succeed.');

        $prodResult = $this->runSwitch('switch-prod');

        $this->assertTrue($prodResult->isSuccess(), sprintf(
            "switch-prod failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $prodResult->getOutput(),
            $prodResult->getErrorOutput()
        ));

        $this->assertFileExists($this->testTarget . '/composer.json.PROD');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.DEV');

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;

        $this->assertDirectoryExists($vendorPath);
        $this->assertFalse(is_link($vendorPath), 'Expected the restored package to already be a real directory with no follow-up command.');
    }

    /**
     * `composer switch-update -- --yes`, even from the INITIAL state (no
     * switch has ever run), dispatches to the PROD/INITIAL->PROD row of
     * the decision table: it writes the status and PROD flag files, and
     * plans/runs `install` since nothing is installed yet — it is no
     * longer the silent, file-untouched no-op pre-v3 releases made it —
     * AC-11.
     */
    public function test_switchUpdateDispatchesFromInitialState() : void
    {
        $result = $this->runSwitch('switch-update');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-update failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertFileExists($this->testTarget . '/composer.json.PROD');
        $this->assertFileExists($this->testTarget . '/composer/local-repositories.status');

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;
        $this->assertDirectoryExists($vendorPath);
    }

    /**
     * `composer switch-dev -- --yes --no-install` writes the switched
     * `composer.json` and prints the planned command instead of running
     * it, leaving the vendor state unchanged (nothing installed yet) —
     * AC-11.
     */
    public function test_noInstallLeavesVendorStateUnchanged() : void
    {
        $this->bootstrapProd();

        $result = $this->runSwitch('switch-dev', '--no-install');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-dev --no-install failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertStringContainsString('to finish', $result->getOutput());

        $vendorPath = $this->testTarget . '/vendor/' . self::PACKAGE_NAME;

        $this->assertFalse(is_link($vendorPath), 'Expected --no-install to leave the vendor state unchanged (no symlink installed yet).');
    }

    /**
     * `composer switch-describe-json` in a fresh (INITIAL) work copy
     * exits zero and prints a single JSON document on stdout exposing
     * the reshaped key set — `mode`, `lockStatus`, `installedState` and
     * `pendingProdChanges` — rather than the retired `verification`
     * field, proving the CLI entry point emits
     * {@see \Mistralys\ComposerSwitcher\ConfigSwitcher::describe()}'s
     * reshaped snapshot as machine-readable JSON — AC-13.
     */
    public function test_describeJsonExposesReshapedKeys() : void
    {
        $result = $this->runComposer('switch-describe-json');

        $this->assertTrue($result->isSuccess());

        $decoded = json_decode(trim($result->getOutput()), true);

        $this->assertIsArray($decoded, 'Expected switch-describe-json stdout to decode as a JSON object.');
        $this->assertSame(ConfigSwitcher::MODE_INITIAL, $decoded['mode'] ?? null);
        $this->assertArrayHasKey('lockStatus', $decoded);
        $this->assertArrayHasKey('installedState', $decoded);
        $this->assertArrayHasKey('pendingProdChanges', $decoded);
        $this->assertArrayNotHasKey('verification', $decoded);
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

    /**
     * A real `composer switch-dev -- --yes` run is framed by the
     * shared "Composer Local Switcher —" header and a "done" footer,
     * and at default verbosity carries none of the bookkeeping `Detail`
     * messages (e.g. "Rebuilt a fresh DEV") {@see OutcomeRenderer} only
     * shows at `-v` — proving the readable-output contract end to end
     * through the real CLI dispatch path, not only via
     * {@see ScriptedEventContext} as Tier 1 already covers. Covers
     * AC-05, AC-06, AC-08.
     */
    public function test_switchOutputIsFramedAndNotDuplicated() : void
    {
        $this->bootstrapProd();

        $result = $this->runSwitch('switch-dev');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-dev failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertTrue(
            $result->containsOutput('Composer Local Switcher — switch PROD → DEV'),
            "Expected the framed header naming the transition to be present.\nOutput:\n" . $result->getOutput()
        );

        $this->assertTrue(
            $result->containsOutput('Composer Local Switcher — switch to DEV done'),
            "Expected the done footer to be present.\nOutput:\n" . $result->getOutput()
        );

        $this->assertFalse(
            $result->containsOutput('Rebuilt a fresh DEV'),
            "Expected bookkeeping text to be hidden at default verbosity.\nOutput:\n" . $result->getOutput()
        );

        $occurrences = substr_count($result->getOutput() . $result->getErrorOutput(), 'Composer Local Switcher — switch PROD → DEV');
        $this->assertSame(1, $occurrences, 'Expected the switch header to appear exactly once, not duplicated.');
    }

    /**
     * The same real `switch-dev` run with `-v` reveals the bookkeeping
     * `Detail` message ("Rebuilt a fresh DEV") and at least one file
     * operation line, both suppressed at default verbosity — the `-v`
     * counterpart to {@see self::test_switchOutputIsFramedAndNotDuplicated()}.
     * Covers AC-06.
     */
    public function test_verboseSwitchShowsDetails() : void
    {
        $this->bootstrapProd();

        $result = $this->runComposer('-v', 'switch-dev', '--', '--yes');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-dev -v failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertTrue(
            $result->containsOutput('Rebuilt a fresh DEV'),
            "Expected the bookkeeping Detail message to be shown at -v.\nOutput:\n" . $result->getOutput()
        );

        $this->assertTrue(
            $result->containsOutput('will '),
            "Expected at least one file operation line to be shown at -v.\nOutput:\n" . $result->getOutput()
        );
    }

    /**
     * A real `switch-dev` transcript structurally matches this plan's
     * target layout: the transition header, the change set
     * (`composer.json changes (…):`), the `Next:` planned-command line,
     * the running frame (`running \`composer …\``) and the done footer
     * each appear, in that order — the end-to-end counterpart to the
     * plan's target layout no stage in the prior project checked
     * against a real Composer run (see WP-006's notes and insight
     * `33a6df5a-e93d-41a3-b64d-766d33e0fe2e`).
     */
    public function test_realSwitchTranscriptMatchesPlannedLayout() : void
    {
        $this->bootstrapProd();

        $result = $this->runSwitch('switch-dev');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-dev failed unexpectedly.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $output = $result->getOutput();

        $headerPos = strpos($output, 'Composer Local Switcher — switch PROD → DEV');
        $changeSetPos = strpos($output, 'composer.json changes');
        $nextPos = strpos($output, 'Next:');
        $runningFramePos = strpos($output, 'Composer Local Switcher — running `composer');
        $footerPos = strpos($output, 'Composer Local Switcher — switch to DEV done');

        $this->assertNotFalse($headerPos, "Expected the transition header.\nOutput:\n" . $output);
        $this->assertNotFalse($changeSetPos, "Expected the composer.json change set.\nOutput:\n" . $output);
        $this->assertNotFalse($nextPos, "Expected the 'Next:' planned-command line.\nOutput:\n" . $output);
        $this->assertNotFalse($runningFramePos, "Expected the running-command frame.\nOutput:\n" . $output);
        $this->assertNotFalse($footerPos, "Expected the done footer.\nOutput:\n" . $output);

        $this->assertTrue(
            $headerPos < $changeSetPos && $changeSetPos < $nextPos && $nextPos < $runningFramePos && $runningFramePos < $footerPos,
            "Expected header, change set, 'Next:', running frame and footer to appear in that order.\nOutput:\n" . $output
        );
    }

    /**
     * A switch blocked by a stale `version` override (one that no
     * longer satisfies its root `require`/`require-dev` constraint —
     * {@see \Mistralys\ComposerSwitcher\ConfigSwitcher::MESSAGE_VERSION_OVERRIDE_CONFLICT})
     * exits non-zero, prints the blocked header, and shows no preview
     * wording — nothing was written, so the output must not read like a
     * dry run.
     */
    public function test_blockedStalePinTranscript() : void
    {
        $this->bootstrapProd();

        $this->setLocalRepositoryVersionOverride('1.0.0');

        $result = $this->runSwitch('switch-dev');

        $this->assertFalse($result->isSuccess(), sprintf(
            "Expected the stale-pin switch to be blocked (non-zero exit).\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertTrue(
            $result->containsOutput('Composer Local Switcher — switch PROD → DEV blocked, nothing was changed'),
            "Expected the blocked header naming the transition.\nOutput:\n" . $result->getOutput()
        );

        $combinedOutput = $result->getOutput() . $result->getErrorOutput();

        $this->assertStringNotContainsStringIgnoringCase(
            'preview',
            $combinedOutput,
            "Expected no preview wording in a blocked-switch transcript.\nOutput:\n" . $combinedOutput
        );

        $this->assertStringNotContainsString(
            'Would run',
            $combinedOutput,
            "Expected no 'Would run' wording in a blocked-switch transcript.\nOutput:\n" . $combinedOutput
        );
    }

    // endregion

    // region: Support methods

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
