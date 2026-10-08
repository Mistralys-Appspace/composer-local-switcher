<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase;

/**
 * Covers planner behaviour not already pinned by the broader
 * decision-table coverage in {@see TestSwitching}/{@see TestDryRun}:
 * the DEV->DEV refresh's `--with <removed>:<prod-locked version>` pin
 * for a package dropped from `local-repositories.json` mid-session.
 * The no-delta byte-stability case (an unchanged repository list
 * leaves `composer.json` untouched on refresh) is already covered by
 * {@see TestSwitching::test_devRefreshWithNoDeltaLeavesComposerJsonByteIdenticalAndKeepsUserEdits()},
 * so it is not duplicated here.
 */
final class TestCommandPlanning extends ComposerSwitcherTestCase
{
    // region: _Tests

    /**
     * Removing a package from `local-repositories.json` while already
     * in DEV plans a partial `update` naming only the packages still
     * present and changed/added (none, here), plus a `--with` pin for
     * the removed one at its PROD-locked version — falling back to
     * `*` when the lock cannot report a locked version for it (the
     * Tier 1 fixture lock is a plain marker file, not real JSON).
     */
    public function test_devRefreshPlansWithPinForRemovedPackage() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $devFilePath = $this->testTarget . '/composer/local-repositories.json';
        $devConfig = json_decode((string)file_get_contents($devFilePath), true);

        $devConfig['local-repositories'] = array_values(array_filter(
            $devConfig['local-repositories'],
            static fn(array $entry) : bool => $entry['package-name'] !== 'mistralys/application-utils'
        ));

        file_put_contents($devFilePath, json_encode($devConfig));

        $outcome = $this->createSwitcher()->switchToDevelopment();

        $command = $outcome->getComposerCommand();
        $this->assertNotNull($command);

        $arguments = $command->getArguments();

        $this->assertSame('update', $arguments[0]);
        $this->assertContains('--with', $arguments);

        $withIndex = array_search('--with', $arguments, true);
        $this->assertSame('mistralys/application-utils:*', $arguments[$withIndex + 1]);
    }

    /**
     * Removing one package while simultaneously changing another's
     * version override plans a single `update` naming the changed
     * package, plus the removed package's `--with` pin — both deltas
     * are folded into one command.
     */
    public function test_devRefreshPlansCombinedChangeAndRemovalInOneCommand() : void
    {
        $switcher = $this->createSwitcher();
        $switcher->switchToDevelopment();

        $devFilePath = $this->testTarget . '/composer/local-repositories.json';
        $devConfig = json_decode((string)file_get_contents($devFilePath), true);

        $kept = array();
        foreach($devConfig['local-repositories'] as $entry) {
            if($entry['package-name'] === 'mistralys/application-utils') {
                continue;
            }

            if($entry['package-name'] === 'mistralys/application_framework') {
                $entry['version'] = '5.0.0';
            }

            $kept[] = $entry;
        }

        $devConfig['local-repositories'] = $kept;
        file_put_contents($devFilePath, json_encode($devConfig));

        $outcome = $this->createSwitcher()->switchToDevelopment();

        $command = $outcome->getComposerCommand();
        $this->assertNotNull($command);

        $arguments = $command->getArguments();

        $this->assertSame('update', $arguments[0]);
        $this->assertContains('mistralys/application_framework', $arguments);
        $this->assertContains('--with', $arguments);

        $withIndex = array_search('--with', $arguments, true);
        $this->assertSame('mistralys/application-utils:*', $arguments[$withIndex + 1]);
    }

    // endregion
}
