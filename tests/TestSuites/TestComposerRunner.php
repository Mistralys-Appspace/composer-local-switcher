<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerRunner;
use PHPUnit\Framework\TestCase;

final class TestComposerRunner extends TestCase
{
    // region: _Tests

    // NOTE: Every test that invokes a real Composer binary (successful or
    // failing command, `isAvailable()` against a real binary, large-output
    // draining) lives in the Tier 2 counterpart of this class,
    // Mistralys\ComposerSwitcher\IntegrationSuites\TestComposerRunner
    // (tests/IntegrationSuites/TestComposerRunner.php). This Tier 1 file is
    // limited to the one case that never shells out to a real binary, so
    // `composer test` stays free of Composer-binary invocations.

    public function test_isAvailableReturnsFalseForUnresolvableBinary() : void
    {
        $this->withComposerBinaryEnv('/nonexistent/composer-binary-does-not-exist', function () {
            $runner = new ComposerRunner(getcwd());

            $this->assertFalse($runner->isAvailable());
        });
    }

    // endregion

    // region: Support methods

    /**
     * @param string $value
     * @param callable():void $callback
     */
    private function withComposerBinaryEnv(string $value, callable $callback) : void
    {
        $original = getenv('COMPOSER_BINARY');

        putenv('COMPOSER_BINARY=' . $value);

        try {
            $callback();
        } finally {
            if($original === false) {
                putenv('COMPOSER_BINARY');
            } else {
                putenv('COMPOSER_BINARY=' . $original);
            }
        }
    }

    // endregion
}
