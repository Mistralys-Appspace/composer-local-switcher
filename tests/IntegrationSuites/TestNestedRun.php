<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\IntegrationSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\IntegrationTestCase;

/**
 * Tier 2 suite proving a retained `post-update-cmd: @composer
 * switch-update` hook (the pre-v3 migration guide's recommended
 * wiring, which the v3 guide now recommends removing but tolerates
 * being left in place) is harmless in both circumstances it can fire
 * in: inside a Composer process the switcher itself launched (the
 * nested-run guard no-ops it), and after a user-run `composer
 * require`/`update` where the guard does not apply (it runs as an
 * ordinary, safe refresh).
 *
 * The hook is wired into the work copy's `composer.json` at test
 * runtime, not committed to the fixture — the fixture's own
 * `switch-prod`/`switch-dev` entry points must stay hook-free so every
 * other Tier 2 suite is unaffected by it.
 *
 * Every test here shells out to a real Composer binary and/or the cached
 * local package clone, so it belongs in the `Integration` testsuite
 * (`composer test-integration`), not the default, offline `Test suites`
 * testsuite (`composer test`).
 */
final class TestNestedRun extends IntegrationTestCase
{
    private const NEW_PACKAGE_NAME = 'psr/log';
    private const NEW_PACKAGE_CONSTRAINT = '^3.0';

    // region: _Tests

    /**
     * With the hook wired in, `switch-dev` plans and runs its own
     * `composer update <package>` — which, via the hook, fires a nested
     * `composer switch-update` inside that very child process.
     * `COMPOSER_SWITCHER_NESTED` is set for every child
     * {@see \Mistralys\ComposerSwitcher\Utils\ComposerProcess} spawns, so
     * the nested invocation no-ops immediately instead of recursing —
     * `switch-dev` itself still completes successfully, and
     * `composer.json` ends up exactly as an ordinary hook-free
     * `switch-dev` would leave it — AC-12.
     */
    public function test_postUpdateHookSwitchUpdateIsHarmless() : void
    {
        $this->bootstrapProd();
        $this->wireSwitchUpdateHook();

        $result = $this->runSwitch('switch-dev');

        $this->assertTrue($result->isSuccess(), sprintf(
            "switch-dev failed unexpectedly with the post-update-cmd hook wired.\nOutput:\n%s\nError output:\n%s",
            $result->getOutput(),
            $result->getErrorOutput()
        ));

        $this->assertStringContainsString(
            '182224',
            $result->getOutput(),
            'Expected the nested-run guard\'s MESSAGE_NESTED_RUN_SKIPPED code to appear in the output.'
        );

        $this->assertFileExists($this->testTarget . '/composer.json.DEV');
        $this->assertFileDoesNotExist($this->testTarget . '/composer.json.PROD');
    }

    /**
     * With the hook wired in and the work copy already in DEV, a plain
     * user-run `composer require` (via {@see self::runComposer()}, so
     * non-interactive and without `--yes` — a real user would not pass
     * switcher flags to their own `composer require`) fires the hook
     * outside any switcher-launched process, so the nested-run guard
     * does not apply: the hook's own `switch-update` call runs as an
     * ordinary DEV->DEV refresh. With `local-repositories.json`
     * unchanged, that refresh has nothing to show — its `composerJson`
     * section is empty, so it never prompts and needs no `--yes` even
     * non-interactively — so the user's own `composer require` command
     * exits `0`, `composer.json` carries exactly Composer's own edit,
     * and no `MESSAGE_CONFIRMATION_REQUIRED` (182228) appears anywhere
     * in the command's output. The carried-back package then survives
     * a subsequent `switch-prod` — AC-12, AC-14.
     */
    public function test_userRunRequireWithHookRetainedIsHarmless() : void
    {
        $this->switchToDev();
        $this->wireSwitchUpdateHook();

        $requireResult = $this->runComposer('require', self::NEW_PACKAGE_NAME . ':' . self::NEW_PACKAGE_CONSTRAINT, '--no-interaction');

        $this->assertTrue($requireResult->isSuccess(), sprintf(
            "composer require failed unexpectedly with the post-update-cmd hook wired.\nOutput:\n%s\nError output:\n%s",
            $requireResult->getOutput(),
            $requireResult->getErrorOutput()
        ));

        $this->assertFalse(
            $requireResult->containsOutput('182228'),
            'Expected no MESSAGE_CONFIRMATION_REQUIRED to appear in the output of the user-run composer require.'
        );

        $afterRequire = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertArrayHasKey(self::NEW_PACKAGE_NAME, $afterRequire['require'] ?? array());

        $prodSwitch = $this->runSwitch('switch-prod');
        $this->assertTrue($prodSwitch->isSuccess(), 'Expected switch-prod to succeed.');

        $prodData = $this->decodeJsonFile($this->testTarget . '/composer.json');
        $this->assertArrayHasKey(
            self::NEW_PACKAGE_NAME,
            $prodData['require'] ?? array(),
            'Expected the hook-refreshed, user-required package to be carried back on switch-prod.'
        );
    }

    // endregion

    // region: Support methods

    /**
     * Wires `post-update-cmd: ["@composer switch-update"]` into the
     * work copy's `composer.json`, at test runtime only — never
     * committed to the fixture.
     */
    private function wireSwitchUpdateHook() : void
    {
        $path = $this->testTarget . '/composer.json';
        $data = $this->decodeJsonFile($path);

        $data['scripts']['post-update-cmd'] = array('@composer switch-update');

        $this->writeJsonFile($path, $data);
    }

    // endregion
}
